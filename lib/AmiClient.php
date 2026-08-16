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
