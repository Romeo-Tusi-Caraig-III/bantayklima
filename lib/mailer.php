<?php
// Sending email over SMTP (Gmail, Outlook, a school mail server...). No Composer needed.
// Settings are saved from Admin > Settings (database), with config.php values as a fallback.

/** SMTP settings in one array. host '' means email is not connected. */
function smtp_cfg(): array {
    $s = (array) setting_get('smtp', []);
    $pick = fn (string $k, $def = '') => (isset($s[$k]) && $s[$k] !== '') ? $s[$k] : (cfg('smtp_' . $k) ?? $def);
    return [
        'host' => trim((string) $pick('host')), 'port' => (int) ($pick('port', 465) ?: 465), 'secure' => in_array($pick('secure', 'ssl'), ['ssl', 'tls', 'none'], true) ? $pick('secure', 'ssl') : 'ssl',
        'user' => trim((string) $pick('user')), 'pass' => (string) $pick('pass'),
        'from_email' => trim((string) $pick('from_email')), 'from_name' => trim((string) $pick('from_name', 'BantayKlima')),
    ];
}
function email_connected(): bool { return smtp_cfg()['host'] !== ''; }

function mime_words(string $s): string { return preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s; }
function mail_clean(string $s): string { return trim(str_replace(["\r", "\n"], ' ', $s)); }

final class SmtpSession {
    private $fp = null;
    public function __construct(private array $c) {}

    private function read(): array {
        $all = ''; $code = 0;
        while (($l = fgets($this->fp, 1024)) !== false) {
            $all .= $l; $code = (int) substr($l, 0, 3);
            if (strlen($l) < 4 || $l[3] === ' ') break;
        }
        return [$code, trim($all)];
    }
    private function cmd(string $line, array $ok, string $shown = ''): string {
        fwrite($this->fp, $line . "\r\n");
        [$code, $resp] = $this->read();
        if (!in_array($code, $ok, true)) {
            $hint = $code === 535 ? ' (wrong username or password. For Gmail use an App Password, not your normal password.)' : '';
            throw new RuntimeException('The mail server said: ' . ($resp !== '' ? $resp : 'nothing (connection closed)') . $hint);
        }
        return $resp;
    }

    public function open(): void {
        $c = $this->c;
        $verify = cfg('smtp_verify') !== false;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => $verify, 'verify_peer_name' => $verify, 'SNI_enabled' => true, 'peer_name' => $c['host']]]);
        $remote = ($c['secure'] === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $c['port'];
        $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            $why = $errstr !== '' ? $errstr : 'no answer';
            if (stripos($why, 'certificate') !== false || stripos($why, 'SSL') !== false) $why .= '. The server certificate could not be verified: set openssl.cafile (or curl.cainfo) to a cacert.pem file in php.ini and restart Apache.';
            throw new RuntimeException("Could not connect to {$c['host']}:{$c['port']}: $why");
        }
        $this->fp = $fp;
        stream_set_timeout($fp, 20);
        [$code, $resp] = $this->read();
        if ($code !== 220) throw new RuntimeException('Unexpected greeting from the mail server: ' . $resp);
        $me = preg_replace('/[^A-Za-z0-9.-]/', '', (string) (gethostname() ?: 'localhost')) ?: 'localhost';
        $this->cmd("EHLO $me", [250]);
        if ($c['secure'] === 'tls') {
            $this->cmd('STARTTLS', [220]);
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('Could not start an encrypted connection (STARTTLS). Try port 465 with "SSL".');
            $this->cmd("EHLO $me", [250]);
        }
        if ($c['user'] !== '') {
            $this->cmd('AUTH LOGIN', [334]);
            $this->cmd(base64_encode($c['user']), [334]);
            $this->cmd(base64_encode($c['pass']), [235]);
        }
    }

    public function send(string $to, string $subject, string $body): void {
        $c = $this->c;
        $from = $c['from_email'] !== '' ? $c['from_email'] : $c['user'];
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Set a valid "From" email address in Settings.');
        $to = mail_clean($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('That email address does not look right.');
        $domain = substr(strrchr($from, '@'), 1);
        $name = mail_clean($c['from_name']);
        $headers = [
            'Date: ' . date('r'),
            'From: ' . ($name !== '' ? mime_words($name) . " <$from>" : $from),
            "To: <$to>",
            'Subject: ' . mime_words(mail_clean($subject)),
            'Message-ID: <' . bin2hex(random_bytes(8)) . "@$domain>",
            'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: base64',
        ];
        $data = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode(str_replace(["\r\n", "\r"], "\n", $body)), 76, "\r\n");
        $this->cmd("MAIL FROM:<$from>", [250]);
        $this->cmd("RCPT TO:<$to>", [250, 251]);
        $this->cmd('DATA', [354]);
        $this->cmd($data . '.', [250]);
    }

    public function close(): void {
        if ($this->fp) { @fwrite($this->fp, "QUIT\r\n"); @fclose($this->fp); $this->fp = null; }
    }
}

/** Send one email over SMTP, reusing one connection for a whole batch. Returns [ok, error text]. */
function smtp_send(string $to, string $subject, string $body): array {
    static $session = null;
    for ($try = 0; $try < 2; $try++) {
        try {
            if (!$session) { $session = new SmtpSession(smtp_cfg()); $session->open(); }
            $session->send($to, $subject, $body);
            return [true, ''];
        } catch (RuntimeException $e) {
            if ($session) $session->close();
            $session = null;
            // a dropped connection is worth one fresh try; a refusal by the server is not
            if ($try === 1 || str_starts_with($e->getMessage(), 'The mail server said') || str_contains($e->getMessage(), 'Set a valid') || str_contains($e->getMessage(), 'does not look right')) return [false, $e->getMessage()];
        }
    }
    return [false, 'Could not send.'];
}

/** How many real emails went out in the last 24 hours (guards Gmail's daily limit). */
function email_sent_last_day(): int {
    return (int) db()->query("SELECT COUNT(*) FROM messages WHERE channel = 'email' AND status = 'sent' AND created_at >= datetime('now', '-1 day')")->fetchColumn();
}

/** How many active subscribers with an email address an announcement to this area would reach. */
function email_audience(?int $municipalityId): int {
    $st = db()->prepare("SELECT COUNT(*) FROM subscribers WHERE status = 'active' AND email IS NOT NULL AND email <> ''" . ($municipalityId ? ' AND municipality_id = ?' : ''));
    $st->execute($municipalityId ? [$municipalityId] : []);
    return (int) $st->fetchColumn();
}
