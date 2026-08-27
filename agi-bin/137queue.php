#!/usr/bin/php -q
<?php
/**
 * Queue 8002 post-call AGI — run AFTER Queue() returns (answered call hung up).
 * Do NOT pass this as the Queue() AGI argument (that runs on answer and uploads a partial wav).
 *
 * Dialplan (extensions_override_issabelpbx.conf):
 *   Queue(8002,${QOPTIONS},,,${QMAXWAIT},,,${QGOSUB},${QRULE},${QPOSITION})
 *   ExecIf($["${QUEUESTATUS}"=""]?AGI(137queue.php))
 *
 * Old SOAP: AddAgentCall + AttachVoiceFilePath
 * New: upload full MixMonitor wav + PhoneCall ANSWERED → trackingCode
 */
require 'phpagi.php';
require_once __DIR__ . '/137_bridge.php';

$agi = new AGI();
error_reporting(E_ALL);

try {
    $bridge = new Bridge137();

    $uidVar = $agi->get_variable('UNIQUEID');
    $member = $agi->get_variable('MEMBERINTERFACE');
    $agentVar = $agi->get_variable('137_AGENT');
    $cid = $agi->get_variable('CALLERID(num)');
    $monVar = $agi->get_variable('MONITOR_FILENAME');
    $cdrRec = $agi->get_variable('CDR(recordingfile)');

    $uniqueId = isset($uidVar['data']) ? (string)$uidVar['data'] : '';
    $memberData = isset($member['data']) ? (string)$member['data'] : '';
    $agentHint = isset($agentVar['data']) ? (string)$agentVar['data'] : '';
    $caller = isset($cid['data']) ? (string)$cid['data'] : '';
    $monitorHint = isset($monVar['data']) ? (string)$monVar['data'] : '';
    $cdrFile = isset($cdrRec['data']) ? (string)$cdrRec['data'] : '';

    $agentExt = '';
    if ($agentHint !== '' && $agentHint !== '0' && preg_match('/\d+/', $agentHint, $am)) {
        $agentExt = $am[0];
    } elseif (preg_match('/\/(.*?)\@/', $memberData, $m)) {
        $agentExt = $m[1];
    } elseif (preg_match('/SIP\/(\d+)/i', $memberData, $m)) {
        $agentExt = $m[1];
    }

    // Let MixMonitor flush the wav after hangup.
    usleep(1500000);

    $filePath = $bridge->resolveMonitorFile($uniqueId, $monitorHint, $cdrFile);
    $agi->verbose('137queue uid=' . $uniqueId . ' agent=' . $agentExt . ' file=' . ($filePath ?: 'NONE'));

    if (!$filePath || !is_file($filePath)) {
        $agi->verbose('137queue: MixMonitor file not found for ' . $uniqueId);
        exit(1);
    }

    if (filesize($filePath) < 1024) {
        $agi->verbose('137queue: recording too small (' . filesize($filePath) . ' bytes), skip');
        exit(1);
    }

    list($mobile, $tel) = $bridge->callerParts($caller);

    $result = $bridge->submitRecording(
        $filePath,
        'ANSWERED',
        $bridge->phoneForRequest($mobile, $tel),
        $agentExt !== '' ? $agentExt : null,
        'agi=137queue;uniqueId=' . $uniqueId . ($agentExt !== '' ? ';agent=' . $agentExt : '')
    );

    $agi->verbose('137queue tracking=' . $result['trackingCode'] . ' requestId=' . $result['requestId']);
    $agi->set_variable('TRACKING_CODE', $result['trackingCode']);
} catch (Throwable $e) {
    $agi->verbose('137queue ERROR: ' . $e->getMessage());
    exit(1);
}
