<?php
declare(strict_types=1);

// "Ask DDLX": a help assistant for sellers and the team.
// With an Anthropic API key (Admin → Settings) it answers with an AI model, grounded in the facts below and in the
// person's own account summary. Without a key, or when the AI is unreachable, it falls back to matching these same
// facts, so the page always gives a useful answer. It only answers questions; it never changes anything.

const AI_DAILY_LIMIT = 40;
const AI_HISTORY = 12;
const AI_MAX_QUESTION = 1500;

function assistant_facts(): array
{
    $c = rtrim(rtrim(number_format((float) setting('commission_percent', '10'), 2, '.', ''), '0'), '.');
    $hold = (int) setting('payout_hold_days', '7');
    $min = number_format((float) setting('min_withdrawal', '1000'));
    $fee = number_format((float) setting('withdrawal_fee', '0'));
    return [
        ['keys' => ['start', 'begin', 'apply', 'application', 'register', 'become', 'join', 'steps', 'how does it work'], 'title' => 'Getting started', 'text' => "To sell on DDLX: 1) create an account and fill in the five-step seller application (business, brand, products, bank/payout, declarations); 2) wait for the team to approve it; 3) complete verification (identity and business documents); 4) once verified, open Seller Center and add products. Each step shows its status and the team's feedback if corrections are needed. Drafts save as you go."],
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
        . "Rules: answer only from the facts below and the account summary; if you do not know, say so and suggest writing to the team from Messages. Never invent policies, fees, dates or order details. You cannot change anything in the account, approve anything, or see other people's data. Never ask for passwords, bank details or verification documents. Be brief, friendly and practical: short paragraphs or a few steps. Reply in the language the person writes in. Do not use markdown headings.\n\n"
        . "FACTS ABOUT VENDOR HUB:\n" . $facts . "\nACCOUNT SUMMARY FOR THIS PERSON:\n" . assistant_context($u) . "\n";
}

function assistant_ready(): bool
{
    return (string) setting('ai_api_key', '') !== '' && setting('ai_enabled', '1') === '1';
}

// Calls the Anthropic Messages API. Returns the reply text or null if it could not be reached.
function assistant_call(string $system, array $messages): ?string
{
    if (!empty($GLOBALS['vh_ai_stub'])) return ($GLOBALS['vh_ai_stub'])($system, $messages);
    if (defined('VH_TESTING') || !function_exists('curl_init')) return null;
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . (string) setting('ai_api_key', ''), 'anthropic-version: 2023-06-01'],
        CURLOPT_POSTFIELDS => json_encode(['model' => (string) setting('ai_model', 'claude-sonnet-5-5'), 'max_tokens' => 700, 'system' => $system, 'messages' => $messages])]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false || $code !== 200) { log_error('Assistant call failed: HTTP ' . $code); return null; }
    $d = json_decode((string) $res, true);
    $text = '';
    foreach ($d['content'] ?? [] as $b) if (($b['type'] ?? '') === 'text') $text .= $b['text'];
    return trim($text) !== '' ? trim($text) : null;
}

function assistant_history(string $userId): array
{
    $rows = db()->all('SELECT role, body, created_at FROM assistant_messages WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . AI_HISTORY, [$userId]);
    return array_reverse($rows);
}

function assistant_left_today(string $userId): int
{
    $used = (int) db()->val("SELECT COUNT(*) FROM assistant_messages WHERE user_id = ? AND role = 'user' AND created_at > ?", [$userId, db_plus('-1 day')]);
    return max(0, AI_DAILY_LIMIT - $used);
}

function assistant_ask(array $u, string $question): array
{
    $question = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', str_replace("\r\n", "\n", $question)) ?? '');
    if ($question === '') return ['ok' => false, 'error' => 'Type your question.'];
    if (mb_strlen($question) > AI_MAX_QUESTION) return ['ok' => false, 'error' => 'Keep the question under ' . AI_MAX_QUESTION . ' characters.'];
    if (assistant_left_today($u['id']) <= 0) return ['ok' => false, 'error' => 'You have reached today\'s limit of ' . AI_DAILY_LIMIT . ' questions. Write to the team from Messages, or try again tomorrow.'];
    $hist = assistant_history($u['id']);
    $msgs = [];
    foreach ($hist as $h) $msgs[] = ['role' => $h['role'] === 'user' ? 'user' : 'assistant', 'content' => $h['body']];
    $msgs[] = ['role' => 'user', 'content' => $question];
    // The API wants the conversation to start with the person and alternate.
    while ($msgs && $msgs[0]['role'] !== 'user') array_shift($msgs);
    $clean = [];
    foreach ($msgs as $m) { if ($clean && $clean[count($clean) - 1]['role'] === $m['role']) $clean[count($clean) - 1]['content'] .= "\n" . $m['content']; else $clean[] = $m; }
    $reply = assistant_ready() ? assistant_call(assistant_system_prompt($u), $clean) : null;
    $ai = $reply !== null;
    if (!$ai) $reply = assistant_fallback($question);
    $now = now_db();
    db()->insert('assistant_messages', ['id' => uuid(), 'user_id' => $u['id'], 'role' => 'user', 'body' => $question, 'created_at' => $now]);
    db()->insert('assistant_messages', ['id' => uuid(), 'user_id' => $u['id'], 'role' => 'assistant', 'body' => str_limit($reply, 4000), 'created_at' => db_plus('+0 seconds')]);
    if (random_int(1, 40) === 1) db()->exec('DELETE FROM assistant_messages WHERE created_at < ?', [db_plus('-30 days')]);
    return ['ok' => true, 'reply' => $reply, 'ai' => $ai];
}
