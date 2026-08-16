<?php
/**
 * Issabel AGI bridge config.
 * Copy to: /etc/asterisk/137-bridge.php  (or agi-bin/137-bridge.config.php)
 *
 * Issabel host: 192.168.1.70
 * Point URLs to the AlmaLinux host that runs files + 137-request (adjust IPs/ports).
 */
return [
    // FROM Issabel — must be reachable:
    'files_base_url'   => 'http://192.168.1.12:6000',
    'request_base_url' => 'http://192.168.1.12:5006',
    // Or public: 'https://apiweb-137request.sabzevar.ir:5007'

    'api_key'          => 'dev-internal-key-137',
    'api_key_header'   => 'X-Api-Key',

    'monitor_dir'      => '/var/spool/asterisk/monitor',
    'http_timeout'     => 60,
];
