<?php
/**
 * Issabel AGI bridge config.
 * Copy to: /etc/asterisk/137-bridge.php
 *
 * Issabel: 192.168.1.70
 * Request is HTTPS on nginx :5007 (Kestrel :5006 is localhost-only).
 * Files: storage.sabzevar.ir (or deploy filestorage on AlmaLinux :6000 behind nginx).
 */
return [
    'files_base_url'   => 'https://192.168.1.13:6001',
    // URL the employer's browser uses to play recordings (can differ from files_base_url).
    'files_public_base_url' => 'https://192.168.1.13:6001',
    'request_base_url' => 'https://apiweb-137request.sabzevar.ir:5007',

    'api_key'          => 'dev-internal-key-137',
    'api_key_header'   => 'X-Api-Key',

    'monitor_dir'      => '/var/spool/asterisk/monitor',
    'http_timeout'     => 60,

    // Cert on sabzevar.ir is valid; keep true. Set false only for broken LAN certs.
    'ssl_verify'       => true,
];
