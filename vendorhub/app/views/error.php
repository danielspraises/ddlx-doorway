<p class="eyebrow"><?= (int) $status ?></p>
<h1><?= e($title) ?></h1>
<?php if ((int) $status >= 500): ?>
<p class="muted"><?= e($message) ?></p>
<p><a class="btn" href="<?= e((string) ($_SERVER['REQUEST_URI'] ?? '/')) ?>">Try again</a></p>
<?php else: ?>
<p class="muted"><?= e($message) ?></p>
<p><a class="btn" href="/">Go to Vendor Hub</a></p>
<?php endif; ?>
