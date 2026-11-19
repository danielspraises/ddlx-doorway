<?php /** @var array $rows */ ?>
<ul class="srows">
<?php foreach ($rows as $r): ?>
  <li class="<?= $r['applied'] ? '' : 'off' ?>">
    <?php if ($r['image']): ?><img class="thumb" src="<?= e(product_image_url($r['image'])) ?>" alt=""><?php else: ?><span class="thumb"></span><?php endif; ?>
    <div class="grow"><p class="nm"><?= e($r['name']) ?></p>
      <p class="muted small"><?= $r['applied'] ? '' : 'Price is already lower, so it stays as it is' ?><?= $r['out'] ? ($r['applied'] ? '' : ' · ') . 'Out of stock' : '' ?></p></div>
    <?php if ($r['applied']): ?>
    <div class="rt"><strong><?= e(sale_money_range($r['sale'], $r['currency'])) ?></strong><span class="muted small"><s><?= e(sale_money_range($r['regular'], $r['currency'])) ?></s></span></div>
    <span class="dchip up"><?= e(sale_off_range($r['off'])) ?></span>
    <?php endif; ?>
  </li>
<?php endforeach; ?>
</ul>
