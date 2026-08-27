#!/usr/bin/php -q
<?php
/**
 * Fallback watcher: submit recent queue/kartabl wavs that never got MIXMON_POST.
 * Cron every minute:
 *   * * * * * asterisk /usr/bin/php -q /var/lib/asterisk/agi-bin/137-watch-submit.php >/dev/null 2>&1
 */
require_once __DIR__ . '/137_bridge.php';

$monitor = '/var/spool/asterisk/monitor';
$lockDir = '/tmp/137-submit-locks';
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0775, true);
}

$bridge = new Bridge137();
$day = date('Y/m/d');
$dirs = array($monitor . '/' . $day, $monitor);
$now = time();
$submitted = 0;

foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        continue;
    }
    foreach (glob($dir . '/*.{wav,WAV}', GLOB_BRACE) ?: array() as $file) {
        $base = basename($file);
        if (stripos($base, 'q-8002-') !== 0 && stripos($base, '137k-') !== 0) {
            continue;
        }
        $mtime = filemtime($file);
        if ($mtime === false || ($now - $mtime) > 3600 || ($now - $mtime) < 5) {
            continue; // only last hour, and not brand-new (still writing)
        }
        if (filesize($file) < 1024) {
            continue;
        }
        if (!preg_match('/(\d+\.\d+)\.wav$/i', $base, $m)) {
            continue;
        }
        $uid = $m[1];
        $done = $lockDir . '/' . preg_replace('/[^0-9.]/', '_', $uid) . '.done';
        if (is_file($done)) {
            continue;
        }

        $caller = '';
        if (preg_match('/q-8002-(\d+)-/', $base, $cm)) {
            $caller = $cm[1];
        }

        // Re-use CLI submit script logic via include would double-run locks; call it:
        $cmd = '/usr/bin/php -q ' . escapeshellarg(__DIR__ . '/137-on-record-end.php')
            . ' ' . escapeshellarg($uid)
            . ' ' . escapeshellarg($caller)
            . ' 2>&1';
        $out = shell_exec($cmd);
        if ($out) {
            fwrite(STDERR, trim($out) . "\n");
        }
        if (is_file($done)) {
            $submitted++;
        }
    }
}

fwrite(STDERR, "137-watch-submit done submitted={$submitted}\n");
