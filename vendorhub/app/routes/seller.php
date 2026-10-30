<?php
declare(strict_types=1);

const SELLER_TABS = ['overview' => 'Overview', 'products' => 'Products', 'inventory' => 'Inventory', 'sales' => 'Sales', 'promotions' => 'Promotions', 'returns' => 'Returns', 'payouts' => 'Payouts', 'store' => 'Store'];

route('GET', '/seller', function () {
    $u = require_seller();
    $tab = array_key_exists(input('tab'), SELLER_TABS) ? input('tab') : 'overview';
    $vars = ['title' => SELLER_TABS[$tab], 'tab' => $tab, 'user' => $u, 'live_refresh' => in_array($tab, ['overview', 'sales', 'payouts', 'products', 'inventory', 'returns'], true)];
    if (in_array($tab, ['overview', 'products', 'inventory'], true)) $vars['products'] = seller_products($u['id']);
    if ($tab === 'overview') { $vars['level'] = level_refresh($u['id']); $vars['lm'] = jdec((string) $vars['level']['metrics'], []); $vars['rating'] = seller_rating_summary($u['id']); $vars['chart'] = seller_daily_earnings($u['id']); $vars['to_process'] = seller_to_process($u['id']); }
    if (in_array($tab, ['overview', 'sales', 'payouts'], true)) { $vars['balances'] = wallet_balances($u['id']); $vars['month'] = sales_last_30d($u['id']); }
    if ($tab === 'sales') { $vars['sales'] = seller_sales($u['id']); $vars['history'] = item_history(array_column($vars['sales'], 'id')); }
    if ($tab === 'payouts') { $vars['accounts'] = bank_accounts($u['id']); $vars['withdrawals'] = seller_withdrawals($u['id']); }
    if ($tab === 'returns') $vars['cases'] = seller_returns($u['id']);
    if ($tab === 'promotions') { $vars['coupons'] = coupons_for_seller($u['id']); $vars['all_products'] = array_values(array_filter(seller_products($u['id']), fn ($p) => $p['status'] !== 'draft' || $p['live'])); $vars['edit'] = null; foreach ($vars['coupons'] as $c) if ($c['id'] === input('edit')) $vars['edit'] = $c; $vars['err'] = null; }
    if ($tab === 'store') $vars['store'] = store_profile($u['id']);
    render('seller', $vars);
});

route('POST', '/returns/respond', function () {
    $u = require_seller();
    $err = seller_respond_return($u['id'], input('id'), input('action'), input_raw('response'));
    redirect('/seller?tab=returns', $err ?? 'Your reply was sent.', $err ? 'error' : 'ok');
});

// ---------------------------------------------------------------- exports

route('GET', '/seller/export', function () {
    $u = require_seller();
    $kind = input('kind');
    [$from, $to] = range_from_input(input('from'), input('to'));
    if ($kind === 'sales') [$h, $rows] = export_seller_sales($u['id'], $from, $to);
    elseif ($kind === 'products') [$h, $rows] = export_seller_products($u['id']);
    elseif ($kind === 'payouts') [$h, $rows] = export_seller_payouts($u['id']);
    else abort(404);
    csv_out('vendor-hub-' . $kind . '-' . gmdate('Y-m-d') . '.csv', $h, $rows);
});

// ---------------------------------------------------------------- fulfilment

route('POST', '/orders/fulfil', function () {
    $u = require_seller();
    $to = input('to');
    $err = seller_set_fulfilment($u['id'], input('id'), $to);
    redirect('/seller?tab=sales', $err ?? ($to === 'processing' ? 'Order is being processed.' : 'Marked ready for shipping. The marketplace has been told.'), $err ? 'error' : 'ok');
});

// ---------------------------------------------------------------- payouts

route('POST', '/payouts/bank', function () {
    $u = require_seller();
    if (input('action') === 'remove') {
        remove_bank_account($u['id'], input('id'));
        redirect('/seller?tab=payouts', 'Account removed.');
    }
    $err = add_bank_account($u['id'], input('bank_name'), preg_replace('/\D/', '', input('account_number')) ?? '', input('account_name'));
    redirect('/seller?tab=payouts', $err ?? 'Bank account saved.', $err ? 'error' : 'ok');
});

route('POST', '/payouts/request', function () {
    $u = require_seller();
    $amount = str_replace(',', '', input('amount'));
    if (!is_numeric($amount) || (float) $amount <= 0) redirect('/seller?tab=payouts', 'Enter an amount.', 'error');
    $err = request_withdrawal($u['id'], input('account_id'), input('currency', 'NGN'), (float) $amount);
    redirect('/seller?tab=payouts', $err ?? 'Withdrawal requested. We will pay it to your bank account.', $err ? 'error' : 'ok');
});

route('POST', '/payouts/cancel', function () {
    $u = require_seller();
    $err = cancel_withdrawal($u['id'], input('id'));
    redirect('/seller?tab=payouts', $err ?? 'Withdrawal cancelled and returned to your wallet.', $err ? 'error' : 'ok');
});

// ---------------------------------------------------------------- store profile

route('POST', '/store/save', function () {
    $u = require_seller();
    $err = save_store_profile($u['id'], [
        'tagline' => input('tagline'), 'description' => input_raw('description'), 'phone' => input('phone'), 'support_email' => input('support_email'),
        'return_policy' => input_raw('return_policy'), 'shipping_policy' => input_raw('shipping_policy'), 'vacation' => !empty($_POST['vacation']), 'vacation_message' => input('vacation_message'),
    ]);
    redirect('/seller?tab=store', $err ?? 'Store saved.', $err ? 'error' : 'ok');
});

route('POST', '/store/image', function () {
    $u = require_seller();
    $kind = input('kind');
    $err = input('action') === 'remove' ? set_store_image($u['id'], $kind, null) : set_store_image($u['id'], $kind, $_FILES['file'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
    redirect('/seller?tab=store', $err ?? (input('action') === 'remove' ? 'Image removed.' : 'Image saved.'), $err ? 'error' : 'ok');
});

route('POST', '/promotions/save', function () {
    $u = require_seller();
    $in = $_POST + ['product_ids' => []];
    $r = coupon_save($u['id'], $in);
    if (!$r['ok']) {
        $coupons = coupons_for_seller($u['id']);
        $edit = ['id' => (string) ($_POST['id'] ?? ''), 'code' => (string) ($_POST['code'] ?? ''), 'kind' => (string) ($_POST['kind'] ?? 'percent'), 'amount' => $_POST['amount'] ?? '', 'currency' => (string) ($_POST['currency'] ?? 'NGN'), 'scope' => (string) ($_POST['scope'] ?? 'all'),
            'product_list' => array_map('strval', (array) ($_POST['product_ids'] ?? [])), 'min_spend' => $_POST['min_spend'] ?? '', 'usage_limit' => $_POST['usage_limit'] ?? '', 'one_per_customer' => !empty($_POST['one_per_customer']) ? 1 : 0,
            'starts_at' => local_to_db((string) ($_POST['starts_at'] ?? '')) ?: null, 'ends_at' => local_to_db((string) ($_POST['ends_at'] ?? '')) ?: null, 'form_only' => true];
        render('seller', ['title' => 'Promotions', 'tab' => 'promotions', 'user' => $u, 'live_refresh' => false, 'coupons' => $coupons, 'all_products' => array_values(array_filter(seller_products($u['id']), fn ($p) => $p['status'] !== 'draft' || $p['live'])), 'edit' => $edit, 'err' => $r['error']]);
        return;
    }
    redirect('/seller?tab=promotions', 'Code saved. It appears on the marketplace within a minute or two.');
});

route('POST', '/promotions/action', function () {
    $u = require_seller();
    $id = input('id');
    $err = match (input('action')) { 'pause' => coupon_toggle($u['id'], $id, false), 'resume' => coupon_toggle($u['id'], $id, true), 'delete' => coupon_delete($u['id'], $id), default => 'Unknown action.' };
    redirect('/seller?tab=promotions', $err ?? 'Done.', $err ? 'error' : 'ok');
});
