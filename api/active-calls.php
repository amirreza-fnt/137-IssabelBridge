<?php
declare(strict_types=1);

/**
 * GET /api/active-calls.php?secret=...&queue=8002
 * Kartabl polls this for live queue callers / members (AMI).
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Bridge-Secret');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $configPath = dirname(__DIR__) . '/config.php';
    if (!is_file($configPath)) {
        throw new RuntimeException('config.php missing');
    }
    /** @var array $config */
    $config = require $configPath;
    require_once dirname(__DIR__) . '/lib/AmiClient.php';

    $secret = (string)($_GET['secret'] ?? ($_SERVER['HTTP_X_BRIDGE_SECRET'] ?? ''));
    if (!hash_equals((string)$config['bridge_secret'], $secret)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $queue = preg_replace('/\D+/', '', (string)($_GET['queue'] ?? '8002')) ?: '8002';
    $amiCfg = $config['ami'];
    $ami = new AmiClient(
        (string)$amiCfg['host'],
        (int)$amiCfg['port'],
        (string)$amiCfg['username'],
        (string)$amiCfg['secret'],
        (int)($amiCfg['timeout'] ?? 5)
    );

    $snap = $ami->activeCallsSnapshot($queue);
    echo json_encode([
        'ok'            => true,
        'queue'         => $snap['queue'],
        'callers'       => $snap['callers'],
        'members'       => $snap['members'],
        'membersOnline' => isset($snap['membersOnline']) ? $snap['membersOnline'] : 0,
        'membersTotal'  => isset($snap['membersTotal']) ? $snap['membersTotal'] : count($snap['members']),
        'waiting'       => isset($snap['waiting']) ? $snap['waiting'] : count($snap['callers']),
        'peers'         => isset($snap['peers']) ? $snap['peers'] : [],
        'at'            => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
