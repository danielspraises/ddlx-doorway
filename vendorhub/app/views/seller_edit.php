<?php
/** @var string $sid @var string $account @var array $app @var ?array $link */
$lists = ['state' => NIGERIAN_STATES, 'country' => APP_COUNTRIES, 'role' => APP_ROLES, 'id_type' => ID_TYPES, 'type' => BUSINESS_TYPES, 'category' => BUSINESS_CATEGORIES, 'brand_category' => BUSINESS_CATEGORIES];
$store = (string) ($app['brand']['store_name'] ?? '') ?: $account;
?>
<p class="eyebrow">Admin</p>
<h1>Edit seller <?= help('Change anything the seller entered in their application: names, addresses, registration details and links. The seller sees the new details straight away. Documents are not changed here.') ?></h1>
<p class="muted"><strong><?= e($store) ?></strong><?= verified_tick($sid) ?> · <?= e($account) ?></p>
<p><a class="btn btn-ghost btn-sm" href="/admin?tab=sellers">&larr; Back to sellers</a></p>
<?php foreach ($lists as $n => $opts): ?><datalist id="dl-<?= e($n) ?>"><?php foreach ($opts as $o): ?><option value="<?= e($o) ?>"></option><?php endforeach; ?></datalist><?php endforeach; ?>
<form method="post" action="/admin/seller" data-keep><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($sid) ?>">
  <div class="card" style="margin-bottom:16px"><h3 style="margin-top:0">Account</h3>
    <?= f_text('account_email', 'Sign-in email', $account, null, ['type' => 'email', 'required' => 1, 'hint' => 'The email the seller signs in with and receives notifications on.']) ?></div>
  <?php foreach (SELLER_EDIT_FIELDS as $step => [$title, $fields]): $key = APP_STEPS[$step][0]; ?>
    <div class="card" style="margin-bottom:16px"><h3 style="margin-top:0"><?= e($title) ?></h3>
      <div class="grid g2">
      <?php foreach ($fields as $k => $label): $val = (string) ($app[$key][$k] ?? ''); $name = 's' . $step . '_' . $k;
          if (in_array($k, ['description', 'brand_description'], true)) { echo '<div style="grid-column:1/-1">' . f_area($name, $label, $val, null, ['rows' => 3, 'maxlength' => 2000]) . '</div>'; continue; }
          echo f_text($name, $label, $val, null, isset($lists[$k]) ? ['list' => 'dl-' . $k] : []);
      endforeach; ?>
      </div></div>
  <?php endforeach; ?>
  <p class="row"><button class="btn" type="submit">Save details</button><a class="btn btn-ghost" href="/admin?tab=sellers">Cancel</a></p>
</form>
