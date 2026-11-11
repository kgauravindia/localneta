<?php
// ppms/mp-form.php - Create / Edit / Delete Member of Parliament in `mp`
define('PPMS_INIT', true);
require_once __DIR__ . '/includes/auth.php';

$adminUser = ppms_require_auth();
$pdo = ppms_db();

$editId = (int)($_GET['id'] ?? $_POST['mp_id'] ?? 0);
$isEdit = $editId > 0;
$existing = null;

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM mp WHERE id = ? LIMIT 1");
    $stmt->execute([$editId]);
    $existing = $stmt->fetch();
    if (!$existing) {
        ppms_set_flash('error', "MP #{$editId} not found.");
        header('Location: ' . get_site_url('ppms/mps'), true, 302);
        exit;
    }
}

$states = ppms_get_states($pdo);
$stateNameByCode = [];
foreach ($states as $s) {
    $stateNameByCode[(string)$s['state_code']] = (string)$s['state'];
}

$partySuggestions = $pdo->query("SELECT DISTINCT party_name FROM mp WHERE party_name <> '' ORDER BY party_name ASC")->fetchAll(PDO::FETCH_COLUMN);

$initialStateCode = '';
if ($existing) {
    if (!empty($existing['state_code'])) {
        $initialStateCode = (string)$existing['state_code'];
    } elseif (strcasecmp((string)($existing['state'] ?? ''), 'Nominated') === 0) {
        $initialStateCode = 'NOMINATED';
    }
}

$form = [
    'member_name' => $existing ? clean_mp_display_name($existing['member_name']) : '',
    'slug' => $existing ? (string)($existing['slug'] ?? '') : '',
    'type' => $existing ? (strtolower((string)$existing['type']) === 'rs' ? 'rs' : 'ls') : 'ls',
    'membership_status' => $existing ? (string)($existing['membership_status'] ?: 'Sitting') : 'Sitting',
    'state_code' => $initialStateCode,
    'constituency' => $existing ? trim((string)$existing['constituency']) : '',
    'party_name' => $existing ? trim((string)$existing['party_name']) : '',
    'lok_sabha_terms' => $existing ? trim((string)($existing['lok_sabha_terms'] ?? '18')) : '18',
    'term_period' => $existing ? trim((string)($existing['term_period'] ?? '')) : '',
    'total_terms' => ($existing && $existing['total_terms'] !== null) ? (string)$existing['total_terms'] : '',
    'age' => ($existing && $existing['age'] !== null) ? (string)$existing['age'] : '',
    'election' => $existing ? trim((string)($existing['election'] ?? '')) : 'ls-18',
    'photo_url' => $existing ? trim((string)($existing['photo_url'] ?? '')) : '',
    'sources' => $existing ? trim((string)($existing['sources'] ?? 'https://sansad.in/')) : 'https://sansad.in/',
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!ppms_verify_csrf($csrf)) {
        $errors[] = 'Security token expired. Please reload and resubmit.';
    } else {
        $postAction = trim((string)($_POST['form_action'] ?? 'save'));

        if ($postAction === 'delete' && $isEdit) {
            $delStmt = $pdo->prepare("DELETE FROM mp WHERE id = ? LIMIT 1");
            $delStmt->execute([$editId]);
            $deletedName = clean_mp_display_name($existing['member_name']);
            ppms_log_action($pdo, $adminUser, 'DELETE', $editId, $deletedName, "Deleted MP ({$existing['constituency']}) from Edit form", 'mp');
            ppms_set_flash('success', "MP #{$editId} ({$deletedName}) deleted permanently.");
            header('Location: ' . get_site_url('ppms/mps'), true, 302);
            exit;
        }

        $form['member_name'] = clean_mp_display_name((string)($_POST['member_name'] ?? ''));
        $form['slug'] = trim((string)($_POST['slug'] ?? ''));
        $form['type'] = ($_POST['type'] ?? 'ls') === 'rs' ? 'rs' : 'ls';
        $form['membership_status'] = trim((string)($_POST['membership_status'] ?? 'Sitting'));
        $form['state_code'] = trim((string)($_POST['state_code'] ?? ''));
        $form['constituency'] = trim((string)($_POST['constituency'] ?? ''));
        $form['party_name'] = trim((string)($_POST['party_name'] ?? ''));
        $form['lok_sabha_terms'] = trim((string)($_POST['lok_sabha_terms'] ?? ''));
        $form['term_period'] = trim((string)($_POST['term_period'] ?? ''));
        $form['total_terms'] = trim((string)($_POST['total_terms'] ?? ''));
        $form['age'] = trim((string)($_POST['age'] ?? ''));
        $form['election'] = trim((string)($_POST['election'] ?? ''));
        $form['photo_url'] = trim((string)($_POST['photo_url'] ?? ''));
        $form['sources'] = trim((string)($_POST['sources'] ?? ''));

        if ($form['member_name'] === '') {
            $errors[] = 'Member of Parliament Name is required.';
        }
        if ($form['constituency'] === '') {
            $errors[] = 'Constituency / Seat Name is required.';
        }
        if ($form['party_name'] === '') {
            $errors[] = 'Political Party Name is required (use "Independent" if none).';
        }
        if (!in_array($form['membership_status'], ['Sitting', 'Died', 'Resigned', 'Former'], true)) {
            $errors[] = 'Invalid membership status selected.';
        }

        // Resolve state and state_code
        $stateCodeSave = null;
        $stateNameSave = '';
        if ($form['state_code'] === 'NOMINATED' || ($form['type'] === 'rs' && $form['state_code'] === '')) {
            $stateCodeSave = null;
            $stateNameSave = 'Nominated';
        } elseif ($form['state_code'] !== '' && isset($stateNameByCode[$form['state_code']])) {
            $stateCodeSave = $form['state_code'];
            $stateNameSave = $stateNameByCode[$form['state_code']];
        } else {
            $errors[] = 'Please select a valid State / UT (or Nominated for Rajya Sabha).';
        }

        // Optional numeric fields
        $ageSave = null;
        if ($form['age'] !== '') {
            if (!ctype_digit($form['age']) || (int)$form['age'] < 18 || (int)$form['age'] > 120) {
                $errors[] = 'Age must be an integer between 18 and 120.';
            } else {
                $ageSave = (int)$form['age'];
            }
        }

        $totalTermsSave = null;
        if ($form['total_terms'] !== '') {
            if (!ctype_digit($form['total_terms']) || (int)$form['total_terms'] < 0 || (int)$form['total_terms'] > 30) {
                $errors[] = 'Total Terms must be an integer between 0 and 30.';
            } else {
                $totalTermsSave = (int)$form['total_terms'];
            }
        }

        if ($form['photo_url'] !== '' && !filter_var($form['photo_url'], FILTER_VALIDATE_URL)) {
            $errors[] = 'Photo URL must be a valid https:// or http:// image address.';
        }

        $sourcesSave = $form['sources'] !== '' ? $form['sources'] : 'https://sansad.in/';
        if (!preg_match('#^https?://#i', $sourcesSave)) {
            $sourcesSave = 'https://' . ltrim($sourcesSave, '/');
        }
        if (!filter_var($sourcesSave, FILTER_VALIDATE_URL)) {
            $errors[] = 'Source URL must be a valid web address.';
        }

        $resolvedSlug = '';
        if (empty($errors)) {
            $slugRes = ppms_build_unique_mp_slug(
                $pdo,
                $form['slug'],
                $form['member_name'],
                $form['constituency'],
                $stateNameSave,
                $form['type'],
                $editId
            );
            if (!$slugRes['ok']) {
                $errors[] = $slugRes['error'];
            } else {
                $resolvedSlug = $slugRes['slug'];
            }
        }

        if (empty($errors)) {
            $lsTermsSave = $form['lok_sabha_terms'] !== ''
                ? substr($form['lok_sabha_terms'], 0, 100)
                : ($form['type'] === 'rs' ? 'Rajya Sabha' : '18');
            $termPeriodSave = $form['term_period'] !== '' ? substr($form['term_period'], 0, 50) : null;
            $electionSave = $form['election'] !== '' ? substr($form['election'], 0, 50) : null;
            $photoSave = $form['photo_url'] !== '' ? $form['photo_url'] : null;

            if ($isEdit) {
                $upd = $pdo->prepare("
                    UPDATE mp
                    SET member_name = ?,
                        slug = ?,
                        party_name = ?,
                        constituency = ?,
                        state = ?,
                        state_code = ?,
                        membership_status = ?,
                        lok_sabha_terms = ?,
                        age = ?,
                        photo_url = ?,
                        term_period = ?,
                        total_terms = ?,
                        type = ?,
                        election = ?,
                        sources = ?
                    WHERE id = ?
                ");
                $upd->execute([
                    $form['member_name'],
                    $resolvedSlug,
                    $form['party_name'],
                    $form['constituency'],
                    $stateNameSave,
                    $stateCodeSave,
                    $form['membership_status'],
                    $lsTermsSave,
                    $ageSave,
                    $photoSave,
                    $termPeriodSave,
                    $totalTermsSave,
                    $form['type'],
                    $electionSave,
                    substr($sourcesSave, 0, 255),
                    $editId,
                ]);
                ppms_log_action($pdo, $adminUser, 'UPDATE', $editId, $form['member_name'], "Updated {$form['type']} MP ({$form['constituency']}, {$stateNameSave})", 'mp');
                ppms_set_flash('success', "MP #{$editId} ({$form['member_name']}) updated successfully.");
                header('Location: ' . get_site_url('ppms/mp-form?id=' . $editId), true, 302);
                exit;
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO mp (
                        member_name, slug, party_name, constituency, state, state_code,
                        membership_status, lok_sabha_terms, age, photo_url,
                        term_period, total_terms, type, election, sources
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([
                    $form['member_name'],
                    $resolvedSlug,
                    $form['party_name'],
                    $form['constituency'],
                    $stateNameSave,
                    $stateCodeSave,
                    $form['membership_status'],
                    $lsTermsSave,
                    $ageSave,
                    $photoSave,
                    $termPeriodSave,
                    $totalTermsSave,
                    $form['type'],
                    $electionSave,
                    substr($sourcesSave, 0, 255),
                ]);
                $newId = (int)$pdo->lastInsertId();
                ppms_log_action($pdo, $adminUser, 'CREATE', $newId, $form['member_name'], "Created {$form['type']} MP ({$form['constituency']}, {$stateNameSave})", 'mp');
                ppms_set_flash('success', "Added new MP #{$newId}: {$form['member_name']}!");
                header('Location: ' . get_site_url('ppms/mp-form?id=' . $newId), true, 302);
                exit;
            }
        }
    }
}

$activeNav = $isEdit ? 'mps' : 'mp-form';
$ppmsPageTitle = $isEdit ? ('Edit MP #' . $editId) : 'Add New Parliament MP';
require_once __DIR__ . '/includes/layout_top.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <a href="<?= e(get_site_url('ppms/mps')) ?>" class="btn btn-light border btn-sm fw-semibold">
        <i class="fas fa-arrow-left me-1"></i> Back to All MPs
    </a>
    <?php if ($isEdit): ?>
        <a href="<?= e(get_mp_page_url($form['slug'], $editId)) ?>" target="_blank" rel="noopener" class="btn btn-ppms-green btn-sm">
            <i class="fas fa-external-link-alt me-1"></i> View Public MP Page
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

<form method="POST" action="<?= e(get_site_url('ppms/mp-form' . ($isEdit ? '?id=' . $editId : ''))) ?>">
    <input type="hidden" name="csrf_token" value="<?= e(ppms_csrf_token()) ?>">
    <input type="hidden" name="mp_id" value="<?= $editId ?>">
    <input type="hidden" name="form_action" value="save">

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="ppms-card">
                <div class="ppms-card-header">
                    <h2 class="ppms-card-title">
                        <i class="fas fa-landmark text-danger"></i>
                        <?= $isEdit ? 'Edit Member of Parliament' : 'Register New Member of Parliament' ?>
                    </h2>
                    <?php if ($isEdit): ?>
                        <span class="badge bg-light text-dark border">MP #<?= $editId ?></span>
                    <?php endif; ?>
                </div>
                <div class="ppms-card-body">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label for="mpMemberName" class="form-label small fw-bold">Member Name <span class="text-danger">*</span></label>
                            <input type="text" id="mpMemberName" name="member_name" value="<?= e($form['member_name']) ?>" class="form-control" required placeholder="e.g. Shri Rajiv Pratap Rudy">
                        </div>

                        <div class="col-md-12">
                            <label for="mpSlug" class="form-label small fw-bold">URL Slug (SEO Path)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light small text-muted">/mp/</span>
                                <input type="text" id="mpSlug" name="slug" value="<?= e($form['slug']) ?>" class="form-control" placeholder="Auto-generated if left blank">
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnRegenMpSlug">
                                    <i class="fas fa-sync-alt me-1"></i> Generate
                                </button>
                            </div>
                            <div class="form-text">Honorific prefixes (Shri, Smt., Dr.) are stripped automatically during slug generation.</div>
                        </div>

                        <div class="col-md-4">
                            <label for="mpType" class="form-label small fw-bold">Parliament House <span class="text-danger">*</span></label>
                            <select id="mpType" name="type" class="form-select" required>
                                <option value="ls" <?= $form['type'] === 'ls' ? 'selected' : '' ?>>Lok Sabha (ls)</option>
                                <option value="rs" <?= $form['type'] === 'rs' ? 'selected' : '' ?>>Rajya Sabha (rs)</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label for="mpStatus" class="form-label small fw-bold">Membership Status <span class="text-danger">*</span></label>
                            <select id="mpStatus" name="membership_status" class="form-select" required>
                                <?php foreach (['Sitting', 'Died', 'Resigned', 'Former'] as $stOpt): ?>
                                    <option value="<?= e($stOpt) ?>" <?= $form['membership_status'] === $stOpt ? 'selected' : '' ?>><?= e($stOpt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label for="mpState" class="form-label small fw-bold">State / UT <span class="text-danger">*</span></label>
                            <select id="mpState" name="state_code" class="form-select">
                                <option value="">Select State / UT</option>
                                <option value="NOMINATED" <?= $form['state_code'] === 'NOMINATED' ? 'selected' : '' ?>>Nominated (Rajya Sabha)</option>
                                <?php foreach ($states as $st): ?>
                                    <option value="<?= e((string)$st['state_code']) ?>" <?= $form['state_code'] === (string)$st['state_code'] ? 'selected' : '' ?>>
                                        <?= e($st['state']) ?> (<?= e($st['state_code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="mpConstituency" class="form-label small fw-bold">Constituency / RS Seat <span class="text-danger">*</span></label>
                            <input type="text" id="mpConstituency" name="constituency" value="<?= e($form['constituency']) ?>" class="form-control" required placeholder="e.g. Saran or Rajya Sabha (Bihar)">
                        </div>

                        <div class="col-md-6">
                            <label for="mpParty" class="form-label small fw-bold">Political Party Name <span class="text-danger">*</span></label>
                            <input type="text" id="mpParty" name="party_name" value="<?= e($form['party_name']) ?>" list="mpPartyOptions" class="form-control" required placeholder="e.g. Bharatiya Janata Party">
                            <datalist id="mpPartyOptions">
                                <?php foreach ($partySuggestions as $ps): ?>
                                    <option value="<?= e((string)$ps) ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>

                        <div class="col-md-4">
                            <label for="mpElection" class="form-label small fw-bold">Election / Cycle</label>
                            <input type="text" id="mpElection" name="election" value="<?= e($form['election']) ?>" class="form-control" placeholder="e.g. ls-18 or RS 2024">
                        </div>

                        <div class="col-md-4">
                            <label for="mpLsTerms" class="form-label small fw-bold">Lok Sabha / House Terms</label>
                            <input type="text" id="mpLsTerms" name="lok_sabha_terms" value="<?= e($form['lok_sabha_terms']) ?>" class="form-control" placeholder="e.g. 16, 17, 18">
                        </div>

                        <div class="col-md-4">
                            <label for="mpTermPeriod" class="form-label small fw-bold">Term Period</label>
                            <input type="text" id="mpTermPeriod" name="term_period" value="<?= e($form['term_period']) ?>" class="form-control" placeholder="e.g. 2024-2029">
                        </div>

                        <div class="col-md-3">
                            <label for="mpAge" class="form-label small fw-bold">Age</label>
                            <input type="number" id="mpAge" name="age" value="<?= e($form['age']) ?>" min="18" max="120" class="form-control" placeholder="e.g. 62">
                        </div>

                        <div class="col-md-3">
                            <label for="mpTotalTerms" class="form-label small fw-bold">Total Terms</label>
                            <input type="number" id="mpTotalTerms" name="total_terms" value="<?= e($form['total_terms']) ?>" min="0" max="30" class="form-control" placeholder="e.g. 4">
                        </div>

                        <div class="col-md-6">
                            <label for="mpSources" class="form-label small fw-bold">Official Reference URL</label>
                            <input type="url" id="mpSources" name="sources" value="<?= e($form['sources']) ?>" class="form-control" placeholder="https://sansad.in/">
                        </div>

                        <div class="col-md-12">
                            <label for="mpPhotoUrl" class="form-label small fw-bold">Official Portrait URL</label>
                            <input type="url" id="mpPhotoUrl" name="photo_url" value="<?= e($form['photo_url']) ?>" class="form-control" placeholder="https://sansad.in/...">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="ppms-card">
                <div class="ppms-card-header">
                    <h3 class="ppms-card-title"><i class="fas fa-user-circle text-info"></i> Portrait Preview</h3>
                </div>
                <div class="ppms-card-body text-center">
                    <img id="mpPhotoPreview"
                         src="<?= e($form['photo_url'] !== '' ? $form['photo_url'] : get_site_url('assets/images/dummy-user.png')) ?>"
                         alt="MP Portrait Preview"
                         onerror="this.src='<?= e(get_site_url('assets/images/dummy-user.png')) ?>'"
                         style="width:110px; height:110px; object-fit:cover; border-radius:14px; border:1px solid var(--ppms-border); background:#F8FAFC;">
                    <div class="small text-muted mt-2">
                        Preview updates when you paste a valid <code>photo_url</code>.
                    </div>
                </div>
            </div>

            <div class="ppms-card">
                <div class="ppms-card-header">
                    <h3 class="ppms-card-title"><i class="fas fa-save text-primary"></i> Publish &amp; Actions</h3>
                </div>
                <div class="ppms-card-body">
                    <button type="submit" class="btn btn-ppms-saffron w-100 py-2 mb-2">
                        <i class="fas fa-save me-1"></i> <?= $isEdit ? 'Save MP Changes' : 'Create MP Record' ?>
                    </button>
                    <a href="<?= e(get_site_url('ppms/mps')) ?>" class="btn btn-light border w-100 btn-sm fw-semibold">
                        Cancel
                    </a>

                    <?php if ($isEdit && $existing): ?>
                        <hr class="my-3">
                        <div class="small text-muted mb-3">Created: <strong><?= e((string)$existing['created_at']) ?></strong></div>
                        <button type="button" class="btn btn-outline-danger btn-sm w-100" data-bs-toggle="modal" data-bs-target="#deleteSingleMpModal">
                            <i class="fas fa-trash-alt me-1"></i> Delete This MP
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</form>

<?php if ($isEdit): ?>
<div class="modal fade" id="deleteSingleMpModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold fs-6"><i class="fas fa-trash-alt me-2"></i> Delete MP #<?= $editId ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body small">
                Permanently remove <strong><?= e($form['member_name']) ?></strong> (<?= e($form['constituency']) ?>) from <code>mp</code>?
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light border btn-sm" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="<?= e(get_site_url('ppms/mp-form?id=' . $editId)) ?>" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= e(ppms_csrf_token()) ?>">
                    <input type="hidden" name="mp_id" value="<?= $editId ?>">
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
const MP_IS_EDIT = {$isEditJs};

function slugifyMpName(raw) {
    let text = (raw || '').toString().trim();
    text = text.replace(/^(?:(?:Shri|Smt\.?|Dr\.?|Prof\.?|Ms\.?|Mrs\.?|Mr\.?|Km\.?|Kumari|Adv\.?|Thiru|Com\.)\s+)+/i, '');
    return text
        .replace(/['’`*#]/g, '')
        .replace(/&/g, ' and ')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 140);
}

document.addEventListener('DOMContentLoaded', function () {
    const nameEl = document.getElementById('mpMemberName');
    const slugEl = document.getElementById('mpSlug');
    const regenBtn = document.getElementById('btnRegenMpSlug');
    const photoInp = document.getElementById('mpPhotoUrl');
    const photoPrev = document.getElementById('mpPhotoPreview');

    let touchedSlug = MP_IS_EDIT || (slugEl && slugEl.value.trim() !== '');
    if (slugEl) {
        slugEl.addEventListener('input', () => { touchedSlug = slugEl.value.trim() !== ''; });
    }
    if (nameEl && slugEl) {
        nameEl.addEventListener('input', () => {
            if (!touchedSlug) slugEl.value = slugifyMpName(nameEl.value);
        });
    }
    if (regenBtn && nameEl && slugEl) {
        regenBtn.addEventListener('click', () => {
            slugEl.value = slugifyMpName(nameEl.value);
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

