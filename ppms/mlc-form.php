<?php
// ppms/mlc-form.php - Create / Edit / Delete Member of Legislative Council in `mlc`
define('PPMS_INIT', true);
require_once __DIR__ . '/includes/auth.php';

$adminUser = ppms_require_auth();
$pdo = ppms_db();

$editId = (int)($_GET['id'] ?? $_POST['mlc_id'] ?? 0);
$isEdit = $editId > 0;
$existing = null;

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM mlc WHERE id = ? LIMIT 1");
    $stmt->execute([$editId]);
    $existing = $stmt->fetch();
    if (!$existing) {
        ppms_set_flash('error', "MLC #{$editId} not found.");
        header('Location: ' . get_site_url('ppms/mlcs'), true, 302);
        exit;
    }
}

$states = ppms_get_states($pdo);
$stateNameByCode = [];
foreach ($states as $s) {
    $stateNameByCode[(string)$s['state_code']] = (string)$s['state'];
}

$partySuggestions = $pdo->query("SELECT DISTINCT party_name FROM mlc WHERE party_name <> '' ORDER BY party_name ASC")->fetchAll(PDO::FETCH_COLUMN);
$ctypeChoices = ['Elected by MLAs', 'Local Authorities', 'Graduates', 'Teachers', 'Nominated'];

$form = [
    'member_name' => $existing ? clean_mlc_display_name($existing['member_name']) : '',
    'slug' => $existing ? (string)($existing['slug'] ?? '') : '',
    'state_code' => $existing ? (string)($existing['state_code'] ?? '10') : '10',
    'status' => $existing ? strtoupper(trim((string)($existing['status'] ?: 'ACTIVE'))) : 'ACTIVE',
    'membership_status' => $existing ? trim((string)($existing['membership_status'] ?: 'Sitting')) : 'Sitting',
    'house' => $existing ? trim((string)($existing['house'] ?: 'Legislative Council')) : 'Legislative Council',
    'party_name' => $existing ? trim((string)($existing['party_name'] ?? '')) : '',
    'role_designation' => $existing ? trim((string)($existing['role_designation'] ?? '')) : '',
    'constituency' => $existing ? trim((string)($existing['constituency'] ?? '')) : '',
    'constituency_type' => $existing ? trim((string)($existing['constituency_type'] ?: 'Elected by MLAs')) : 'Elected by MLAs',
    'constituency_area' => $existing ? trim((string)($existing['constituency_area'] ?? '')) : '',
    'constituency_full' => $existing ? trim((string)($existing['constituency_full'] ?? '')) : '',
    'constituency_xls' => $existing ? trim((string)($existing['constituency_xls'] ?? '')) : '',
    'seat_no' => ($existing && $existing['seat_no'] !== null) ? (string)$existing['seat_no'] : '',
    'neva_member_id' => ($existing && $existing['neva_member_id'] !== null) ? (string)$existing['neva_member_id'] : '',
    'assembly_id' => $existing ? (string)($existing['assembly_id'] ?? '1') : '1',
    'constituency_code' => ($existing && $existing['constituency_code'] !== null) ? (string)$existing['constituency_code'] : '',
    'gender' => $existing ? trim((string)($existing['gender'] ?? '')) : '',
    'social_category' => $existing ? trim((string)($existing['social_category'] ?? '')) : '',
    'father_name' => $existing ? trim((string)($existing['father_name'] ?? '')) : '',
    'dob' => $existing ? trim((string)($existing['dob'] ?? '')) : '',
    'dob_date' => $existing ? trim((string)($existing['dob_date'] ?? '')) : '',
    'age' => ($existing && $existing['age'] !== null) ? (string)$existing['age'] : '',
    'marital_status' => $existing ? trim((string)($existing['marital_status'] ?? '')) : '',
    'spouse_name' => $existing ? trim((string)($existing['spouse_name'] ?? '')) : '',
    'children_details' => $existing ? trim((string)($existing['children_details'] ?? '')) : '',
    'highest_qualification' => $existing ? trim((string)($existing['highest_qualification'] ?? '')) : '',
    'other_qualification' => $existing ? trim((string)($existing['other_qualification'] ?? '')) : '',
    'hobbies' => $existing ? trim((string)($existing['hobbies'] ?? '')) : '',
    'term_period' => $existing ? trim((string)($existing['term_period'] ?? '')) : '',
    'mobile' => $existing ? trim((string)($existing['mobile'] ?? '')) : '',
    'alternate_mobile' => $existing ? trim((string)($existing['alternate_mobile'] ?? '')) : '',
    'office_tel' => $existing ? trim((string)($existing['office_tel'] ?? '')) : '',
    'email' => $existing ? trim((string)($existing['email'] ?? '')) : '',
    'local_address' => $existing ? trim((string)($existing['local_address'] ?? '')) : '',
    'permanent_address' => $existing ? trim((string)($existing['permanent_address'] ?? '')) : '',
    'photo_url' => $existing ? trim((string)($existing['photo_url'] ?? '')) : '',
    'source_url' => $existing ? trim((string)($existing['source_url'] ?? '')) : '',
    'sources' => $existing ? trim((string)($existing['sources'] ?: 'https://neva.gov.in/')) : 'https://neva.gov.in/',
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!ppms_verify_csrf($csrf)) {
        $errors[] = 'Security token expired. Please reload and resubmit.';
    } else {
        $postAction = trim((string)($_POST['form_action'] ?? 'save'));

        if ($postAction === 'delete' && $isEdit) {
            $delStmt = $pdo->prepare("DELETE FROM mlc WHERE id = ? LIMIT 1");
            $delStmt->execute([$editId]);
            $deletedName = clean_mlc_display_name($existing['member_name']);
            ppms_log_action($pdo, $adminUser, 'DELETE', $editId, $deletedName, "Deleted MLC ({$existing['constituency']}) from Edit form", 'mlc');
            ppms_set_flash('success', "MLC #{$editId} ({$deletedName}) deleted permanently.");
            header('Location: ' . get_site_url('ppms/mlcs'), true, 302);
            exit;
        }

        foreach (array_keys($form) as $fk) {
            $form[$fk] = trim((string)($_POST[$fk] ?? ''));
        }
        $form['member_name'] = clean_mlc_display_name($form['member_name']);
        $form['status'] = strtoupper($form['status'] ?: 'ACTIVE');

        if ($form['member_name'] === '') {
            $errors[] = 'MLC Member Name is required.';
        }
        if ($form['state_code'] === '' || !isset($stateNameByCode[$form['state_code']])) {
            $errors[] = 'Please select a valid State for this Legislative Council member.';
        }
        if ($form['constituency'] === '') {
            $errors[] = 'Constituency / MLC Seat Name is required.';
        }
        if ($form['constituency_type'] === '') {
            $errors[] = 'Constituency Type / Quota is required.';
        }
        if ($form['party_name'] === '') {
            $errors[] = 'Political Party Name is required (use "Independent" or "Nominated" if none).';
        }
        if (!in_array($form['status'], ['ACTIVE', 'INACTIVE'], true)) {
            $errors[] = 'Status must be ACTIVE or INACTIVE.';
        }
        if (!in_array($form['membership_status'], ['Sitting', 'Died', 'Resigned', 'Retired', 'Former'], true)) {
            $errors[] = 'Invalid Membership Status selected.';
        }

        $stateNameSave = $stateNameByCode[$form['state_code']] ?? '';

        // Optional numeric fields (empty -> NULL)
        $nevaMemberIdSave = null;
        if ($form['neva_member_id'] !== '') {
            if (!ctype_digit($form['neva_member_id'])) {
                $errors[] = 'NeVA Member ID must be a positive integer (or leave blank).';
            } else {
                $nevaMemberIdSave = (int)$form['neva_member_id'];
                $dupNeva = $pdo->prepare("SELECT id FROM mlc WHERE state_code = ? AND neva_member_id = ? AND id <> ? LIMIT 1");
                $dupNeva->execute([$form['state_code'], $nevaMemberIdSave, $editId]);
                if ($dupNeva->fetch()) {
                    $errors[] = "NeVA Member ID {$nevaMemberIdSave} is already assigned to another MLC in {$stateNameSave}.";
                }
            }
        }

        $seatNoSave = null;
        if ($form['seat_no'] !== '') {
            if (!ctype_digit($form['seat_no'])) {
                $errors[] = 'Seat No must be a whole number.';
            } else {
                $seatNoSave = (int)$form['seat_no'];
            }
        }

        $constCodeSave = null;
        if ($form['constituency_code'] !== '') {
            if (!ctype_digit($form['constituency_code'])) {
                $errors[] = 'Constituency Code must be a whole number.';
            } else {
                $constCodeSave = (int)$form['constituency_code'];
            }
        }

        $assemblyIdSave = ctype_digit($form['assembly_id']) ? (int)$form['assembly_id'] : 1;

        $ageSave = null;
        if ($form['age'] !== '') {
            if (!ctype_digit($form['age']) || (int)$form['age'] < 18 || (int)$form['age'] > 120) {
                $errors[] = 'Age must be an integer between 18 and 120.';
            } else {
                $ageSave = (int)$form['age'];
            }
        }

        $dobDateSave = null;
        if ($form['dob_date'] !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['dob_date'])) {
                $errors[] = 'ISO Date of Birth must use YYYY-MM-DD format.';
            } else {
                $dobDateSave = $form['dob_date'];
            }
        }

        if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email address format appears invalid.';
        }

        if ($form['photo_url'] !== '' && !filter_var($form['photo_url'], FILTER_VALIDATE_URL)) {
            $errors[] = 'Photo URL must be a valid https:// or http:// URL.';
        }
        if ($form['source_url'] !== '' && !filter_var($form['source_url'], FILTER_VALIDATE_URL)) {
            $errors[] = 'Member Profile Source URL must be a valid https:// or http:// URL.';
        }
        $sourcesSave = $form['sources'] !== '' ? $form['sources'] : 'https://neva.gov.in/';
        if (!preg_match('#^https?://#i', $sourcesSave)) {
            $sourcesSave = 'https://' . ltrim($sourcesSave, '/');
        }
        if (!filter_var($sourcesSave, FILTER_VALIDATE_URL)) {
            $errors[] = 'Portal Reference URL must be a valid web address.';
        }

        $resolvedSlug = '';
        if (empty($errors)) {
            $slugRes = ppms_build_unique_mlc_slug(
                $pdo,
                $form['slug'],
                $form['member_name'],
                $form['state_code'],
                $stateNameSave,
                $form['constituency_area'] ?: $form['constituency'],
                $form['constituency_type'],
                $editId
            );
            if (!$slugRes['ok']) {
                $errors[] = $slugRes['error'];
            } else {
                $resolvedSlug = $slugRes['slug'];
            }
        }

        if (empty($errors)) {
            $nn = static fn(string $v, ?int $max = null): ?string => $v === '' ? null : ($max !== null ? mb_substr($v, 0, $max, 'UTF-8') : $v);
            $houseSave = $form['house'] !== '' ? $form['house'] : ($stateNameSave . ' Legislative Council');

            if ($isEdit) {
                $upd = $pdo->prepare("
                    UPDATE mlc
                    SET neva_member_id = ?, assembly_id = ?, constituency_code = ?, seat_no = ?,
                        member_name = ?, slug = ?, party_name = ?, role_designation = ?,
                        constituency = ?, constituency_type = ?, constituency_area = ?, constituency_full = ?, constituency_xls = ?,
                        state = ?, state_code = ?, house = ?, membership_status = ?,
                        gender = ?, social_category = ?, father_name = ?, dob = ?, dob_date = ?, age = ?,
                        marital_status = ?, spouse_name = ?, children_details = ?, highest_qualification = ?, other_qualification = ?,
                        hobbies = ?, term_period = ?, mobile = ?, alternate_mobile = ?, office_tel = ?, email = ?,
                        local_address = ?, permanent_address = ?, photo_url = ?, source_url = ?, sources = ?, status = ?
                    WHERE id = ?
                ");
                $upd->execute([
                    $nevaMemberIdSave,
                    $assemblyIdSave,
                    $constCodeSave,
                    $seatNoSave,
                    mb_substr($form['member_name'], 0, 200, 'UTF-8'),
                    substr($resolvedSlug, 0, 255),
                    mb_substr($form['party_name'], 0, 200, 'UTF-8'),
                    $nn($form['role_designation'], 100),
                    mb_substr($form['constituency'], 0, 200, 'UTF-8'),
                    mb_substr($form['constituency_type'], 0, 100, 'UTF-8'),
                    $nn($form['constituency_area'], 150),
                    $nn($form['constituency_full'], 200),
                    $nn($form['constituency_xls'], 150),
                    mb_substr($stateNameSave, 0, 100, 'UTF-8'),
                    $form['state_code'],
                    mb_substr($houseSave, 0, 120, 'UTF-8'),
                    mb_substr($form['membership_status'], 0, 50, 'UTF-8'),
                    $nn($form['gender'], 20),
                    $nn($form['social_category'], 30),
                    $nn($form['father_name'], 200),
                    $nn($form['dob'], 50),
                    $dobDateSave,
                    $ageSave,
                    $nn($form['marital_status'], 50),
                    $nn($form['spouse_name'], 200),
                    $nn($form['children_details'], 255),
                    $nn($form['highest_qualification'], 150),
                    $nn($form['other_qualification']),
                    $nn($form['hobbies'], 150),
                    $nn($form['term_period'], 80),
                    $nn($form['mobile'], 30),
                    $nn($form['alternate_mobile'], 50),
                    $nn($form['office_tel'], 50),
                    $nn($form['email'], 150),
                    $nn($form['local_address']),
                    $nn($form['permanent_address']),
                    $nn($form['photo_url']),
                    $nn($form['source_url'], 255),
                    substr($sourcesSave, 0, 255),
                    $form['status'],
                    $editId,
                ]);
                ppms_log_action($pdo, $adminUser, 'UPDATE', $editId, $form['member_name'], "Updated MLC ({$form['constituency']}, {$stateNameSave})", 'mlc');
                ppms_set_flash('success', "MLC #{$editId} ({$form['member_name']}) updated successfully.");
                header('Location: ' . get_site_url('ppms/mlc-form?id=' . $editId), true, 302);
                exit;
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO mlc (
                        neva_member_id, assembly_id, constituency_code, seat_no,
                        member_name, slug, party_name, role_designation,
                        constituency, constituency_type, constituency_area, constituency_full, constituency_xls,
                        state, state_code, house, membership_status,
                        gender, social_category, father_name, dob, dob_date, age,
                        marital_status, spouse_name, children_details, highest_qualification, other_qualification,
                        hobbies, term_period, mobile, alternate_mobile, office_tel, email,
                        local_address, permanent_address, photo_url, source_url, sources, status
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                    )
                ");
                $ins->execute([
                    $nevaMemberIdSave,
                    $assemblyIdSave,
                    $constCodeSave,
                    $seatNoSave,
                    mb_substr($form['member_name'], 0, 200, 'UTF-8'),
                    substr($resolvedSlug, 0, 255),
                    mb_substr($form['party_name'], 0, 200, 'UTF-8'),
                    $nn($form['role_designation'], 100),
                    mb_substr($form['constituency'], 0, 200, 'UTF-8'),
                    mb_substr($form['constituency_type'], 0, 100, 'UTF-8'),
                    $nn($form['constituency_area'], 150),
                    $nn($form['constituency_full'], 200),
                    $nn($form['constituency_xls'], 150),
                    mb_substr($stateNameSave, 0, 100, 'UTF-8'),
                    $form['state_code'],
                    mb_substr($houseSave, 0, 120, 'UTF-8'),
                    mb_substr($form['membership_status'], 0, 50, 'UTF-8'),
                    $nn($form['gender'], 20),
                    $nn($form['social_category'], 30),
                    $nn($form['father_name'], 200),
                    $nn($form['dob'], 50),
                    $dobDateSave,
                    $ageSave,
                    $nn($form['marital_status'], 50),
                    $nn($form['spouse_name'], 200),
                    $nn($form['children_details'], 255),
                    $nn($form['highest_qualification'], 150),
                    $nn($form['other_qualification']),
                    $nn($form['hobbies'], 150),
                    $nn($form['term_period'], 80),
                    $nn($form['mobile'], 30),
                    $nn($form['alternate_mobile'], 50),
                    $nn($form['office_tel'], 50),
                    $nn($form['email'], 150),
                    $nn($form['local_address']),
                    $nn($form['permanent_address']),
                    $nn($form['photo_url']),
                    $nn($form['source_url'], 255),
                    substr($sourcesSave, 0, 255),
                    $form['status'],
                ]);
                $newId = (int)$pdo->lastInsertId();
                ppms_log_action($pdo, $adminUser, 'CREATE', $newId, $form['member_name'], "Created MLC ({$form['constituency']}, {$stateNameSave})", 'mlc');
                ppms_set_flash('success', "Added new MLC #{$newId}: {$form['member_name']}!");
                header('Location: ' . get_site_url('ppms/mlc-form?id=' . $newId), true, 302);
                exit;
            }
        }
    }
}

$activeNav = $isEdit ? 'mlcs' : 'mlc-form';
$ppmsPageTitle = $isEdit ? ('Edit MLC #' . $editId) : 'Add New Council MLC';
require_once __DIR__ . '/includes/layout_top.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <a href="<?= e(get_site_url('ppms/mlcs')) ?>" class="btn btn-light border btn-sm fw-semibold">
        <i class="fas fa-arrow-left me-1"></i> Back to All MLCs
    </a>
    <?php if ($isEdit): ?>
        <a href="<?= e(get_mlc_page_url($form['slug'], $editId)) ?>" target="_blank" rel="noopener" class="btn btn-ppms-green btn-sm">
            <i class="fas fa-external-link-alt me-1"></i> View Public MLC Page
        </a>
    <?php endif; ?>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger border-0 shadow-sm mb-3">
        <div class="fw-bold small mb-1"><i class="fas fa-exclamation-triangle me-1"></i> Please fix the following issues:</div>
        <ul class="mb-0 small ps-3">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="POST" action="<?= e(get_site_url('ppms/mlc-form' . ($isEdit ? '?id=' . $editId : ''))) ?>">
    <input type="hidden" name="csrf_token" value="<?= e(ppms_csrf_token()) ?>">
    <input type="hidden" name="mlc_id" value="<?= $editId ?>">
    <input type="hidden" name="form_action" value="save">

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="ppms-card">
                <div class="ppms-card-header">
                    <h2 class="ppms-card-title">
                        <i class="fas fa-scroll text-primary"></i>
                        <?= $isEdit ? 'Edit Legislative Council Member (MLC)' : 'Register New Council MLC' ?>
                    </h2>
                    <?php if ($isEdit): ?>
                        <span class="badge bg-light text-dark border">MLC #<?= $editId ?></span>
                    <?php endif; ?>
                </div>
                <div class="ppms-card-body">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label for="mlcMemberName" class="form-label small fw-bold">MLC Full Name <span class="text-danger">*</span></label>
                            <input type="text" id="mlcMemberName" name="member_name" value="<?= e($form['member_name']) ?>" class="form-control" required placeholder="e.g. Prof. (Dr.) Nawal Kishore Yadav">
                        </div>

                        <div class="col-md-12">
                            <label for="mlcSlug" class="form-label small fw-bold">Prefixed URL Slug (SEO Path)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light small text-muted">/mlc/</span>
                                <input type="text" id="mlcSlug" name="slug" value="<?= e($form['slug']) ?>" class="form-control" placeholder="Auto-generated with state prefix (br-, up-, ap-...)">
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnRegenMlcSlug">
                                    <i class="fas fa-sync-alt me-1"></i> Generate
                                </button>
                            </div>
                            <div class="form-text">Prefix is enforced automatically based on State (<code>br-</code> Bihar, <code>up-</code> UP, <code>ap-</code> Andhra Pradesh).</div>
                        </div>

                        <div class="col-md-4">
                            <label for="mlcState" class="form-label small fw-bold">State <span class="text-danger">*</span></label>
                            <select id="mlcState" name="state_code" class="form-select" required>
                                <option value="">Select State</option>
                                <?php foreach ($states as $st): ?>
                                    <option value="<?= e((string)$st['state_code']) ?>" data-state-name="<?= e($st['state']) ?>" <?= $form['state_code'] === (string)$st['state_code'] ? 'selected' : '' ?>>
                                        <?= e($st['state']) ?> (<?= e($st['state_code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label for="mlcStatus" class="form-label small fw-bold">Directory Status <span class="text-danger">*</span></label>
                            <select id="mlcStatus" name="status" class="form-select" required>
                                <option value="ACTIVE" <?= $form['status'] === 'ACTIVE' ? 'selected' : '' ?>>ACTIVE</option>
                                <option value="INACTIVE" <?= $form['status'] === 'INACTIVE' ? 'selected' : '' ?>>INACTIVE</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label for="mlcMemberStatus" class="form-label small fw-bold">Membership Status</label>
                            <select id="mlcMemberStatus" name="membership_status" class="form-select">
                                <?php foreach (['Sitting', 'Died', 'Resigned', 'Retired', 'Former'] as $ms): ?>
                                    <option value="<?= e($ms) ?>" <?= $form['membership_status'] === $ms ? 'selected' : '' ?>><?= e($ms) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="mlcConstituency" class="form-label small fw-bold">Constituency Display <span class="text-danger">*</span></label>
                            <input type="text" id="mlcConstituency" name="constituency" value="<?= e($form['constituency']) ?>" class="form-control" required placeholder="e.g. Patna (Teachers) or Elected by MLAs">
                        </div>

                        <div class="col-md-6">
                            <label for="mlcCtype" class="form-label small fw-bold">Constituency Quota Type <span class="text-danger">*</span></label>
                            <select id="mlcCtype" name="constituency_type" class="form-select" required>
                                <?php foreach ($ctypeChoices as $ct): ?>
                                    <option value="<?= e($ct) ?>" <?= $form['constituency_type'] === $ct ? 'selected' : '' ?>><?= e($ct) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="mlcParty" class="form-label small fw-bold">Political Party <span class="text-danger">*</span></label>
                            <input type="text" id="mlcParty" name="party_name" value="<?= e($form['party_name']) ?>" list="mlcPartyOptions" class="form-control" required placeholder="e.g. Bharatiya Janata Party">
                            <datalist id="mlcPartyOptions">
                                <?php foreach ($partySuggestions as $ps): ?>
                                    <option value="<?= e((string)$ps) ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>

                        <div class="col-md-6">
                            <label for="mlcRole" class="form-label small fw-bold">Portfolio / Council Role</label>
                            <input type="text" id="mlcRole" name="role_designation" value="<?= e($form['role_designation']) ?>" class="form-control" placeholder="e.g. Deputy Chief Minister, Chairman, Minister">
                        </div>

                        <div class="col-md-4">
                            <label for="mlcArea" class="form-label small fw-bold">Constituency Area</label>
                            <input type="text" id="mlcArea" name="constituency_area" value="<?= e($form['constituency_area']) ?>" class="form-control" placeholder="e.g. Patna Division">
                        </div>

                        <div class="col-md-4">
                            <label for="mlcFull" class="form-label small fw-bold">Constituency Full Label</label>
                            <input type="text" id="mlcFull" name="constituency_full" value="<?= e($form['constituency_full']) ?>" class="form-control">
                        </div>

                        <div class="col-md-4">
                            <label for="mlcHouse" class="form-label small fw-bold">Council House</label>
                            <input type="text" id="mlcHouse" name="house" value="<?= e($form['house']) ?>" class="form-control" placeholder="e.g. Bihar Legislative Council">
                        </div>

                        <div class="col-md-3">
                            <label for="mlcSeatNo" class="form-label small fw-bold">Seat No</label>
                            <input type="number" id="mlcSeatNo" name="seat_no" value="<?= e($form['seat_no']) ?>" class="form-control">
                        </div>

                        <div class="col-md-3">
                            <label for="mlcNevaId" class="form-label small fw-bold">NeVA Member ID</label>
                            <input type="number" id="mlcNevaId" name="neva_member_id" value="<?= e($form['neva_member_id']) ?>" class="form-control" placeholder="Optional">
                        </div>

                        <div class="col-md-3">
                            <label for="mlcConstCode" class="form-label small fw-bold">Constituency Code</label>
                            <input type="number" id="mlcConstCode" name="constituency_code" value="<?= e($form['constituency_code']) ?>" class="form-control">
                        </div>

                        <div class="col-md-3">
                            <label for="mlcTermPeriod" class="form-label small fw-bold">Term Period</label>
                            <input type="text" id="mlcTermPeriod" name="term_period" value="<?= e($form['term_period']) ?>" class="form-control" placeholder="e.g. 2022-2028">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Personal & Contact Details -->
            <div class="ppms-card mb-0">
                <div class="ppms-card-header">
                    <h3 class="ppms-card-title"><i class="fas fa-address-card text-success"></i> Biography, Masked Public Contacts &amp; Addresses</h3>
                </div>
                <div class="ppms-card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Gender</label>
                            <input type="text" name="gender" value="<?= e($form['gender']) ?>" class="form-control form-control-sm" placeholder="Male / Female">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Father's Name</label>
                            <input type="text" name="father_name" value="<?= e($form['father_name']) ?>" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Highest Qualification</label>
                            <input type="text" name="highest_qualification" value="<?= e($form['highest_qualification']) ?>" class="form-control form-control-sm">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Date of Birth (Display)</label>
                            <input type="text" name="dob" value="<?= e($form['dob']) ?>" class="form-control form-control-sm" placeholder="e.g. 05 Jul 1962">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Date of Birth (ISO)</label>
                            <input type="text" name="dob_date" value="<?= e($form['dob_date']) ?>" class="form-control form-control-sm" placeholder="YYYY-MM-DD">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Age</label>
                            <input type="number" name="age" value="<?= e($form['age']) ?>" min="18" max="120" class="form-control form-control-sm">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Marital Status</label>
                            <input type="text" name="marital_status" value="<?= e($form['marital_status']) ?>" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Spouse Name</label>
                            <input type="text" name="spouse_name" value="<?= e($form['spouse_name']) ?>" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Social Category</label>
                            <input type="text" name="social_category" value="<?= e($form['social_category']) ?>" class="form-control form-control-sm">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Mobile (Auto-masked on public pages)</label>
                            <input type="text" name="mobile" value="<?= e($form['mobile']) ?>" class="form-control form-control-sm" placeholder="10-digit number">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Email (Auto-masked on public pages)</label>
                            <input type="email" name="email" value="<?= e($form['email']) ?>" class="form-control form-control-sm">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Alternate Mobile</label>
                            <input type="text" name="alternate_mobile" value="<?= e($form['alternate_mobile']) ?>" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Office Telephone</label>
                            <input type="text" name="office_tel" value="<?= e($form['office_tel']) ?>" class="form-control form-control-sm">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Local / Official Address</label>
                            <textarea name="local_address" rows="2" class="form-control form-control-sm"><?= e($form['local_address']) ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Permanent Address</label>
                            <textarea name="permanent_address" rows="2" class="form-control form-control-sm"><?= e($form['permanent_address']) ?></textarea>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Other Qualifications / Hobbies</label>
                            <input type="text" name="other_qualification" value="<?= e($form['other_qualification']) ?>" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Children Details</label>
                            <input type="text" name="children_details" value="<?= e($form['children_details']) ?>" class="form-control form-control-sm">
                        </div>

                        <div class="col-md-12">
                            <label for="mlcPhotoUrl" class="form-label small fw-bold">Portrait URL</label>
                            <input type="url" id="mlcPhotoUrl" name="photo_url" value="<?= e($form['photo_url']) ?>" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Member Profile Source URL</label>
                            <input type="url" name="source_url" value="<?= e($form['source_url']) ?>" class="form-control form-control-sm" placeholder="https://blc.neva.gov.in/...">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Portal Base Source URL</label>
                            <input type="url" name="sources" value="<?= e($form['sources']) ?>" class="form-control form-control-sm">
                        </div>

                        <input type="hidden" name="assembly_id" value="<?= e($form['assembly_id']) ?>">
                        <input type="hidden" name="constituency_xls" value="<?= e($form['constituency_xls']) ?>">
                        <input type="hidden" name="hobbies" value="<?= e($form['hobbies']) ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="ppms-card">
                <div class="ppms-card-header">
                    <h3 class="ppms-card-title"><i class="fas fa-user-tie text-info"></i> Portrait Preview</h3>
                </div>
                <div class="ppms-card-body text-center">
                    <img id="mlcPhotoPreview"
                         src="<?= e($form['photo_url'] !== '' ? $form['photo_url'] : get_site_url('assets/images/dummy-user.png')) ?>"
                         alt="MLC Portrait"
                         onerror="this.src='<?= e(get_site_url('assets/images/dummy-user.png')) ?>'"
                         style="width:110px; height:110px; object-fit:cover; border-radius:14px; border:1px solid var(--ppms-border); background:#F8FAFC;">
                    <div class="small text-muted mt-2">
                        Public pages automatically mask mobile &amp; email unless a user signs in to unlock them.
                    </div>
                </div>
            </div>

            <div class="ppms-card">
                <div class="ppms-card-header">
                    <h3 class="ppms-card-title"><i class="fas fa-save text-primary"></i> Publish &amp; Actions</h3>
                </div>
                <div class="ppms-card-body">
                    <button type="submit" class="btn btn-ppms-saffron w-100 py-2 mb-2">
                        <i class="fas fa-save me-1"></i> <?= $isEdit ? 'Save MLC Changes' : 'Create MLC Record' ?>
                    </button>
                    <a href="<?= e(get_site_url('ppms/mlcs')) ?>" class="btn btn-light border w-100 btn-sm fw-semibold">
                        Cancel
                    </a>

                    <?php if ($isEdit && $existing): ?>
                        <hr class="my-3">
                        <div class="small text-muted mb-1">Created: <strong><?= e((string)$existing['created_at']) ?></strong></div>
                        <div class="small text-muted mb-3">Updated: <strong><?= e((string)$existing['updated_at']) ?></strong></div>
                        <button type="button" class="btn btn-outline-danger btn-sm w-100" data-bs-toggle="modal" data-bs-target="#deleteSingleMlcModal">
                            <i class="fas fa-trash-alt me-1"></i> Delete This MLC
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</form>

<?php if ($isEdit): ?>
<div class="modal fade" id="deleteSingleMlcModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold fs-6"><i class="fas fa-trash-alt me-2"></i> Delete MLC #<?= $editId ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body small">
                Permanently delete <strong><?= e($form['member_name']) ?></strong> (<?= e($form['constituency']) ?>) from <code>mlc</code>?
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light border btn-sm" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="<?= e(get_site_url('ppms/mlc-form?id=' . $editId)) ?>" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= e(ppms_csrf_token()) ?>">
                    <input type="hidden" name="mlc_id" value="<?= $editId ?>">
                    <input type="hidden" name="form_action" value="delete">
                    <button type="submit" class="btn btn-danger btn-sm fw-bold">Delete Permanently</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$isEditJs = $isEdit ? 'true' : 'false';
$ppmsExtraScripts = <<<HTML
<script>
const MLC_IS_EDIT = {$isEditJs};
const MLC_STATE_PREFIX = {'9':'up','10':'br','28':'ap','27':'mh','29':'ka','36':'ts'};

function getMlcPrefix(stateCode, stateName) {
    const code = (stateCode || '').toString().replace(/^0+/, '');
    if (MLC_STATE_PREFIX[code]) return MLC_STATE_PREFIX[code];
    const clean = (stateName || '').toLowerCase().replace(/[^a-z]/g, '');
    return clean.length >= 2 ? clean.slice(0, 2) : 'in';
}

function slugifyMlcName(raw, stateCode, stateName) {
    let text = (raw || '').toString().trim();
    text = text.replace(/^(?:\(?\s*(?:Sri|Shri|Sh|Smt|Dr|Prof|Ms|Mrs|Mr|Km|Kumari|Adv|Er|Thiru|Com)\.?\s*\)?\s+)+/i, '');
    let nameSlug = text
        .replace(/['’`*#]/g, '')
        .replace(/&/g, ' and ')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 120);
    const pref = getMlcPrefix(stateCode, stateName);
    if (!nameSlug) return pref;
    return nameSlug.startsWith(pref + '-') ? nameSlug : (pref + '-' + nameSlug);
}

document.addEventListener('DOMContentLoaded', function () {
    const nameEl = document.getElementById('mlcMemberName');
    const slugEl = document.getElementById('mlcSlug');
    const stateEl = document.getElementById('mlcState');
    const regenBtn = document.getElementById('btnRegenMlcSlug');
    const photoInp = document.getElementById('mlcPhotoUrl');
    const photoPrev = document.getElementById('mlcPhotoPreview');

    let touchedSlug = MLC_IS_EDIT || (slugEl && slugEl.value.trim() !== '');
    const buildSlug = () => {
        const opt = stateEl?.options[stateEl.selectedIndex];
        const stName = opt ? (opt.getAttribute('data-state-name') || opt.textContent) : '';
        return slugifyMlcName(nameEl?.value || '', stateEl?.value || '', stName);
    };

    if (slugEl) {
        slugEl.addEventListener('input', () => { touchedSlug = slugEl.value.trim() !== ''; });
    }
    if (nameEl && slugEl) {
        nameEl.addEventListener('input', () => {
            if (!touchedSlug) slugEl.value = buildSlug();
        });
    }
    if (stateEl && slugEl) {
        stateEl.addEventListener('change', () => {
            if (!touchedSlug) slugEl.value = buildSlug();
        });
    }
    if (regenBtn && slugEl) {
        regenBtn.addEventListener('click', () => {
            slugEl.value = buildSlug();
        });
    }
    if (photoInp && photoPrev) {
        photoInp.addEventListener('input', () => {
            const val = photoInp.value.trim();
            if (/^https?:\/\//i.test(val)) {
                photoPrev.src = val;
            }
        });
    }
});
</script>
HTML;
require_once __DIR__ . '/includes/layout_bottom.php';
?>
