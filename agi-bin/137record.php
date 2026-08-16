#!/usr/bin/php -q
<?php
/**
 * Exten 138 — citizen records a message (was SOAP AddVoiceMessageWithSend2 + MessageFileBase64).
 * Now: files upload + 137-request PhoneCall → trackingCode TTS.
 */
require 'phpagi.php';
require_once __DIR__ . '/137_bridge.php';

$agi = new AGI();
error_reporting(E_ALL);

try {
    $bridge = new Bridge137();
    $cid = $agi->get_variable('CALLERID(num)');
    $caller = isset($cid['data']) ? (string)$cid['data'] : '';
    list($mobile, $tel) = $bridge->callerParts($caller);
    $agi->verbose('137record caller=' . $caller);

    $soundname = uniqid('fdd-', false);
    $decodeName = uniqid('decode-', false);
    $fName = '/tmp/' . $soundname;
    $fullFileName = $fName . '.WAV';
    $fulldecodeName = '/tmp/' . $decodeName . '.wav';

    $agi->stream_file('137/operator');
    $agi->record_file($fName, 'WAV', '*', -1, null, true, null);

    if (!file_exists($fullFileName)) {
        $agi->verbose('137record: record missing ' . $fullFileName);
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
    $agi->set_variable('TRACKING_CODE', $result['trackingCode']);

    $agi->stream_file('137/code');
    $agi->say_digits($bridge->digitsForTts($result['trackingCode']), '1234567');
    $agi->stream_file('137/end');
} catch (Throwable $e) {
    $agi->verbose('137record ERROR: ' . $e->getMessage());
    $agi->stream_file('137/end');
    exit(1);
}
