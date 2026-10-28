<?php
declare(strict_types=1);

const SELLER_TABS = ['overview' => 'Overview', 'products' => 'Products', 'inventory' => 'Inventory', 'sales' => 'Sales', 'returns' => 'Returns', 'payouts' => 'Payouts', 'store' => 'Store'];

route('GET', '/seller', function () {
    $u = require_seller();
    $tab = array_key_exists(input('tab'), SELLER_TABS) ? input('tab') : 'overview';
    $vars = ['title' => SELLER_TABS[$tab], 'tab' => $tab, 'user' => $u, 'live_refresh' => in_array($tab, ['overview', 'sales', 'payouts', 'products', 'inventory', 'returns'], true)];
    if (in_array($tab, ['overview', 'products', 'inventory'], true)) $vars['products'] = seller_products($u['id']);
    if ($tab === 'overview') { $vars['chart'] = seller_daily_earnings($u['id']); $vars['to_process'] = seller_to_process($u['id']); }
    if (in_array($tab, ['overview', 'sales', 'payouts'], true)) { $vars['balances'] = wallet_balances($u['id']); $vars['month'] = sales_last_30d($u['id']); }
    if ($tab === 'sales') { $vars['sales'] = seller_sales($u['id']); $vars['history'] = item_history(array_column($vars['sales'], 'id')); }
    if ($tab === 'payouts') { $vars['accounts'] = bank_accounts($u['id']); $vars['withdrawals'] = seller_withdrawals($u['id']); }
    if ($tab === 'returns') $vars['cases'] = seller_returns($u['id']);
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
