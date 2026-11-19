<?php
/** @var array $market @var array $items @var ?array $edit */
$kinds = ['live' => 'ok', 'scheduled' => 'warn', 'paused' => 'warn', 'ended' => '', 'cancelled' => '', 'blocked' => 'bad'];
$ev = fn (string $k) => $edit[$k] ?? '';
?>
<div class="dgrid" style="margin-top:6px">
  <section class="dcard c5">
    <div class="dhead"><h3><?= $edit ? 'Edit ' . e($edit['name']) : 'New DDLX sale' ?></h3><?php if ($edit): ?><a class="dlink" href="/admin?tab=sales">New instead</a><?php endif; ?></div>
    <form method="post" action="/admin/sales/save" data-keep><?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= e($ev('id')) ?>">
      <div class="field"><label for="s-name">Name</label><input id="s-name" name="name" value="<?= e($ev('name')) ?>" maxlength="60" placeholder="Black Friday" required></div>
      <div class="grid g2">
        <div class="field"><label for="s-start">Starts</label><input id="s-start" type="date" name="starts_on" value="<?= e($ev('starts_on')) ?>" required></div>
        <div class="field"><label for="s-end">Ends</label><input id="s-end" type="date" name="ends_on" value="<?= e($ev('ends_on')) ?>" required></div>
      </div>
      <div class="field"><label for="s-min">Smallest discount (optional)</label><div class="unit"><input id="s-min" name="min_discount" value="<?= $edit && $edit['min_discount'] !== null ? e(rtrim(rtrim(number_format($edit['min_discount'], 2), '0'), '.')) : '' ?>" inputmode="decimal" placeholder="10"><span class="post">%</span></div></div>
      <div class="field"><label for="s-note">Note for sellers (optional)</label><input id="s-note" name="note" value="<?= e($ev('note')) ?>" maxlength="300" placeholder="Shoppers will see a Black Friday banner."></div>
      <button class="btn" type="submit"><?= $edit ? 'Save changes' : 'Open to sellers' ?></button>
    </form>
  </section>
  <section class="dcard c7">
    <div class="dhead"><h3>DDLX sales</h3></div>
    <?php if (!$market): ?><p class="muted small">None yet. Sellers are told when you open one and join with their own products and discounts.</p><?php else: ?>
    <ul class="slist2 flat"><?php foreach ($market as $m): ?><li>
      <div class="grow"><p class="nm"><?= e($m['name']) ?></p><p class="muted small"><?= e(sale_dates($m)) ?><?= $m['min_discount'] !== null ? ' · at least ' . e(rtrim(rtrim(number_format($m['min_discount'], 2), '0'), '.')) . '% off' : '' ?></p></div>
      <div class="rt"><strong><?= (int) $m['joined'] ?></strong><span class="muted small">seller<?= $m['joined'] === 1 ? '' : 's' ?> in</span></div>
      <?= pill($m['phase'], SALE_PHASE_LABEL, $kinds) ?>
      <?php if (!in_array($m['phase'], ['ended', 'cancelled'], true)): ?><form method="post" action="/admin/sales/cancel" class="row" data-keep><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($m['id']) ?>">
        <a class="btn btn-sm btn-outline" href="/admin?tab=sales&amp;edit=<?= e($m['id']) ?>">Edit</a><button class="btn btn-sm btn-ghost" data-confirm="Cancel this sale? Every seller's discounts for it go off.">Cancel</button></form><?php endif; ?>
    </li><?php endforeach; ?></ul><?php endif; ?>
  </section>
  <section class="dcard c12">
    <div class="dhead"><h3>Sellers' sales</h3><span class="muted small"><?= count($items) ?></span></div>
    <?php if (!$items): ?><p class="muted small">No seller has scheduled a sale yet.</p><?php else: ?>
    <ul class="slist2 flat"><?php foreach ($items as $c): ?><li>
      <div class="grow"><p class="nm"><?= e($c['name']) ?></p><p class="muted small"><?= e($c['store']) ?> · <?= e(sale_dates($c)) ?></p></div>
      <div class="rt"><strong><?= e(sale_describe($c)) ?></strong><span class="muted small"><?= (int) $c['covers'] ?> product<?= $c['covers'] === 1 ? '' : 's' ?></span></div>
      <?= pill($c['phase'], SALE_PHASE_LABEL, $kinds) ?>
      <?php if (!in_array($c['phase'], ['ended', 'cancelled'], true)): ?><form method="post" action="/admin/sales/block" data-keep><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($c['id']) ?>"><input type="hidden" name="block" value="<?= $c['phase'] === 'blocked' ? '0' : '1' ?>">
        <button class="btn btn-sm btn-outline" <?= $c['phase'] === 'blocked' ? '' : 'data-confirm="Switch this sale off? The seller\'s prices go back to normal."' ?>><?= $c['phase'] === 'blocked' ? 'Switch on' : 'Switch off' ?></button></form><?php endif; ?>
    </li><?php endforeach; ?></ul><?php endif; ?>
  </section>
</div>
