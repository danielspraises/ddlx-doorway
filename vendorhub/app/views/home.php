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
  <div class="scene" aria-hidden="true">
    <div class="orbits" data-parallax>
      <span class="blob b1"></span><span class="blob b2"></span>
      <?= tote_svg() ?>
      <div class="orbit o1"><span class="coin" style="--a:0deg"><i><?= icon('package') ?></i></span><span class="coin" style="--a:120deg"><i><?= icon('wallet') ?></i></span><span class="coin" style="--a:240deg"><i><?= icon('truck') ?></i></span></div>
      <div class="orbit o2"><span class="coin" style="--a:60deg"><i><?= icon('chart') ?></i></span><span class="coin" style="--a:180deg"><i><?= icon('bell') ?></i></span><span class="coin" style="--a:300deg"><i><?= icon('store') ?></i></span></div>
      <span class="ring r3"></span>
      <span class="chip ch1"><b></b>New order · ₦45,000</span>
      <span class="chip ch2"><b></b>Payout sent</span>
      <span class="chip ch3"><b></b>Product approved</span>
      <span class="spark s1"></span><span class="spark s2"></span><span class="spark s3"></span><span class="spark s4"></span>
    </div>
  </div>
  <div class="wrap hero-copy">
    <p class="eyebrow">DDLX · Vendor Hub</p>
    <h1>Run your store. Build your brand.</h1>
    <p class="lead">Apply to sell, then manage products, inventory, orders, and growth in one workspace.</p>
    <div class="hero-actions">
      <a class="btn btn-lg" href="/apply">Start application <?= icon('arrow-up-right') ?></a>
      <a class="btn btn-lg btn-ghost" href="/auth">Continue application <?= icon('arrow-right') ?></a>
    </div>
    <p class="hero-cats"><?= icon('check') ?> Fashion <span>/</span> Electronics <span>/</span> Home <span>/</span> Beauty <span>/</span> Food &amp; more</p>
  </div>
</section>

<div class="marquee" aria-hidden="true"><div class="marquee-track"><?php for ($k = 0; $k < 2; $k++): ?><div class="marquee-set"><?php foreach (['Fashion', 'Electronics', 'Home & living', 'Beauty', 'Food & drink', 'Health', 'Kids', 'Sports', 'Art & crafts', 'Auto & tools'] as $c): ?><span><?= e($c) ?></span><?php endforeach; ?></div><?php endfor; ?></div></div>
<section class="band"><div class="wrap">
  <p class="eyebrow" style="text-transform:uppercase">From application to everyday operations</p>
  <ul><li><?= icon('store') ?> Store</li><li><?= icon('package') ?> Inventory</li><li><?= icon('chart') ?> Insights</li><li><?= icon('wallet') ?> Payouts</li></ul>
</div></section>

<section class="section" id="how"><div class="wrap">
  <div class="section-head" data-reveal><div><p class="eyebrow">Your application</p><h2>Five clear steps to your DDLX store.</h2></div><span class="muted small">Progress saves as you go.</span></div>
  <ol class="stepcards" data-reveal>
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
  <div class="section-head" data-reveal><div><p class="eyebrow">Once you are approved</p><h2>Everything you need to sell on DDLX.</h2></div></div>
  <div class="features">
    <div class="feature" data-reveal style="--d:<?= (($fi = ($fi ?? -1) + 1) % 3) * .1 ?>s"><?= icon('package') ?><h3>Products and inventory</h3><p>Simple and variable products, photo galleries, clear categories and live stock. Every product is reviewed before it goes on the marketplace.</p></div>
    <div class="feature" data-reveal style="--d:<?= (($fi = ($fi ?? -1) + 1) % 3) * .1 ?>s"><?= icon('truck') ?><h3>Orders in step with the marketplace</h3><p>Mark orders processing and ready for shipping. DDLX collects and delivers, and every status updates on both sides.</p></div>
    <div class="feature" data-reveal style="--d:<?= (($fi = ($fi ?? -1) + 1) % 3) * .1 ?>s"><?= icon('wallet') ?><h3>Clear payouts</h3><p>See pending and available earnings, add your bank account and withdraw once an order has been delivered.</p></div>
    <div class="feature" data-reveal style="--d:<?= (($fi = ($fi ?? -1) + 1) % 3) * .1 ?>s"><?= icon('chart') ?><h3>Insights</h3><p>Daily earnings, best sellers and spreadsheet downloads of your sales and products.</p></div>
    <div class="feature" data-reveal style="--d:<?= (($fi = ($fi ?? -1) + 1) % 3) * .1 ?>s"><?= icon('bell') ?><h3>Notifications</h3><p>New orders, low stock, decisions on your products and payouts, in the app and by email.</p></div>
    <div class="feature" data-reveal style="--d:<?= (($fi = ($fi ?? -1) + 1) % 3) * .1 ?>s"><?= icon('shield') ?><h3>Verified sellers only</h3><p>Identity and business checks keep the marketplace trusted for buyers and for you.</p></div>
  </div>
</div></section>

<section class="section" style="border-bottom:0"><div class="wrap">
  <div class="cta" data-reveal><h2>Ready to launch your DDLX store?</h2><a class="btn btn-lg" href="/apply">Start application <?= icon('arrow-up-right') ?></a></div>
</div></section>
