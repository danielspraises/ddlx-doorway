<?php
declare(strict_types=1);

// "Ask DDLX": a help assistant for sellers and the team.
// With an Anthropic API key (Admin → Settings) it answers with an AI model, grounded in the facts below and in the
// person's own account summary. Without a key, or when the AI is unreachable, it falls back to matching these same
// facts, so the page always gives a useful answer. It only answers questions; it never changes anything.

const AI_HISTORY = 12;
const AI_MAX_QUESTION = 1500;

function assistant_today(): string
{
    return (new DateTime('now', new DateTimeZone('Africa/Lagos')))->format('l, j F Y, H:i') . ' (West Africa Time)';
}

function assistant_facts(): array
{
    $c = rtrim(rtrim(number_format((float) setting('commission_percent', '10'), 2, '.', ''), '0'), '.');
    $hold = (int) setting('payout_hold_days', '7');
    $min = number_format((float) setting('min_withdrawal', '1000'));
    $fee = number_format((float) setting('withdrawal_fee', '0'));
    $mkt = rtrim((string) setting('marketplace_url', '') ?: 'https://ddlx.shop', '/');
    $levelText = assistant_level_text();
    return [
        ['keys' => ['date', 'today', 'what day', 'current time', 'what time', 'time now'], 'title' => 'Today', 'text' => 'It is ' . assistant_today() . '.'],
        ['keys' => ['platform', 'name', 'what is this', 'what is vendor', 'who are you', 'ddlx', 'vendor hub', 'about'], 'title' => 'The platform', 'text' => 'This is Vendor Hub, the seller portal for DDLX. You apply, get verified, list products, manage stock and orders, and withdraw earnings here. Your approved products are sold on the DDLX marketplace.'],
        ['keys' => ['marketplace', 'link', 'website', 'site', 'url', 'shop', 'ddlx.shop', 'store link', 'where do customers'], 'title' => 'The marketplace', 'text' => 'The DDLX marketplace is at ' . $mkt . '. Customers browse and pay there; the orders appear in your Sales tab here.'],
        ['keys' => ['badge', 'blue', 'gold', 'silver', 'bronze', 'level', 'levels', 'tier', 'rank', 'elite'], 'title' => 'Seller levels and badges', 'text' => $levelText],
        ['keys' => ['start', 'begin', 'sell', 'when can i', 'allowed', 'eligible', 'apply', 'application', 'register', 'become', 'join', 'steps', 'how does it work'], 'title' => 'Getting started', 'text' => "To sell on DDLX: 1) create an account and fill in the five-step seller application (business, brand, products, bank/payout, declarations); 2) wait for the team to approve it; 3) complete verification (identity and business documents); 4) once verified, open Seller Center and add products. Each step shows its status and the team's feedback if corrections are needed. Drafts save as you go."],
        ['keys' => ['verification', 'verify', 'document', 'id', 'identity', 'cac'], 'title' => 'Verification', 'text' => 'After the application is approved you upload identity and business documents on the Verification page. The team reviews them. If something is unclear you get a note explaining exactly what to fix, and you can upload again. Photos should be clear, uncropped and readable. You can start selling only after verification is approved.'],
        ['keys' => ['product', 'add product', 'list', 'listing', 'create product', 'upload product'], 'title' => 'Adding products', 'text' => 'Seller Center → Products → Add product. Fill in name, category (choose the most specific one), price, stock, weight and dimensions, and add a main photo and optional gallery photos. Save as a draft, then press Submit for review. Approved products go live on the DDLX marketplace. You can also add many at once with Products → Import CSV.'],
        ['keys' => ['review', 'approve', 'approval', 'pending', 'rejected', 'changes requested', 'live'], 'title' => 'Product review', 'text' => 'Every new product, and every change to price, photos or description of a live product, is reviewed by the DDLX team. Statuses: Draft, In review, Changes requested (read the note, fix, resubmit), Rejected, Approved (live). Stock levels are not reviewed and change straight away. You can take a live product off the marketplace and put it back at any time.'],
        ['keys' => ['sku', 'code', 'duplicate', 'unique'], 'title' => 'SKUs', 'text' => 'Every product (and every variation) needs a SKU that is unique across the whole marketplace. Leave it blank in a CSV import, or press Generate SKU in the editor, to get a free one automatically.'],
        ['keys' => ['csv', 'bulk', 'import', 'excel', 'spreadsheet', 'many products'], 'title' => 'Bulk upload', 'text' => 'Products → Import CSV: download the template, fill one product per row (name, category path, regular_price, weight_kg, length_cm, width_cm, height_cm are required), save as CSV and upload. All rows are checked first; if any has a problem nothing is imported, and the page tells you which rows to fix. Products arrive as drafts so you can add photos and submit them. Up to 100 rows per file. To change stock for many products: Inventory → Update many at once (CSV). Prices are changed in the product editor because price changes are reviewed.'],
        ['keys' => ['stock', 'inventory', 'quantity', 'out of stock', 'low stock'], 'title' => 'Stock', 'text' => 'Inventory tab: add, remove or set the quantity for each product or variation. Changes apply immediately, even on live products. You are alerted when stock runs low. Products at zero stock show as out of stock on the marketplace.'],
        ['keys' => ['order', 'orders', 'processing', 'ready for shipping', 'ship', 'shipping', 'delivery', 'delivered', 'status', 'pickup', 'pick up'], 'title' => 'Orders and delivery', 'text' => 'When a customer pays on ddlx.shop the order appears in Sales. Move it to Processing when you start preparing it, then to Ready for shipping when it is packed. DDLX then collects and delivers it: the team marks Shipped, Delivered and Completed, and you see those statuses update here. You do not arrange delivery yourself. Statuses only move forward.'],
        ['keys' => ['earn', 'earnings', 'money', 'payout', 'withdraw', 'withdrawal', 'wallet', 'balance', 'paid', 'bank', 'commission', 'fee', 'hold'], 'title' => 'Earnings and withdrawals', 'text' => "DDLX takes a commission of {$c}% by default (some categories can differ, shown when you pick the category). A sale's earnings stay pending until the order is delivered, then become withdrawable after a {$hold}-day hold. Payouts tab: add a bank account, then request a withdrawal. The smallest withdrawal is {$min}; the fee per withdrawal is {$fee}. The team reviews each request and marks it paid with a reference."],
        ['keys' => ['return', 'returns', 'refund', 'dispute', 'damaged', 'wrong item'], 'title' => 'Returns and refunds', 'text' => 'Returns tab: when a customer returns an order, a case appears. Respond with your side (accept, or dispute with a note). The DDLX team makes the final decision. If a refund is approved, the earnings for those items are reversed from your wallet and stock can be returned.'],
        ['keys' => ['vacation', 'store', 'profile', 'logo', 'banner', 'policy', 'tagline'], 'title' => 'Your store', 'text' => 'Store tab: add a logo, banner, tagline, description, return and shipping policies and contact details. Vacation mode hides your products temporarily with a message while you are away.'],
        ['keys' => ['password', 'forgot', 'reset', 'login', 'sign in', 'locked', '2fa', 'two-step', 'authenticator', 'security'], 'title' => 'Account and security', 'text' => 'Forgot your password: use "Forgot password" on the sign-in page. For extra safety turn on two-step sign-in under your account menu → Account security (you add the setup key to an authenticator app and keep your backup codes). After several wrong passwords sign-in pauses for 15 minutes.'],
        ['keys' => ['message', 'contact', 'support', 'help', 'human', 'team', 'talk', 'complain'], 'title' => 'Talking to the team', 'text' => 'Use Messages (envelope icon) to write to the DDLX team. Replies arrive there and as notifications and email. For anything about a specific order or payout, include the order number.'],
        ['keys' => ['notification', 'email', 'alert'], 'title' => 'Notifications', 'text' => 'The bell shows new orders, order progress, product decisions, payouts and messages. You can switch email alerts on or off on the Notifications page.'],
    ];
}

function assistant_level_text(): string
{
    $r = level_rules();
    $line = function (string $lv) use ($r): string {
        $x = $r[$lv];
        $parts = [$x['orders'] . '+ delivered orders in the last ' . LEVEL_WINDOW_DAYS . ' days', 'returns at most ' . $x['returns'] . '%'];
        if ($x['dispatch'] > 0) $parts[] = 'at least ' . $x['dispatch'] . '% of orders packed within ' . DISPATCH_HOURS . ' hours';
        if ($x['rating'] > 0) $parts[] = 'average rating ' . $x['rating'] . '+ from at least ' . $x['min_ratings'] . ' customer ratings';
        return LEVEL_LABEL[$lv] . ': ' . implode(', ', $parts) . '.';
    };
    return 'Seller levels are earned from your activity in the last ' . LEVEL_WINDOW_DAYS . " days: Starter, Bronze, Silver, Gold and Blue. To earn the Blue badge (the top level) you need: " . $line('blue') . "\nOther levels. " . $line('bronze') . ' ' . $line('silver') . ' ' . $line('gold') . ' Your Overview tab shows how close you are.';
}

function assistant_fallback(string $question): string
{
    $q = mb_strtolower($question);
    $best = null;
    $bestScore = 0;
    foreach (assistant_facts() as $f) {
        $score = 0;
        foreach ($f['keys'] as $k) if (str_contains($q, $k)) $score += 1 + substr_count($k, ' ');
        if ($score > $bestScore) { $best = $f; $bestScore = $score; }
    }
    if (!$best) return "I am not sure about that one. Here is what I can help with: getting started, verification, adding products and bulk upload, product review, stock, orders and delivery, earnings and withdrawals, returns, your store, and account security. Ask about any of those, or write to the team from Messages.";
    return $best['title'] . ":\n" . $best['text'];
}

// A short, factual summary of this person's own account, so answers can be specific.
function assistant_context(array $u): string
{
    if (!empty($u['isAdmin']) || !empty($u['canReview']) || !empty($u['canOrders']) || !empty($u['canMessages'])) {
        return 'This person is on the DDLX team (' . (!empty($u['isAdmin']) ? 'administrator' : 'staff') . '). Help them operate Vendor Hub: reviewing applications, verifications and products, orders, returns, payouts, messages, settings.';
    }
    $id = $u['id'];
    $lines = ['Seller stage: ' . (STAGE_LABEL[$u['stage']] ?? $u['stage'])];
    if ($u['stage'] === 'verified') {
        $lines[] = 'Products: ' . (int) db()->val('SELECT COUNT(*) FROM products WHERE seller_id = ?', [$id]) . ' total, ' . (int) db()->val("SELECT COUNT(*) FROM products WHERE seller_id = ? AND live = 1", [$id]) . ' live, ' . (int) db()->val("SELECT COUNT(*) FROM products WHERE seller_id = ? AND status = 'pending_review'", [$id]) . ' in review, ' . (int) db()->val("SELECT COUNT(*) FROM products WHERE seller_id = ? AND status IN ('changes_requested','rejected')", [$id]) . ' needing changes.';
        $lines[] = 'Open orders to handle: ' . (int) db()->val("SELECT COUNT(*) FROM order_items WHERE seller_id = ? AND status = 'paid' AND fulfilment IN ('placed','processing')", [$id]) . '.';
        foreach (wallet_balances($id) as $w) $lines[] = 'Wallet (' . $w['currency'] . '): available ' . $w['available'] . ', pending ' . ($w['pending'] ?? 0) . '.';
    }
    return implode("\n", $lines);
}

function assistant_system_prompt(array $u): string
{
    $facts = '';
    foreach (assistant_facts() as $f) $facts .= '- ' . $f['title'] . ': ' . $f['text'] . "\n";
    return "You are Ask DDLX, the help assistant inside DDLX Vendor Hub, the seller portal for the DDLX marketplace (ddlx.shop). You help sellers and the DDLX team use Vendor Hub.\n"
        . "Rules: answer only from the facts below and the account summary; if you do not know, say so and suggest writing to the team from Messages. Never invent policies, fees, dates or order details. You cannot change anything in the account, approve anything, or see other people's data. Never ask for passwords, bank details or verification documents. Be brief, friendly and practical: short paragraphs or a few steps. Reply in the language the person writes in. Today is " . assistant_today() . ". Do not use markdown headings.\n\n"
        . "FACTS ABOUT VENDOR HUB:\n" . $facts . "\nACCOUNT SUMMARY FOR THIS PERSON:\n" . assistant_context($u) . "\n";
}

// Services in order of preference: the better free one first. Every service that has a key is used, in this order,
// and the built-in help answers when none of them can.
const AI_PROVIDERS = [
    'gemini'    => ['label' => 'Google Gemini', 'model' => 'gemini-3.8-flash', 'help' => 'Free key at aistudio.google.com'],
    'groq'      => ['label' => 'Groq', 'model' => 'openai/gpt-oss-20b', 'url' => 'https://api.groq.com/openai/v1/chat/completions', 'help' => 'Free key at console.groq.com'],
    'anthropic' => ['label' => 'Anthropic Claude (paid)', 'model' => 'claude-sonnet-5-5', 'help' => 'Optional, paid'],
];

function assistant_key(string $p): string
{
    $k = (string) setting('ai_key_' . $p, '');
    if ($k !== '') return $k;
    // A key saved before each service had its own box.
    $legacy = (string) setting('ai_api_key', '');
    if ($legacy === '') return '';
    $was = (string) setting('ai_provider', '');
    return ($was === $p || ($was === '' && $p === 'anthropic')) ? $legacy : '';
}

// Services that have a key, best first.
function assistant_providers(): array
{
    if (setting('ai_enabled', '1') !== '1') return [];
    return array_values(array_filter(array_keys(AI_PROVIDERS), fn ($p) => assistant_key($p) !== ''));
}

function assistant_ready(): bool
{
    return assistant_providers() !== [];
}

// Groq retires and renames models often, so its current chat models are looked up with the saved key (cached for a day).
function assistant_discover_groq(): array
{
    $cache = jdec((string) setting('ai_models_groq', ''), []);
    if (!empty($cache['at']) && time() - (int) $cache['at'] < (empty($cache['ids']) ? 600 : 86400)) return $cache['ids'] ?? [];
    $ids = [];
    if (!defined('VH_TESTING') && function_exists('curl_init') && assistant_key('groq') !== '') {
        $ch = curl_init('https://api.groq.com/openai/v1/models');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . assistant_key('groq')]]);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($res !== false && $code === 200) {
            $all = [];
            foreach ((json_decode((string) $res, true)['data'] ?? []) as $m) {
                $id = (string) ($m['id'] ?? '');
                if ($id === '' || ($m['active'] ?? true) === false) continue;
                if (preg_match('/whisper|guard|tts|speech|embed|prompt|safeguard|orpheus|distil/i', $id)) continue;
                $all[] = $id;
            }
            // Prefer large, general chat models, then smaller fast ones.
            $rank = fn (string $id): int => preg_match('/gpt-oss-120b/', $id) ? 0 : (preg_match('/llama-3\.3|llama-4|70b|120b/', $id) ? 1 : (preg_match('/gpt-oss-20b|qwen|kimi/', $id) ? 2 : (preg_match('/llama|8b/', $id) ? 3 : 4)));
            usort($all, fn ($x, $y) => $rank($x) <=> $rank($y) ?: strcmp($x, $y));
            $ids = array_slice($all, 0, 3);
        }
    }
    set_setting('ai_models_groq', json_encode(['at' => time(), 'ids' => $ids]));
    return $ids;
}

function assistant_model(string $p): string
{
    $m = trim((string) setting('ai_model', ''));
    // A custom model name only applies to Gemini, the first choice.
    return ($p === 'gemini' && $m !== '' && str_starts_with($m, 'gemini')) ? $m : AI_PROVIDERS[$p]['model'];
}

// Models tried together within one service when the first is busy (free models are often overloaded for a few minutes).
function assistant_model_chain(string $p): array
{
    $more = ['gemini' => ['gemini-flash-latest', 'gemini-3.5-flash-lite', 'gemini-flash-lite-latest'], 'groq' => [], 'anthropic' => []][$p] ?? [];
    $first = [assistant_model($p)];
    if ($p === 'groq') { $d = assistant_discover_groq(); if ($d) { $first = array_slice($d, 0, 1); $more = array_slice($d, 1, 2); } else { $more = ['llama-3.3-70b-versatile']; } }
    return array_values(array_unique(array_merge($first, $more)));
}

// Asks every connected service at the same time (best first) and uses the best answer that arrives.
// Running them together keeps the wait short even when one service is busy, and a total time limit guarantees
// the page always gets an answer: the built-in help answers if no service does.
function assistant_call(string $system, array $messages): ?string
{
    if (!empty($GLOBALS['vh_ai_stub'])) return ($GLOBALS['vh_ai_stub'])($system, $messages);
    if ((defined('VH_TESTING') && empty($GLOBALS['vh_ai_url_map'])) || !function_exists('curl_init')) return null;
    @set_time_limit(45);
    $cands = [];
    foreach (assistant_providers() as $p) {
        $key = assistant_key($p);
        foreach (array_slice(assistant_model_chain($p), 0, $p === 'gemini' ? 3 : 2) as $model) $cands[] = ['p' => $p, 'model' => $model, 'req' => assistant_request($p, $key, $model, $system, $messages)];
    }
    if (!$cands) return null;
    $mh = curl_multi_init();
    foreach ($cands as $i => &$c) {
        $ch = curl_init($c['req']['url']);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 22, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $c['req']['headers'], CURLOPT_POSTFIELDS => json_encode($c['req']['body'])]);
        curl_multi_add_handle($mh, $ch);
        $c['ch'] = $ch; $c['done'] = false; $c['text'] = null; $c['err'] = '';
    }
    unset($c);
    $deadline = microtime(true) + 24;
    $winner = null;
    do {
        $running = 0;
        curl_multi_exec($mh, $running);
        while (($info = curl_multi_info_read($mh)) !== false) {
            foreach ($cands as $i => &$c) {
                if ($c['ch'] !== $info['handle'] || $c['done']) continue;
                $c['done'] = true;
                $body = (string) curl_multi_getcontent($c['ch']);
                $code = (int) curl_getinfo($c['ch'], CURLINFO_HTTP_CODE);
                if ($info['result'] !== CURLE_OK || $code !== 200) $c['err'] = $info['result'] !== CURLE_OK ? 'could not reach the service' : 'HTTP ' . $code . ' ' . trim(substr((string) preg_replace('/\s+/', ' ', $body), 0, 220));
                else { $c['text'] = assistant_parse($c['p'], $body); if ($c['text'] === null) $c['err'] = 'empty answer'; }
            }
            unset($c);
        }
        // Best-first: take the first candidate in priority order, but only once every candidate ahead of it has finished.
        foreach ($cands as $c) {
            if (!$c['done']) break;
            if ($c['text'] !== null) { $winner = $c; break 2; }
        }
        if ($running > 0) curl_multi_select($mh, 0.3);
    } while ($running > 0 && microtime(true) < $deadline);
    // Time is up or all finished: any answer that did arrive is better than none.
    if (!$winner) foreach ($cands as $c) if ($c['done'] && $c['text'] !== null) { $winner = $c; break; }
    $problems = [];
    foreach ($cands as $c) {
        if (!$c['done'] && $c['text'] === null) $c['err'] = 'no answer in time';
        if ($c['text'] === null && $c !== $winner) $problems[] = $c['p'] . ' ' . $c['model'] . ': ' . $c['err'];
        curl_multi_remove_handle($mh, $c['ch']); curl_close($c['ch']);
    }
    curl_multi_close($mh);
    foreach ($problems as $pr) log_error('Assistant call failed (' . $pr . ')');
    if ($winner) {
        set_setting('ai_last_error', $problems ? gmdate('Y-m-d H:i') . ' UTC (answered by ' . $winner['p'] . ' ' . $winner['model'] . ') ' . implode(' | ', array_slice($problems, 0, 4)) : '');
        set_setting('ai_last_used', $winner['p']);
        return $winner['text'];
    }
    set_setting('ai_last_error', gmdate('Y-m-d H:i') . ' UTC, ' . implode(' | ', array_slice($problems, 0, 5)));
    return null;
}

// What to send to one service for one model.
function assistant_request(string $p, string $key, string $model, string $system, array $messages): array
{
    $r = assistant_request_real($p, $key, $model, $system, $messages);
    if (!empty($GLOBALS['vh_ai_url_map'][$p . '|' . $model])) $r['url'] = $GLOBALS['vh_ai_url_map'][$p . '|' . $model]; // tests point services at local fakes
    return $r;
}

function assistant_request_real(string $p, string $key, string $model, string $system, array $messages): array
{
    if ($p === 'anthropic') {
        return ['url' => 'https://api.anthropic.com/v1/messages', 'headers' => ['content-type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
            'body' => ['model' => $model, 'max_tokens' => 700, 'system' => $system, 'messages' => $messages]];
    }
    if ($p === 'gemini') { // Google's own Gemini API
        $contents = [];
        foreach ($messages as $m) $contents[] = ['role' => $m['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => (string) $m['content']]]];
        return ['url' => 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent', 'headers' => ['content-type: application/json', 'x-goog-api-key: ' . $key],
            'body' => ['systemInstruction' => ['parts' => [['text' => $system]]], 'contents' => $contents, 'generationConfig' => ['maxOutputTokens' => 2048]]];
    }
    return ['url' => AI_PROVIDERS[$p]['url'], 'headers' => ['content-type: application/json', 'Authorization: Bearer ' . $key], // Groq: the common "chat completions" format
        'body' => ['model' => $model, 'max_tokens' => 900, 'messages' => array_merge([['role' => 'system', 'content' => $system]], $messages)]];
}

function assistant_parse(string $p, string $raw): ?string
{
    $d = json_decode($raw, true);
    $text = '';
    if ($p === 'anthropic') { foreach ($d['content'] ?? [] as $blk) if (($blk['type'] ?? '') === 'text') $text .= $blk['text']; }
    elseif ($p === 'gemini') { foreach ($d['candidates'][0]['content']['parts'] ?? [] as $part) if (empty($part['thought'])) $text .= (string) ($part['text'] ?? ''); }
    else $text = (string) ($d['choices'][0]['message']['content'] ?? '');
    return trim($text) !== '' ? trim($text) : null;
}

// Turns a plain or lightly marked-up answer (**bold**, lists, `code`) into safe HTML. Everything is escaped first.
function assistant_format(string $text): string
{
    $inline = function (string $t): string {
        $t = htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        $t = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $t);
        $t = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/s', '<em>$1</em>', $t);
        $t = preg_replace('/`([^`]+)`/', '<code>$1</code>', $t);
        return $t;
    };
    // Built-in help writes steps inline as "1) ... 2) ..."; show them as a list.
    if (preg_match('/(?:^|\s)1\)\s/', $text) && preg_match('/\s2\)\s/', $text)) $text = preg_replace(['/\s*;?\s*(?<![\w])(\d{1,2})\)\s+/'], ["\n$1. "], $text);
    $out = ''; $list = null; $para = [];
    $flushPara = function () use (&$out, &$para, $inline) { if ($para) { $out .= '<p>' . implode('<br>', array_map($inline, $para)) . '</p>'; $para = []; } };
    $flushList = function () use (&$out, &$list) { if ($list) { $out .= '<' . $list['t'] . '>' . implode('', $list['items']) . '</' . $list['t'] . '>'; $list = null; } };
    foreach (preg_split('/\r?\n/', trim($text)) as $line) {
        $l = trim($line);
        if ($l === '') { $flushPara(); $flushList(); continue; }
        if (preg_match('/^#{1,6}\s+(.*)$/', $l, $m)) { $flushPara(); $flushList(); $out .= '<p><strong>' . $inline($m[1]) . '</strong></p>'; continue; }
        if (preg_match('/^\d+[.)]\s+(.*)$/', $l, $m)) { $flushPara(); if (!$list || $list['t'] !== 'ol') { $flushList(); $list = ['t' => 'ol', 'items' => []]; } $list['items'][] = '<li>' . $inline($m[1]) . '</li>'; continue; }
        if (preg_match('/^[-*\x{2022}]\s+(.*)$/u', $l, $m)) { $flushPara(); if (!$list || $list['t'] !== 'ul') { $flushList(); $list = ['t' => 'ul', 'items' => []]; } $list['items'][] = '<li>' . $inline($m[1]) . '</li>'; continue; }
        $flushList(); $para[] = $l;
    }
    $flushPara(); $flushList();
    return $out;
}

function assistant_history(string $userId): array
{
    $rows = db()->all('SELECT role, body, created_at FROM assistant_messages WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . AI_HISTORY, [$userId]);
    return array_reverse($rows);
}

function assistant_left_today(string $userId): int
{
    $used = (int) db()->val("SELECT COUNT(*) FROM assistant_messages WHERE user_id = ? AND role = 'user' AND created_at > ?", [$userId, db_plus('-1 day')]);
    $limit = (int) setting('ai_daily_limit', '0'); // 0 = no limit
    return $limit <= 0 ? PHP_INT_MAX : max(0, $limit - $used);
}

function assistant_ask(array $u, string $question): array
{
    $question = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', str_replace("\r\n", "\n", $question)) ?? '');
    if ($question === '') return ['ok' => false, 'error' => 'Type your question.'];
    if (mb_strlen($question) > AI_MAX_QUESTION) return ['ok' => false, 'error' => 'Keep the question under ' . AI_MAX_QUESTION . ' characters.'];
    if (assistant_left_today($u['id']) <= 0) return ['ok' => false, 'error' => 'You have reached today\'s limit of ' . (int) setting('ai_daily_limit', '0') . ' questions. Write to the team from Messages, or try again tomorrow.'];
    $hist = assistant_history($u['id']);
    $msgs = [];
    foreach ($hist as $h) $msgs[] = ['role' => $h['role'] === 'user' ? 'user' : 'assistant', 'content' => $h['body']];
    $msgs[] = ['role' => 'user', 'content' => $question];
    // The API wants the conversation to start with the person and alternate.
    while ($msgs && $msgs[0]['role'] !== 'user') array_shift($msgs);
    $clean = [];
    foreach ($msgs as $m) { if ($clean && $clean[count($clean) - 1]['role'] === $m['role']) $clean[count($clean) - 1]['content'] .= "\n" . $m['content']; else $clean[] = $m; }
    try {
        $reply = assistant_ready() ? assistant_call(assistant_system_prompt($u), $clean) : null;
    } catch (Throwable $e) {
        log_error('Assistant error: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        set_setting('ai_last_error', gmdate('Y-m-d H:i') . ' UTC, ' . get_class($e) . ': ' . $e->getMessage());
        $reply = null;
    }
    $ai = $reply !== null;
    if (!$ai) {
        $reply = assistant_fallback($question);
        // Admins are told why the AI did not answer, so it can be fixed.
        if (!empty($u['isAdmin']) && assistant_ready() && (string) setting('ai_last_error', '') !== '') $reply .= "\n\n(Admin note: the AI services did not answer. " . str_limit((string) setting('ai_last_error', ''), 420) . ')';
    }
    $now = now_db();
    db()->insert('assistant_messages', ['id' => uuid(), 'user_id' => $u['id'], 'role' => 'user', 'body' => $question, 'created_at' => $now]);
    db()->insert('assistant_messages', ['id' => uuid(), 'user_id' => $u['id'], 'role' => 'assistant', 'body' => str_limit($reply, 4000), 'created_at' => db_plus('+0 seconds')]);
    if (random_int(1, 40) === 1) db()->exec('DELETE FROM assistant_messages WHERE created_at < ?', [db_plus('-30 days')]);
    return ['ok' => true, 'reply' => $reply, 'ai' => $ai];
}
