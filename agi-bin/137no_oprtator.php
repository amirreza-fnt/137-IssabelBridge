#!/usr/bin/php -q
<?php
/**
 * Exten 140 — no operator available: record voicemail → 137-request (NO_ANSWER).
 * * = submit + tracking TTS; hangup = auto-submit via 137-on-record-hangup.php
 */
require 'phpagi.php';
require_once __DIR__ . '/137_bridge.php';

function recordLockPath($uniqueId)
{
    $id = preg_replace('/\W+/', '', (string)$uniqueId);
    return '/tmp/137-submitted-' . ($id !== '' ? $id : 'unknown') . '.flag';
}

$agi = new AGI();
error_reporting(E_ALL);

try {
    $bridge = new Bridge137();
    $agi->stream_file('137/nooperator');

    $cid = $agi->get_variable('CALLERID(num)');
    $caller = isset($cid['data']) ? (string)$cid['data'] : '';
    $uidVar = $agi->get_variable('UNIQUEID');
    $uniqueId = isset($uidVar['data']) ? (string)$uidVar['data'] : '';
    list($mobile, $tel) = $bridge->callerParts($caller);
    $agi->verbose('137no_oprtator caller=' . $caller);

    $soundname = uniqid('fdd-', false);
    $decodeName = uniqid('decode-', false);
    $fName = '/tmp/' . $soundname;
    $fullFileName = $fName . '.WAV';
    $fulldecodeName = '/tmp/' . $decodeName . '.wav';

    $agi->set_variable('__137_SUBMITTED', '0');
    $agi->set_variable('__137_RECORD_TMP', $fName);
    $agi->exec('Set', 'CHANNEL(hangup_handler_push)=137-record-hangup,s,1');

    $agi->record_file($fName, 'WAV', '*', -1, null, true, null);

    if ($uniqueId !== '' && is_file(recordLockPath($uniqueId))) {
        $agi->verbose('137no_oprtator: already submitted on hangup');
        exit(0);
    }

    if (!file_exists($fullFileName) || filesize($fullFileName) < 800) {
        $agi->verbose('137no_oprtator: record missing or too small');
        $agi->stream_file('137/end');
        exit(1);
    }

    $bridge->soxToWav16($fullFileName, $fulldecodeName);

    $result = $bridge->submitRecording(
        $fulldecodeName,
        'NO_ANSWER',
        $bridge->phoneForRequest($mobile, $tel),
        null,
        'agi=137no_oprtator'
    );

    $agi->verbose('137no_oprtator tracking=' . $result['trackingCode']);
    $agi->set_variable('137_SUBMITTED', '1');
    $agi->set_variable('TRACKING_CODE', $result['trackingCode']);
    if ($uniqueId !== '') {
        @touch(recordLockPath($uniqueId));
    }

    $agi->stream_file('137/code');
    $agi->say_digits($bridge->digitsForTts($result['trackingCode']), '1234567');
    $agi->stream_file('137/end');
} catch (Throwable $e) {
    $agi->verbose('137no_oprtator ERROR: ' . $e->getMessage());
    $agi->stream_file('137/end');
    exit(1);
}
