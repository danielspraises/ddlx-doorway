<?php
declare(strict_types=1);

// Email through the hosting's own mail service (works on cPanel). Failures never break the page.
function send_mail(string $to, string $subject, string $body): bool
{
    $from = (string) setting('mail_from', cfg('mail_from', 'no-reply@' . preg_replace('/^www\./', '', parse_url(base_url(), PHP_URL_HOST) ?: 'localhost')));
    $headers = [
        'From: Vendor Hub <' . $from . '>',
        'Reply-To: ' . $from,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: VendorHub',
    ];
    if (defined('VH_TESTING')) {
        $GLOBALS['vh_mail_outbox'][] = compact('to', 'subject', 'body');
        return true;
    }
    $subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    if (smtp_on()) {
        $r = smtp_send($to, $subject, $body, $from);
        if ($r === null) return true;
        log_error('SMTP failed for ' . $to . ': ' . $r . ' (falling back to mail())');
    }
    $ok = @mail($to, $subject, $body, implode("\r\n", $headers), '-f' . $from);
    if (!$ok) {
        log_error('mail() failed for ' . $to);
    }
    return $ok;
}

// ---- SMTP (Brevo, Gmail, your host's mail server...). Plain sockets, no libraries. ----
function smtp_on(): bool
{
    return (string) setting('smtp_host', '') !== '' && !defined('VH_TESTING');
}

/** Sends one message. Returns null on success, or a short error text. */
function smtp_send(string $to, string $subjectEncoded, string $body, string $from): ?string
{
    $host = (string) setting('smtp_host', '');
    $port = (int) setting('smtp_port', '587');
    $mode = (string) setting('smtp_secure', 'tls'); // ssl | tls | none
    $user = (string) setting('smtp_user', '');
    $pass = (string) setting('smtp_pass', '');
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client(($mode === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 12);
    if (!$fp) return 'Could not connect to ' . $host . ':' . $port . ' (' . $errstr . ')';
    stream_set_timeout($fp, 15);
    $read = function () use ($fp): array {
        $code = ''; $text = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $text .= $line;
            $code = substr($line, 0, 3);
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }
        return [(int) $code, trim($text)];
    };
    $cmd = function (string $c, array $ok) use ($fp, $read): ?string {
        fwrite($fp, $c . "\r\n");
        [$code, $text] = $read();
        return in_array($code, $ok, true) ? null : ($text !== '' ? $text : 'No answer from the mail server');
    };
    $fail = function (string $m) use ($fp): string { @fwrite($fp, "QUIT\r\n"); @fclose($fp); return $m; };
    [$code, $text] = $read();
    if ($code !== 220) return $fail($text ?: 'The mail server did not greet us');
    $ehlo = preg_replace('/[^A-Za-z0-9.-]/', '', (string) (parse_url(base_url(), PHP_URL_HOST) ?: 'localhost')) ?: 'localhost';
    if ($e = $cmd('EHLO ' . $ehlo, [250])) return $fail($e);
    if ($mode === 'tls') {
        if ($e = $cmd('STARTTLS', [220])) return $fail($e);
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) return $fail('Could not start a secure connection (TLS)');
        if ($e = $cmd('EHLO ' . $ehlo, [250])) return $fail($e);
    }
    if ($user !== '') {
        if ($e = $cmd('AUTH LOGIN', [334])) return $fail($e);
        if ($e = $cmd(base64_encode($user), [334])) return $fail($e);
        if ($e = $cmd(base64_encode($pass), [235])) return $fail('Sign-in to the mail server failed: ' . $e);
    }
    if ($e = $cmd('MAIL FROM:<' . $from . '>', [250])) return $fail($e);
    if ($e = $cmd('RCPT TO:<' . $to . '>', [250, 251])) return $fail($e);
    if ($e = $cmd('DATA', [354])) return $fail($e);
    $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
    $msg = 'Date: ' . gmdate('r') . "\r\n" . 'From: Vendor Hub <' . $from . ">\r\n" . 'To: <' . $to . ">\r\n" . 'Reply-To: ' . $from . "\r\n"
        . 'Subject: ' . $subjectEncoded . "\r\n" . 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . ">\r\n"
        . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($body), 76, "\r\n");
    fwrite($fp, $msg . ".\r\n");
    [$code, $text] = $read();
    if ($code !== 250) return $fail($text ?: 'The mail server refused the message');
    @fwrite($fp, "QUIT\r\n"); @fclose($fp);
    return null;
}

function save_smtp_settings(array $in): ?string
{
    $host = trim((string) ($in['smtp_host'] ?? ''));
    if ($host !== '' && !preg_match('/^[A-Za-z0-9.-]{3,120}$/', $host)) return 'The mail server name looks wrong (example: smtp-relay.brevo.com).';
    $port = (int) ($in['smtp_port'] ?? 587);
    if ($port < 1 || $port > 65535) return 'The mail server port should be a number such as 587 or 465.';
    $mode = (string) ($in['smtp_secure'] ?? 'tls');
    if (!in_array($mode, ['tls', 'ssl', 'none'], true)) return 'Choose a connection type.';
    set_setting('smtp_host', $host);
    set_setting('smtp_port', (string) $port);
    set_setting('smtp_secure', $mode);
    set_setting('smtp_user', trim((string) ($in['smtp_user'] ?? '')));
    $pass = (string) ($in['smtp_pass'] ?? '');
    if (($in['smtp_clear'] ?? '') === 'yes') set_setting('smtp_pass', '');
    elseif ($pass !== '') set_setting('smtp_pass', $pass);
    return null;
}
