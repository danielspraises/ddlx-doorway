<?php /** @var string $slug @var string $html @var string $updated */ ?>
<div class="legal">
  <p class="eyebrow">Legal</p>
  <h1><?= e(LEGAL_DOCS[$slug][0]) ?></h1>
  <p class="muted small">Last updated <?= e(human_date($updated . ' 00:00:00')) ?></p>
  <nav class="legal-nav" aria-label="Legal pages"><?php foreach (LEGAL_DOCS as $k => $d): ?><a href="/<?= e($k) ?>" class="<?= $k === $slug ? 'on' : '' ?>"><?= e($d[0]) ?></a><?php endforeach; ?></nav>
  <article class="legal-body"><?= $html ?></article>
</div>
