<?php /** @var string $doc */
$out = terms_outstanding_count();
?>
<p class="muted">These pages are public. Sellers agree to the Seller Agreement when they apply. They use your live commission, payout hold and withdrawal settings, so you do not need to retype those numbers.</p>
<nav class="legal-nav" aria-label="Pages"><?php foreach (LEGAL_DOCS as $k => $d): ?><a href="/admin?tab=legal&amp;doc=<?= e($k) ?>" class="<?= $k === $doc ? 'on' : '' ?>"><?= e($d[0]) ?></a><?php endforeach; ?></nav>
<div class="card" style="margin-top:14px">
  <div class="row between"><div><strong><?= e(LEGAL_DOCS[$doc][0]) ?></strong><p class="muted small" style="margin:2px 0 0"><?= e(LEGAL_DOCS[$doc][1]) ?> <?= legal_is_custom($doc) ? 'Your own wording is in use.' : 'The built-in wording is in use.' ?> Updated <?= e(human_date(legal_updated($doc) . ' 00:00:00')) ?>.</p></div><a class="btn btn-sm btn-outline" href="/<?= e($doc) ?>" target="_blank" rel="noopener">View page</a></div>
  <form method="post" action="/admin/legal/save" data-keep style="margin-top:14px"><?= csrf_field() ?><input type="hidden" name="doc" value="<?= e($doc) ?>">
    <div class="field"><label for="legal-text">Wording</label><textarea id="legal-text" name="text" rows="22" style="font-family:ui-monospace,monospace;font-size:.85rem;line-height:1.5"><?= e(legal_text($doc)) ?></textarea>
      <span class="hint">Start a heading with ##, a bullet with -, and put **bold** around words. A blank line starts a new paragraph. You can use {company}, {email}, {commission}, {hold_days}, {min_withdrawal}, {fee} and {site}.</span></div>
    <?php if ($doc === 'seller-terms'): ?><label class="check"><input type="checkbox" name="ask_again" value="1"><span>Ask sellers to agree again (version <?= (int) legal_version() ?> becomes <?= (int) legal_version() + 1 ?>). Use this when the change matters.</span></label>
      <p class="muted small" style="margin:4px 0 0 28px"><?= (int) $out ?> verified seller<?= $out === 1 ? ' has' : 's have' ?> not agreed to the current version yet.</p><?php endif; ?>
    <div class="row" style="margin-top:12px"><button class="btn" type="submit">Save</button></div>
  </form>
  <?php if (legal_is_custom($doc)): ?><form method="post" action="/admin/legal/reset" data-keep data-confirm="Replace your wording with the built-in version?" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="doc" value="<?= e($doc) ?>"><button class="btn btn-sm btn-ghost" type="submit">Use the built-in wording again</button></form><?php endif; ?>
</div>
<div class="card" style="margin-top:14px"><strong>Company details used in these pages</strong>
  <form method="post" action="/admin/legal/company" data-keep style="margin-top:10px"><?= csrf_field() ?>
    <div class="grid g2"><?= f_text('company', 'Company name', (string) setting('legal_company', ''), null, ['placeholder' => 'DDLX', 'maxlength' => 120]) ?><?= f_text('email', 'Contact email for legal questions', (string) setting('legal_email', ''), null, ['type' => 'email', 'maxlength' => 255]) ?></div>
    <div class="row"><button class="btn btn-sm" type="submit">Save</button></div></form></div>
<p class="muted small" style="margin-top:14px">These are standard templates written for an online marketplace in Nigeria. Have a lawyer review them before you rely on them.</p>
