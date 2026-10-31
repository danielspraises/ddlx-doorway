<?php
declare(strict_types=1);

function admin_tabs(array $u): array
{
    $tabs = [];
    if ($u['isAdmin']) $tabs['overview'] = 'Overview';
    if ($u['isAdmin']) $tabs += ['applications' => 'Applications', 'verifications' => 'Verifications'];
    if ($u['canReview']) $tabs['products'] = 'Products';
    if ($u['canMessages']) $tabs['messages'] = 'Messages';
    if ($u['canOrders']) { $tabs['orders'] = 'Orders'; $tabs['returns'] = 'Returns'; }
    if ($u['isAdmin']) $tabs += ['sellers' => 'Sellers', 'codes' => 'Discount codes', 'payouts' => 'Payouts', 'refunds' => 'Customer refunds', 'categories' => 'Categories', 'staff' => 'Staff', 'activity' => 'Activity', 'settings' => 'Settings'];
    return $tabs;
}

route('GET', '/admin', function () {
    $u = require_user();
    if (!$u['isAdmin'] && !$u['canReview'] && !$u['canOrders']) redirect(home_path($u));
    $tabs = admin_tabs($u);
    $tab = array_key_exists(input('tab'), $tabs) ? input('tab') : array_key_first($tabs);
    if ($tab === 'messages') redirect('/messages');
    $vars = ['title' => $tabs[$tab], 'tab' => $tab, 'tabs' => $tabs, 'user' => $u, 'sel' => input('id'), 'filter' => input('filter'), 'live_refresh' => !in_array($tab, ['categories', 'staff', 'settings', 'activity'], true)];
    switch ($tab) {
        case 'applications': $vars['items'] = application_review_list(); break;
        case 'verifications': $vars['items'] = verification_review_list(); break;
        case 'products': $vars['items'] = review_queue(); break;
        case 'overview': $vars['o'] = admin_overview(); $vars['live_refresh'] = true; break;
        case 'returns': $f = array_key_exists(input('filter'), ['open' => 1, 'done' => 1, 'all' => 1]) ? input('filter') : 'open'; $vars['items'] = admin_returns($f); $vars['filter'] = $f; break;
        case 'orders': $f = array_key_exists(input('filter'), ADMIN_ORDER_FILTERS) ? input('filter') : 'open'; $vars['items'] = admin_orders($f); $vars['filter'] = $f; $vars['history'] = []; break;
        case 'payouts': $vars['items'] = withdrawal_review_list(); break;
        case 'categories': $vars['all'] = categories_all(true); break;
        case 'staff': $vars['items'] = staff_list(); break;
        case 'refunds': $f = in_array(input('filter'), ['open', 'done', 'all'], true) ? input('filter') : 'open'; $vars['items'] = customer_refunds_list($f); $vars['filter'] = $f; break;
        case 'payouts': $vars['items'] = withdrawal_review_list(); $vars['ps'] = paystack_on(); break;
        case 'codes': $vars['items'] = coupons_admin_list(); break;
        case 'sellers': $vars['items'] = levels_admin_list(input('q')); $vars['q'] = input('q'); break;
        case 'activity': $f = array_key_exists(input('filter'), AUDIT_LABEL) ? input('filter') : ''; $vars['items'] = audit_list(200, $f); $vars['filter'] = $f; break;
        case 'settings': break;
    }
    render('admin', $vars);
});

route('GET', '/admin/export', function () {
    $u = require_user();
    $kind = input('kind');
    [$from, $to] = range_from_input(input('from'), input('to'));
    if ($kind === 'orders') { require_orders(); [$h, $rows] = export_admin_orders($from, $to); }
    elseif ($kind === 'payouts') { require_admin(); [$h, $rows] = export_admin_payouts(); }
    elseif ($kind === 'commission') { require_admin(); [$h, $rows] = export_commission($from, $to); }
    else abort(404);
    csv_out('vendor-hub-' . $kind . '-' . gmdate('Y-m-d') . '.csv', $h, $rows);
});

route('POST', '/admin/return/open', function () {
    $u = require_orders();
    $err = open_return($u, input('item_id'), input('reason'), input_raw('details'));
    if (!$err) audit($u, 'return', 'Opened a return case (' . input('reason') . ')', input('item_id'));
    redirect('/admin?tab=' . ($err ? 'orders' : 'returns'), $err ?? 'Return case opened. The seller has been told.', $err ? 'error' : 'ok');
});

route('POST', '/admin/return/resolve', function () {
    $u = require_orders();
    $d = input('decision');
    $err = resolve_return($u, input('id'), $d, input('restock') === 'yes', input_raw('note'));
    if (!$err) audit($u, 'return', $d === 'refund' ? 'Approved a refund' : 'Rejected a return', input('id'));
    redirect('/admin?tab=returns', $err ?? ($d === 'refund' ? 'Refund recorded and the seller\'s earnings reversed. Refund the customer in WooCommerce.' : 'Case rejected.'), $err ? 'error' : 'ok');
});

route('POST', '/admin/order', function () {
    $u = require_orders();
    $to = input('to');
    $err = admin_set_order_status($u, input('id'), $to, input('carrier'), input('tracking'), input('restock') !== 'no', input('note'));
    if (!$err) audit($u, 'order', 'Set order ' . (string) db()->val('SELECT number FROM orders WHERE id = ?', [input('id')]) . ' to ' . $to, input('id'));
    $done = ['shipped' => 'Marked as shipped. Sellers and the marketplace are updated.', 'delivered' => 'Marked as delivered. The sellers\' payout clock has started.', 'completed' => 'Order completed.',
        'cancelled' => 'Order cancelled and the sellers\' earnings reversed.', 'refunded' => 'Order marked as refunded and the sellers\' earnings reversed.'][$to] ?? 'Saved.';
    redirect('/admin?tab=orders&filter=' . rawurlencode(input('filter') ?: 'open'), $err ?? $done, $err ? 'error' : 'ok');
});

$adminBack = function (string $tab, ?string $err, string $done, string $extra = ''): never {
    redirect('/admin?tab=' . $tab . $extra, $err ?? $done, $err ? 'error' : 'ok');
};

route('POST', '/admin/application', function () use ($adminBack) {
    $u = require_admin();
    $err = review_application($u, input('id'), input('status'), input_raw('comment'), input('expected'));
    if (!$err) audit($u, 'application', 'Application ' . input('status') . ': ' . (string) db()->val('SELECT email FROM users WHERE id = ?', [input('id')]), input('id'));
    $adminBack('applications', $err, 'Decision saved.', $err ? '&id=' . rawurlencode(input('id')) : '');
});

route('POST', '/admin/verification', function () use ($adminBack) {
    $u = require_admin();
    $err = review_verification($u, input('id'), input('status'), input_raw('comment'), input('expected'));
    if (!$err) audit($u, 'verification', 'Verification ' . input('status') . ': ' . (string) db()->val('SELECT email FROM users WHERE id = ?', [input('id')]), input('id'));
    $adminBack('verifications', $err, 'Decision saved.', $err ? '&id=' . rawurlencode(input('id')) : '');
});

route('POST', '/admin/product', function () use ($adminBack) {
    $u = require_reviewer();
    $decision = input('decision');
    if (!in_array($decision, ['approve', 'request_changes', 'reject', 'take_down'], true)) $adminBack('products', 'Unknown decision.', '');
    $err = review_product($u, input('id'), $decision, input_raw('comment'), input('expected'));
    if (!$err) audit($u, 'product', ucfirst(str_replace('_', ' ', $decision)) . ': ' . (string) db()->val('SELECT name FROM products WHERE id = ?', [input('id')]), input('id'));
    $adminBack('products', $err, ['approve' => 'Approved and live on the marketplace.', 'request_changes' => 'Changes requested.', 'reject' => 'Product rejected.', 'take_down' => 'Taken off the marketplace.'][$decision], $err ? '&id=' . rawurlencode(input('id')) : '');
});

route('POST', '/admin/payout', function () use ($adminBack) {
    $u = require_admin();
    $err = decide_withdrawal($u, input('id'), input('decision'), input('text'));
    if (!$err) { $w = db()->one('SELECT amount, currency FROM withdrawals WHERE id = ?', [input('id')]); audit($u, 'payout', 'Withdrawal ' . input('decision') . ($w ? ': ' . money((float) $w['amount'], $w['currency']) : ''), input('id')); }
    $adminBack('payouts', $err, input('decision') === 'paid' ? 'Marked as paid.' : 'Withdrawal rejected and refunded to the wallet.');
});

route('POST', '/admin/category', function () use ($adminBack) {
    $actor = require_admin();
    $id = input('id');
    $parent = is_uuid(input('parent_id')) ? input('parent_id') : null;
    [$err, $done] = match (input('action')) {
        'create' => [category_create($parent, input('name')), 'Category added.'],
        'rename' => [category_rename($id, input('name')), 'Category renamed.'],
        'move' => [category_move($id, input('to') === 'root' ? null : (is_uuid(input('to')) ? input('to') : $id)), 'Category moved.'],
        'hide' => [category_set_active($id, false), 'Category hidden.'],
        'show' => [category_set_active($id, true), 'Category shown.'],
        'delete' => [category_delete($id), 'Category deleted.'],
        'commission' => [category_set_commission($id, input('commission')), 'Commission saved.'],
        default => ['Unknown action.', ''],
    };
    if (!$err) audit($actor, 'category', 'Category ' . input('action') . ': ' . (input('name') ?: (categories_all()[$id]['name'] ?? '')) . (input('action') === 'commission' ? ' = ' . (input('commission') === '' ? 'marketplace rate' : input('commission') . '%') : ''), $id);
    $adminBack('categories', $err, $done);
});

route('POST', '/admin/staff', function () use ($adminBack) {
    $u = require_admin();
    $perms = clean_permissions(post_array('permissions'));
    $err = match (input('action')) {
        'create' => staff_create($u, input('email'), input_raw('password'), $perms),
        'update' => staff_update(input('user_id'), !empty($_POST['active']), $perms),
        'password' => staff_set_password(input('user_id'), input_raw('password')),
        default => 'Unknown action.',
    };
    if (!$err) audit($u, 'staff', 'Staff ' . input('action') . ': ' . (string) db()->val('SELECT email FROM users WHERE id = ?', [input('user_id')] ) . (input('action') === 'create' ? input('email') : ''), input('user_id') ?: null);
    $adminBack('staff', $err, ['create' => 'Staff account created.', 'update' => 'Staff member updated.', 'password' => 'Password changed. They were signed out.'][input('action')] ?? 'Done.');
});

route('POST', '/admin/settings', function () use ($adminBack) {
    $actor = require_admin();
    if (input('action') === 'regenerate') {
        set_setting(input('which') === 'secret' ? 'marketplace_webhook_secret' : 'marketplace_api_key', bin2hex(random_bytes(24)));
        audit($actor, 'settings', 'Created a new ' . (input('which') === 'secret' ? 'webhook secret' : 'API key'));
        $adminBack('settings', null, 'New key created. Paste it into the WordPress plugin settings.');
    }
    $err = save_settings($_POST);
    if (!$err) audit($actor, 'settings', 'Saved settings (commission ' . setting('commission_percent', '') . '%, hold ' . setting('payout_hold_days', '') . ' days)');
    $adminBack('settings', $err, 'Settings saved.');
});

route('GET', '/admin/health', function () {
    require_admin();
    render('health', ['title' => 'System check', 'user' => current_user(), 'rows' => system_checks(input('skip') !== 'market')]);
});

route('POST', '/admin/backup', function () use ($adminBack) {
    $u = require_admin();
    if (!password_verify(input_raw('password'), (string) db()->val('SELECT password_hash FROM users WHERE id = ?', [$u['id']]))) $adminBack('settings', 'Password is not correct.', '');
    audit($u, 'settings', 'Downloaded a database backup');
    @set_time_limit(300);
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="ddlx-vendorhub-backup-' . gmdate('Y-m-d-His') . '.sql"');
    header('Cache-Control: no-store');
    backup_stream(function (string $chunk) { echo $chunk; flush(); });
    exit;
});

route('POST', '/admin/level', function () use ($adminBack) {
    $u = require_admin();
    if (input('action') === 'recalculate') {
        $n = 0;
        foreach (levels_admin_list() as $s) { level_refresh($s['id'], true); $n++; }
        $adminBack('sellers', null, 'Levels recalculated for ' . $n . ' seller' . ($n === 1 ? '' : 's') . '.');
    }
    $err = is_uuid(input('seller')) ? level_set_override($u, input('seller'), input('level'), input_raw('note')) : 'Seller not found.';
    $adminBack('sellers', $err, input('level') === '' ? 'Level is automatic again.' : 'Level pinned.');
});

route('POST', '/admin/level-rules', function () use ($adminBack) {
    require_admin();
    $adminBack('settings', save_level_rules((array) ($_POST['lv'] ?? [])) ?? save_level_policy($_POST), 'Seller level rules saved. They apply at the next check, or press Recalculate on the Sellers tab.');
});

route('POST', '/admin/code', function () use ($adminBack) {
    $u = require_admin();
    $err = coupon_admin_set($u, input('id'), input('action') === 'block');
    $adminBack('codes', $err, input('action') === 'block' ? 'Code switched off.' : 'Code switched on.');
});

route('POST', '/admin/payout/paystack', function () use ($adminBack) {
    $u = require_admin();
    $a = input('action');
    $err = $a === 'check' ? paystack_check_withdrawal(input('id')) : paystack_send_withdrawal($u, input('id'));
    $adminBack('payouts', $err, $a === 'check' ? 'Checked with Paystack.' : 'Sent to Paystack. The seller is told when the bank confirms it.');
});

route('POST', '/admin/refund', function () use ($adminBack) {
    $u = require_admin();
    $amount = str_replace(',', '', input('amount'));
    $err = match (input('action')) {
        'paystack' => is_numeric($amount) ? paystack_send_refund($u, input('id'), (float) $amount) : 'Enter the amount to refund.',
        'manual' => customer_refund_mark($u, input('id'), input_raw('note')),
        default => 'Unknown action.',
    };
    $adminBack('refunds', $err, input('action') === 'paystack' ? 'Refund sent to Paystack. It shows as refunded when Paystack confirms.' : 'Marked as refunded.');
});

route('POST', '/admin/email', function () use ($adminBack) {
    $u = require_admin();
    if (input('action') === 'test') {
        $to = (string) $u['email'];
        if ((string) setting('smtp_host', '') === '') $adminBack('settings', 'Add the mail server details and save first.', '');
        $from = (string) setting('mail_from', 'no-reply@' . (parse_url(base_url(), PHP_URL_HOST) ?: 'localhost'));
        $r = smtp_send($to, '=?UTF-8?B?' . base64_encode('Vendor Hub test email') . '?=', "If you can read this, email from Vendor Hub works.\n", $from);
        $adminBack('settings', $r === null ? null : 'Test failed: ' . $r, 'Test email sent to ' . $to . '. Check your inbox (and spam).');
    }
    $adminBack('settings', save_smtp_settings($_POST), 'Email settings saved.');
});
route('POST', '/admin/paystack', function () use ($adminBack) {
    require_admin();
    if (input('action') === 'test') {
        if (!paystack_on()) $adminBack('settings', 'Add your Paystack key and switch it on first.', '');
        $b = paystack_balance();
        $adminBack('settings', $b['ok'] ? null : 'Paystack said: ' . $b['error'], 'Connected to Paystack (' . (paystack_test_mode() ? 'test mode' : 'live mode') . '). Available balance: NGN ' . number_format($b['ok'] ? $b['ngn'] : 0, 2) . '.');
    }
    $adminBack('settings', save_paystack_settings($_POST), 'Paystack settings saved.');
});
