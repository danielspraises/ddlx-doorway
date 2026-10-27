<?php
declare(strict_types=1);

const VERIFY_MAX_BYTES = 10 * 1024 * 1024;

function verification_registered(string $userId): bool
{
    $a = load_application($userId);
    return (($a['registration']['registered'] ?? '') === 'yes');
}

function verification_page(array $u, ?array $errors = null, ?int $forceStep = null): void
{
    $app = load_application($u['id']);
    if (!$app || $app['status'] !== 'approved') {
        render('verification', ['title' => 'Verification', 'app' => $app, 'ver' => null, 'step' => 1, 'errors' => [], 'user' => $u, 'registered' => false]);
        return;
    }
    $ver = load_verification($u['id']);
    $step = $forceStep ?? max(1, min(8, (int) input('step', (string) ($ver['current_step'] ?? 1))));
    render('verification', ['title' => 'Verification', 'app' => $app, 'ver' => $ver, 'step' => $step, 'errors' => $errors ?? [], 'user' => $u, 'registered' => (($app['registration']['registered'] ?? '') === 'yes')]);
}

route('GET', '/verification', function () {
    $u = require_user();
    if ($u['isAdmin'] || $u['canReview']) redirect(home_path($u));
    verification_page($u);
});

// Saves the typed details and moves between steps; "submit" sends everything for review.
route('POST', '/verification', function () {
    $u = require_user();
    $ver = load_verification($u['id']);
    if (!$ver) redirect('/apply', 'Your application must be approved first.', 'error');
    if (!in_array($ver['status'], ['draft', 'needs_corrections'], true)) redirect('/verification', 'This verification can no longer be edited.', 'error');
    $step = max(1, min(8, (int) input('step', '1')));
    $action = input('action', 'save');
    $details = $ver['details'];
    foreach (['social_verified', 'address_confirmed', 'declaration'] as $k) {
        if ($step === ['social_verified' => 4, 'address_confirmed' => 5, 'declaration' => 8][$k]) $details[$k] = isset($_POST[$k]) ? 'yes' : 'no';
    }
    if ($step === 7) $details['business_presence'] = str_limit(input('business_presence'), 1000);
    $registered = verification_registered($u['id']);

    if ($action === 'next' || $action === 'submit') {
        $errors = verification_step_errors($step, $details, $ver['documents'], $registered);
        if ($errors) {
            $ver['details'] = $details;
            verification_page($u, $errors, $step);
            return;
        }
    }
    if ($action === 'submit') {
        for ($n = 1; $n <= 8; $n++) {
            $errors = verification_step_errors($n, $details, $ver['documents'], $registered);
            if ($errors) { db()->exec('UPDATE seller_verifications SET details = ?, current_step = ?, updated_at = ? WHERE id = ?', [jenc($details), $n, now_db(), $ver['id']]); redirect('/verification?step=' . $n, 'Finish this step before submitting.', 'error'); }
        }
    }
    $next = match ($action) { 'next' => min(8, $step + 1), 'back' => max(1, $step - 1), default => $step };
    $now = now_db();
    $n = db()->exec('UPDATE seller_verifications SET details = ?, current_step = ?, status = ?, submitted_at = ?, updated_at = ? WHERE id = ? AND status IN (\'draft\', \'needs_corrections\')',
        [jenc($details), $next, $action === 'submit' ? 'submitted' : $ver['status'], $action === 'submit' ? $now : $ver['submitted_at'], $now, $ver['id']]);
    if ($n === 0) redirect('/verification', 'This verification can no longer be edited.', 'error');
    if ($action === 'submit') {
        announce('admins', 'verification', 'A seller verification is waiting for review');
        redirect('/verification', 'Verification submitted for review.');
    }
    redirect('/verification?step=' . $next, $action === 'save' ? 'Progress saved.' : null);
});

route('POST', '/verification/upload', function () {
    $u = require_user();
    $ver = load_verification($u['id']);
    if (!$ver || !in_array($ver['status'], ['draft', 'needs_corrections'], true)) redirect('/verification', 'This verification can no longer be edited.', 'error');
    $step = max(1, min(8, (int) input('step', '1')));
    $def = VERIFY_STEPS[$step] ?? null;
    $kind = $def['file'] ?? '';
    if ($kind === '') redirect('/verification?step=' . $step);
    $allowed = !empty($def['images_only']) ? Storage::IMAGE_TYPES : array_merge(Storage::IMAGE_TYPES, ['application/pdf']);
    $got = Storage::fromUpload($_FILES['file'] ?? [], $allowed, VERIFY_MAX_BYTES);
    if (is_string($got)) redirect('/verification?step=' . $step, $got, 'error');
    [$bytes, $mime] = $got;
    $bytes = Storage::cleanImage($bytes, $mime);
    $path = $u['id'] . '/' . $ver['id'] . '/' . $kind . '-' . uuid() . '.' . Storage::EXT[$mime];
    Storage::save('verification', $path, $bytes);
    $docs = $ver['documents'];
    $old = $docs[$kind] ?? null;
    $docs[$kind] = $path;
    db()->exec("UPDATE seller_verifications SET documents = ?, current_step = ?, updated_at = ? WHERE id = ? AND status IN ('draft', 'needs_corrections')", [jenc($docs), $step, now_db(), $ver['id']]);
    if (is_string($old) && preg_match(Storage::verificationRe(), $old)) Storage::remove('verification', $old);
    redirect('/verification?step=' . $step, 'Document uploaded and saved.');
});

// Private documents: the owner and admins only.
route('GET', '/files/verification', function () {
    $u = require_user();
    $p = input('p');
    if (!preg_match(Storage::verificationRe(), $p, $m)) abort(404);
    if ($m[1] !== $u['id'] && !$u['isAdmin']) abort(403, 'You do not have access to this file.');
    $bytes = Storage::read('verification', $p);
    if ($bytes === null) abort(404);
    Storage::send($bytes, Storage::MIME[$m[3]] ?? 'application/octet-stream', false);
});
