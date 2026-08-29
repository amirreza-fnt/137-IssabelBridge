#!/usr/bin/php -q
<?php
/**
 * Exten 138 — citizen records a message (was SOAP AddVoiceMessageWithSend2 + MessageFileBase64).
 * Now: files upload + 137-request PhoneCall → trackingCode TTS.
 *
 * * (star)  = stop recording → submit → play tracking code
 * Hangup    = auto-submit via 137-on-record-hangup.php (no TTS)
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
    $cid = $agi->get_variable('CALLERID(num)');
    $caller = isset($cid['data']) ? (string)$cid['data'] : '';
    $uidVar = $agi->get_variable('UNIQUEID');
    $uniqueId = isset($uidVar['data']) ? (string)$uidVar['data'] : '';
    list($mobile, $tel) = $bridge->callerParts($caller);
    $agi->verbose('137record caller=' . $caller);

    $soundname = uniqid('fdd-', false);
    $decodeName = uniqid('decode-', false);
    $fName = '/tmp/' . $soundname;
    $fullFileName = $fName . '.WAV';
    $fulldecodeName = '/tmp/' . $decodeName . '.wav';

    $agi->set_variable('__137_SUBMITTED', '0');
    $agi->set_variable('__137_RECORD_TMP', $fName);
    $agi->exec('Set', 'CHANNEL(hangup_handler_push)=137-record-hangup,s,1');

    $agi->stream_file('137/operator');
    // Only * ends recording in-call; hangup triggers 137-on-record-hangup.php
    $agi->record_file($fName, 'WAV', '*', -1, null, true, null);

    if ($uniqueId !== '' && is_file(recordLockPath($uniqueId))) {
        $agi->verbose('137record: already submitted on hangup uid=' . $uniqueId);
        exit(0);
    }

    if (!file_exists($fullFileName) || filesize($fullFileName) < 800) {
        $agi->verbose('137record: record missing or too small ' . $fullFileName);
        $agi->stream_file('137/end');
        exit(1);
    }

    $bridge->soxToWav16($fullFileName, $fulldecodeName);

    $result = $bridge->submitRecording(
        $fulldecodeName,
        'NO_ANSWER',
        $bridge->phoneForRequest($mobile, $tel),
        null,
        'agi=137record'
    );

    $agi->verbose('137record tracking=' . $result['trackingCode'] . ' requestId=' . $result['requestId']);
    $agi->set_variable('137_SUBMITTED', '1');
    $agi->set_variable('TRACKING_CODE', $result['trackingCode']);
    if ($uniqueId !== '') {
        @touch(recordLockPath($uniqueId));
    }

    // * pressed — play tracking code
    $agi->stream_file('137/code');
    $agi->say_digits($bridge->digitsForTts($result['trackingCode']), '1234567');
    $agi->stream_file('137/end');
} catch (Throwable $e) {
    $agi->verbose('137record ERROR: ' . $e->getMessage());
    $agi->stream_file('137/end');
    exit(1);
}
