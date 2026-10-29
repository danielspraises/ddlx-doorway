<p class="eyebrow">Products</p>
<h1>Import products from CSV</h1>
<p class="muted">Add many simple products at once. They are created as drafts: add photos to each, then submit them for review. Up to <?= IMPORT_MAX_ROWS ?> rows per file.</p>
<?php if ($result && !$result['ok']): ?>
  <div class="notice error"><strong><?= e($result['error']) ?></strong>
  <?php if ($result['errors']): ?><ul><?php foreach ($result['errors'] as $line => $list): ?><li>Row <?= (int) $line ?>: <?= e(implode('; ', $list)) ?></li><?php endforeach; ?></ul><?php endif; ?></div>
<?php endif; ?>
<ol>
  <li><a href="/products/import/template">Download the template</a> and open it in Excel or Google Sheets.</li>
  <li>Fill one product per row. Leave <code>sku</code> empty to get one automatically. Required: name, category, regular_price, weight_kg, length_cm, width_cm, height_cm.</li>
  <li>Save as CSV and upload it here. Every row is checked first; if any row has a problem nothing is imported.</li>
</ol>
<form method="post" action="/products/import" enctype="multipart/form-data" class="card" data-keep><?= csrf_field() ?>
  <label>CSV file<input type="file" name="file" accept=".csv,text/csv" required></label>
  <div class="row"><button class="btn" type="submit">Import products</button><a class="btn btn-ghost" href="/seller?tab=products">Cancel</a></div>
</form>
<details style="margin-top:24px"><summary>Categories you can use (<?= count($categories) ?>)</summary>
  <ul class="list"><?php foreach ($categories as $c): ?><li><?= e($c) ?></li><?php endforeach; ?></ul></details>
