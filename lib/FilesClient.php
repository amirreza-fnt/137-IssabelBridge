<?php
declare(strict_types=1);

require_once __DIR__ . '/HttpClient.php';

/**
 * Two-step upload to FileStorageService:
 * 1) POST /api/files/prepare-upload
 * 2) POST /api/files/upload  (header UploadToken + multipart file)
 */
final class FilesClient
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $apiKeyHeader = 'X-Api-Key'
    ) {}

    /**
     * @return array{fileId:string, shortCode:?string, url:?string}
     */
    public function uploadLocalFile(string $absolutePath, string $accessType = 'TokenProtected'): array
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            throw new RuntimeException("Recording file not readable: {$absolutePath}");
        }

        $fileName = basename($absolutePath);
        $size = filesize($absolutePath);
        if ($size === false || $size <= 0) {
            throw new RuntimeException("Empty recording: {$absolutePath}");
        }

        $prepare = $this->http->request(
            'POST',
            rtrim($this->baseUrl, '/') . '/api/files/prepare-upload',
            [
                'fileName'   => $fileName,
                'sizeBytes'  => $size,
                'accessType' => $accessType,
            ],
            [$this->apiKeyHeader . ': ' . $this->apiKey]
        );

        if ($prepare['status'] < 200 || $prepare['status'] >= 300 || empty($prepare['json']['uploadToken'])) {
            throw new RuntimeException(
                'prepare-upload failed HTTP ' . $prepare['status'] . ': ' . $prepare['body']
            );
        }

        $token = (string)$prepare['json']['uploadToken'];

        $upload = $this->http->request(
            'POST',
            rtrim($this->baseUrl, '/') . '/api/files/upload',
            null,
            [
                'UploadToken: ' . $token,
                $this->apiKeyHeader . ': ' . $this->apiKey,
            ],
            [
                'file' => new CURLFile($absolutePath, $this->guessMime($fileName), $fileName),
            ]
        );

        if ($upload['status'] < 200 || $upload['status'] >= 300 || empty($upload['json']['fileId'])) {
            throw new RuntimeException(
                'upload failed HTTP ' . $upload['status'] . ': ' . $upload['body']
            );
        }

        return [
            'fileId'    => (string)$upload['json']['fileId'],
            'shortCode' => $upload['json']['shortCode'] ?? null,
            'url'       => $upload['json']['url'] ?? null,
        ];
    }

    private function guessMime(string $fileName): string
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($ext === 'wav') {
            return 'audio/wav';
        }
        if ($ext === 'mp3') {
            return 'audio/mpeg';
        }
        if ($ext === 'ogg') {
            return 'audio/ogg';
        }
        if ($ext === 'gsm') {
            return 'audio/x-gsm';
        }
        return 'application/octet-stream';
    }
}
