<?php
declare(strict_types=1);

// Paystack: sends approved withdrawals to sellers' bank accounts (Transfers) and returns money to customers (Refunds).
// Every money step is started by an admin click. Paystack then reports the result to /api/paystack/webhook.
// Amounts are in kobo (NGN x 100). Only NGN is sent automatically; other currencies stay manual.

function paystack_key(): string
{
    return (string) setting('paystack_secret_key', '');
}

function paystack_on(): bool
{
    return paystack_key() !== '' && setting('paystack_enabled', '1') === '1';
}

function paystack_test_mode(): bool
{
    return str_starts_with(paystack_key(), 'sk_test_');
}

function kobo(float $amount): int
{
    return (int) round($amount * 100);
}

// One call to the Paystack API.
// Returns ['ok'=>bool, 'code'=>int, 'data'=>array, 'error'=>string, 'unknown'=>bool]. "unknown" means the call may have
// reached Paystack (timeout), so the caller must check before trying again.
function paystack_call(string $method, string $path, ?array $body = null): array
{
    if (!empty($GLOBALS['vh_paystack_stub'])) {
        $r = ($GLOBALS['vh_paystack_stub'])($method, $path, $body);
        return $r + ['ok' => $r['code'] >= 200 && $r['code'] < 300, 'data' => [], 'error' => $r['message'] ?? '', 'unknown' => false];
    }
    if (defined('VH_TESTING') || !function_exists('curl_init') || paystack_key() === '') return ['ok' => false, 'code' => 0, 'data' => [], 'error' => 'Paystack is not set up.', 'unknown' => false];
    $ch = curl_init('https://api.paystack.co' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . paystack_key(), 'Content-Type: application/json']]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) return ['ok' => false, 'code' => 0, 'data' => [], 'error' => 'Could not reach Paystack: ' . $err, 'unknown' => true];
    $j = json_decode((string) $res, true);
    $j = is_array($j) ? $j : [];
    $ok = $code >= 200 && $code < 300 && !empty($j['status']);
    return ['ok' => $ok, 'code' => $code, 'data' => is_array($j['data'] ?? null) ? $j['data'] : [], 'error' => $ok ? '' : (string) ($j['message'] ?? 'Paystack answered with status ' . $code), 'unknown' => $code >= 500];
}

// ---------------------------------------------------------------- settings and connection test

function save_paystack_settings(array $in): ?string
{
    $key = trim((string) ($in['paystack_secret_key'] ?? ''));
    if (($in['paystack_clear_key'] ?? '') === 'yes') set_setting('paystack_secret_key', '');
    elseif ($key !== '') {
        if (!preg_match('/^sk_(test|live)_[A-Za-z0-9]{20,100}$/', $key)) return 'That does not look like a Paystack secret key. It starts with sk_test_ or sk_live_.';
        set_setting('paystack_secret_key', $key);
    }
    set_setting('paystack_enabled', ($in['paystack_enabled'] ?? '') === 'yes' ? '1' : '0');
    return null;
}

function paystack_balance(): array
{
    $r = paystack_call('GET', '/balance');
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error']];
    $ngn = 0.0;
    foreach ($r['data'] as $b) if (($b['currency'] ?? '') === 'NGN') $ngn = (float) ($b['balance'] ?? 0) / 100;
    return ['ok' => true, 'ngn' => $ngn];
}

// ---------------------------------------------------------------- banks

function paystack_banks(): array
{
    $cache = jdec((string) setting('paystack_banks', ''), []);
    if ($cache && !empty($cache['at']) && strtotime($cache['at'] . ' UTC') > time() - 7 * 86400 && !empty($cache['banks'])) return $cache['banks'];
    if (!paystack_on()) return $cache['banks'] ?? [];
    $r = paystack_call('GET', '/bank?currency=NGN&perPage=200');
    if (!$r['ok'] || !$r['data']) return $cache['banks'] ?? [];
    $banks = [];
    foreach ($r['data'] as $b) if (!empty($b['code']) && !empty($b['name']) && empty($b['is_deleted'])) $banks[(string) $b['code']] = (string) $b['name'];
    set_setting('paystack_banks', jenc(['at' => now_db(), 'banks' => $banks]));
    return $banks;
}

function bank_norm(string $s): string
{
    return preg_replace('/\b(plc|bank|limited|ltd|microfinance|mfb|nigeria|of)\b|[^a-z0-9]/', '', strtolower($s)) ?? '';
}

// The bank's Paystack code for a name the seller typed or picked, or null.
function bank_code_for(string $name): ?string
{
    $banks = paystack_banks();
    $n = bank_norm($name);
    if ($n === '') return null;
    foreach ($banks as $code => $bn) if (strcasecmp($bn, trim($name)) === 0) return (string) $code;
    $hits = [];
    foreach ($banks as $code => $bn) if (bank_norm($bn) === $n) $hits[] = (string) $code;
    return count($hits) === 1 ? $hits[0] : null;
}

// Asks the bank who owns the account. Returns the name, or null when it cannot be checked.
function paystack_resolve_account(string $number, string $bankCode): ?string
{
    if (!paystack_on()) return null;
    $r = paystack_call('GET', '/bank/resolve?account_number=' . rawurlencode($number) . '&bank_code=' . rawurlencode($bankCode));
    return $r['ok'] && !empty($r['data']['account_name']) ? trim((string) $r['data']['account_name']) : null;
}

function recipient_for(array $w): array
{
    $key = hash('sha256', $w['seller_id'] . '|' . $w['bank_name'] . '|' . $w['account_number']);
    $acct = db()->one('SELECT id, bank_code, recipient_code, account_name FROM bank_accounts WHERE seller_id = ? AND account_number = ? AND bank_name = ?', [$w['seller_id'], $w['account_number'], $w['bank_name']]);
    if ($acct && $acct['recipient_code']) return ['ok' => true, 'code' => $acct['recipient_code']];
    $code = $acct['bank_code'] ?? null;
    $code = $code ?: bank_code_for($w['bank_name']);
    if (!$code) return ['ok' => false, 'error' => 'Paystack does not know the bank "' . $w['bank_name'] . '". Ask the seller to re-add the account choosing the bank from the list, or pay manually.'];
    $r = paystack_call('POST', '/transferrecipient', ['type' => 'nuban', 'name' => $w['account_name'], 'account_number' => $w['account_number'], 'bank_code' => $code, 'currency' => 'NGN']);
    if (!$r['ok'] || empty($r['data']['recipient_code'])) return ['ok' => false, 'error' => 'Paystack could not set up the recipient: ' . $r['error']];
    if ($acct) db()->exec('UPDATE bank_accounts SET bank_code = ?, recipient_code = ? WHERE id = ?', [$code, $r['data']['recipient_code'], $acct['id']]);
    return ['ok' => true, 'code' => $r['data']['recipient_code']];
}

// ---------------------------------------------------------------- withdrawals

function paystack_send_withdrawal(array $admin, string $id): ?string
{
    if (!paystack_on()) return 'Paystack is not switched on. Add the key in Settings.';
    $db = db();
    $w = $db->one('SELECT * FROM withdrawals WHERE id = ?', [$id]);
    if (!$w) return 'Request not found.';
    if ($w['currency'] !== 'NGN') return 'Paystack transfers are for NGN only. Pay this one manually.';
    $pay = round2((float) $w['amount'] - (float) $w['fee']);
    if ($pay <= 0) return 'Nothing to send after the fee.';
    $attempt = (int) $w['transfer_attempts'] + 1;
    $ref = 'vh-' . $id . '-' . $attempt;
    // Claim it. Only one click can win, so the money cannot be sent twice.
    $n = $db->exec("UPDATE withdrawals SET status = 'processing', transfer_ref = ?, transfer_attempts = ?, decided_by = ?, decided_at = ? WHERE id = ? AND status = 'requested'", [$ref, $attempt, $admin['id'], now_db(), $id]);
    if (!$n) return 'This request is already being paid or was decided.';
    $revert = function (string $why) use ($db, $id): string {
        $db->exec("UPDATE withdrawals SET status = 'requested', admin_note = ? WHERE id = ? AND status = 'processing'", [str_limit($why, 480), $id]);
        return $why;
    };
    $rec = recipient_for($w);
    if (!$rec['ok']) return $revert($rec['error']);
    $r = paystack_call('POST', '/transfer', ['source' => 'balance', 'amount' => kobo($pay), 'recipient' => $rec['code'], 'reason' => 'DDLX seller payout', 'reference' => $ref]);
    if ($r['unknown']) {
        $db->exec('UPDATE withdrawals SET admin_note = ? WHERE id = ?', ['Paystack did not answer. Press "Check status" before trying again.', $id]);
        return 'Paystack did not answer, so it is not clear if the transfer started. Press "Check status" on this request in a minute.';
    }
    if (!$r['ok']) return $revert('Paystack refused: ' . $r['error']);
    $st = (string) ($r['data']['status'] ?? '');
    if ($st === 'otp') return $revert('Paystack wants an OTP for this transfer. In your Paystack dashboard go to Settings → Preferences and turn off "Confirm transfers before sending" (or approve it there), then try again.');
    $db->exec('UPDATE withdrawals SET transfer_code = ? WHERE id = ?', [(string) ($r['data']['transfer_code'] ?? ''), $id]);
    if ($st === 'success') paystack_finish_withdrawal($ref, true, '');
    elseif (in_array($st, ['failed', 'reversed'], true)) paystack_finish_withdrawal($ref, false, 'Paystack ' . $st);
    audit($admin, 'payout', 'Sent a Paystack transfer of ' . number_format($pay, 2) . ' NGN to ' . $w['account_name']);
    return null;
}

// Paystack said the transfer succeeded or failed. Safe to repeat.
function paystack_finish_withdrawal(string $ref, bool $success, string $reason): bool
{
    $db = db();
    $seller = null;
    $done = $db->tx(function () use ($db, $ref, $success, $reason, &$seller) {
        $w = $db->one('SELECT id, seller_id, status, transfer_code FROM withdrawals WHERE transfer_ref = ?' . $db->lock(), [$ref]);
        if (!$w) return false;
        $seller = $w['seller_id'];
        if ($success) {
            if ($w['status'] !== 'processing') return false;
            $db->exec("UPDATE withdrawals SET status = 'paid', reference = ?, decided_at = ?, admin_note = NULL WHERE id = ?", [$w['transfer_code'] ?: $ref, now_db(), $w['id']]);
            return true;
        }
        if (!in_array($w['status'], ['processing', 'paid'], true)) return false;
        $db->exec("UPDATE withdrawals SET status = 'failed', admin_note = ?, decided_at = ? WHERE id = ?", [str_limit($reason !== '' ? $reason : 'The transfer failed.', 480), now_db(), $w['id']]);
        give_back_withdrawal($w['id'], 'Transfer failed: the money is back in your wallet');
        return true;
    });
    if ($done && $seller) {
        $msg = $success ? 'Your withdrawal has been sent to your bank' : 'Your withdrawal could not be sent and the money is back in your wallet';
        announce('seller', 'payout', $msg, $seller);
        notify($seller, 'payout', $msg, $success ? '' : $reason, '/seller?tab=payouts');
        if (!$success) { announce('admins', 'payout', 'A Paystack transfer failed'); foreach (array_column(db()->all("SELECT user_id AS id FROM user_roles WHERE role = 'admin'"), 'id') as $a) notify($a, 'payout', 'A Paystack transfer failed', $reason, '/admin?tab=payouts'); }
    }
    return $done;
}

// For a transfer whose answer was lost: ask Paystack what happened.
function paystack_check_withdrawal(string $id): ?string
{
    $w = db()->one('SELECT status, transfer_ref FROM withdrawals WHERE id = ?', [$id]);
    if (!$w || $w['status'] !== 'processing' || !$w['transfer_ref']) return 'Nothing to check for this request.';
    $r = paystack_call('GET', '/transfer/verify/' . rawurlencode($w['transfer_ref']));
    if ($r['code'] === 404) {
        db()->exec("UPDATE withdrawals SET status = 'requested', admin_note = ? WHERE id = ? AND status = 'processing'", ['Paystack has no record of the last attempt. You can send it again.', $id]);
        return null;
    }
    if (!$r['ok']) return 'Could not check: ' . $r['error'];
    $st = (string) ($r['data']['status'] ?? '');
    if ($st === 'success') { db()->exec('UPDATE withdrawals SET transfer_code = ? WHERE id = ? AND (transfer_code IS NULL OR transfer_code = ?)', [(string) ($r['data']['transfer_code'] ?? ''), $id, '']); paystack_finish_withdrawal($w['transfer_ref'], true, ''); }
    elseif (in_array($st, ['failed', 'reversed'], true)) paystack_finish_withdrawal($w['transfer_ref'], false, 'Paystack ' . $st . ': ' . (string) ($r['data']['reason'] ?? ''));
    return null;
}

// ---------------------------------------------------------------- customer refunds

const CR_LABEL = ['to_pay' => 'Waiting to be refunded', 'sending' => 'Sending', 'refunded' => 'Refunded', 'failed' => 'Failed', 'manual' => 'Refunded outside Vendor Hub'];

// Called when sellers' lines are refunded: remembers how much customer money should go back for that order.
function customer_refund_add(\Db $db, string $orderId, string $currency, float $amount): void
{
    if ($amount <= 0) return;
    $open = $db->one("SELECT id FROM customer_refunds WHERE order_id = ? AND status = 'to_pay'" . $db->lock(), [$orderId]);
    $now = now_db();
    if ($open) $db->exec('UPDATE customer_refunds SET amount = amount + ?, updated_at = ? WHERE id = ?', [$amount, $now, $open['id']]);
    else $db->insert('customer_refunds', ['id' => uuid(), 'order_id' => $orderId, 'currency' => $currency, 'amount' => $amount, 'status' => 'to_pay', 'note' => '', 'created_at' => $now, 'updated_at' => $now]);
}

function customer_refunds_list(string $filter = 'open'): array
{
    $where = $filter === 'done' ? "r.status IN ('refunded','manual')" : ($filter === 'all' ? '1 = 1' : "r.status IN ('to_pay','sending','failed')");
    return db()->all("SELECT r.*, o.number, o.marketplace_order_id, o.payment_ref, o.payment_method, o.customer_name FROM customer_refunds r JOIN orders o ON o.id = r.order_id WHERE $where ORDER BY r.created_at DESC LIMIT 200");
}

function customer_refund_open_count(): int
{
    return (int) db()->val("SELECT COUNT(*) FROM customer_refunds WHERE status IN ('to_pay','failed')");
}

function paystack_send_refund(array $admin, string $id, float $amount): ?string
{
    if (!paystack_on()) return 'Paystack is not switched on. Add the key in Settings.';
    $db = db();
    $r = $db->one('SELECT r.*, o.payment_ref, o.number FROM customer_refunds r JOIN orders o ON o.id = r.order_id WHERE r.id = ?', [$id]);
    if (!$r) return 'Refund not found.';
    if ($r['currency'] !== 'NGN') return 'Paystack refunds are for NGN only. Refund this one in your payment dashboard and mark it done.';
    if (trim((string) $r['payment_ref']) === '') return 'This order has no Paystack payment reference (it may have been paid another way). Refund it where it was paid, then mark it done.';
    $amount = round2($amount);
    if ($amount <= 0 || $amount > 100000000) return 'Enter the amount to refund.';
    $n = $db->exec("UPDATE customer_refunds SET status = 'sending', amount = ?, decided_by = ?, decided_at = ?, updated_at = ? WHERE id = ? AND status IN ('to_pay','failed')", [$amount, $admin['id'], now_db(), now_db(), $id]);
    if (!$n) return 'This refund is already being sent or is done.';
    $res = paystack_call('POST', '/refund', ['transaction' => $r['payment_ref'], 'amount' => kobo($amount), 'currency' => 'NGN', 'customer_note' => 'Refund for DDLX order ' . $r['number'], 'merchant_note' => 'Vendor Hub refund ' . $id]);
    if ($res['unknown']) {
        $db->exec('UPDATE customer_refunds SET note = ? WHERE id = ?', ['Paystack did not answer. Check Paystack → Refunds before trying again.', $id]);
        return 'Paystack did not answer, so it is not clear if the refund started. Check your Paystack dashboard under Refunds, then use "Mark done" or "Try again".';
    }
    if (!$res['ok']) {
        $db->exec("UPDATE customer_refunds SET status = 'failed', note = ?, updated_at = ? WHERE id = ?", [str_limit('Paystack refused: ' . $res['error'], 480), now_db(), $id]);
        return 'Paystack refused: ' . $res['error'];
    }
    $db->exec('UPDATE customer_refunds SET paystack_refund_id = ?, note = ? WHERE id = ?', [(string) ($res['data']['id'] ?? ''), '', $id]);
    if (($res['data']['status'] ?? '') === 'processed') paystack_finish_refund((string) ($res['data']['id'] ?? ''), (string) $r['payment_ref'], true, '');
    audit($admin, 'order', 'Sent a Paystack refund of ' . number_format($amount, 2) . ' NGN for order ' . $r['number'], $id);
    return null;
}

function customer_refund_mark(array $admin, string $id, string $note): ?string
{
    $n = db()->exec("UPDATE customer_refunds SET status = 'manual', note = ?, decided_by = ?, decided_at = ?, updated_at = ? WHERE id = ? AND status IN ('to_pay','failed','sending')", [str_limit(trim($note), 480), $admin['id'], now_db(), now_db(), $id]);
    if ($n) audit($admin, 'order', 'Marked a customer refund as done outside Vendor Hub', $id);
    return $n ? null : 'This refund is already done.';
}

function paystack_finish_refund(string $refundId, string $paymentRef, bool $success, string $reason): bool
{
    $db = db();
    $where = $refundId !== '' ? 'r.paystack_refund_id = ?' : 'o.payment_ref = ?';
    $row = $db->one("SELECT r.id, r.status FROM customer_refunds r JOIN orders o ON o.id = r.order_id WHERE r.status = 'sending' AND $where ORDER BY r.updated_at DESC", [$refundId !== '' ? $refundId : $paymentRef]);
    if (!$row) return false;
    if ($success) $db->exec("UPDATE customer_refunds SET status = 'refunded', note = '', updated_at = ? WHERE id = ? AND status = 'sending'", [now_db(), $row['id']]);
    else $db->exec("UPDATE customer_refunds SET status = 'failed', note = ?, updated_at = ? WHERE id = ? AND status = 'sending'", [str_limit($reason !== '' ? $reason : 'Paystack could not complete the refund.', 480), now_db(), $row['id']]);
    return true;
}

// ---------------------------------------------------------------- webhook

function paystack_signature_ok(string $raw, string $header): bool
{
    $key = paystack_key();
    return $key !== '' && $header !== '' && hash_equals(hash_hmac('sha512', $raw, $key), $header);
}

function paystack_handle_event(array $e): void
{
    $ev = (string) ($e['event'] ?? '');
    $d = is_array($e['data'] ?? null) ? $e['data'] : [];
    switch ($ev) {
        case 'transfer.success': paystack_finish_withdrawal((string) ($d['reference'] ?? ''), true, ''); break;
        case 'transfer.failed':
        case 'transfer.reversed': paystack_finish_withdrawal((string) ($d['reference'] ?? ''), false, 'Paystack ' . substr($ev, 9) . (!empty($d['reason']) ? ': ' . $d['reason'] : '')); break;
        case 'refund.processed': paystack_finish_refund((string) ($d['id'] ?? ''), (string) ($d['transaction_reference'] ?? ''), true, ''); break;
        case 'refund.failed': paystack_finish_refund((string) ($d['id'] ?? ''), (string) ($d['transaction_reference'] ?? ''), false, 'Paystack could not complete the refund.'); break;
    }
}
