<?php
declare(strict_types=1);

// Public legal pages: /seller-terms, /terms, /privacy, /returns-policy, /prohibited-items, /cookies
foreach (LEGAL_PATHS as $slug) {
    route('GET', '/' . $slug, function () use ($slug) {
        render('legal', ['title' => LEGAL_DOCS[$slug][0], 'slug' => $slug, 'user' => current_user(), 'html' => legal_html(legal_text($slug)), 'updated' => legal_updated($slug)]);
    });
}

// Existing sellers agree to the current Seller Agreement.
route('POST', '/seller-terms/accept', function () {
    $u = require_seller();
    if (empty($_POST['agree'])) redirect('/seller?tab=overview', 'Tick the box to agree.', 'error');
    terms_record($u['id']);
    redirect('/seller?tab=overview', 'Thank you. Your agreement is recorded.');
});
