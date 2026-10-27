<?php
/** @var ?array $app @var ?array $ver @var int $step @var array $errors @var bool $registered */
$status = $ver['status'] ?? 'draft';
$editable = $ver && in_array($status, ['draft', 'needs_corrections'], true);
$docs = $ver['documents'] ?? [];
$details = $ver['details'] ?? [];
$def = VERIFY_STEPS[$step] ?? VERIFY_STEPS[1];
$docLink = function (string $kind, string $title) use ($docs): string {
    if (empty($docs[$kind])) return '';
    $type = str_ends_with($docs[$kind], '.pdf') ? 'pdf' : 'image';
    return '<a href="/files/verification?p=' . e(rawurlencode($docs[$kind])) . '" data-preview="/files/verification?p=' . e(rawurlencode($docs[$kind])) . '" data-title="' . e($title) . '" data-type="' . $type . '">Open</a>';
};
?>
<p class="eyebrow">Stage 2</p>
<div class="row between"><h1>Seller verification</h1><?= $ver ? pill($status, APP_STATUS_LABEL) : '' ?></div>

<?php if (!$app || $app['status'] !== 'approved'): ?>
  <div class="notice"><h3>Application approval comes first</h3><p>Your verification opens once your application has been approved.</p><p><a class="btn" href="/apply">My application</a></p></div>
<?php else: ?>
  <?php if (!empty($ver['admin_comment'])): ?><div class="notice"><h3>Verification feedback</h3><p><?= nl2br(e($ver['admin_comment'])) ?></p></div><?php endif; ?>

  <?php if (!$editable): ?>
    <div class="notice"><h3><?= $status === 'approved' ? 'Verification complete' : ($status === 'rejected' ? 'Verification not approved' : 'Verification under review') ?></h3>
      <p><?= $status === 'approved' ? 'Your identity and business have been approved. You can now set up your store and products.' : 'Your documents and review updates appear here.' ?></p>
      <?php if ($status === 'approved'): ?><p><a class="btn" href="/seller">Open Seller Center</a></p><?php endif; ?></div>
    <h2>What you submitted</h2>
    <dl class="dl">
      <?php foreach (VERIFY_STEPS as $s): if ($s['file'] === '' || empty($docs[$s['file']])) continue; ?><dt><?= e($s['label']) ?></dt><dd><?= $docLink($s['file'], $s['label']) ?></dd><?php endforeach; ?>
      <?php if (!empty($details['business_presence'])): ?><dt>Business presence</dt><dd><?= e($details['business_presence']) ?></dd><?php endif; ?>
    </dl>
  <?php else: ?>
    <ol class="steps" aria-label="Verification progress">
      <?php foreach (VERIFY_STEPS as $n => $s): ?><li><a href="/verification?step=<?= $n ?>" class="<?= $n === $step ? 'active' : '' ?>" <?= $n === $step ? 'aria-current="step"' : '' ?>><span class="num">0<?= $n ?></span><?= e($s['title']) ?></a></li><?php endforeach; ?>
    </ol>
    <p class="muted small">Step <?= $step ?> of 8</p>
    <h2 style="margin-top:4px"><?= e($def['title']) ?></h2>
    <p class="muted"><?= e($def['lead']) ?></p>

    <?php if ($def['file'] !== '' && ($def['file'] !== 'registration' || $registered)): $kind = $def['file']; ?>
      <div class="card" style="margin:18px 0">
        <h3 style="margin-top:0"><?= e($def['label']) ?></h3>
        <div class="grid g2">
          <div><p class="label">What to upload</p><ul><?php foreach ($def['accepted'] as $t): ?><li><?= e($t) ?></li><?php endforeach; ?></ul></div>
          <div><p class="label">To get approved first time</p><ul><?php foreach ($def['tips'] as $t): ?><li><?= e($t) ?></li><?php endforeach; ?></ul></div>
        </div>
        <p class="muted small"><?= !empty($def['images_only']) ? 'JPG, PNG or WebP' : 'PDF, JPG, PNG or WebP' ?> · up to 10 MB</p>
        <form method="post" action="/verification/upload" enctype="multipart/form-data" data-keep><?= csrf_field() ?>
          <input type="hidden" name="step" value="<?= $step ?>">
          <div class="field"><label for="file">Choose file</label><input id="file" type="file" name="file" required accept="<?= !empty($def['images_only']) ? 'image/jpeg,image/png,image/webp' : 'application/pdf,image/jpeg,image/png,image/webp' ?>"></div>
          <button class="btn btn-outline" type="submit">Upload</button>
          <?php if (!empty($docs[$kind])): ?><span class="pill pill-ok">Document saved</span> <?= $docLink($kind, $def['label']) ?><?php endif; ?>
        </form>
        <?php if (!empty($errors['file'])): ?><p class="err" role="alert"><?= e($errors['file']) ?></p><?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if ($step === 3 && !$registered): ?><p class="notice">You said your business is not registered, so no registration certificate is needed.</p><?php endif; ?>

    <form method="post" action="/verification" data-keep><?= csrf_field() ?>
      <input type="hidden" name="step" value="<?= $step ?>">
      <?php if ($step === 4): ?>
        <dl class="dl"><?php foreach (SOCIALS as $k => $label): if (!empty($app['brand'][$k])): ?><dt><?= e($label) ?></dt><dd><?= e($app['brand'][$k]) ?></dd><?php endif; endforeach; ?></dl>
        <?= f_check('social_verified', 'These social accounts are current and belong to my business.', ($details['social_verified'] ?? '') === 'yes', $errors['confirm'] ?? null) ?>
      <?php elseif ($step === 5): ?>
        <p><?= nl2br(e(implode(', ', array_filter([$app['business']['address'] ?? '', $app['business']['city'] ?? '', $app['business']['state'] ?? '', $app['business']['country'] ?? ''])))) ?></p>
        <?= f_check('address_confirmed', 'I confirm that this is my current business address.', ($details['address_confirmed'] ?? '') === 'yes', $errors['confirm'] ?? null) ?>
      <?php elseif ($step === 7): ?>
        <?= f_area('business_presence', 'Where do customers find you?', (string) ($details['business_presence'] ?? ''), $errors['presence'] ?? null, ['required' => 1, 'rows' => 3, 'maxlength' => 1000, 'hint' => 'For example your shop address, Instagram page or market stall. One or two sentences is enough.']) ?>
      <?php elseif ($step === 8): ?>
        <dl class="dl"><?php foreach (VERIFY_STEPS as $s): if ($s['file'] === '') continue; ?><dt><?= e($s['label']) ?></dt><dd><?= !empty($docs[$s['file']]) ? $docLink($s['file'], $s['label']) : ($s['file'] === 'registration' && !$registered ? 'Not needed' : '<span class="err">Missing</span>') ?></dd><?php endforeach; ?></dl>
        <?= f_check('declaration', 'I confirm that these documents are genuine, belong to me or my business, and may be used to verify my seller application.', ($details['declaration'] ?? '') === 'yes', $errors['confirm'] ?? null) ?>
      <?php endif; ?>
      <div class="row" style="margin-top:20px">
        <?php if ($step > 1): ?><button class="btn btn-ghost" name="action" value="back" type="submit">Back</button><?php endif; ?>
        <button class="btn btn-outline" name="action" value="save" type="submit">Save progress</button>
        <?php if ($step < 8): ?><button class="btn" name="action" value="next" type="submit">Save and continue</button>
        <?php else: ?><button class="btn btn-accent" name="action" value="submit" type="submit" data-confirm="Submit your verification for review?">Submit verification</button><?php endif; ?>
      </div>
    </form>
  <?php endif; ?>
<?php endif; ?>
