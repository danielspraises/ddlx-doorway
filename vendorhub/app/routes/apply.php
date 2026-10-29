<?php
declare(strict_types=1);

route('GET', '/apply', function () {
    $u = require_user();
    if ($u['isAdmin'] || $u['canReview'] || $u['canOrders'] || $u['canMessages']) redirect(home_path($u));
    $app = load_application($u['id']);
    $editable = !$app || in_array($app['status'], ['draft', 'needs_corrections'], true);
    $step = max(1, min(5, (int) input('step', (string) ($app['current_step'] ?? 1))));
    $data = [];
    if ($app && $step <= 4) $data = $app[APP_STEPS[$step][0]];
    if ($step === 1 && empty($data['email'])) $data['email'] = $u['email'];
    render('apply', ['title' => 'Seller application', 'app' => $app, 'editable' => $editable, 'step' => $step, 'data' => $data, 'errors' => [], 'user' => $u]);
});

route('POST', '/apply', function () {
    $u = require_user();
    $step = max(1, min(5, (int) input('step', '1')));
    $action = input('action', 'save');
    $app = load_application($u['id']);
    $editable = !$app || in_array($app['status'], ['draft', 'needs_corrections'], true);
    $again = fn (array $errors, array $data, ?string $error = null) => render('apply', ['title' => 'Seller application', 'app' => $app, 'editable' => $editable, 'step' => $step, 'data' => $data, 'errors' => $errors, 'error' => $error, 'user' => $u]);
    if (!$editable) redirect('/apply', 'This application can no longer be edited.', 'error');

    if ($step === 5) {
        if ($action === 'back') redirect('/apply?step=4');
        $decl = [];
        foreach ([0, 1, 2, 3] as $i) $decl[(string) $i] = isset($_POST['decl'][$i]) ? 'yes' : 'no';
        $r = save_application($u['id'], 5, [], $action === 'submit', $decl);
        if (!$r['ok']) { $again([], [], $r['error']); return; }
        redirect('/apply', $action === 'submit' ? 'Application submitted. We will review it and get back to you.' : 'Progress saved.');
    }

    $data = section_from_post($step);
    if ($action === 'back') {
        save_application($u['id'], $step, $data, false, [], max(1, $step - 1));
        redirect('/apply?step=' . max(1, $step - 1));
    }
    $errors = $action === 'next' ? validate_section($step, $data) : [];
    if ($errors) { $again($errors, $data); return; }
    $r = save_application($u['id'], $step, $data, false, [], $action === 'next' ? $step + 1 : $step);
    if (!$r['ok']) { $again([], $data, $r['error']); return; }
    redirect('/apply?step=' . ($action === 'next' ? $step + 1 : $step), $action === 'next' ? null : 'Progress saved.');
});
