<?php
declare(strict_types=1);

// Stage 1 (seller application) and Stage 2 (verification): fields, rules and review summaries.

const NIGERIAN_STATES = ['Abia','Adamawa','Akwa Ibom','Anambra','Bauchi','Bayelsa','Benue','Borno','Cross River','Delta','Ebonyi','Edo','Ekiti','Enugu','FCT - Abuja','Gombe','Imo','Jigawa','Kaduna','Kano','Katsina','Kebbi','Kogi','Kwara','Lagos','Nasarawa','Niger','Ogun','Ondo','Osun','Oyo','Plateau','Rivers','Sokoto','Taraba','Yobe','Zamfara'];
const APP_COUNTRIES = ['Nigeria', 'Ghana', 'Kenya', 'South Africa', 'United Kingdom', 'United States', 'Other'];
const APP_ROLES = ['Owner', 'Director', 'Manager', 'Authorised Representative'];
const ID_TYPES = ['National Identity Number (NIN)', 'International Passport', "Driver's Licence", "Voter's Card"];
const BUSINESS_TYPES = ['Individual / Sole proprietorship', 'Partnership', 'Other'];
const BUSINESS_CATEGORIES = ['Clothing', 'Footwear', 'Accessories', 'Others'];
const SOCIALS = ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok', 'x' => 'X / Twitter', 'whatsapp' => 'WhatsApp Business', 'snapchat' => 'Snapchat', 'other' => 'Other social media'];
const APP_STEPS = [1 => ['personal', 'Personal Information'], 2 => ['business', 'Business Information'], 3 => ['brand', 'Brand & Store'], 4 => ['registration', 'Business Registration'], 5 => ['review', 'Review & Submit']];
const APP_STATUS_LABEL = ['draft' => 'Draft', 'submitted' => 'Under review', 'needs_corrections' => 'Needs corrections', 'approved' => 'Approved', 'rejected' => 'Rejected'];
const APP_DECLARATIONS = [
    'The information I have given is true and complete.',
    'I am authorised to apply on behalf of this business.',
    'I will follow DDLX marketplace rules and sell only genuine products.',
    'I agree that DDLX may verify my identity and business details.',
];

function normalize_phone(string $v): string
{
    return preg_replace('/[\s\-()]/', '', $v) ?? $v;
}

function is_ng_phone(string $v): bool
{
    return preg_match('/^(\+?234|0)[789][01]\d{8}$/', normalize_phone($v)) === 1;
}

// Reads one section's fields from the posted form (only the known keys, trimmed).
function section_from_post(int $step): array
{
    $keys = [
        1 => ['first_name', 'last_name', 'other_names', 'email', 'phone', 'address', 'city', 'state', 'country', 'role', 'id_type', 'id_number'],
        2 => ['name', 'type', 'type_other', 'category', 'category_other', 'phone', 'email', 'address', 'city', 'state', 'country', 'website', 'years', 'employees', 'description'],
        3 => array_merge(['store_name', 'owns_brand', 'brand_name', 'brand_category', 'brand_category_other', 'brand_description'], array_keys(SOCIALS)),
        4 => ['registered', 'reg_number', 'reg_name'],
    ][$step] ?? [];
    $out = [];
    foreach ($keys as $k) {
        $out[$k] = str_limit(input($k), $k === 'description' || $k === 'brand_description' ? 2000 : 500);
    }
    return $out;
}

// Returns [field => message]. Empty means the section is complete.
function validate_section(int $step, array $d): array
{
    $e = [];
    $req = function (string $k, string $label, int $max = 200) use (&$e, $d) {
        $v = trim((string) ($d[$k] ?? ''));
        if ($v === '') $e[$k] = $label . ' is required';
        elseif (mb_strlen($v) > $max) $e[$k] = $label . ' is too long';
    };
    $v = fn (string $k) => trim((string) ($d[$k] ?? ''));
    if ($step === 1) {
        $req('first_name', 'First name', 100); $req('last_name', 'Last name', 100);
        if (!filter_var($v('email'), FILTER_VALIDATE_EMAIL)) $e['email'] = 'Enter a valid email address';
        if ($v('phone') === '') $e['phone'] = 'Phone number is required';
        elseif (!is_ng_phone($v('phone'))) $e['phone'] = 'Enter a valid Nigerian phone number, e.g. 0803 123 4567';
        $req('address', 'Residential address', 500); $req('city', 'City', 100); $req('state', 'State', 50); $req('country', 'Country', 50);
        $req('role', "Applicant's role", 50); $req('id_type', 'ID type', 50); $req('id_number', 'ID number', 50);
    } elseif ($step === 2) {
        $req('name', 'Business name'); $req('type', 'Business type', 50); $req('category', 'Business category', 50);
        $req('address', 'Business address', 500); $req('city', 'City', 100); $req('state', 'State', 50); $req('country', 'Country', 50);
        $req('description', 'Business description', 2000);
        if ($v('type') === 'Other' && $v('type_other') === '') $e['type_other'] = 'Please specify the business type';
        if ($v('category') === 'Others' && $v('category_other') === '') $e['category_other'] = 'Please specify the category';
        if ($v('phone') !== '' && !is_ng_phone($v('phone'))) $e['phone'] = 'Enter a valid Nigerian phone number';
        if ($v('email') !== '' && !filter_var($v('email'), FILTER_VALIDATE_EMAIL)) $e['email'] = 'Enter a valid email';
        if ($v('website') !== '' && !preg_match('#^https?://[^\s.]+\.[^\s]+$#i', $v('website'))) $e['website'] = 'Enter a valid URL starting with http:// or https://';
        foreach (['years' => 'Years in business', 'employees' => 'Number of employees'] as $k => $label) {
            if ($v($k) !== '' && !ctype_digit($v($k))) $e[$k] = $label . ' must be 0 or more';
        }
    } elseif ($step === 3) {
        $req('store_name', 'Store name');
        $count = 0;
        foreach (array_keys(SOCIALS) as $k) if ($v($k) !== '') $count++;
        if ($count < 2) $e['socials'] = 'Please provide at least 2 social media accounts';
        if (!in_array($v('owns_brand'), ['yes', 'no'], true)) $e['owns_brand'] = 'Please choose Yes or No';
        if ($v('owns_brand') === 'yes') {
            if ($v('brand_name') === '') $e['brand_name'] = 'Brand name is required';
            if ($v('brand_category') === '') $e['brand_category'] = 'Brand category is required';
            if ($v('brand_category') === 'Others' && $v('brand_category_other') === '') $e['brand_category_other'] = 'Please specify the brand category';
            if ($v('brand_description') === '') $e['brand_description'] = 'Brand description is required';
        }
    } elseif ($step === 4) {
        if (!in_array($v('registered'), ['yes', 'no'], true)) $e['registered'] = 'Please choose Yes or No';
        if ($v('registered') === 'yes') {
            if ($v('reg_number') === '') $e['reg_number'] = 'Registration number is required';
            if ($v('reg_name') === '') $e['reg_name'] = 'Registered business name is required';
        }
    }
    return $e;
}

// [label, value] rows for review screens.
function summary_rows(int $step, array $d): array
{
    $v = fn (string $k) => ($d[$k] ?? '') !== '' ? (string) $d[$k] : '—';
    if ($step === 1) {
        return [['First name', $v('first_name')], ['Last name', $v('last_name')], ['Other name(s)', $v('other_names')], ['Email', $v('email')], ['Phone', $v('phone')],
            ['Residential address', $v('address')], ['City', $v('city')], ['State', $v('state')], ['Country', $v('country')], ['Role', $v('role')], ['ID type', $v('id_type')], ['ID number', $v('id_number')]];
    }
    if ($step === 2) {
        return [['Business name', $v('name')], ['Business type', ($d['type'] ?? '') === 'Other' ? 'Other — ' . $v('type_other') : $v('type')],
            ['Category', ($d['category'] ?? '') === 'Others' ? 'Others — ' . $v('category_other') : $v('category')], ['Business phone', $v('phone')], ['Business email', $v('email')],
            ['Address', $v('address')], ['City', $v('city')], ['State', $v('state')], ['Country', $v('country')], ['Website', $v('website')],
            ['Years in business', $v('years')], ['Employees', $v('employees')], ['Description', $v('description')]];
    }
    if ($step === 3) {
        $rows = [['Store name', $v('store_name')]];
        foreach (SOCIALS as $k => $label) if (($d[$k] ?? '') !== '') $rows[] = [$label, (string) $d[$k]];
        $rows[] = ['Owns a brand', ($d['owns_brand'] ?? '') === 'yes' ? 'Yes' : (($d['owns_brand'] ?? '') === 'no' ? 'No' : '—')];
        if (($d['owns_brand'] ?? '') === 'yes') {
            $rows[] = ['Brand name', $v('brand_name')];
            $rows[] = ['Brand category', ($d['brand_category'] ?? '') === 'Others' ? 'Others — ' . $v('brand_category_other') : $v('brand_category')];
            $rows[] = ['Brand description', $v('brand_description')];
        }
        return $rows;
    }
    $rows = [['Registered', ($d['registered'] ?? '') === 'yes' ? 'Yes' : (($d['registered'] ?? '') === 'no' ? 'No' : '—')]];
    if (($d['registered'] ?? '') === 'yes') {
        $rows[] = ['Registration number', $v('reg_number')];
        $rows[] = ['Registered name', $v('reg_name')];
    }
    return $rows;
}

function load_application(string $userId): ?array
{
    $row = db()->one('SELECT * FROM seller_applications WHERE user_id = ?', [$userId]);
    if (!$row) return null;
    foreach (['personal', 'business', 'brand', 'registration', 'declaration'] as $k) $row[$k] = jdec($row[$k] ?? '', []);
    return $row;
}

// Saves one section (and optionally submits). Returns ['ok'=>bool,'error'=>?string].
function save_application(string $userId, int $step, array $sectionData, bool $submit, array $declaration = [], ?int $goto = null): array
{
    $db = db();
    $app = load_application($userId);
    if ($app && !in_array($app['status'], ['draft', 'needs_corrections'], true)) {
        return ['ok' => false, 'error' => 'This application can no longer be edited.'];
    }
    $sections = ['personal' => [], 'business' => [], 'brand' => [], 'registration' => []];
    foreach ($sections as $k => $_) $sections[$k] = $app[$k] ?? [];
    if ($step >= 1 && $step <= 4) $sections[APP_STEPS[$step][0]] = $sectionData;
    $decl = $declaration ?: ($app['declaration'] ?? []);

    if ($submit) {
        for ($n = 1; $n <= 4; $n++) {
            if (validate_section($n, $sections[APP_STEPS[$n][0]])) return ['ok' => false, 'error' => 'Please complete every section before submitting.'];
        }
        foreach ([0, 1, 2, 3] as $i) {
            if (($decl[(string) $i] ?? '') !== 'yes') return ['ok' => false, 'error' => 'Please confirm all four declarations.'];
        }
    }
    $email = strtolower(trim((string) ($sections['personal']['email'] ?? ''))) ?: null;
    if ($email) {
        $clash = $db->val("SELECT 1 FROM seller_applications WHERE LOWER(applicant_email) = ? AND status <> 'rejected' AND user_id <> ? LIMIT 1", [$email, $userId]);
        if ($clash) return ['ok' => false, 'error' => 'This email is already used by another application.'];
    }
    $now = now_db();
    $row = ['personal' => jenc($sections['personal']), 'business' => jenc($sections['business']), 'brand' => jenc($sections['brand']), 'registration' => jenc($sections['registration']),
        'declaration' => jenc($decl), 'applicant_email' => $email, 'current_step' => min(5, max(1, $goto ?? $step)), 'updated_at' => $now];
    if ($submit) {
        $row['status'] = 'submitted';
        $row['submitted_at'] = $now;
    }
    if ($app) {
        $set = implode(', ', array_map(fn ($c) => $c . ' = ?', array_keys($row)));
        $db->exec('UPDATE seller_applications SET ' . $set . ' WHERE id = ?', array_merge(array_values($row), [$app['id']]));
    } else {
        $db->insert('seller_applications', $row + ['id' => uuid(), 'user_id' => $userId, 'status' => $submit ? 'submitted' : 'draft', 'created_at' => $now]);
    }
    if ($submit) announce('admins', 'application', 'A seller application is waiting for review');
    return ['ok' => true];
}

// ---------------------------------------------------------------- Stage 2: verification

const VERIFY_STEPS = [
    1 => ['title' => 'Identity', 'file' => 'identity', 'label' => 'Government-issued ID',
        'lead' => 'We need to confirm that you are the person named on your application.',
        'accepted' => ['National ID card', 'International passport (photo page)', "Driver's licence", "Voter's card"],
        'tips' => ['The name must match the name on your application.', 'All four corners are visible and the text is sharp, with no glare.', 'If the card has details on the back, put both sides in one PDF or image.']],
    2 => ['title' => 'Passport photograph', 'file' => 'portrait', 'label' => 'Your photograph', 'images_only' => true,
        'lead' => 'A recent photo of you, so we can match your face to your ID.',
        'accepted' => ['A clear, recent photo of your face and shoulders'],
        'tips' => ['Face the camera in good light, against a plain background.', 'No sunglasses, hat, filters or other people in the photo.']],
    3 => ['title' => 'Business registration', 'file' => 'registration', 'label' => 'Registration certificate',
        'lead' => 'Proof that your business is registered. Only needed if you said it is registered.',
        'accepted' => ['CAC certificate of incorporation', 'CAC business name registration certificate'],
        'tips' => ['The business name must match the one on your application.', 'Upload the whole page, with the registration (RC or BN) number readable.']],
    4 => ['title' => 'Social media', 'file' => '', 'label' => '', 'lead' => 'Check that the social accounts you gave are correct. Nothing to upload.'],
    5 => ['title' => 'Address', 'file' => '', 'label' => '', 'lead' => 'Check that the business address you gave is correct. Nothing to upload.'],
    6 => ['title' => 'Proof of address', 'file' => 'proof_address', 'label' => 'Proof of address',
        'lead' => 'A document that links you or your business to the address from the previous step.',
        'accepted' => ['Utility bill (electricity, water, waste)', 'Bank statement', 'Tenancy or lease agreement', 'Official letter from a government body'],
        'tips' => ['It must be dated within the last 3 months, unless it is a tenancy agreement.', 'It must show your name or your business name and the same address you gave us.']],
    7 => ['title' => 'Business presence', 'file' => 'presence', 'label' => 'Photo of your business or products', 'images_only' => true,
        'lead' => 'A photo that shows your business is real and that you make or sell what you plan to list.',
        'accepted' => ['Your shop or stall front', 'Your workshop or studio', 'Your products, with your brand name, label or packaging visible'],
        'tips' => ['Take the photo yourself, in good light.', 'Do not use pictures from other websites or stock images.']],
    8 => ['title' => 'Declaration', 'file' => '', 'label' => '', 'lead' => 'Review your documents, then confirm and submit. You can open each one to check it.'],
];

function verification_step_errors(int $step, array $details, array $documents, bool $registered): array
{
    $file = VERIFY_STEPS[$step]['file'] ?? '';
    if ($file !== '' && ($file !== 'registration' || $registered) && empty($documents[$file])) return ['file' => 'Upload this document before continuing.'];
    if ($step === 4 && ($details['social_verified'] ?? '') !== 'yes') return ['confirm' => 'Confirm that your submitted social accounts are current.'];
    if ($step === 5 && ($details['address_confirmed'] ?? '') !== 'yes') return ['confirm' => 'Confirm your submitted address.'];
    if ($step === 7 && trim((string) ($details['business_presence'] ?? '')) === '') return ['presence' => 'Describe your business presence.'];
    if ($step === 8 && ($details['declaration'] ?? '') !== 'yes') return ['confirm' => 'Confirm the verification declaration.'];
    return [];
}

// The seller's verification record; created the first time they open it after their application is approved.
function load_verification(string $userId, bool $create = true): ?array
{
    $db = db();
    $row = $db->one('SELECT * FROM seller_verifications WHERE user_id = ?', [$userId]);
    if (!$row && $create) {
        $app = $db->one("SELECT id FROM seller_applications WHERE user_id = ? AND status = 'approved'", [$userId]);
        if (!$app) return null;
        $now = now_db();
        try {
            $db->insert('seller_verifications', ['id' => uuid(), 'application_id' => $app['id'], 'user_id' => $userId, 'status' => 'draft', 'current_step' => 1, 'details' => '{}', 'documents' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        } catch (Throwable $e) {
            if (!Db::isDuplicate($e)) throw $e;
        }
        $row = $db->one('SELECT * FROM seller_verifications WHERE user_id = ?', [$userId]);
    }
    if (!$row) return null;
    $row['details'] = jdec($row['details'] ?? '', []);
    $row['documents'] = jdec($row['documents'] ?? '', []);
    return $row;
}
