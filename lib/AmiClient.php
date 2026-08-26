<?php
/**
 * Minimal Asterisk Manager Interface (AMI) client for answer / reject / hangup.
 * Compatible with PHP 7.2+ on Issabel (no constructor property promotion).
 * Credentials come from /etc/asterisk/manager.conf on Issabel.
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

    /**
     * @param string $host
     * @param int    $port
     * @param string $username
     * @param string $secret
     * @param int    $timeout
     */
    public function __construct($host, $port, $username, $secret, $timeout = 5)
    {
        $this->host = (string)$host;
        $this->port = (int)$port;
        $this->username = (string)$username;
        $this->secret = (string)$secret;
        $this->timeout = (int)$timeout;
    }

    /**
     * @param array $actionFields
     * @return string[]
     */
    public function send(array $actionFields)
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

        $this->writeAction($fp, $actionFields);
        $result = $this->readMessage($fp);

        $this->writeAction($fp, array('Action' => 'Logoff'));
        fclose($fp);

        return $result;
    }

    /** @param string $channel @return string[] */
    public function hangup($channel)
    {
        return $this->send(array(
            'Action'  => 'Hangup',
            'Channel' => $channel,
        ));
    }

    /**
     * @param string $channel
     * @param string $exten
     * @param string $context
     * @param int    $priority
     * @return string[]
     */
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
     * @param string $exten
     * @param string $context
     * @param string $callerId
     * @return string[]
     */
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
        return $this->send(array(
            'Action'  => 'Command',
            'Command' => $command,
        ));
    }

    /**
     * Snapshot of queue + SIP peers for kartabl live panel.
     * @param string $queue
     * @return array
     */
    public function activeCallsSnapshot($queue = '8002')
    {
        $queueLines = $this->cli('queue show ' . $queue);
        $peerLines = $this->cli('sip show peers');
        $chanLines = $this->cli('core show channels concise');

        $rawQueue = implode("\n", $queueLines);
        $rawPeers = implode("\n", $peerLines);
        $rawChannels = implode("\n", $chanLines);

        $callers = array();
        $members = array();
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
                $members[] = array(
                    'exten'     => $m[1],
                    'interface' => $m[2],
                    'status'    => trim($m[3]),
                    'raw'       => trim($line),
                );
            }
            if ($inCallers && preg_match('/(SIP\/[^\s]+|PJSIP\/[^\s]+|Local\/[^\s]+)/', $line, $m)) {
                $callers[] = array(
                    'channel' => $m[1],
                    'raw'     => trim($line),
                );
            }
        }

        if ($callers === array()) {
            foreach ($chanLines as $line) {
                $parts = explode('!', $line);
                if (count($parts) < 3) {
                    continue;
                }
                $ch = $parts[0];
                $ctx = isset($parts[1]) ? $parts[1] : '';
                $ext = isset($parts[2]) ? $parts[2] : '';
                if ($ext === $queue || stripos($ctx, 'queue') !== false || stripos($line, $queue) !== false) {
                    if (stripos($ch, 'SIP/') === 0 || stripos($ch, 'PJSIP/') === 0) {
                        $callers[] = array(
                            'channel' => $ch,
                            'raw'     => $line,
                        );
                    }
                }
            }
        }

        return array(
            'queue'       => $queue,
            'callers'     => $callers,
            'members'     => $members,
            'rawQueue'    => $rawQueue,
            'rawPeers'    => $rawPeers,
            'rawChannels' => $rawChannels,
        );
    }

    /** @param resource $fp @param array $fields */
    private function writeAction($fp, array $fields)
    {
        $buf = '';
        foreach ($fields as $k => $v) {
            $buf .= $k . ': ' . $v . "\r\n";
        }
        $buf .= "\r\n";
        fwrite($fp, $buf);
    }

    /** @param resource $fp @return string[] */
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

    /** @param string[] $lines */
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
