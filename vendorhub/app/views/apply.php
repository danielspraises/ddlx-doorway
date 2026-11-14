<?php
/** @var ?array $app @var bool $editable @var int $step @var array $data @var array $errors @var array $user */
$status = $app['status'] ?? 'draft';
$err = fn (string $k) => $errors[$k] ?? null;
$v = fn (string $k) => (string) ($data[$k] ?? '');
// Shows a block only while another answer has a given value (public/assets/app.js flips it as the person chooses).
$if = fn (string $field, string $value) => 'data-if="' . e($field . '=' . $value) . '"' . ($v($field) === $value ? '' : ' hidden');
?>
<p class="eyebrow">Stage 1</p>
<div class="row between"><h1>Seller application</h1><?= pill($status, APP_STATUS_LABEL) ?></div>

<?php if (!empty($app['admin_comment']) && in_array($status, ['needs_corrections', 'rejected', 'approved'], true)): ?>
  <div class="notice"><h3>Feedback from our team</h3><p><?= nl2br(e($app['admin_comment'])) ?></p></div>
<?php endif; ?>

<?php if (!$editable): ?>
  <?php if ($status === 'approved'): ?>
    <div class="notice"><h3>Application approved</h3><p>Next, verify your identity and business so you can start selling.</p><p><a class="btn" href="/verification">Continue to verification</a></p></div>
  <?php elseif ($status === 'submitted'): ?>
    <div class="notice"><h3>Under review</h3><p>We are reviewing your application. This page updates when there is a decision.</p></div>
  <?php else: ?>
    <div class="notice"><h3>Application not approved</h3><p>See the feedback above. Contact support if you think this is a mistake.</p></div>
  <?php endif; ?>
  <div class="sumgrid">
  <?php for ($n = 1; $n <= 4; $n++): ?>
    <section class="card"><h2 class="sec-title"><span class="sec-num"><?= $n ?></span><?= e(APP_STEPS[$n][1]) ?></h2>
    <dl class="dl"><?php foreach (summary_rows($n, $app[APP_STEPS[$n][0]] ?? []) as [$k, $val]): ?><dt><?= e($k) ?></dt><dd><?= e($val) ?></dd><?php endforeach; ?></dl></section>
  <?php endfor; ?>
  </div>
<?php else: ?>
  <ol class="steps" aria-label="Application progress">
    <?php foreach (APP_STEPS as $n => [, $title]): ?>
      <li><a href="/apply?step=<?= $n ?>" class="<?= $n === $step ? 'active' : ($n < $step ? 'done' : '') ?>" <?= $n === $step ? 'aria-current="step"' : '' ?>><span class="num">0<?= $n ?></span><?= e($title) ?></a></li>
    <?php endforeach; ?>
  </ol>
<?php $tips = [1=>['Why we ask','We use your details to check you are who you say you are and to contact you about your store. Your ID number is never shown to customers.'],2=>['Business details','Tell us how you trade. Registered or not, we only need accurate information. You can change it until you submit.'],3=>['Your storefront','This is what customers see: store name, description and photos. A clear name and a short, honest description build trust.'],4=>['Registration','Add your CAC and tax details if you have them. They speed up verification later. Unregistered sellers can still apply.'],5=>['Before you submit','Check each step. After you submit, the team reviews within a few working days and writes to you here if anything needs fixing.']]; ?>
  <div class="narrow-col card">
  <h2 style="margin-top:0"><?= e(APP_STEPS[$step][1]) ?> <?= help($tips[$step][1] . ' Use "Save progress" any time and come back later.') ?></h2>
  <?php if (!empty($error)): ?><p class="err" role="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post" action="/apply" data-keep novalidate><?= csrf_field() ?>
    <input type="hidden" name="step" value="<?= $step ?>">
    <?php if ($step === 1): ?>
      <div class="grid g2">
        <?= f_text('first_name', 'First name', $v('first_name'), $err('first_name'), ['required' => 1, 'maxlength' => 100]) ?>
        <?= f_text('last_name', 'Last name', $v('last_name'), $err('last_name'), ['required' => 1, 'maxlength' => 100]) ?>
      </div>
      <?= f_text('other_names', 'Other name(s)', $v('other_names'), $err('other_names'), ['maxlength' => 100]) ?>
      <div class="grid g2">
        <?= f_text('email', 'Email', $v('email'), $err('email'), ['type' => 'email', 'required' => 1]) ?>
        <?= f_text('phone', 'Phone number', $v('phone'), $err('phone'), ['type' => 'tel', 'required' => 1, 'placeholder' => '0803 123 4567']) ?>
      </div>
      <?= f_area('address', 'Residential address', $v('address'), $err('address'), ['required' => 1, 'rows' => 2]) ?>
      <div class="grid g3">
        <?= f_text('city', 'City', $v('city'), $err('city'), ['required' => 1]) ?>
        <?= f_select('state', 'State', NIGERIAN_STATES, $v('state'), $err('state'), ['required' => 1]) ?>
        <?= f_select('country', 'Country', APP_COUNTRIES, $v('country') ?: 'Nigeria', $err('country'), ['required' => 1]) ?>
      </div>
      <div class="grid g3">
        <?= f_select('role', 'Your role', APP_ROLES, $v('role'), $err('role'), ['required' => 1]) ?>
        <?= f_select('id_type', 'ID type', ID_TYPES, $v('id_type'), $err('id_type'), ['required' => 1]) ?>
        <?= f_text('id_number', 'ID number', $v('id_number'), $err('id_number'), ['required' => 1]) ?>
      </div>
    <?php elseif ($step === 2): ?>
      <?= f_text('name', 'Business name', $v('name'), $err('name'), ['required' => 1]) ?>
      <div class="grid g2">
        <?= f_select('type', 'Business type', BUSINESS_TYPES, $v('type'), $err('type'), ['required' => 1]) ?>
        <div <?= $if('type', 'Other') ?>><?= f_text('type_other', 'Specify business type', $v('type_other'), $err('type_other')) ?></div>
        <?= f_select('category', 'Business category', BUSINESS_CATEGORIES, $v('category'), $err('category'), ['required' => 1]) ?>
        <div <?= $if('category', 'Others') ?>><?= f_text('category_other', 'Specify category', $v('category_other'), $err('category_other')) ?></div>
        <?= f_text('phone', 'Business phone', $v('phone'), $err('phone'), ['type' => 'tel']) ?>
        <?= f_text('email', 'Business email', $v('email'), $err('email'), ['type' => 'email']) ?>
      </div>
      <?= f_area('address', 'Business address', $v('address'), $err('address'), ['required' => 1, 'rows' => 2]) ?>
      <div class="grid g3">
        <?= f_text('city', 'City', $v('city'), $err('city'), ['required' => 1]) ?>
        <?= f_select('state', 'State', NIGERIAN_STATES, $v('state'), $err('state'), ['required' => 1]) ?>
        <?= f_select('country', 'Country', APP_COUNTRIES, $v('country') ?: 'Nigeria', $err('country'), ['required' => 1]) ?>
      </div>
      <div class="grid g3">
        <?= f_text('website', 'Website', $v('website'), $err('website'), ['type' => 'url', 'placeholder' => 'https://']) ?>
        <?= f_text('years', 'Years in business', $v('years'), $err('years'), ['inputmode' => 'numeric']) ?>
        <?= f_text('employees', 'Employees', $v('employees'), $err('employees'), ['inputmode' => 'numeric']) ?>
      </div>
      <?= f_area('description', 'What does your business do?', $v('description'), $err('description'), ['required' => 1, 'rows' => 4, 'maxlength' => 2000]) ?>
    <?php elseif ($step === 3): ?>
      <?= f_text('store_name', 'Store name', $v('store_name'), $err('store_name'), ['required' => 1, 'hint' => 'This is the name customers see on the marketplace.']) ?>
      <p class="label">Social media <span class="hint">(at least 2)</span></p>
      <?php if ($err('socials')): ?><p class="err" role="alert"><?= e($err('socials')) ?></p><?php endif; ?>
      <div class="grid g2"><?php foreach (SOCIALS as $k => $label): ?><?= f_text($k, $label, $v($k), null, ['placeholder' => '@handle or link']) ?><?php endforeach; ?></div>
      <?= f_select('owns_brand', 'Do you own a brand?', ['yes' => 'Yes', 'no' => 'No'], $v('owns_brand'), $err('owns_brand'), ['required' => 1]) ?>
      <div <?= $if('owns_brand', 'yes') ?>>
        <div class="grid g2">
          <?= f_text('brand_name', 'Brand name', $v('brand_name'), $err('brand_name')) ?>
          <?= f_select('brand_category', 'Brand category', BUSINESS_CATEGORIES, $v('brand_category'), $err('brand_category')) ?>
          <div <?= $if('brand_category', 'Others') ?>><?= f_text('brand_category_other', 'Specify brand category', $v('brand_category_other'), $err('brand_category_other')) ?></div>
        </div>
        <?= f_area('brand_description', 'About the brand', $v('brand_description'), $err('brand_description'), ['rows' => 3, 'maxlength' => 2000]) ?>
      </div>
    <?php elseif ($step === 4): ?>
      <?= f_select('registered', 'Is your business registered (CAC)?', ['yes' => 'Yes', 'no' => 'No'], $v('registered'), $err('registered'), ['required' => 1]) ?>
      <div class="grid g2" <?= $if('registered', 'yes') ?>>
        <?= f_text('reg_number', 'Registration number', $v('reg_number'), $err('reg_number'), ['hint' => 'RC or BN number.']) ?>
        <?= f_text('reg_name', 'Registered business name', $v('reg_name'), $err('reg_name')) ?>
      </div>
    <?php else: $decl = $app['declaration'] ?? []; ?>
      <?php for ($n = 1; $n <= 4; $n++): ?>
        <h3><?= e(APP_STEPS[$n][1]) ?> <a class="small" href="/apply?step=<?= $n ?>">Edit</a></h3>
        <dl class="dl"><?php foreach (summary_rows($n, $app[APP_STEPS[$n][0]] ?? []) as [$k, $val]): ?><dt><?= e($k) ?></dt><dd><?= e($val) ?></dd><?php endforeach; ?></dl>
      <?php endfor; ?>
      <hr class="sep">
      <?php foreach (APP_DECLARATIONS as $i => $text): ?><label class="check"><input type="checkbox" name="decl[<?= $i ?>]" value="yes" <?= ($decl[(string) $i] ?? '') === 'yes' ? 'checked' : '' ?>><span><?= e($text) ?></span></label><?php endforeach; ?>
      <div class="agree-box"><label class="check"><input type="checkbox" name="agree" value="yes" required><span>I have read and agree to the <a href="/seller-terms" target="_blank" rel="noopener">Seller Agreement</a>, the <a href="/terms" target="_blank" rel="noopener">Terms and Conditions</a> and the <a href="/privacy" target="_blank" rel="noopener">Privacy Policy</a>. I also accept the <a href="/returns-policy" target="_blank" rel="noopener">Returns and Refunds</a> policy and the <a href="/prohibited-items" target="_blank" rel="noopener">Prohibited Items</a> list.</span></label>
      <p class="muted small" style="margin:6px 0 0 28px">Version <?= (int) legal_version() ?>. Your agreement is recorded with the date and time.</p></div>
    <?php endif; ?>
    <div class="row" style="margin-top:20px">
      <?php if ($step > 1): ?><button class="btn btn-ghost" name="action" value="back" type="submit">Back</button><?php endif; ?>
      <button class="btn btn-outline" name="action" value="save" type="submit">Save progress</button>
      <?php if ($step < 5): ?><button class="btn" name="action" value="next" type="submit">Save and continue</button>
      <?php else: ?><button class="btn btn-accent" name="action" value="submit" type="submit" data-confirm="Submit your application for review?">Submit application</button><?php endif; ?>
    </div>
  </form>
  <p class="muted small" style="margin:14px 0 0">Stuck? <a href="/assistant">Ask DDLX</a> or <a href="/messages?new=1">write to the team</a>.</p>
  </div>
<?php endif; ?>
