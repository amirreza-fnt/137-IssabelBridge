<?php
declare(strict_types=1);

/**
 * POST /api/call-control.php
 *
 * Operator software → Issabel AMI: answer (redirect) / reject (hangup) / hangup.
 *
 * {
 *   "secret": "...",
 *   "action": "answer" | "reject" | "hangup",
 *   "channel": "SIP/trunk-0000001",   // required for all
 *   "exten": "1001",                 // required for answer
 *   "context": "from-internal"       // optional
 * }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Bridge-Secret');
header('Access-Control-Allow-Methods: POST, OPTIONS');

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

    $data = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid JSON');
    }

    $secret = (string)($data['secret'] ?? ($_SERVER['HTTP_X_BRIDGE_SECRET'] ?? ''));
    if (!hash_equals((string)$config['bridge_secret'], $secret)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $action = strtolower(trim((string)($data['action'] ?? '')));
    $channel = trim((string)($data['channel'] ?? ''));
    if ($channel === '') {
        throw new RuntimeException('channel is required');
    }

    $amiCfg = $config['ami'];
    $ami = new AmiClient(
        (string)$amiCfg['host'],
        (int)$amiCfg['port'],
        (string)$amiCfg['username'],
        (string)$amiCfg['secret'],
        (int)($amiCfg['timeout'] ?? 5)
    );

    if ($action === 'reject' || $action === 'hangup') {
        $amiResult = $ami->hangup($channel);
    } elseif ($action === 'answer') {
        $exten = trim((string)($data['exten'] ?? ''));
        if ($exten === '') {
            throw new RuntimeException('exten is required for answer');
        }
        $context = trim((string)($data['context'] ?? 'from-internal'));
        $amiResult = $ami->redirect($channel, $exten, $context, 1);
    } else {
        throw new RuntimeException('action must be answer|reject|hangup');
    }

    echo json_encode([
        'ok'     => true,
        'action' => $action,
        'ami'    => $amiResult,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
