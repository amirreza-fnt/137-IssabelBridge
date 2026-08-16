#!/usr/bin/php -q
<?php
/**
 * Queue 8002 hangup AGI — answered call with MixMonitor file.
 * Old: SOAP AddAgentCall + AttachVoiceFilePath
 * New: upload monitor wav + PhoneCall ANSWERED → trackingCode (stored on request only).
 */
require 'phpagi.php';
require_once __DIR__ . '/137_bridge.php';

$agi = new AGI();
error_reporting(E_ALL);

try {
    $bridge = new Bridge137();

    $uidVar = $agi->get_variable('UNIQUEID');
    $member = $agi->get_variable('MEMBERINTERFACE');
    $cid = $agi->get_variable('CALLERID(num)');

    $uniqueId = isset($uidVar['data']) ? (string)$uidVar['data'] : '';
    $memberData = isset($member['data']) ? (string)$member['data'] : '';
    $caller = isset($cid['data']) ? (string)$cid['data'] : '';

    $agentExt = '';
    if (preg_match('/\/(.*?)\@/', $memberData, $m)) {
        $agentExt = $m[1];
    }

    $filePath = $bridge->findMonitorByUniqueId($uniqueId);
    $agi->verbose('137queue uid=' . $uniqueId . ' agent=' . $agentExt . ' file=' . ($filePath ?: 'NONE'));

    if (!$filePath || !is_file($filePath)) {
        $agi->verbose('137queue: MixMonitor file not found for ' . $uniqueId);
        exit(1);
    }

    list($mobile, $tel) = $bridge->callerParts($caller);

    $result = $bridge->submitRecording(
        $filePath,
        'ANSWERED',
        $bridge->phoneForRequest($mobile, $tel),
        $agentExt !== '' ? $agentExt : null,
        'agi=137queue;uniqueId=' . $uniqueId
    );

    $agi->verbose('137queue tracking=' . $result['trackingCode'] . ' requestId=' . $result['requestId']);
    $agi->set_variable('TRACKING_CODE', $result['trackingCode']);
} catch (Throwable $e) {
    $agi->verbose('137queue ERROR: ' . $e->getMessage());
    exit(1);
}
