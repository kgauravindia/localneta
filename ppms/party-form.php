<?php
// ppms/party-form.php - Create / Edit Political Party Record in `political_party`
define('PPMS_INIT', true);
require_once __DIR__ . '/includes/auth.php';

$adminUser = ppms_require_auth();
$pdo = ppms_db();

$editId = (int)($_GET['id'] ?? $_POST['party_id'] ?? 0);
$isEdit = $editId > 0;
$existing = null;

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM political_party WHERE id = ? LIMIT 1");
    $stmt->execute([$editId]);
    $existing = $stmt->fetch();
    if (!$existing) {
        ppms_set_flash('error', "Political party #{$editId} not found.");
        header('Location: ' . get_site_url('ppms/parties'), true, 302);
        exit;
    }
}

$states = ppms_get_states($pdo);
$stateNameByCode = [];
foreach ($states as $s) {
    $stateNameByCode[(string)$s['state_code']] = (string)$s['state'];
}
$symbolCatalog = ppms_get_symbol_catalog();
$symbolBaseUrl = get_site_url('assets/images/symbols');

$defaultStateCode = trim((string)($_GET['state_code'] ?? ''));
if ($defaultStateCode !== '' && !isset($stateNameByCode[$defaultStateCode])) {
    $defaultStateCode = '';
}

$form = [
    'no' => $existing ? (string)($existing['no'] ?? '') : '',
    'type' => $existing ? (clean_party_display_text($existing['type']) ?: 'Registered') : 'Registered',
    'status' => $existing ? (strtoupper(clean_party_display_text($existing['status'])) ?: 'ACTIVE') : 'ACTIVE',
    'state_code' => $existing ? (string)($existing['state_code'] ?? '') : $defaultStateCode,
    'name' => $existing ? clean_party_display_name($existing['name']) : '',
    'name_hi' => $existing ? clean_party_display_text($existing['name_hi'] ?? '') : '',
    'slug' => $existing ? (string)($existing['slug'] ?? '') : '',
    'symbol' => $existing ? clean_party_display_text($existing['symbol'] ?? '') : '',
    'last_update' => $existing ? clean_party_display_text($existing['last_update'] ?? '') : date('d-m-y'),
    'url' => $existing ? trim((string)($existing['url'] ?? '')) : '',
    'mobile' => $existing ? trim((string)($existing['mobile'] ?? '')) : '',
    'whatsapp' => $existing ? trim((string)($existing['whatsapp'] ?? '')) : '',
    'misscall' => $existing ? trim((string)($existing['misscall'] ?? '')) : '',
    'email' => $existing ? trim((string)($existing['email'] ?? '')) : '',
    'facebook' => $existing ? trim((string)($existing['facebook'] ?? '')) : '',
    'instagram' => $existing ? trim((string)($existing['instagram'] ?? '')) : '',
    'x' => $existing ? trim((string)($existing['x'] ?? '')) : '',
    'youtube' => $existing ? trim((string)($existing['youtube'] ?? '')) : '',
    'linkedin' => $existing ? trim((string)($existing['linkedin'] ?? '')) : '',
    'address' => $existing ? clean_party_display_text($existing['address'] ?? '') : '',
    'address_hi' => $existing ? clean_party_display_text($existing['address_hi'] ?? '') : '',
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!ppms_verify_csrf($csrf)) {
        $errors[] = 'Security token expired. Please reload and resubmit.';
    } else {
        $postAction = trim((string)($_POST['form_action'] ?? 'save'));

        if ($postAction === 'delete' && $isEdit) {
            $delStmt = $pdo->prepare("DELETE FROM political_party WHERE id = ? LIMIT 1");
            $delStmt->execute([$editId]);
            $deletedName = clean_party_display_name($existing['name']);
            ppms_log_action($pdo, $adminUser, 'DELETE', $editId, $deletedName, 'Deleted from Edit form');
            ppms_set_flash('success', "Political party #{$editId} ({$deletedName}) deleted.");
            header('Location: ' . get_site_url('ppms/parties'), true, 302);
            exit;
        }

        $form['no'] = trim((string)($_POST['no'] ?? ''));
        $form['type'] = trim((string)($_POST['type'] ?? 'Registered'));
        $form['status'] = strtoupper(trim((string)($_POST['status'] ?? 'ACTIVE')));
        $form['state_code'] = trim((string)($_POST['state_code'] ?? ''));
        $form['name'] = clean_party_display_name((string)($_POST['name'] ?? ''));
        $form['name_hi'] = clean_party_display_text((string)($_POST['name_hi'] ?? ''));
        $form['slug'] = trim((string)($_POST['slug'] ?? ''));
        $form['symbol'] = clean_party_display_text((string)($_POST['symbol'] ?? ''));
        $form['last_update'] = trim((string)($_POST['last_update'] ?? ''));
        $form['url'] = trim((string)($_POST['url'] ?? ''));
        $form['mobile'] = clean_party_display_text((string)($_POST['mobile'] ?? ''));
        $form['whatsapp'] = clean_party_display_text((string)($_POST['whatsapp'] ?? ''));
        $form['misscall'] = clean_party_display_text((string)($_POST['misscall'] ?? ''));
        $form['email'] = strtolower(clean_party_display_text((string)($_POST['email'] ?? '')));
        $form['facebook'] = trim((string)($_POST['facebook'] ?? ''));
        $form['instagram'] = trim((string)($_POST['instagram'] ?? ''));
        $form['x'] = trim((string)($_POST['x'] ?? ''));
        $form['youtube'] = trim((string)($_POST['youtube'] ?? ''));
        $form['linkedin'] = trim((string)($_POST['linkedin'] ?? ''));
        $form['address'] = clean_party_display_text((string)($_POST['address'] ?? ''));
        $form['address_hi'] = clean_party_display_text((string)($_POST['address_hi'] ?? ''));

        if ($form['name'] === '') {
            $errors[] = 'Party English Name is required.';
        }
        if (!in_array($form['type'], ['National', 'State', 'Registered'], true)) {
            $errors[] = 'Please choose a valid Recognition Type.';
        }
        if (!in_array($form['status'], ['ACTIVE', 'INACTIVE', 'DELISTED'], true)) {
            $errors[] = 'Please choose a valid ECI Status.';
        }

        // State validation
        if ($form['type'] === 'National') {
            if ($form['state_code'] === '' || !isset($stateNameByCode[$form['state_code']])) {
                $form['state_code'] = '7'; // Default HQ Delhi for National parties if omitted
            }
            $stateNameToSave = 'ALL';
        } else {
            if ($form['state_code'] === '' || !isset($stateNameByCode[$form['state_code']])) {
                $errors[] = 'Please select a valid State / UT for State or Registered parties.';
                $stateNameToSave = '';
            } else {
                $stateNameToSave = $stateNameByCode[$form['state_code']];
            }
        }

        // Website URL validation
        if ($form['url'] !== '') {
            $normWeb = normalize_party_social_url($form['url'], 'web');
            if ($normWeb === '') {
                $errors[] = 'Official Website URL is not a valid web address.';
            } else {
                $form['url'] = $normWeb;
            }
        }

        // Contact & Social channels validation
        if ($form['mobile'] !== '') {
            $mobDigits = preg_replace('/\D+/', '', $form['mobile']) ?? '';
            if (strlen($mobDigits) < 5 || !preg_match('/^[0-9+\-\s(),\/]+$/', $form['mobile'])) {
                $errors[] = 'Mobile / Helpline No. must be a valid phone number (at least 5 digits).';
            }
        }
        if ($form['misscall'] !== '') {
            $mcDigits = preg_replace('/\D+/', '', $form['misscall']) ?? '';
            if (strlen($mcDigits) < 5 || !preg_match('/^[0-9+\-\s(),]+$/', $form['misscall'])) {
                $errors[] = 'Missed Call No. must be a valid helpline/toll-free number (at least 5 digits).';
            }
        }
        if ($form['whatsapp'] !== '') {
            if (preg_match('#^(?:https?://|(?:www\.|api\.|chat\.)?(?:wa\.me|whatsapp\.com)/)#i', $form['whatsapp'])) {
                $normWa = normalize_party_social_url($form['whatsapp'], 'whatsapp');
                $waHost = strtolower((string)parse_url($normWa, PHP_URL_HOST));
                if ($normWa === '' || !preg_match('/^(?:wa\.me|(?:[a-z0-9-]+\.)*whatsapp\.com)$/', $waHost)) {
                    $errors[] = 'WhatsApp link must be a valid https://wa.me or whatsapp.com URL.';
                } else {
                    $form['whatsapp'] = $normWa;
                }
            } else {
                $waDigits = preg_replace('/\D+/', '', $form['whatsapp']) ?? '';
                if (strlen($waDigits) < 7 || !preg_match('/^[0-9+\-\s()]+$/', $form['whatsapp'])) {
                    $errors[] = 'WhatsApp must be a valid WhatsApp number (at least 7 digits) or a WhatsApp link.';
                }
            }
        }
        if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Official Email ID is not a valid email address.';
        }

        foreach ([
            'facebook' => 'Facebook URL',
            'instagram' => 'Instagram URL',
            'x' => 'X (Twitter) URL',
            'youtube' => 'YouTube URL',
            'linkedin' => 'LinkedIn URL',
        ] as $socCol => $socLabel) {
            if ($form[$socCol] !== '') {
                $normSoc = normalize_party_social_url($form[$socCol], $socCol);
                if ($normSoc === '') {
                    $errors[] = "{$socLabel} is invalid. Enter a valid URL or @handle.";
                } else {
                    $form[$socCol] = $normSoc;
                }
            }
        }

        // Optional PNG Symbol Upload
        if (empty($errors) && isset($_FILES['symbol_upload']) && is_array($_FILES['symbol_upload']) && (int)$_FILES['symbol_upload']['error'] !== UPLOAD_ERR_NO_FILE) {
            $upErr = (int)$_FILES['symbol_upload']['error'];
            if ($upErr !== UPLOAD_ERR_OK) {
                $errors[] = 'Symbol PNG upload failed (error code ' . $upErr . ').';
            } elseif ((int)$_FILES['symbol_upload']['size'] > 512 * 1024) {
                $errors[] = 'Uploaded symbol image must be 512 KB or smaller.';
            } else {
                $tmpPath = (string)$_FILES['symbol_upload']['tmp_name'];
                $imgType = @exif_imagetype($tmpPath);
                if ($imgType !== IMAGETYPE_PNG) {
                    $errors[] = 'Uploaded symbol file must be a valid PNG image.';
                } else {
                    $symLabelBase = $form['symbol'] !== ''
                        ? $form['symbol']
                        : pathinfo((string)$_FILES['symbol_upload']['name'], PATHINFO_FILENAME);
                    $shortTitle = preg_replace('/[\*#\[].*$/u', '', $symLabelBase) ?? $symLabelBase;
                    $shortTitle = trim($shortTitle, " \t\n\r\0\x0B.,");
                    $symKey = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(str_replace('&', ' and ', $shortTitle))) ?? '', '_');
                    if ($symKey === '') {
                        $errors[] = 'Please specify a valid Symbol Name before uploading a PNG icon.';
                    } else {
                        $destDir = __DIR__ . '/../../assets/images/symbols/';
                        if (!is_dir($destDir)) {
                            mkdir($destDir, 0755, true);
                        }
                        $destFile = $destDir . $symKey . '.png';
                        if (!move_uploaded_file($tmpPath, $destFile)) {
                            $errors[] = 'Unable to save uploaded PNG symbol to symbols directory.';
                        } elseif ($form['symbol'] === '') {
                            $form['symbol'] = ucwords(str_replace('_', ' ', $symKey));
                        }
                    }
                }
            }
        }

        // Slug uniqueness validation / auto-generation
        $resolvedSlug = '';
        if (empty($errors)) {
            $slugResult = ppms_build_unique_slug($pdo, $form['slug'], $form['name'], $stateNameToSave, $editId);
            if (!$slugResult['ok']) {
                $errors[] = $slugResult['error'];
            } else {
                $resolvedSlug = $slugResult['slug'];
            }
        }

        if (empty($errors)) {
            $adminId = (int)$adminUser['id'];
            $noSave = $form['no'] !== '' ? substr($form['no'], 0, 10) : null;
            $nameHiSave = $form['name_hi'] !== '' ? $form['name_hi'] : null;
            $symbolSave = $form['symbol'] !== '' ? $form['symbol'] : null;
            $addrSave = $form['address'] !== '' ? $form['address'] : null;
            $addrHiSave = $form['address_hi'] !== '' ? $form['address_hi'] : null;
            $updDateSave = $form['last_update'] !== '' ? substr($form['last_update'], 0, 20) : null;
            $mobileSave = $form['mobile'] !== '' ? substr($form['mobile'], 0, 40) : null;
            $whatsappSave = $form['whatsapp'] !== '' ? substr($form['whatsapp'], 0, 120) : null;
            $misscallSave = $form['misscall'] !== '' ? substr($form['misscall'], 0, 40) : null;
            $emailSave = $form['email'] !== '' ? substr($form['email'], 0, 150) : null;
            $facebookSave = $form['facebook'] !== '' ? substr($form['facebook'], 0, 255) : null;
            $instagramSave = $form['instagram'] !== '' ? substr($form['instagram'], 0, 255) : null;
            $xSave = $form['x'] !== '' ? substr($form['x'], 0, 255) : null;
            $youtubeSave = $form['youtube'] !== '' ? substr($form['youtube'], 0, 255) : null;
            $linkedinSave = $form['linkedin'] !== '' ? substr($form['linkedin'], 0, 255) : null;

            if ($isEdit) {
                $updStmt = $pdo->prepare("
                    UPDATE political_party
                    SET no = ?,
                        state = ?,
                        name = ?,
                        slug = ?,
                        name_hi = ?,
                        symbol = ?,
                        address = ?,
                        address_hi = ?,
                        last_update = ?,
                        type = ?,
                        state_code = ?,
                        url = ?,
                        mobile = ?,
                        whatsapp = ?,
                        misscall = ?,
                        email = ?,
                        facebook = ?,
                        instagram = ?,
                        x = ?,
                        youtube = ?,
                        linkedin = ?,
                        status = ?,
                        updated_by = ?
                    WHERE id = ?
                ");
                $updStmt->execute([
                    $noSave,
                    $stateNameToSave,
                    $form['name'],
                    $resolvedSlug,
                    $nameHiSave,
                    $symbolSave,
                    $addrSave,
                    $addrHiSave,
                    $updDateSave,
                    $form['type'],
                    $form['state_code'],
                    $form['url'],
                    $mobileSave,
                    $whatsappSave,
                    $misscallSave,
                    $emailSave,
                    $facebookSave,
                    $instagramSave,
                    $xSave,
                    $youtubeSave,
                    $linkedinSave,
                    $form['status'],
                    $adminId,
                    $editId,
                ]);
                ppms_log_action($pdo, $adminUser, 'UPDATE', $editId, $form['name'], "Updated {$form['type']} ({$form['status']})");
                ppms_set_flash('success', "Political party #{$editId} ({$form['name']}) updated successfully.");
                header('Location: ' . get_site_url('ppms/party-form?id=' . $editId), true, 302);
                exit;
            } else {
                $insStmt = $pdo->prepare("
                    INSERT INTO political_party
                        (no, state, name, slug, name_hi, symbol, address, address_hi, last_update, type, state_code, created_by, updated_by, url, mobile, whatsapp, misscall, email, facebook, instagram, x, youtube, linkedin, status)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $insStmt->execute([
                    $noSave,
                    $stateNameToSave,
                    $form['name'],
                    $resolvedSlug,
                    $nameHiSave,
                    $symbolSave,
                    $addrSave,
                    $addrHiSave,
                    $updDateSave,
                    $form['type'],
                    $form['state_code'],
                    $adminId,
                    $adminId,
                    $form['url'],
                    $mobileSave,
                    $whatsappSave,
                    $misscallSave,
                    $emailSave,
                    $facebookSave,
                    $instagramSave,
                    $xSave,
                    $youtubeSave,
                    $linkedinSave,
                    $form['status'],
                ]);
                $newId = (int)$pdo->lastInsertId();
                ppms_log_action($pdo, $adminUser, 'CREATE', $newId, $form['name'], "Created {$form['type']} ({$form['status']})");
                ppms_set_flash('success', "Added new political party #{$newId}: {$form['name']}!");
                header('Location: ' . get_site_url('ppms/party-form?id=' . $newId), true, 302);
                exit;
            }
        }
    }
}

$matchedSymbol = get_party_symbol_image($form['symbol']);
$activeNav = $isEdit ? 'parties' : 'party-form';
$ppmsPageTitle = $isEdit ? ('Edit Party #' . $editId) : 'Add New Political Party';
require_once __DIR__ . '/includes/layout_top.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <a href="<?= e(get_site_url('ppms/parties')) ?>" class="btn btn-light border btn-sm fw-semibold">
        <i class="fas fa-arrow-left me-1"></i> Back to All Parties
    </a>
    <?php if ($isEdit): ?>
        <div class="d-flex gap-2">
            <a href="<?= e(get_party_page_url($form['slug'], $editId)) ?>" target="_blank" rel="noopener" class="btn btn-ppms-green btn-sm">
                <i class="fas fa-external-link-alt me-1"></i> View Public Party Page
            </a>
        </div>
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

<form method="POST" action="<?= e(get_site_url('ppms/party-form' . ($isEdit ? '?id=' . $editId : ''))) ?>" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= e(ppms_csrf_token()) ?>">
    <input type="hidden" name="party_id" value="<?= $editId ?>">
    <input type="hidden" name="form_action" value="save">

    <div class="row g-3">
        <!-- Left Main Details -->
        <div class="col-lg-8">
            <div class="ppms-card">
                <div class="ppms-card-header">
                    <h2 class="ppms-card-title">
                        <i class="fas fa-flag text-warning"></i>
                        <?= $isEdit ? 'Edit Political Party Details' : 'Register New Political Party' ?>
                    </h2>
                    <?php if ($isEdit): ?>
                        <span class="badge bg-light text-dark border">ID #<?= $editId ?></span>
                    <?php endif; ?>
                </div>
                <div class="ppms-card-body">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label for="partyName" class="form-label small fw-bold">Party Name (English) <span class="text-danger">*</span></label>
                            <input type="text" id="partyName" name="name" value="<?= e($form['name']) ?>" class="form-control" required placeholder="e.g. Bharatiya Janata Party">
                        </div>

                        <div class="col-md-12">
                            <label for="partyNameHi" class="form-label small fw-bold">Party Name (Hindi / हिंदी देवनागरी)</label>
                            <input type="text" id="partyNameHi" name="name_hi" value="<?= e($form['name_hi']) ?>" class="form-control lang-hi" lang="hi" placeholder="e.g. भारतीय जनता पार्टी">
                        </div>

                        <div class="col-md-12">
                            <label for="partySlug" class="form-label small fw-bold">URL Slug (Unique SEO Path)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light small text-muted">/political-party/</span>
                                <input type="text" id="partySlug" name="slug" value="<?= e($form['slug']) ?>" class="form-control" placeholder="Auto-generated if left blank">
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnRegenSlug">
                                    <i class="fas fa-sync-alt me-1"></i> Generate
                                </button>
                            </div>
                            <div class="form-text">Leave blank on creation to automatically generate &amp; disambiguate from Party Name.</div>
                        </div>

                        <div class="col-md-4">
                            <label for="partyType" class="form-label small fw-bold">Recognition Type <span class="text-danger">*</span></label>
                            <select id="partyType" name="type" class="form-select" required>
                                <option value="National" <?= $form['type'] === 'National' ? 'selected' : '' ?>>National Party</option>
                                <option value="State" <?= $form['type'] === 'State' ? 'selected' : '' ?>>State Recognized</option>
                                <option value="Registered" <?= $form['type'] === 'Registered' ? 'selected' : '' ?>>Registered (RUPP)</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label for="partyStatus" class="form-label small fw-bold">ECI Status <span class="text-danger">*</span></label>
                            <select id="partyStatus" name="status" class="form-select" required>
                                <option value="ACTIVE" <?= $form['status'] === 'ACTIVE' ? 'selected' : '' ?>>ACTIVE</option>
                                <option value="INACTIVE" <?= $form['status'] === 'INACTIVE' ? 'selected' : '' ?>>INACTIVE</option>
                                <option value="DELISTED" <?= $form['status'] === 'DELISTED' ? 'selected' : '' ?>>DELISTED</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label for="partyState" class="form-label small fw-bold">
                                <span id="stateFieldLabel"><?= $form['type'] === 'National' ? 'HQ State / UT' : 'State / UT' ?></span>
                                <span class="text-danger">*</span>
                            </label>
                            <select id="partyState" name="state_code" class="form-select">
                                <option value="">Select State / UT</option>
                                <?php foreach ($states as $st): ?>
                                    <option value="<?= e((string)$st['state_code']) ?>" <?= $form['state_code'] === (string)$st['state_code'] ? 'selected' : '' ?>>
                                        <?= e($st['state']) ?> (<?= e($st['state_code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text" id="nationalStateHint" style="<?= $form['type'] === 'National' ? '' : 'display:none;' ?>">
                                National parties are stored as All-India (<code>ALL</code>) with HQ State Code.
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label for="partyNo" class="form-label small fw-bold">ECI List Serial No (<code>no</code>)</label>
                            <input type="text" id="partyNo" name="no" value="<?= e($form['no']) ?>" class="form-control" maxlength="10" placeholder="e.g. 1">
                        </div>

                        <div class="col-md-6">
                            <label for="partyLastUpdate" class="form-label small fw-bold">ECI Last Notification Date</label>
                            <div class="input-group">
                                <input type="text" id="partyLastUpdate" name="last_update" value="<?= e($form['last_update']) ?>" class="form-control" maxlength="20" placeholder="dd-mm-yy or YYYY-MM-DD">
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="document.getElementById('partyLastUpdate').value='<?= e(date('d-m-y')) ?>'">Today</button>
                            </div>
                        </div>

                        <div class="col-md-12">
                            <label for="partyUrl" class="form-label small fw-bold">Official Website URL</label>
                            <input type="url" id="partyUrl" name="url" value="<?= e($form['url']) ?>" class="form-control" placeholder="https://example.org">
                        </div>

                        <div class="col-md-12">
                            <label for="partyAddress" class="form-label small fw-bold">Headquarters Address (English)</label>
                            <textarea id="partyAddress" name="address" rows="3" class="form-control" placeholder="Full official ECI registered headquarters address..."><?= e($form['address']) ?></textarea>
                        </div>

                        <div class="col-md-12">
                            <label for="partyAddressHi" class="form-label small fw-bold">Headquarters Address (Hindi / हिंदी)</label>
                            <textarea id="partyAddressHi" name="address_hi" rows="3" class="form-control lang-hi" lang="hi" placeholder="पार्टी मुख्यालय का पूरा हिंदी पता..."><?= e($form['address_hi']) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Official Contact, Helpline & Social Channels Card -->
            <div class="ppms-card mb-0">
                <div class="ppms-card-header">
                    <h3 class="ppms-card-title">
                        <i class="fas fa-address-book text-success"></i>
                        Official Contact, Helpline &amp; Social Media
                    </h3>
                    <span class="small text-muted">Published on public party profile</span>
                </div>
                <div class="ppms-card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="partyMobile" class="form-label small fw-bold">Mobile / Helpline No.</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light"><i class="fas fa-phone text-primary"></i></span>
                                <input type="text" id="partyMobile" name="mobile" value="<?= e($form['mobile']) ?>" class="form-control" maxlength="40" placeholder="e.g. +91 11 2300 5700">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label for="partyWhatsapp" class="form-label small fw-bold">WhatsApp No. / Channel</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light"><i class="fab fa-whatsapp text-success"></i></span>
                                <input type="text" id="partyWhatsapp" name="whatsapp" value="<?= e($form['whatsapp']) ?>" class="form-control" maxlength="120" placeholder="+91 98... or https://whatsapp.com/channel/...">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label for="partyMisscall" class="form-label small fw-bold">Missed Call Membership No.</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light"><i class="fas fa-phone-volume text-warning"></i></span>
                                <input type="text" id="partyMisscall" name="misscall" value="<?= e($form['misscall']) ?>" class="form-control" maxlength="40" placeholder="e.g. 8800002024 or 1800...">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label for="partyEmail" class="form-label small fw-bold">Official Email ID</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light"><i class="fas fa-envelope text-danger"></i></span>
                                <input type="email" id="partyEmail" name="email" value="<?= e($form['email']) ?>" class="form-control" maxlength="150" placeholder="office@party.org">
                            </div>
                        </div>

                        <div class="col-12">
                            <hr class="my-1">
                            <div class="small fw-bold text-secondary mb-0">Social Media Profiles (Enter full URL or <code>@handle</code>)</div>
                        </div>

                        <div class="col-md-6">
                            <label for="partyFacebook" class="form-label small fw-bold">Facebook</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light"><i class="fab fa-facebook text-primary"></i></span>
                                <input type="text" id="partyFacebook" name="facebook" value="<?= e($form['facebook']) ?>" class="form-control" maxlength="255" placeholder="https://facebook.com/party or handle">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label for="partyInstagram" class="form-label small fw-bold">Instagram</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light"><i class="fab fa-instagram text-danger"></i></span>
                                <input type="text" id="partyInstagram" name="instagram" value="<?= e($form['instagram']) ?>" class="form-control" maxlength="255" placeholder="https://instagram.com/party or @handle">
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label for="partyX" class="form-label small fw-bold">X (Twitter)</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light"><i class="fab fa-x-twitter text-dark"></i></span>
                                <input type="text" id="partyX" name="x" value="<?= e($form['x']) ?>" class="form-control" maxlength="255" placeholder="https://x.com/party or @handle">
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label for="partyYoutube" class="form-label small fw-bold">YouTube</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light"><i class="fab fa-youtube text-danger"></i></span>
                                <input type="text" id="partyYoutube" name="youtube" value="<?= e($form['youtube']) ?>" class="form-control" maxlength="255" placeholder="https://youtube.com/@party">
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label for="partyLinkedin" class="form-label small fw-bold">LinkedIn</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light"><i class="fab fa-linkedin text-primary"></i></span>
                                <input type="text" id="partyLinkedin" name="linkedin" value="<?= e($form['linkedin']) ?>" class="form-control" maxlength="255" placeholder="https://linkedin.com/company/...">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Sidebar: Symbol Picker & Save Controls -->
        <div class="col-lg-4">
            <div class="ppms-card">
                <div class="ppms-card-header">
                    <h3 class="ppms-card-title"><i class="fas fa-award text-success"></i> Election Symbol</h3>
                </div>
                <div class="ppms-card-body">
                    <div class="text-center p-3 mb-3 rounded border bg-light">
                        <img id="symbolPreviewImg"
                             src="<?= e(!empty($matchedSymbol['url']) ? $matchedSymbol['url'] : get_site_url('localneta.png')) ?>"
                             alt="Symbol Preview"
                             style="width:88px; height:88px; object-fit:contain; background:#FFFFFF; border:1px solid var(--ppms-border); border-radius:10px; padding:8px; <?= empty($matchedSymbol['url']) ? 'opacity:0.35;' : '' ?>">
                        <div class="small fw-bold mt-2 text-dark" id="symbolPreviewCaption">
                            <?= $matchedSymbol ? e($matchedSymbol['label']) : ($form['symbol'] !== '' ? e($form['symbol']) . ' (No PNG matched)' : 'Unreserved / No Symbol') ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="partySymbol" class="form-label small fw-bold">Symbol Name / ECI Note</label>
                        <div class="input-group input-group-sm">
                            <input type="text" id="partySymbol" name="symbol" value="<?= e($form['symbol']) ?>" class="form-control" placeholder="e.g. Lotus, Broom, Bicycle">
                            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#symbolPickerModal">
                                <i class="fas fa-images me-1"></i> Pick
                            </button>
                        </div>
                        <div class="form-text">Supports ECI footnotes (e.g. <code>Elephant [In all States...]</code>) and matches PNG files automatically.</div>
                    </div>

                    <div class="mb-0">
                        <label for="symbolUpload" class="form-label small fw-bold">Upload New Symbol PNG (Optional)</label>
                        <input type="file" id="symbolUpload" name="symbol_upload" accept="image/png" class="form-control form-control-sm">
                        <div class="form-text">Max 512 KB PNG. Saved into <code>assets/images/symbols/</code> matching the Symbol Name above.</div>
                    </div>
                </div>
            </div>

            <div class="ppms-card">
                <div class="ppms-card-header">
                    <h3 class="ppms-card-title"><i class="fas fa-save text-primary"></i> Publish &amp; Actions</h3>
                </div>
                <div class="ppms-card-body">
                    <button type="submit" class="btn btn-ppms-saffron w-100 py-2 mb-2">
                        <i class="fas fa-save me-1"></i> <?= $isEdit ? 'Save Changes' : 'Create Political Party' ?>
                    </button>
                    <a href="<?= e(get_site_url('ppms/parties')) ?>" class="btn btn-light border w-100 btn-sm fw-semibold">
                        Cancel
                    </a>

                    <?php if ($isEdit && $existing): ?>
                        <hr class="my-3">
                        <div class="small text-muted mb-1">Created: <strong><?= e((string)$existing['created_at']) ?></strong></div>
                        <div class="small text-muted mb-3">Updated: <strong><?= e((string)$existing['updated_at']) ?></strong></div>

                        <button type="button" class="btn btn-outline-danger btn-sm w-100" data-bs-toggle="modal" data-bs-target="#deleteSingleModal">
                            <i class="fas fa-trash-alt me-1"></i> Delete This Party
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- Symbol Catalog Picker Modal -->
<div class="modal fade" id="symbolPickerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title fw-bold fs-6"><i class="fas fa-icons me-2 text-warning"></i> Choose ECI Election Symbol (<?= count($symbolCatalog) ?> PNGs)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="input-group input-group-sm mb-3">
                    <span class="input-group-text bg-light"><i class="fas fa-search"></i></span>
                    <input type="text" id="symbolPickerSearch" class="form-control" placeholder="Search symbol by name (e.g. Lotus, Book, Fan, Lamp)...">
                </div>
                <div class="ppms-sym-grid" id="symbolPickerGrid">
                    <?php foreach ($symbolCatalog as $sym): ?>
                        <button type="button"
                                class="ppms-sym-tile border"
                                data-sym-label="<?= e($sym['label']) ?>"
                                data-sym-url="<?= e($sym['url']) ?>"
                                onclick="selectPpmsSymbol(this)">
                            <img src="<?= e($sym['url']) ?>" alt="<?= e($sym['label']) ?>" loading="lazy">
                            <div class="ppms-sym-tile-label"><?= e($sym['label']) ?></div>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($isEdit): ?>
<div class="modal fade" id="deleteSingleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold fs-6"><i class="fas fa-trash-alt me-2"></i> Delete Party #<?= $editId ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body small">
                Permanently delete <strong><?= e($form['name']) ?></strong> from <code>political_party</code>?
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light border btn-sm" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="<?= e(get_site_url('ppms/party-form?id=' . $editId)) ?>" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= e(ppms_csrf_token()) ?>">
                    <input type="hidden" name="party_id" value="<?= $editId ?>">
                    <input type="hidden" name="form_action" value="delete">
                    <button type="submit" class="btn btn-danger btn-sm fw-bold">Delete Permanently</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$fallbackLogoJson = json_encode(get_site_url('localneta.png'));
$symbolMapForJs = [];
foreach ($symbolCatalog as $sItem) {
    $symbolMapForJs[$sItem['key']] = ['label' => $sItem['label'], 'url' => $sItem['url']];
}
$symbolMapJson = json_encode($symbolMapForJs);
$isEditJs = $isEdit ? 'true' : 'false';

$ppmsExtraScripts = <<<HTML
<script>
const PPMS_SYMBOLS = {$symbolMapJson};
const PPMS_FALLBACK_IMG = {$fallbackLogoJson};
const PPMS_IS_EDIT = {$isEditJs};

function slugifyPartyName(text) {
    return (text || '')
        .toString()
        .replace(/['’`*#]/g, '')
        .replace(/&/g, ' and ')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 180);
}

function updateSymbolPreview(rawText) {
    const img = document.getElementById('symbolPreviewImg');
    const cap = document.getElementById('symbolPreviewCaption');
    if (!img || !cap) return;

    const clean = (rawText || '').trim();
    if (!clean || ['-', 'NA', 'N/A', 'NONE', 'NIL'].includes(clean.toUpperCase())) {
        img.src = PPMS_FALLBACK_IMG;
        img.style.opacity = '0.35';
        cap.textContent = 'Unreserved / No Symbol';
        return;
    }

    let shortTitle = clean.replace(/[\*#\[].*$/, '').replace(/,\s*restricted\b.*$/i, '').replace(/^[.\s]+|[.\s]+$/g, '');
    if (!shortTitle) shortTitle = clean;
    let key = shortTitle.toLowerCase().replace(/&/g, ' and ').replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
    if (key === 'hammer_sickle_and_star') key = 'hummer_sickle_and_star';

    if (PPMS_SYMBOLS[key]) {
        img.src = PPMS_SYMBOLS[key].url;
        img.style.opacity = '1';
        cap.textContent = shortTitle;
    } else {
        img.src = PPMS_FALLBACK_IMG;
        img.style.opacity = '0.35';
        cap.textContent = shortTitle + ' (No PNG matched)';
    }
}

function selectPpmsSymbol(btn) {
    const label = btn.getAttribute('data-sym-label') || '';
    const inp = document.getElementById('partySymbol');
    if (inp) {
        inp.value = label;
        updateSymbolPreview(label);
    }
    const modalEl = document.getElementById('symbolPickerModal');
    if (modalEl) {
        bootstrap.Modal.getOrCreateInstance(modalEl).hide();
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const nameEl = document.getElementById('partyName');
    const slugEl = document.getElementById('partySlug');
    const regenBtn = document.getElementById('btnRegenSlug');
    const symEl = document.getElementById('partySymbol');
    const typeEl = document.getElementById('partyType');
    const stateLabelEl = document.getElementById('stateFieldLabel');
    const natHintEl = document.getElementById('nationalStateHint');
    const pickerSearch = document.getElementById('symbolPickerSearch');

    let userTouchedSlug = PPMS_IS_EDIT || (slugEl && slugEl.value.trim() !== '');
    if (slugEl) {
        slugEl.addEventListener('input', () => {
            userTouchedSlug = slugEl.value.trim() !== '';
        });
    }
    if (nameEl && slugEl) {
        nameEl.addEventListener('input', () => {
            if (!userTouchedSlug) {
                slugEl.value = slugifyPartyName(nameEl.value);
            }
        });
    }
    if (regenBtn && nameEl && slugEl) {
        regenBtn.addEventListener('click', () => {
            slugEl.value = slugifyPartyName(nameEl.value);
        });
    }
    if (symEl) {
        symEl.addEventListener('input', () => updateSymbolPreview(symEl.value));
    }
    if (typeEl) {
        typeEl.addEventListener('change', () => {
            const isNat = typeEl.value === 'National';
            if (stateLabelEl) stateLabelEl.textContent = isNat ? 'HQ State / UT' : 'State / UT';
            if (natHintEl) natHintEl.style.display = isNat ? '' : 'none';
        });
    }
    if (pickerSearch) {
        pickerSearch.addEventListener('input', () => {
            const q = pickerSearch.value.trim().toLowerCase();
            document.querySelectorAll('#symbolPickerGrid .ppms-sym-tile').forEach((tile) => {
                const lbl = (tile.getAttribute('data-sym-label') || '').toLowerCase();
                tile.style.display = (q === '' || lbl.includes(q)) ? '' : 'none';
            });
        });
    }
});
</script>
HTML;
require_once __DIR__ . '/includes/layout_bottom.php';
?>

