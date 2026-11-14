<?php
declare(strict_types=1);

// Legal pages. Each has built-in wording that the admin can replace from Admin > Legal pages.
// Wording may use {company}, {email}, {commission}, {hold_days}, {min_withdrawal}, {fee}, {site}: they follow the live settings.

const LEGAL_DOCS = [
    'seller-terms' => ['Seller Agreement', 'The rules for selling on DDLX. Sellers agree to it when they apply.'],
    'terms' => ['Terms and Conditions', 'The rules for using the DDLX marketplace.'],
    'privacy' => ['Privacy Policy', 'What personal data is collected and how it is used.'],
    'returns-policy' => ['Returns and Refunds', 'How customers return items and get refunded.'],
    'prohibited-items' => ['Prohibited Items', 'What may not be sold on DDLX.'],
    'cookies' => ['Cookie Policy', 'How cookies are used.'],
];
const LEGAL_PATHS = ['seller-terms', 'terms', 'privacy', 'returns-policy', 'prohibited-items', 'cookies'];

function legal_vars(): array
{
    $c = rtrim(rtrim(number_format(commission_percent(), 2), '0'), '.');
    $fee = withdrawal_fee();
    return [
        '{company}' => trim((string) setting('legal_company', '')) ?: 'DDLX',
        '{email}' => trim((string) setting('legal_email', '')) ?: 'the team through the Messages page in Vendor Hub',
        '{commission}' => $c . '%',
        '{hold_days}' => (string) hold_days(),
        '{min_withdrawal}' => money(min_withdrawal()),
        '{fee}' => $fee > 0 ? money($fee) : 'no fee',
        '{site}' => rtrim((string) setting('marketplace_url', 'https://ddlx.shop'), '/'),
    ];
}

function legal_default(string $slug): string
{
    static $d = null;
    if ($d === null) $d = require dirname(__DIR__) . '/legal_text.php';
    return $d[$slug] ?? '';
}

function legal_text(string $slug): string
{
    $custom = trim((string) setting('legal_text_' . $slug, ''));
    return $custom !== '' ? $custom : legal_default($slug);
}

function legal_is_custom(string $slug): bool
{
    return trim((string) setting('legal_text_' . $slug, '')) !== '';
}

/** Current version of the seller agreement. Going up asks sellers to agree again. */
function legal_version(): int
{
    return max(1, (int) setting('legal_version', '1'));
}

function legal_updated(string $slug): string
{
    $d = (string) setting('legal_updated_' . $slug, '');
    return $d !== '' ? $d : '2026-11-14';
}

function legal_save(array $admin, string $slug, string $text, bool $askAgain): ?string
{
    if (!isset(LEGAL_DOCS[$slug])) return 'Unknown page.';
    $text = trim(str_replace("\r\n", "\n", $text));
    if (mb_strlen($text) > 60000) return 'The text is too long.';
    set_setting('legal_text_' . $slug, $text === trim(legal_default($slug)) ? '' : $text);
    set_setting('legal_updated_' . $slug, gmdate('Y-m-d'));
    if ($askAgain && $slug === 'seller-terms') {
        set_setting('legal_version', (string) (legal_version() + 1));
        audit($admin, 'settings', 'Updated the Seller Agreement and asked sellers to agree again');
    } else {
        audit($admin, 'settings', 'Updated the ' . LEGAL_DOCS[$slug][0] . ' page');
    }
    return null;
}

function legal_reset(array $admin, string $slug): void
{
    if (!isset(LEGAL_DOCS[$slug])) return;
    set_setting('legal_text_' . $slug, '');
    set_setting('legal_updated_' . $slug, gmdate('Y-m-d'));
    audit($admin, 'settings', 'Put the built-in wording back on the ' . LEGAL_DOCS[$slug][0] . ' page');
}

/** Plain text to safe HTML: "## Heading", "- bullet", blank-line paragraphs, **bold**. Escapes everything first. */
function legal_html(string $text): string
{
    $text = strtr($text, legal_vars());
    $out = ''; $list = false; $para = [];
    $inline = fn (string $s) => preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', e($s));
    $flush = function () use (&$out, &$para, $inline) { if ($para) { $out .= '<p>' . $inline(implode(' ', $para)) . '</p>'; $para = []; } };
    foreach (explode("\n", str_replace("\r\n", "\n", $text)) as $line) {
        $t = trim($line);
        if ($t === '') { $flush(); if ($list) { $out .= '</ul>'; $list = false; } continue; }
        if (str_starts_with($t, '## ')) { $flush(); if ($list) { $out .= '</ul>'; $list = false; } $out .= '<h2>' . $inline(substr($t, 3)) . '</h2>'; continue; }
        if (str_starts_with($t, '- ')) { $flush(); if (!$list) { $out .= '<ul>'; $list = true; } $out .= '<li>' . $inline(substr($t, 2)) . '</li>'; continue; }
        if ($list) { $out .= '</ul>'; $list = false; }
        $para[] = $t;
    }
    $flush();
    if ($list) $out .= '</ul>';
    return $out;
}

// ---------------------------------------------------------------- agreeing to the seller agreement

function terms_record(string $userId, string $doc = 'seller-terms'): void
{
    db()->insert('terms_acceptances', ['id' => uuid(), 'user_id' => $userId, 'doc' => $doc, 'version' => legal_version(), 'ip' => substr(client_ip(), 0, 45), 'created_at' => now_db()]);
}

/** The version this person last agreed to (0 = never). */
function terms_accepted_version(string $userId, string $doc = 'seller-terms'): int
{
    return (int) db()->val('SELECT MAX(version) FROM terms_acceptances WHERE user_id = ? AND doc = ?', [$userId, $doc]);
}

function terms_current(string $userId): bool
{
    return terms_accepted_version($userId) >= legal_version();
}

/** Sellers who have not agreed to the current version yet (verified sellers only). */
function terms_outstanding_count(): int
{
    return (int) db()->val("SELECT COUNT(*) FROM seller_verifications v WHERE v.status = 'approved' AND COALESCE((SELECT MAX(t.version) FROM terms_acceptances t WHERE t.user_id = v.user_id AND t.doc = 'seller-terms'), 0) < ?", [legal_version()]);
}
