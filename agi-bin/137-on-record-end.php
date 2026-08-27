#!/usr/bin/php -q
<?php
/**
 * Runs when MixMonitor finishes (3rd MixMonitor argument / MIXMON_POST).
 * Usage:
 *   137-on-record-end.php <uniqueId> [caller] [agentExt]
 *
 * Asterisk also appends: <basename> <format>  (we ignore extras).
 */
require_once __DIR__ . '/137_bridge.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');

$uniqueId = isset($argv[1]) ? trim((string)$argv[1]) : '';
$caller   = isset($argv[2]) ? trim((string)$argv[2]) : '';
$agentExt = isset($argv[3]) ? preg_replace('/\D+/', '', (string)$argv[3]) : '';

$logFile = '/var/log/asterisk/137-submit.log';
$log = function ($msg) use ($logFile) {
    $line = date('c') . ' ' . $msg . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND);
    fwrite(STDERR, $line);
};

if ($uniqueId === '' || $uniqueId === '0') {
    $log('137-on-record-end: missing uniqueId');
    exit(1);
}

$lockDir = '/tmp/137-submit-locks';
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0775, true);
}
$lockFile = $lockDir . '/' . preg_replace('/[^0-9.]/', '_', $uniqueId) . '.lock';
$doneFile = $lockDir . '/' . preg_replace('/[^0-9.]/', '_', $uniqueId) . '.done';

if (is_file($doneFile)) {
    $log("137-on-record-end: already submitted {$uniqueId}");
    exit(0);
}

$fp = @fopen($lockFile, 'c+');
if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB)) {
    $log("137-on-record-end: busy {$uniqueId}");
    exit(0);
}

try {
    // Let wav flush; if this was an early StopMixMonitor (kartabl redirect), file may be tiny —
    // wait a bit and pick the largest matching wav (prefers 137k-*).
    usleep(1500000);

    $bridge = new Bridge137();
    $filePath = null;
    for ($i = 0; $i < 8; $i++) {
        $filePath = $bridge->resolveMonitorFile($uniqueId, '', '');
        if ($filePath && is_file($filePath) && filesize($filePath) >= 1024) {
            break;
        }
        usleep(500000);
    }

    if (!$filePath || !is_file($filePath) || filesize($filePath) < 1024) {
        $log('137-on-record-end: no usable wav yet for ' . $uniqueId . ' path=' . ($filePath ?: 'NONE'));
        exit(1);
    }

    // If another MixMonitor for same UID is still growing (kartabl just started), wait once more.
    $size1 = filesize($filePath);
    usleep(800000);
    $filePath2 = $bridge->resolveMonitorFile($uniqueId, '', '');
    if ($filePath2 && is_file($filePath2) && filesize($filePath2) > $size1) {
        $filePath = $filePath2;
        usleep(800000);
    }

    if (is_file($doneFile)) {
        exit(0);
    }

    list($mobile, $tel) = $bridge->callerParts($caller);
    $result = $bridge->submitRecording(
        $filePath,
        'ANSWERED',
        $bridge->phoneForRequest($mobile, $tel),
        $agentExt !== '' ? $agentExt : null,
        'agi=137-on-record-end;uniqueId=' . $uniqueId . ';file=' . basename($filePath)
    );

    file_put_contents($doneFile, $result['trackingCode'] . "\n");
    $log('137-on-record-end tracking=' . $result['trackingCode']
        . ' requestId=' . $result['requestId']
        . ' file=' . $filePath);
} catch (Throwable $e) {
    $log('137-on-record-end ERROR: ' . $e->getMessage());
    exit(1);
} finally {
    flock($fp, LOCK_UN);
    fclose($fp);
}
