<?php
declare(strict_types=1);

/**
 * POST /api/submit-recording.php
 *
 * Called by Issabel dialplan / AGI / MixMonitor hangup handler.
 * Uploads wav/mp3 to files service, creates PhoneCall request, returns trackingCode.
 *
 * JSON body example (NO_ANSWER — one recording):
 * {
 *   "secret": "...",
 *   "outcome": "NO_ANSWER",
 *   "callerPhone": "09121234567",
 *   "files": ["/var/spool/asterisk/monitor/2026/08/16/q-xxx.wav"]
 * }
 *
 * ANSWERED (citizen + operator / mixed):
 * {
 *   "secret": "...",
 *   "outcome": "ANSWERED",
 *   "callerPhone": "09121234567",
 *   "operatorExt": "1001",
 *   "files": [
 *     "/var/spool/asterisk/monitor/...-in.wav",
 *     "/var/spool/asterisk/monitor/...-out.wav"
 *   ]
 * }
 *
 * Or send relative names under monitor_dir:
 *   "files": ["2026/08/16/call-1.wav"]
 */

header('Content-Type: application/json; charset=utf-8');

try {
    $configPath = dirname(__DIR__) . '/config.php';
    if (!is_file($configPath)) {
        throw new RuntimeException('config.php missing. Copy config.example.php → config.php');
    }
    /** @var array $config */
    $config = require $configPath;

    require_once dirname(__DIR__) . '/lib/HttpClient.php';
    require_once dirname(__DIR__) . '/lib/FilesClient.php';
    require_once dirname(__DIR__) . '/lib/RequestClient.php';

    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid JSON body');
    }

    $secret = (string)($data['secret'] ?? ($_SERVER['HTTP_X_BRIDGE_SECRET'] ?? ''));
    if (!hash_equals((string)$config['bridge_secret'], $secret)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $outcome = strtoupper(trim((string)($data['outcome'] ?? '')));
    if (!in_array($outcome, ['NO_ANSWER', 'ANSWERED'], true)) {
        throw new RuntimeException('outcome must be NO_ANSWER or ANSWERED');
    }

    $filesIn = $data['files'] ?? [];
    if (!is_array($filesIn) || $filesIn === []) {
        throw new RuntimeException('files[] is required (absolute paths or relative to monitor_dir)');
    }

    $monitorDir = rtrim((string)$config['monitor_dir'], '/');
    $paths = [];
    foreach ($filesIn as $f) {
        $f = (string)$f;
        if ($f === '') {
            continue;
        }
        if ($f[0] !== '/') {
            $f = $monitorDir . '/' . ltrim($f, '/');
        }
        $paths[] = $f;
    }
    if ($paths === []) {
        throw new RuntimeException('No valid file paths');
    }

    $http = new HttpClient((int)$config['http_timeout']);
    $filesClient = new FilesClient(
        $http,
        (string)$config['files_base_url'],
        (string)$config['api_key'],
        (string)$config['api_key_header']
    );
    $requestClient = new RequestClient(
        $http,
        (string)$config['request_base_url'],
        (string)$config['api_key'],
        (string)$config['api_key_header']
    );

    $fileIds = [];
    $uploaded = [];
    foreach ($paths as $path) {
        $up = $filesClient->uploadLocalFile($path, 'TokenProtected');
        $fileIds[] = $up['fileId'];
        $uploaded[] = ['path' => $path, 'fileId' => $up['fileId']];
    }

    $created = $requestClient->createPhoneCallRequest(
        isset($data['callerPhone']) ? (string)$data['callerPhone'] : null,
        $outcome,
        $fileIds,
        isset($data['operatorExt']) ? (string)$data['operatorExt'] : null,
        isset($data['nationalCode']) ? (string)$data['nationalCode'] : null,
        isset($data['firstName']) ? (string)$data['firstName'] : null,
        isset($data['lastName']) ? (string)$data['lastName'] : null,
        isset($data['description']) ? (string)$data['description'] : null
    );

    // No local log DB — request-service keeps RequestLogs. Echo for Asterisk/AGI only.
    http_response_code(201);
    echo json_encode([
        'ok'           => true,
        'requestId'    => $created['requestId'],
        'trackingCode' => $created['trackingCode'],
        'outcome'      => $outcome,
        'files'        => $uploaded,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
