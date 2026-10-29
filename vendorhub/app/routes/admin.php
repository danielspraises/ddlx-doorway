<?php
declare(strict_types=1);

function admin_tabs(array $u): array
{
    $tabs = [];
    if ($u['isAdmin']) $tabs['overview'] = 'Overview';
    if ($u['isAdmin']) $tabs += ['applications' => 'Applications', 'verifications' => 'Verifications'];
    if ($u['canReview']) $tabs['products'] = 'Products';
    if ($u['canOrders']) { $tabs['orders'] = 'Orders'; $tabs['returns'] = 'Returns'; }
    if ($u['isAdmin']) $tabs += ['payouts' => 'Payouts', 'categories' => 'Categories', 'staff' => 'Staff', 'settings' => 'Settings'];
    return $tabs;
}

route('GET', '/admin', function () {
    $u = require_user();
    if (!$u['isAdmin'] && !$u['canReview'] && !$u['canOrders']) redirect(home_path($u));
    $tabs = admin_tabs($u);
    $tab = array_key_exists(input('tab'), $tabs) ? input('tab') : array_key_first($tabs);
    $vars = ['title' => $tabs[$tab], 'tab' => $tab, 'tabs' => $tabs, 'user' => $u, 'sel' => input('id'), 'filter' => input('filter'), 'live_refresh' => !in_array($tab, ['categories', 'staff', 'settings'], true)];
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
    else abort(404);
    csv_out('vendor-hub-' . $kind . '-' . gmdate('Y-m-d') . '.csv', $h, $rows);
});

route('POST', '/admin/return/open', function () {
    $u = require_orders();
    $err = open_return($u, input('item_id'), input('reason'), input_raw('details'));
    redirect('/admin?tab=' . ($err ? 'orders' : 'returns'), $err ?? 'Return case opened. The seller has been told.', $err ? 'error' : 'ok');
});

route('POST', '/admin/return/resolve', function () {
    $u = require_orders();
    $d = input('decision');
    $err = resolve_return($u, input('id'), $d, input('restock') === 'yes', input_raw('note'));
    redirect('/admin?tab=returns', $err ?? ($d === 'refund' ? 'Refund recorded and the seller\'s earnings reversed. Refund the customer in WooCommerce.' : 'Case rejected.'), $err ? 'error' : 'ok');
});

route('POST', '/admin/order', function () {
    $u = require_orders();
    $to = input('to');
    $err = admin_set_order_status($u, input('id'), $to, input('carrier'), input('tracking'), input('restock') !== 'no', input('note'));
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
    $adminBack('applications', $err, 'Decision saved.', $err ? '&id=' . rawurlencode(input('id')) : '');
});

route('POST', '/admin/verification', function () use ($adminBack) {
    $u = require_admin();
    $err = review_verification($u, input('id'), input('status'), input_raw('comment'), input('expected'));
    $adminBack('verifications', $err, 'Decision saved.', $err ? '&id=' . rawurlencode(input('id')) : '');
});

route('POST', '/admin/product', function () use ($adminBack) {
    $u = require_reviewer();
    $decision = input('decision');
    if (!in_array($decision, ['approve', 'request_changes', 'reject', 'take_down'], true)) $adminBack('products', 'Unknown decision.', '');
    $err = review_product($u, input('id'), $decision, input_raw('comment'), input('expected'));
    $adminBack('products', $err, ['approve' => 'Approved and live on the marketplace.', 'request_changes' => 'Changes requested.', 'reject' => 'Product rejected.', 'take_down' => 'Taken off the marketplace.'][$decision], $err ? '&id=' . rawurlencode(input('id')) : '');
});

route('POST', '/admin/payout', function () use ($adminBack) {
    $u = require_admin();
    $err = decide_withdrawal($u, input('id'), input('decision'), input('text'));
    $adminBack('payouts', $err, input('decision') === 'paid' ? 'Marked as paid.' : 'Withdrawal rejected and refunded to the wallet.');
});

route('POST', '/admin/category', function () use ($adminBack) {
    require_admin();
    $id = input('id');
    $parent = is_uuid(input('parent_id')) ? input('parent_id') : null;
    [$err, $done] = match (input('action')) {
        'create' => [category_create($parent, input('name')), 'Category added.'],
        'rename' => [category_rename($id, input('name')), 'Category renamed.'],
        'move' => [category_move($id, input('to') === 'root' ? null : (is_uuid(input('to')) ? input('to') : $id)), 'Category moved.'],
        'hide' => [category_set_active($id, false), 'Category hidden.'],
        'show' => [category_set_active($id, true), 'Category shown.'],
        'delete' => [category_delete($id), 'Category deleted.'],
        default => ['Unknown action.', ''],
    };
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
    $adminBack('staff', $err, ['create' => 'Staff account created.', 'update' => 'Staff member updated.', 'password' => 'Password changed. They were signed out.'][input('action')] ?? 'Done.');
});

route('POST', '/admin/settings', function () use ($adminBack) {
    require_admin();
    if (input('action') === 'regenerate') {
        set_setting(input('which') === 'secret' ? 'marketplace_webhook_secret' : 'marketplace_api_key', bin2hex(random_bytes(24)));
        $adminBack('settings', null, 'New key created. Paste it into the WordPress plugin settings.');
    }
    $adminBack('settings', save_settings($_POST), 'Settings saved.');
});

route('GET', '/admin/health', function () {
    require_admin();
    render('health', ['title' => 'System check', 'user' => current_user(), 'rows' => system_checks(input('skip') !== 'market')]);
});
