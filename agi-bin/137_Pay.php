#!/usr/bin/php -q
<?php
/**
 * Exten 139 — tracking inquiry (old SOAP Tracking).
 *
 * 137-request GET /by-tracking-code is not implemented yet (501).
 * This script plays a short message and logs the entered code.
 * When request-service exposes tracking lookup, wire it here.
 */
require 'phpagi.php';

$agi = new AGI();
error_reporting(E_ALL);

$agi->answer();
$rahgiri = $agi->get_data('137/rahgiri', 9000, 20);
$code = isset($rahgiri['result']) ? (string)$rahgiri['result'] : '';
$agi->verbose('137_Pay tracking inquiry code=' . $code);

// Placeholder until GET /api/v1/requests/by-tracking-code/{code} is implemented.
// Keep old prompt file if present; otherwise end.
$prompt = '137/end';
if ($code !== '' && is_file('/var/lib/asterisk/sounds/137/' . $code . '.wav')) {
    $prompt = '137/' . $code;
}
$agi->stream_file($prompt);
$agi->stream_file('137/end');
