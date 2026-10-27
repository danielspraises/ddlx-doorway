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
    $ok = @mail($to, $subject, $body, implode("\r\n", $headers), '-f' . $from);
    if (!$ok) {
        log_error('mail() failed for ' . $to);
    }
    return $ok;
}
