<?php
/**
 * Minimal Asterisk Manager Interface (AMI) client for answer / reject / hangup.
 * Compatible with PHP 7.2+ on Issabel (no constructor property promotion).
 */
class AmiClient
{
    /** @var string */
    private $host;
    /** @var int */
    private $port;
    /** @var string */
    private $username;
    /** @var string */
    private $secret;
    /** @var int */
    private $timeout;

    public function __construct($host, $port, $username, $secret, $timeout = 5)
    {
        $this->host = (string)$host;
        $this->port = (int)$port;
        $this->username = (string)$username;
        $this->secret = (string)$secret;
        $this->timeout = (int)$timeout;
    }

    /** @param array $actionFields @return string[] */
    public function send(array $actionFields)
    {
        $fp = $this->connectAndLogin();
        $this->writeAction($fp, $actionFields);
        $result = $this->readMessage($fp);
        $this->writeAction($fp, array('Action' => 'Logoff'));
        fclose($fp);
        return $result;
    }

    public function hangup($channel)
    {
        return $this->send(array(
            'Action'  => 'Hangup',
            'Channel' => $channel,
        ));
    }

    public function redirect($channel, $exten, $context = 'from-internal', $priority = 1)
    {
        return $this->send(array(
            'Action'   => 'Redirect',
            'Channel'  => $channel,
            'Exten'    => $exten,
            'Context'  => $context,
            'Priority' => (string)$priority,
        ));
    }

    /**
     * Pull caller out of Queue and Dial the agent cleanly.
     * Plain Redirect→from-internal races with the queue's own Local/agent ring → busy tone.
     *
     * @param string $callerChannel
     * @param string $exten
     * @return string[]
     */
    public function answerAsAgent($callerChannel, $exten)
    {
        $exten = preg_replace('/\D+/', '', (string)$exten);
        if ($exten === '') {
            throw new RuntimeException('agent exten required');
        }

        $fp = $this->connectAndLogin();
        try {
            // Only kill Local/from-queue legs (not SIP/2001 itself — safer).
            $chanLines = $this->cliOn($fp, 'core show channels concise');
            foreach ($chanLines as $line) {
                $line = preg_replace('/^Output:\s?/i', '', $line);
                $name = strtok($line, '!');
                if ($name === false || $name === '') {
                    continue;
                }
                if (stripos($name, 'Local/' . $exten . '@from-queue') === 0) {
                    if (strcasecmp($name, $callerChannel) === 0) {
                        continue;
                    }
                    $this->writeAction($fp, array(
                        'Action'  => 'Hangup',
                        'Channel' => $name,
                    ));
                    $this->readMessage($fp);
                }
            }

            $this->writeAction($fp, array(
                'Action'   => 'Redirect',
                'Channel'  => $callerChannel,
                'Context'  => '137-kartabl-answer',
                'Exten'    => $exten,
                'Priority' => '1',
            ));
            return $this->readMessage($fp);
        } finally {
            $this->writeAction($fp, array('Action' => 'Logoff'));
            fclose($fp);
        }
    }

    public function originateLocal($exten, $context = 'from-internal', $callerId = '137')
    {
        return $this->send(array(
            'Action'   => 'Originate',
            'Channel'  => 'Local/' . $exten . '@' . $context,
            'Context'  => $context,
            'Exten'    => $exten,
            'Priority' => '1',
            'CallerID' => $callerId,
            'Async'    => 'true',
        ));
    }

    /** @param string $command @return string[] */
    public function cli($command)
    {
        $fp = $this->connectAndLogin();
        try {
            return $this->cliOn($fp, $command);
        } finally {
            $this->writeAction($fp, array('Action' => 'Logoff'));
            fclose($fp);
        }
    }

    /**
     * Fast snapshot for kartabl: prefer asterisk -rx (usually <200ms).
     * AMI Command on some Issabel builds waits until read deadline (~4s×2 ≈ 8–10s).
     *
     * @param string $queue
     * @return array
     */
    public function activeCallsSnapshot($queue = '8002')
    {
        $queueLines = array();
        $peerLines = array();
        $via = 'shell';

        $queueLines = $this->shellCli('queue show ' . $queue);
        // peers optional — skip for speed (kartabl mainly needs callers/members)

        if ($queueLines === array()) {
            $via = 'ami';
            $fp = $this->connectAndLogin();
            try {
                $queueLines = $this->cliOn($fp, 'queue show ' . $queue);
            } finally {
                $this->writeAction($fp, array('Action' => 'Logoff'));
                fclose($fp);
            }
        }

        $callers = array();
        $members = array();
        $inMembers = false;
        $inCallers = false;

        foreach ($queueLines as $line) {
            $line = preg_replace('/^Output:\s?/i', '', $line);
            if (stripos($line, 'Members:') !== false) {
                $inMembers = true;
                $inCallers = false;
                continue;
            }
            if (stripos($line, 'Callers:') !== false) {
                $inMembers = false;
                $inCallers = true;
                continue;
            }
            if (stripos($line, 'No Callers') !== false) {
                $inMembers = false;
                $inCallers = false;
                continue;
            }
            if ($inMembers && preg_match('/^\s*(\d+)\s+\(/', $line, $m)) {
                $status = 'unknown';
                if (stripos($line, 'Unavailable') !== false) {
                    $status = 'Unavailable';
                } elseif (stripos($line, 'Not in use') !== false) {
                    $status = 'Not in use';
                } elseif (stripos($line, 'In use') !== false) {
                    $status = 'In use';
                } elseif (stripos($line, 'Busy') !== false) {
                    $status = 'Busy';
                } elseif (stripos($line, 'Ringing') !== false) {
                    $status = 'Ringing';
                } elseif (preg_match('/\(([^)]+)\)\s+has taken/', $line, $sm)) {
                    $status = trim($sm[1]);
                }
                $members[] = array(
                    'exten'  => $m[1],
                    'status' => $status,
                    'online' => (stripos($status, 'Unavailable') === false && $status !== 'unknown'),
                    'raw'    => trim($line),
                );
            }
            if ($inCallers && preg_match('/(SIP\/[^\s]+|PJSIP\/[^\s]+|Local\/[^\s]+)/', $line, $m)) {
                $callers[] = array(
                    'channel' => $m[1],
                    'raw'     => trim($line),
                );
            }
        }

        $peers = array();
        foreach ($peerLines as $line) {
            $line = preg_replace('/^Output:\s?/i', '', $line);
            if (preg_match('/^(\d+)\S*\s+(\S+)\s+.*?\s+(OK|UNKNOWN|UNREACHABLE|LAGGED)\b/i', $line, $m)) {
                $peers[] = array(
                    'name'   => $m[1],
                    'host'   => $m[2],
                    'status' => strtoupper($m[3]),
                    'online' => strtoupper($m[3]) === 'OK',
                    'raw'    => trim($line),
                );
            }
        }

        $onlineMembers = 0;
        foreach ($members as $mem) {
            if (!empty($mem['online'])) {
                $onlineMembers++;
            }
        }

        return array(
            'queue'         => $queue,
            'callers'       => $callers,
            'members'       => $members,
            'membersOnline' => $onlineMembers,
            'membersTotal'  => count($members),
            'waiting'       => count($callers),
            'peers'         => $peers,
            'source'        => $via,
        );
    }

    /** @param resource $fp @param string $command @return string[] */
    private function cliOn($fp, $command)
    {
        $this->writeAction($fp, array(
            'Action'  => 'Command',
            'Command' => $command,
        ));
        return $this->readCommandOutput($fp);
    }

    /** @param string $command @return string[] */
    private function shellCli($command)
    {
        $bin = trim((string)shell_exec('command -v asterisk 2>/dev/null'));
        if ($bin === '') {
            $bin = '/usr/sbin/asterisk';
        }
        if (!is_executable($bin)) {
            return array();
        }
        $out = array();
        $code = 0;
        exec(escapeshellcmd($bin) . ' -rx ' . escapeshellarg($command) . ' 2>/dev/null', $out, $code);
        return $code === 0 ? $out : array();
    }

    /** @return resource */
    private function connectAndLogin()
    {
        $fp = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        if ($fp === false) {
            throw new RuntimeException("AMI connect failed: {$errstr} ({$errno})");
        }
        stream_set_timeout($fp, $this->timeout);
        fgets($fp);
        $this->writeAction($fp, array(
            'Action'   => 'Login',
            'Username' => $this->username,
            'Secret'   => $this->secret,
            'Events'   => 'off',
        ));
        $login = $this->readMessage($fp);
        if (!$this->isSuccess($login)) {
            fclose($fp);
            throw new RuntimeException('AMI login failed: ' . implode(' | ', $login));
        }
        return $fp;
    }

    private function writeAction($fp, array $fields)
    {
        $buf = '';
        foreach ($fields as $k => $v) {
            $buf .= $k . ': ' . $v . "\r\n";
        }
        $buf .= "\r\n";
        fwrite($fp, $buf);
    }

    private function readMessage($fp)
    {
        $lines = array();
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

    private function readCommandOutput($fp)
    {
        $lines = array();
        $started = false;
        $deadline = time() + max(2, min(4, $this->timeout));
        while (!feof($fp) && time() <= $deadline) {
            $line = fgets($fp);
            if ($line === false) {
                // brief wait for next chunk
                usleep(50000);
                if (time() > $deadline) {
                    break;
                }
                continue;
            }
            $line = rtrim($line, "\r\n");
            if (stripos($line, 'Response:') === 0) {
                $started = true;
                $lines[] = $line;
                continue;
            }
            if (strpos($line, '--END COMMAND--') !== false) {
                $lines[] = $line;
                break;
            }
            if ($started) {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    private function isSuccess(array $lines)
    {
        foreach ($lines as $line) {
            if (stripos($line, 'Response: Success') === 0) {
                return true;
            }
        }
        return false;
    }
}
