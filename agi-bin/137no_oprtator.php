#!/usr/bin/php -q
<?php
/**
 * Exten 140 — no operator available: record voicemail → 137-request (NO_ANSWER).
 * Replaces old SOAP AddVoiceMessageWithSend2 path.
 */
require 'phpagi.php';
require_once __DIR__ . '/137_bridge.php';

$agi = new AGI();
error_reporting(E_ALL);

try {
    $bridge = new Bridge137();
    $agi->stream_file('137/nooperator');

    $cid = $agi->get_variable('CALLERID(num)');
    $caller = isset($cid['data']) ? (string)$cid['data'] : '';
    list($mobile, $tel) = $bridge->callerParts($caller);
    $agi->verbose('137no_oprtator caller=' . $caller);

    $soundname = uniqid('fdd-', false);
    $decodeName = uniqid('decode-', false);
    $fName = '/tmp/' . $soundname;
    $fullFileName = $fName . '.WAV';
    $fulldecodeName = '/tmp/' . $decodeName . '.wav';

    $agi->record_file($fName, 'WAV', '*', -1, null, true, null);

    if (!file_exists($fullFileName)) {
        $agi->verbose('137no_oprtator: record missing');
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
    $agi->set_variable('TRACKING_CODE', $result['trackingCode']);

    $agi->stream_file('137/code');
    $agi->say_digits($bridge->digitsForTts($result['trackingCode']), '1234567');
    $agi->stream_file('137/end');
} catch (Throwable $e) {
    $agi->verbose('137no_oprtator ERROR: ' . $e->getMessage());
    $agi->stream_file('137/end');
    exit(1);
}
