<?php
/**
 * Issabel / Asterisk bridge → 137 request + files microservices
 * Copy to: /var/www/html/137-bridge/config.php  (or anywhere Apache can read)
 * No local DB. Logging goes to request-service audit trail only.
 */
return [
    // Reachable FROM the Issabel server (LAN / public):
    'files_base_url'    => 'http://192.168.1.12:6000',   // FileStorageService
    'request_base_url'  => 'http://192.168.1.12:5006',   // 137-request (or https://apiweb-137request.sabzevar.ir:5007)

    // Same shared key used by TelephonyDev on request-service
    'api_key'           => 'dev-internal-key-137',
    'api_key_header'    => 'X-Api-Key',

    // Where Issabel/Asterisk stores Monitor recordings
    'monitor_dir'       => '/var/spool/asterisk/monitor',

    // Optional shared secret so only Issabel / operator app can hit these PHP endpoints
    'bridge_secret'     => 'change-me-issabel-bridge',

    // Asterisk Manager Interface (for answer / reject / hangup from operator software)
    'ami' => [
        'host'     => '127.0.0.1',
        'port'     => 5038,
        'username' => 'admin',
        'secret'   => 'amp111',   // from /etc/asterisk/manager.conf — REPLACE
        'timeout'  => 5,
    ],

    // HTTP timeouts (seconds)
    'http_timeout' => 60,
];
