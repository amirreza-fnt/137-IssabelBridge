<?php
declare(strict_types=1);

/**
 * Minimal Asterisk Manager Interface (AMI) client for answer / reject / hangup.
 * Credentials come from /etc/asterisk/manager.conf on Issabel.
 */
final class AmiClient
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $secret,
        private readonly int $timeout = 5
    ) {}

    /**
     * @return list<string> raw AMI response lines
     */
    public function send(array $actionFields): array
    {
        $fp = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        if ($fp === false) {
            throw new RuntimeException("AMI connect failed: {$errstr} ({$errno})");
        }
        stream_set_timeout($fp, $this->timeout);

        // Banner
        fgets($fp);

        $this->writeAction($fp, [
            'Action'   => 'Login',
            'Username' => $this->username,
            'Secret'   => $this->secret,
            'Events'   => 'off',
        ]);
        $login = $this->readMessage($fp);
        if (!$this->isSuccess($login)) {
            fclose($fp);
            throw new RuntimeException('AMI login failed: ' . implode(' | ', $login));
        }

        $this->writeAction($fp, $actionFields);
        $result = $this->readMessage($fp);

        $this->writeAction($fp, ['Action' => 'Logoff']);
        fclose($fp);

        return $result;
    }

    /** Hangup an active channel (reject / end call). */
    public function hangup(string $channel): array
    {
        return $this->send([
            'Action'  => 'Hangup',
            'Channel' => $channel,
        ]);
    }

    /**
     * Redirect ringing channel to an operator extension (answer path).
     * Typical: Redirect Channel → Exten of agent, Context from-internal, Priority 1
     */
    public function redirect(string $channel, string $exten, string $context = 'from-internal', int $priority = 1): array
    {
        return $this->send([
            'Action'   => 'Redirect',
            'Channel'  => $channel,
            'Exten'    => $exten,
            'Context'  => $context,
            'Priority' => (string)$priority,
        ]);
    }

    /** Originate a call to an extension (optional helper). */
    public function originateLocal(string $exten, string $context = 'from-internal', string $callerId = '137'): array
    {
        return $this->send([
            'Action'   => 'Originate',
            'Channel'  => 'Local/' . $exten . '@' . $context,
            'Context'  => $context,
            'Exten'    => $exten,
            'Priority' => '1',
            'CallerID' => $callerId,
            'Async'    => 'true',
        ]);
    }

    /** Run an Asterisk CLI command via AMI (Events off — body is in Output lines). */
    public function cli(string $command): array
    {
        return $this->send([
            'Action'  => 'Command',
            'Command' => $command,
        ]);
    }

    /**
     * Snapshot of queue + SIP peers for kartabl live panel.
     * @return array{queue:string,rawQueue:string,rawPeers:string,rawChannels:string,callers:list<array<string,string>>,members:list<array<string,string>>}
     */
    public function activeCallsSnapshot(string $queue = '8002'): array
    {
        $queueLines = $this->cli('queue show ' . $queue);
        $peerLines = $this->cli('sip show peers');
        $chanLines = $this->cli('core show channels concise');

        $rawQueue = implode("\n", $queueLines);
        $rawPeers = implode("\n", $peerLines);
        $rawChannels = implode("\n", $chanLines);

        $callers = [];
        $members = [];
        $inMembers = false;
        $inCallers = false;
        foreach ($queueLines as $line) {
            if (stripos($line, 'Members:') !== false) {
                $inMembers = true;
                $inCallers = false;
                continue;
            }
            if (stripos($line, 'Callers:') !== false || stripos($line, 'No Callers') !== false) {
                $inMembers = false;
                $inCallers = stripos($line, 'No Callers') === false;
                continue;
            }
            if ($inMembers && preg_match('/^\s*(\d+)\s+\(([^)]+)\).*?\(([^)]+)\)/', $line, $m)) {
                $members[] = [
                    'exten'  => $m[1],
                    'interface' => $m[2],
                    'status' => trim($m[3]),
                    'raw'    => trim($line),
                ];
            }
            if ($inCallers && preg_match('/(SIP\/[^\s]+|PJSIP\/[^\s]+|Local\/[^\s]+)/', $line, $m)) {
                $callers[] = [
                    'channel' => $m[1],
                    'raw'     => trim($line),
                ];
            }
        }

        // Fallback: trunk channels currently in queue context from concise output
        if ($callers === []) {
            foreach ($chanLines as $line) {
                // concise: Channel!Context!Exten!Priority!...
                $parts = explode('!', $line);
                if (count($parts) < 3) {
                    continue;
                }
                $ch = $parts[0];
                $ctx = $parts[1] ?? '';
                $ext = $parts[2] ?? '';
                if ($ext === $queue || stripos($ctx, 'queue') !== false || stripos($line, $queue) !== false) {
                    if (stripos($ch, 'SIP/') === 0 || stripos($ch, 'PJSIP/') === 0) {
                        $callers[] = [
                            'channel' => $ch,
                            'raw'     => $line,
                        ];
                    }
                }
            }
        }

        return [
            'queue'        => $queue,
            'callers'      => $callers,
            'members'      => $members,
            'rawQueue'     => $rawQueue,
            'rawPeers'     => $rawPeers,
            'rawChannels'  => $rawChannels,
        ];
    }

    /** @param resource $fp */
    private function writeAction($fp, array $fields): void
    {
        $buf = '';
        foreach ($fields as $k => $v) {
            $buf .= $k . ': ' . $v . "\r\n";
        }
        $buf .= "\r\n";
        fwrite($fp, $buf);
    }

    /** @param resource $fp @return list<string> */
    private function readMessage($fp): array
    {
        $lines = [];
        while (!feof($fp)) {
            $line = fgets($fp);
            if ($line === false) {
                break;
            }
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                break;
            }
            $lines[] = $line;
        }
        return $lines;
    }

    /** @param list<string> $lines */
    private function isSuccess(array $lines): bool
    {
        foreach ($lines as $line) {
            if (stripos($line, 'Response: Success') === 0) {
                return true;
            }
        }
        return false;
    }
}
