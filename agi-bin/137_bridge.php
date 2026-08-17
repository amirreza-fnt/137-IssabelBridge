<?php
/**
 * Shared helpers for Issabel 137 AGI scripts → files + 137-request.
 * Drop-in replacement for old SOAP (192.168.1.42:8090/asterisk.asmx).
 */
declare(strict_types=1);

final class Bridge137
{
    /** @var array */
    private $cfg;

    public function __construct(?string $configPath = null)
    {
        $path = $configPath ?: '/etc/asterisk/137-bridge.php';
        if (!is_file($path)) {
            // fallback next to agi-bin
            $alt = __DIR__ . '/137-bridge.config.php';
            $path = is_file($alt) ? $alt : $path;
        }
        if (!is_file($path)) {
            throw new RuntimeException('Missing config: /etc/asterisk/137-bridge.php');
        }
        $this->cfg = require $path;
    }

    public function callerParts(string $callerId): array
    {
        $mobile = '';
        $tel = '';
        if (strlen($callerId) >= 11) {
            $mobile = $callerId;
        } else {
            $tel = $callerId;
        }
        return [$mobile, $tel];
    }

    public function phoneForRequest(string $mobile, string $tel): ?string
    {
        $p = $mobile !== '' ? $mobile : $tel;
        return $p !== '' ? $p : null;
    }

    /** Convert Asterisk .WAV (often gsm/ulaw) to standard PCM wav via sox. */
    public function soxToWav16(string $srcWav, string $destWav): void
    {
        // Force RIFF/WAVE PCM so files-service magic-number check accepts it.
        $cmd = 'sox ' . escapeshellarg($srcWav)
            . ' -t wav -b 16 -e signed-integer -r 8000 -c 1 '
            . escapeshellarg($destWav) . ' 2>&1';
        $out = shell_exec($cmd);
        if (!is_file($destWav) || filesize($destWav) <= 0) {
            throw new RuntimeException("sox failed: {$srcWav} → {$destWav} out={$out}");
        }
    }

    /**
     * Upload local audio → files service → create PhoneCall request.
     * @return array{requestId:string,trackingCode:string,fileId:string}
     */
    public function submitRecording(
        string $localWavPath,
        string $outcome,
        ?string $callerPhone,
        ?string $operatorExt = null,
        ?string $extraDescription = null
    ): array {
        if (!is_file($localWavPath)) {
            throw new RuntimeException("Recording not found: {$localWavPath}");
        }

        $work = '/tmp/137-' . uniqid('', false) . '.wav';
        $this->soxToWav16($localWavPath, $work);

        // AccessType.TokenProtected = 2 (files API has no JsonStringEnumConverter)
        $fileId = $this->uploadFile($work, 2);
        @unlink($work);

        $created = $this->createRequest($outcome, $callerPhone, [$fileId], $operatorExt, $extraDescription);
        return [
            'requestId'    => $created['requestId'],
            'trackingCode' => $created['trackingCode'],
            'fileId'       => $fileId,
        ];
    }

    /**
     * @param list<string> $fileIds
     * @return array{requestId:string,trackingCode:string}
     */
    public function createRequest(
        string $outcome,
        ?string $callerPhone,
        array $fileIds,
        ?string $operatorExt = null,
        ?string $extraDescription = null
    ): array {
        $parts = [
            'outcome=' . $outcome,
            $operatorExt ? ('operatorExt=' . $operatorExt) : null,
            $extraDescription,
            'source=issabel-agi',
        ];
        $description = implode(' | ', array_values(array_filter($parts)));

        $body = [
            'channel'     => 'PhoneCall',
            'description' => $description,
            'fileIds'     => array_values($fileIds),
        ];
        if ($callerPhone) {
            $body['citizen'] = ['phoneNumber' => $callerPhone];
        }

        $res = $this->httpJson(
            'POST',
            rtrim($this->cfg['request_base_url'], '/') . '/api/v1/requests',
            $body
        );

        if ($res['status'] !== 201 || empty($res['json']['trackingCode'])) {
            throw new RuntimeException('create request HTTP ' . $res['status'] . ': ' . $res['body']);
        }

        return [
            'requestId'    => (string)$res['json']['requestId'],
            'trackingCode' => (string)$res['json']['trackingCode'],
        ];
    }

    /** @param int $accessType TokenProtected=2 — files API expects numeric enum */
    public function uploadFile(string $absolutePath, int $accessType = 2): string
    {
        $fileName = basename($absolutePath);
        $size = filesize($absolutePath);
        if ($size === false || $size <= 0) {
            throw new RuntimeException("Empty file: {$absolutePath}");
        }

        $prepare = $this->httpJson(
            'POST',
            rtrim($this->cfg['files_base_url'], '/') . '/api/files/prepare-upload',
            [
                'fileName'   => $fileName,
                'sizeBytes'  => (int)$size,
                'accessType' => $accessType,
            ]
        );
        if ($prepare['status'] < 200 || $prepare['status'] >= 300 || empty($prepare['json']['uploadToken'])) {
            throw new RuntimeException('prepare-upload HTTP ' . $prepare['status'] . ': ' . $prepare['body']);
        }

        $token = (string)$prepare['json']['uploadToken'];
        $upload = $this->httpMultipart(
            rtrim($this->cfg['files_base_url'], '/') . '/api/files/upload',
            $absolutePath,
            $token
        );
        if ($upload['status'] < 200 || $upload['status'] >= 300 || empty($upload['json']['fileId'])) {
            throw new RuntimeException('upload HTTP ' . $upload['status'] . ': ' . $upload['body']);
        }

        return (string)$upload['json']['fileId'];
    }

    /** Digits only for Asterisk say_digits (from trackingCode like 137-14050525-000001). */
    public function digitsForTts(string $trackingCode): string
    {
        return preg_replace('/\D+/', '', $trackingCode) ?: '0';
    }

    /** Find MixMonitor file containing UNIQUEID under monitor/Y/m/d/. */
    public function findMonitorByUniqueId(string $uniqueId, ?string $y = null, ?string $m = null, ?string $d = null): ?string
    {
        $y = $y ?: date('Y');
        $m = $m ?: date('m');
        $d = $d ?: date('d');
        $base = rtrim($this->cfg['monitor_dir'], '/') . '/' . $y . '/' . $m . '/' . $d . '/';
        if (!is_dir($base)) {
            return null;
        }
        $found = null;
        foreach (glob($base . '*') ?: [] as $file) {
            if (stripos($file, $uniqueId) !== false) {
                $found = $file;
            }
        }
        return $found;
    }

    /** @return array{status:int,body:string,json:?array} */
    private function httpJson(string $method, string $url, array $body): array
    {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen((string)$payload),
                $this->cfg['api_key_header'] . ': ' . $this->cfg['api_key'],
            ],
            CURLOPT_TIMEOUT        => (int)$this->cfg['http_timeout'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => !empty($this->cfg['ssl_verify']),
            CURLOPT_SSL_VERIFYHOST => !empty($this->cfg['ssl_verify']) ? 2 : 0,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException("HTTP error: {$err}");
        }
        $json = json_decode((string)$raw, true);
        return [
            'status' => $status,
            'body'   => (string)$raw,
            'json'   => is_array($json) ? $json : null,
        ];
    }

    /** @return array{status:int,body:string,json:?array} */
    private function httpMultipart(string $url, string $filePath, string $uploadToken): array
    {
        $ch = curl_init($url);
        $mime = 'audio/wav';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [
                'file' => new CURLFile($filePath, $mime, basename($filePath)),
            ],
            CURLOPT_HTTPHEADER     => [
                'UploadToken: ' . $uploadToken,
                $this->cfg['api_key_header'] . ': ' . $this->cfg['api_key'],
            ],
            CURLOPT_TIMEOUT        => (int)$this->cfg['http_timeout'],
            CURLOPT_SSL_VERIFYPEER => !empty($this->cfg['ssl_verify']),
            CURLOPT_SSL_VERIFYHOST => !empty($this->cfg['ssl_verify']) ? 2 : 0,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException("upload error: {$err}");
        }
        $json = json_decode((string)$raw, true);
        return [
            'status' => $status,
            'body'   => (string)$raw,
            'json'   => is_array($json) ? $json : null,
        ];
    }
}
