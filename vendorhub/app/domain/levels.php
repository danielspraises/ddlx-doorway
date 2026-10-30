<?php
declare(strict_types=1);

// Seller levels: Starter, Bronze, Silver, Gold, Blue. A level is earned from the last 90 days of real activity
// (delivered orders, returns, how fast orders are packed, customer ratings) and decides the badge, the place in the
// marketplace grids, and any perks the admin switches on. The admin can pin a level for one seller.

const LEVELS = ['starter', 'bronze', 'silver', 'gold', 'blue'];
const LEVEL_LABEL = ['starter' => 'Starter', 'bronze' => 'Bronze', 'silver' => 'Silver', 'gold' => 'Gold', 'blue' => 'Blue'];
const LEVEL_NOTE = [
    'starter' => 'New to DDLX. Complete your first orders to earn Bronze.',
    'bronze' => 'Trusted seller with a track record of delivered orders.',
    'silver' => 'Reliable seller: low returns and quick packing.',
    'gold' => 'Top seller with strong ratings from customers.',
    'blue' => 'DDLX elite: the best-rated, fastest and most dependable sellers.',
];
const LEVEL_WINDOW_DAYS = 90;
const DISPATCH_HOURS = 48;

// Orders delivered, highest return rate allowed (%), smallest share packed within 48h (%), lowest average rating,
// ratings needed before the rating counts (a rating of 0 means "not required"), commission points off, payout hold days off.
function level_defaults(): array
{
    return [
        'bronze' => ['orders' => 3, 'returns' => 15, 'dispatch' => 0, 'rating' => 0, 'min_ratings' => 0, 'commission_off' => 0, 'hold_off' => 0],
        'silver' => ['orders' => 15, 'returns' => 10, 'dispatch' => 70, 'rating' => 0, 'min_ratings' => 0, 'commission_off' => 0, 'hold_off' => 0],
        'gold' => ['orders' => 50, 'returns' => 7, 'dispatch' => 85, 'rating' => 4.0, 'min_ratings' => 5, 'commission_off' => 0, 'hold_off' => 0],
        'blue' => ['orders' => 150, 'returns' => 5, 'dispatch' => 92, 'rating' => 4.5, 'min_ratings' => 20, 'commission_off' => 0, 'hold_off' => 0],
    ];
}

function level_rules(): array
{
    $saved = jdec((string) setting('level_rules', ''), []);
    $out = level_defaults();
    foreach ($out as $lv => $row) foreach ($row as $k => $v) if (isset($saved[$lv][$k]) && is_numeric($saved[$lv][$k])) $out[$lv][$k] = $saved[$lv][$k] + 0;
    return $out;
}

function save_level_rules(array $posted): ?string
{
    $rules = level_defaults();
    $max = ['orders' => 100000, 'returns' => 100, 'dispatch' => 100, 'rating' => 5, 'min_ratings' => 100000, 'commission_off' => 50, 'hold_off' => 90];
    foreach ($rules as $lv => $row) foreach ($row as $k => $v) {
        $x = $posted[$lv][$k] ?? null;
        if ($x === null || $x === '') { $rules[$lv][$k] = $v; continue; }
        if (!is_numeric($x) || (float) $x < 0 || (float) $x > $max[$k]) return 'Check the "' . str_replace('_', ' ', $k) . '" number for ' . LEVEL_LABEL[$lv] . '.';
        $rules[$lv][$k] = $k === 'rating' || $k === 'commission_off' ? round((float) $x, 2) : (int) $x;
    }
    $prev = 0;
    foreach (['bronze', 'silver', 'gold', 'blue'] as $lv) {
        if ($rules[$lv]['orders'] < $prev) return 'Each level should need at least as many delivered orders as the one below it.';
        $prev = $rules[$lv]['orders'];
    }
    set_setting('level_rules', jenc($rules));
    return null;
}

function level_rank(string $level): int
{
    $i = array_search($level, LEVELS, true);
    return $i === false ? 0 : (int) $i;
}

// What the seller has done in the last 90 days.
function level_metrics(string $sellerId): array
{
    $db = db();
    $since = db_plus('-' . LEVEL_WINDOW_DAYS . ' days');
    $delivered = (int) $db->val("SELECT COUNT(DISTINCT order_id) FROM order_items WHERE seller_id = ? AND status = 'paid' AND fulfilment IN ('delivered', 'completed') AND created_at > ?", [$sellerId, $since]);
    $items = (int) $db->val("SELECT COUNT(*) FROM order_items WHERE seller_id = ? AND fulfilment <> 'cancelled' AND created_at > ?", [$sellerId, $since]);
    $returns = (int) $db->val("SELECT COUNT(*) FROM return_cases WHERE seller_id = ? AND status <> 'rejected' AND created_at > ?", [$sellerId, $since]);
    $returnPct = $items > 0 ? round($returns / $items * 100, 1) : 0.0;
    // How quickly orders went from paid to ready for shipping.
    $rows = $db->all("SELECT l.order_item_id AS id, l.status, l.created_at FROM order_item_log l JOIN order_items i ON i.id = l.order_item_id WHERE i.seller_id = ? AND i.created_at > ? AND l.status IN ('placed', 'ready_for_shipping') ORDER BY l.created_at LIMIT 20000", [$sellerId, $since]);
    $placed = [];
    $ready = [];
    foreach ($rows as $r) { if ($r['status'] === 'placed') $placed[$r['id']] ??= $r['created_at']; else $ready[$r['id']] ??= $r['created_at']; }
    $fast = 0;
    $packed = 0;
    foreach ($ready as $id => $t) {
        if (!isset($placed[$id])) continue;
        $packed++;
        if (strtotime($t . ' UTC') - strtotime($placed[$id] . ' UTC') <= DISPATCH_HOURS * 3600) $fast++;
    }
    $rated = $db->one('SELECT SUM(rating_avg * rating_count) AS pts, SUM(rating_count) AS n FROM products WHERE seller_id = ? AND rating_count > 0', [$sellerId]) ?: [];
    $ratings = (int) ($rated['n'] ?? 0);
    return ['delivered' => $delivered, 'items' => $items, 'returns' => $returns, 'return_pct' => $returnPct, 'packed' => $packed, 'dispatch_pct' => $packed > 0 ? round($fast / $packed * 100, 1) : null,
        'ratings' => $ratings, 'rating' => $ratings > 0 ? round((float) $rated['pts'] / $ratings, 2) : null];
}

// The checks for one level, each with what the seller has and what is needed.
function level_checks(string $level, array $m, ?array $rules = null): array
{
    $r = ($rules ?? level_rules())[$level] ?? null;
    if (!$r) return [];
    $checks = [['label' => 'Delivered orders (90 days)', 'have' => (string) $m['delivered'], 'need' => (string) $r['orders'], 'ok' => $m['delivered'] >= $r['orders']]];
    $checks[] = ['label' => 'Returns', 'have' => $m['return_pct'] . '%', 'need' => 'at most ' . $r['returns'] . '%', 'ok' => $m['return_pct'] <= $r['returns']];
    if ($r['dispatch'] > 0) $checks[] = ['label' => 'Packed within ' . DISPATCH_HOURS . 'h', 'have' => $m['dispatch_pct'] === null ? 'no data yet' : $m['dispatch_pct'] . '%', 'need' => 'at least ' . $r['dispatch'] . '%', 'ok' => $m['dispatch_pct'] !== null && $m['dispatch_pct'] >= $r['dispatch']];
    if ($r['rating'] > 0) $checks[] = ['label' => 'Customer rating', 'have' => $m['rating'] === null ? 'no ratings yet' : $m['rating'] . ' (' . $m['ratings'] . ')', 'need' => $r['rating'] . '+ from ' . $r['min_ratings'] . '+ ratings', 'ok' => $m['rating'] !== null && $m['rating'] >= $r['rating'] && $m['ratings'] >= $r['min_ratings']];
    return $checks;
}

function level_earned(array $m): string
{
    $rules = level_rules();
    $got = 'starter';
    foreach (['bronze', 'silver', 'gold', 'blue'] as $lv) {
        if (array_filter(level_checks($lv, $m, $rules), fn ($c) => !$c['ok'])) break;
        $got = $lv;
    }
    return $got;
}

function level_row(string $sellerId): ?array
{
    return db()->one('SELECT * FROM seller_levels WHERE seller_id = ?', [$sellerId]);
}

// The level in force: the admin's pin if there is one, otherwise what was earned at the last check.
function seller_level(string $sellerId): string
{
    $r = level_row($sellerId);
    return $r ? ($r['override_level'] ?: $r['auto_level']) : 'starter';
}

function level_perks(string $level): array
{
    $r = level_rules()[$level] ?? null;
    return ['commission_off' => (float) ($r['commission_off'] ?? 0), 'hold_off' => (int) ($r['hold_off'] ?? 0)];
}

// What happens when a seller no longer meets the requirements of the level they hold:
//   auto  - the level drops at the next check
//   grace - they keep it for a number of days (and are warned), then it drops
//   never - levels only go up; the team lowers them by hand
function level_policy(): array
{
    $mode = (string) setting('level_downgrade', 'auto');
    return ['mode' => in_array($mode, ['auto', 'grace', 'never'], true) ? $mode : 'auto', 'grace_days' => max(1, min(180, (int) setting('level_grace_days', '14'))), 'one_step' => setting('level_one_step', '0') === '1'];
}

function save_level_policy(array $in): ?string
{
    $mode = (string) ($in['level_downgrade'] ?? 'auto');
    if (!in_array($mode, ['auto', 'grace', 'never'], true)) return 'Choose how downgrades work.';
    $days = $in['level_grace_days'] ?? '14';
    if (!is_numeric($days) || (int) $days < 1 || (int) $days > 180) return 'Grace period must be 1 to 180 days.';
    set_setting('level_downgrade', $mode);
    set_setting('level_grace_days', (string) (int) $days);
    set_setting('level_one_step', ($in['level_one_step'] ?? '') === 'yes' ? '1' : '0');
    return null;
}

// The level to hold now, given what is earned, what is held, and when they first fell short.
// Returns [level, below_since or null, warn (bool: newly at risk)].
function level_decide(string $held, string $earned, ?string $belowSince, array $policy, ?string $now = null): array
{
    $now ??= now_db();
    if (level_rank($earned) >= level_rank($held)) return [$earned, null, false];
    if ($policy['mode'] === 'never') return [$held, null, false];
    $drop = fn () => $policy['one_step'] ? LEVELS[level_rank($held) - 1] : $earned;
    if ($policy['mode'] === 'auto') {
        $to = $drop();
        return [$to, level_rank($to) > level_rank($earned) ? $now : null, false];
    }
    if ($belowSince === null) return [$held, $now, true];
    if (strtotime($now . ' UTC') >= strtotime(db_plus('+' . $policy['grace_days'] . ' days', $belowSince) . ' UTC')) {
        $to = $drop();
        return [$to, level_rank($to) > level_rank($earned) ? $now : null, false];
    }
    return [$held, $belowSince, false];
}

// Works out the level and saves it. Tells the seller and the marketplace when it changes.
function level_refresh(string $sellerId, bool $force = false): array
{
    $row = level_row($sellerId);
    if (!$force && $row && strtotime($row['computed_at'] . ' UTC') > time() - 6 * 3600) return $row;
    $m = level_metrics($sellerId);
    $earned = level_earned($m);
    $now = now_db();
    $before = $row ? ($row['override_level'] ?: $row['auto_level']) : 'starter';
    [$auto, $below, $warn] = level_decide($row ? $row['auto_level'] : 'starter', $earned, $row['below_since'] ?? null, level_policy(), $now);
    if ($row) db()->exec('UPDATE seller_levels SET auto_level = ?, below_since = ?, metrics = ?, computed_at = ? WHERE seller_id = ?', [$auto, $below, jenc($m), $now, $sellerId]);
    else db()->insert('seller_levels', ['seller_id' => $sellerId, 'auto_level' => $auto, 'override_level' => null, 'override_note' => '', 'below_since' => $below, 'metrics' => jenc($m), 'computed_at' => $now, 'changed_at' => $now]);
    $row = level_row($sellerId);
    $after = $row['override_level'] ?: $row['auto_level'];
    if ($after !== $before) level_changed($sellerId, $before, $after);
    elseif ($warn && !$row['override_level']) {
        $until = human_date(db_plus('+' . level_policy()['grace_days'] . ' days', $now), false);
        notify($sellerId, 'level', 'Your ' . LEVEL_LABEL[$after] . ' level is at risk', 'You no longer meet the ' . LEVEL_LABEL[$after] . ' requirements. Meet them again by ' . $until . ' to keep your badge. See your overview for what is needed.', '/seller?tab=overview');
    }
    return $row;
}

// Once a day, on whatever request comes first, every verified seller is checked so levels fall (or rise) even if nobody opens the page.
function level_sweep_if_due(): void
{
    try {
        $last = (string) setting('level_last_sweep', '');
        if ($last !== '' && strtotime($last . ' UTC') > time() - 86400) return;
        set_setting('level_last_sweep', now_db());
        foreach (db()->all("SELECT user_id FROM seller_verifications WHERE status = 'approved'") as $r) level_refresh($r['user_id'], true);
    } catch (Throwable $e) {
        log_error('level sweep: ' . $e->getMessage());
    }
}

function level_changed(string $sellerId, string $from, string $to): void
{
    $now = now_db();
    db()->exec('UPDATE seller_levels SET changed_at = ? WHERE seller_id = ?', [$now, $sellerId]);
    // Products carry the badge in the marketplace feed, so touch them and the next sync picks the change up.
    db()->exec("UPDATE products SET updated_at = ? WHERE seller_id = ? AND published_snapshot IS NOT NULL", [$now, $sellerId]);
    if (level_rank($to) > level_rank($from)) {
        notify($sellerId, 'level', 'You are now a ' . LEVEL_LABEL[$to] . ' seller', LEVEL_NOTE[$to], '/seller?tab=overview');
    } else {
        notify($sellerId, 'level', 'Your seller level is now ' . LEVEL_LABEL[$to], 'Levels follow the last ' . LEVEL_WINDOW_DAYS . ' days of orders, returns, packing speed and ratings. See what is needed to move up on your overview.', '/seller?tab=overview');
    }
}

function level_set_override(array $admin, string $sellerId, string $level, string $note): ?string
{
    if ($level !== '' && !in_array($level, LEVELS, true)) return 'Unknown level.';
    if (!db()->val('SELECT 1 FROM users WHERE id = ?', [$sellerId])) return 'Seller not found.';
    level_refresh($sellerId, true);
    $before = seller_level($sellerId);
    db()->exec('UPDATE seller_levels SET override_level = ?, override_note = ? WHERE seller_id = ?', [$level === '' ? null : $level, str_limit(trim($note), 250), $sellerId]);
    $after = seller_level($sellerId);
    if ($after !== $before) level_changed($sellerId, $before, $after);
    audit($admin, 'staff', ($level === '' ? 'Removed the pinned level of ' : 'Pinned level ' . LEVEL_LABEL[$level] . ' for ') . (string) db()->val('SELECT email FROM users WHERE id = ?', [$sellerId]), $sellerId);
    return null;
}

// Verified sellers with their level, for the admin list and the marketplace.
function levels_admin_list(string $q = ''): array
{
    $args = [];
    $where = "v.status = 'approved'";
    if ($q !== '') { $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%'; $where .= ' AND (u.email LIKE ? OR a.brand LIKE ?)'; $args = [$like, $like]; }
    $rows = db()->all("SELECT u.id, u.email, a.brand AS brand_json, l.auto_level, l.override_level, l.override_note, l.below_since, l.metrics, l.computed_at FROM seller_verifications v JOIN users u ON u.id = v.user_id LEFT JOIN seller_applications a ON a.user_id = u.id LEFT JOIN seller_levels l ON l.seller_id = u.id WHERE $where ORDER BY u.email LIMIT 300", $args);
    foreach ($rows as &$r) {
        $r['store'] = trim((string) (jdec((string) $r['brand_json'], [])['store_name'] ?? '')) ?: $r['email'];
        $r['level'] = ($r['override_level'] ?: $r['auto_level']) ?: 'starter';
        $r['m'] = jdec((string) $r['metrics'], []);
        unset($r['brand_json'], $r['metrics']);
    }
    return $rows;
}

function level_badge(string $level): string
{
    return '<span class="lvl lvl-' . e($level) . '" title="' . e(LEVEL_NOTE[$level] ?? '') . '">' . icon('shield') . e(LEVEL_LABEL[$level] ?? $level) . '</span>';
}
