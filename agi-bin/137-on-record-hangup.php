#!/usr/bin/php -q
<?php
/**
 * Hangup safety net for ext 138/140 citizen recording (137record.php).
 * Submits the wav when caller hangs up without pressing * — no tracking TTS (channel is gone).
 *
 * Usage: 137-on-record-hangup.php <uniqueId> <caller> <recordBaseWithoutExt>
 *   recordBase example: /tmp/fdd-67890abcdef
 */
require_once __DIR__ . '/137_bridge.php';

function log137hangup($msg)
{
    @file_put_contents('/var/log/asterisk/137-submit.log', date('c') . ' hangup ' . $msg . "\n", FILE_APPEND);
}

function lockPath($uniqueId)
{
    $id = preg_replace('/\W+/', '', (string)$uniqueId);
    return '/tmp/137-submitted-' . ($id !== '' ? $id : 'unknown') . '.flag';
}

$uniqueId = isset($argv[1]) ? trim((string)$argv[1]) : '';
$caller = isset($argv[2]) ? trim((string)$argv[2]) : '';
$recordBase = isset($argv[3]) ? trim((string)$argv[3]) : '';

if ($uniqueId === '') {
    exit(0);
}

$lock = lockPath($uniqueId);
if (is_file($lock)) {
    log137hangup("skip already submitted uid={$uniqueId}");
    exit(0);
}

$fullWav = $recordBase !== '' ? ($recordBase . '.WAV') : '';
if ($fullWav === '' || !is_file($fullWav)) {
    log137hangup("no wav uid={$uniqueId} path={$fullWav}");
    exit(0);
}

$size = filesize($fullWav);
if ($size === false || $size < 800) {
    log137hangup("wav too small uid={$uniqueId} bytes={$size}");
    exit(0);
}

try {
    $bridge = new Bridge137();
    list($mobile, $tel) = $bridge->callerParts($caller);
    $decodeName = '/tmp/decode-hangup-' . preg_replace('/\W+/', '', $uniqueId) . '.wav';
    $bridge->soxToWav16($fullWav, $decodeName);

    $result = $bridge->submitRecording(
        $decodeName,
        'NO_ANSWER',
        $bridge->phoneForRequest($mobile, $tel),
        null,
        'agi=137-on-record-hangup;uniqueId=' . $uniqueId
    );

    @touch($lock);
    log137hangup('OK tracking=' . $result['trackingCode'] . ' uid=' . $uniqueId);
} catch (Throwable $e) {
    log137hangup('ERROR uid=' . $uniqueId . ' ' . $e->getMessage());
    exit(1);
}
