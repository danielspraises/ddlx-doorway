<?php
$steps = [
    ['user', 'Personal Information', 'Your contact details, address and identification.', ['Contact details', 'Residential address', 'Identity & role']],
    ['building', 'Business Information', 'The business behind your store, and what you sell.', ['Business details', 'Product category', 'Business address']],
    ['store', 'Brand & Store', 'Your store name, brand story and social presence.', ['Store name', 'Social channels', 'Brand details']],
    ['file-check', 'Business Registration', 'Your registration status and registered business details.', ['Registration status', 'Registration number', 'Registered name']],
    ['send', 'Review & Submit', 'Check your details and send your application for review.', ['Review details', 'Declarations', 'Submit application']],
];
?>
<section class="hero">
  <div class="hero-grid" aria-hidden="true"></div>
  <div class="scene" aria-hidden="true"><?= tote_svg() ?></div>
  <div class="wrap hero-copy">
    <p class="eyebrow">Vendor Hub · Seller platform</p>
    <h1>Build your brand. Run your store<em>.</em></h1>
    <p class="lead">Apply to sell, then manage products, inventory, orders and growth from one focused workspace.</p>
    <div class="hero-actions">
      <a class="btn btn-lg" href="/apply">Start application <?= icon('arrow-up-right') ?></a>
      <a class="btn btn-lg btn-ghost" href="/auth">Continue application <?= icon('arrow-right') ?></a>
    </div>
    <p class="hero-cats"><?= icon('check') ?> Clothing <span>/</span> Footwear <span>/</span> Accessories</p>
  </div>
  <p class="hero-caption">MADE FOR INDEPENDENT COMMERCE</p>
</section>

<section class="band"><div class="wrap">
  <p class="eyebrow" style="text-transform:uppercase">From application to everyday operations</p>
  <ul><li><?= icon('store') ?> Store</li><li><?= icon('package') ?> Inventory</li><li><?= icon('chart') ?> Insights</li><li><?= icon('wallet') ?> Payouts</li></ul>
</div></section>

<section class="section" id="how"><div class="wrap">
  <div class="section-head"><div><p class="eyebrow">Your application</p><h2>Five clear steps to your DDLX store.</h2></div><span class="muted small">Progress saves as you go.</span></div>
  <ol class="stepcards">
    <?php foreach ($steps as $i => [$ic, $title]): ?>
    <li><button class="stepcard" type="button" data-step="<?= $i ?>" aria-pressed="<?= $i === 0 ? 'true' : 'false' ?>"><span class="top">0<?= $i + 1 ?><?= icon('arrow-right') ?></span><span class="t"><?= e($title) ?></span></button></li>
    <?php endforeach; ?>
  </ol>
  <?php foreach ($steps as $i => [$ic, $title, $desc, $items]): ?>
  <div class="stepdetail<?= $i === 0 ? ' on' : '' ?>" data-detail="<?= $i ?>" aria-live="polite">
    <div class="ic"><?= icon($ic) ?><div><p class="big"><?= e($desc) ?></p><ul><?php foreach ($items as $it): ?><li><?= e($it) ?></li><?php endforeach; ?></ul></div></div>
    <a class="btn btn-lg" href="/apply" style="gap:24px">Begin application <?= icon('arrow-right') ?></a>
  </div>
  <?php endforeach; ?>
</div></section>

<section class="section"><div class="wrap">
  <div class="section-head"><div><p class="eyebrow">Once you are approved</p><h2>Everything you need to sell on DDLX.</h2></div></div>
  <div class="features">
    <div class="feature"><?= icon('package') ?><h3>Products and inventory</h3><p>Simple and variable products, photo galleries, clear categories and live stock. Every product is reviewed before it goes on the marketplace.</p></div>
    <div class="feature"><?= icon('truck') ?><h3>Orders in step with the marketplace</h3><p>Mark orders processing and ready for shipping. DDLX collects and delivers, and every status updates on both sides.</p></div>
    <div class="feature"><?= icon('wallet') ?><h3>Clear payouts</h3><p>See pending and available earnings, add your bank account and withdraw once an order has been delivered.</p></div>
    <div class="feature"><?= icon('chart') ?><h3>Insights</h3><p>Daily earnings, best sellers and spreadsheet downloads of your sales and products.</p></div>
    <div class="feature"><?= icon('bell') ?><h3>Notifications</h3><p>New orders, low stock, decisions on your products and payouts, in the app and by email.</p></div>
    <div class="feature"><?= icon('shield') ?><h3>Verified sellers only</h3><p>Identity and business checks keep the marketplace trusted for buyers and for you.</p></div>
  </div>
</div></section>

<section class="section" style="border-bottom:0"><div class="wrap">
  <div class="cta"><h2>Ready to open your DDLX store<em>?</em></h2><a class="btn btn-lg" href="/apply">Start application <?= icon('arrow-up-right') ?></a></div>
</div></section>
