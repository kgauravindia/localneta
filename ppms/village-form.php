<?php
// ppms/village-form.php - Create / Edit / Delete Village & PIN Code Record in `village`
define('PPMS_INIT', true);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/pincode_helper.php';

$adminUser = ppms_require_auth();
$pdo = ppms_db();

$editId = (int)($_GET['id'] ?? $_POST['village_id'] ?? 0);
$isEdit = $editId > 0;
$existing = null;

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM village WHERE id = ? LIMIT 1");
    $stmt->execute([$editId]);
    $existing = $stmt->fetch();
    if (!$existing) {
        ppms_set_flash('error', "Village #{$editId} not found.");
        header('Location: ' . get_site_url('ppms/villages'), true, 302);
        exit;
    }
}

$states = ppms_get_states($pdo);
$sdbMaps = get_op_sdb_lookup_maps($pdo);

$form = [
    'state_code' => $existing ? (string)($existing['state_code'] ?? '') : trim((string)($_GET['state_code'] ?? '10')),
    'district_code' => $existing ? (string)($existing['district_code'] ?? '') : trim((string)($_GET['district_code'] ?? '')),
    'block_code' => $existing ? (string)($existing['block_code'] ?? '') : trim((string)($_GET['block_code'] ?? '')),
    'district' => $existing ? trim((string)($existing['district'] ?? '')) : '',
    'block' => $existing ? trim((string)($existing['block'] ?? '')) : '',
    'village_code' => ($existing && $existing['village_code'] !== null) ? (string)$existing['village_code'] : '',
    'village' => $existing ? trim((string)($existing['village'] ?? '')) : '',
    'pincode' => ($existing && !empty($existing['pincode'])) ? str_pad((string)$existing['pincode'], 6, '0', STR_PAD_LEFT) : '',
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!ppms_verify_csrf($csrf)) {
        $errors[] = 'Security token expired. Please reload and resubmit.';
    } else {
        $postAction = trim((string)($_POST['form_action'] ?? 'save'));

        if ($postAction === 'delete' && $isEdit) {
            $delStmt = $pdo->prepare("DELETE FROM village WHERE id = ? LIMIT 1");
            $delStmt->execute([$editId]);
            $deletedName = trim((string)($existing['village'] ?? 'Village #' . $editId));
            $pinStr = !empty($existing['pincode']) ? str_pad((string)$existing['pincode'], 6, '0', STR_PAD_LEFT) : '-';
            ppms_invalidate_village_summary_cache();
            ppms_log_action($pdo, $adminUser, 'DELETE', $editId, $deletedName, "Deleted village (PIN {$pinStr}) from Edit form", 'village');
            ppms_set_flash('success', "Village #{$editId} ({$deletedName}) deleted permanently.");
            header('Location: ' . get_site_url('ppms/villages'), true, 302);
            exit;
        }

        $form['state_code'] = trim((string)($_POST['state_code'] ?? ''));
        $form['district_code'] = trim((string)($_POST['district_code'] ?? ''));
        $form['block_code'] = trim((string)($_POST['block_code'] ?? ''));
        $form['village_code'] = trim((string)($_POST['village_code'] ?? ''));
        $form['village'] = trim((string)($_POST['village'] ?? ''));
        $form['pincode'] = normalize_pincode_digits((string)($_POST['pincode'] ?? ''));

        if ($form['state_code'] === '' || !ctype_digit($form['state_code'])) {
            $errors[] = 'Please select a valid State / UT.';
        }
        if ($form['district_code'] === '' || !ctype_digit($form['district_code'])) {
            $errors[] = 'Please select a valid District.';
        }
        if ($form['block_code'] === '' || !ctype_digit($form['block_code'])) {
            $errors[] = 'Please select a valid Block / Sub-District.';
        }
        if ($form['village'] === '') {
            $errors[] = 'Village / Locality Name is required.';
        }
        if (!is_valid_indian_pincode($form['pincode'])) {
            $errors[] = 'PIN Code must be a valid 6-digit Indian PIN code (100000 - 999999).';
        }

        $villageCodeSave = null;
        if ($form['village_code'] !== '') {
            if (!ctype_digit($form['village_code'])) {
                $errors[] = 'LGD Village Code must be a positive whole number (or left blank).';
            } else {
                $villageCodeSave = (int)$form['village_code'];
            }
        }

        // Resolve official district and block labels from op_sdb
        $sdbBlk = $sdbMaps['blocks'][$form['block_code']] ?? null;
        $sdbDist = $sdbMaps['districts'][$form['district_code']] ?? null;
        if ($sdbBlk !== null) {
            if ($form['state_code'] === '') {
                $form['state_code'] = $sdbBlk['state_code'];
            }
            if ($form['district_code'] === '') {
                $form['district_code'] = $sdbBlk['district_code'];
            }
            $form['district'] = $sdbBlk['district'];
            $form['block'] = $sdbBlk['block'];
        } elseif ($sdbDist !== null) {
            $form['district'] = $sdbDist['district'];
            $form['block'] = $form['block'] !== '' ? $form['block'] : ('Block #' . $form['block_code']);
        } else {
            if ($form['district'] === '') {
                $form['district'] = 'District #' . $form['district_code'];
            }
            if ($form['block'] === '') {
                $form['block'] = 'Block #' . $form['block_code'];
            }
        }

        if (empty($errors)) {
            $stInt = (int)$form['state_code'];
            $dtInt = (int)$form['district_code'];
            $bkInt = (int)$form['block_code'];
            $pinInt = (int)$form['pincode'];
            $distSave = mb_substr($form['district'], 0, 38, 'UTF-8');
            $blkSave = mb_substr($form['block'], 0, 36, 'UTF-8');
            $vilSave = mb_substr($form['village'], 0, 123, 'UTF-8');

            if ($isEdit) {
                $upd = $pdo->prepare("
                    UPDATE village
                    SET state_code = ?,
                        district_code = ?,
                        district = ?,
                        block_code = ?,
                        block = ?,
                        village_code = ?,
                        village = ?,
                        pincode = ?
                    WHERE id = ?
                ");
                $upd->execute([
                    $stInt,
                    $dtInt,
                    $distSave,
                    $bkInt,
                    $blkSave,
                    $villageCodeSave,
                    $vilSave,
                    $pinInt,
                    $editId,
                ]);
                ppms_invalidate_village_summary_cache();
                ppms_log_action($pdo, $adminUser, 'UPDATE', $editId, $vilSave, "Updated village (PIN {$form['pincode']}, {$blkSave}, {$distSave})", 'village');
                ppms_set_flash('success', "Village #{$editId} ({$vilSave}) updated successfully.");
                header('Location: ' . get_site_url('ppms/village-form?id=' . $editId), true, 302);
                exit;
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO village (
                        state_code, district_code, district, block_code, block, village_code, village, pincode
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([
                    $stInt,
                    $dtInt,
                    $distSave,
                    $bkInt,
                    $blkSave,
                    $villageCodeSave,
                    $vilSave,
                    $pinInt,
                ]);
                $newId = (int)$pdo->lastInsertId();
                ppms_invalidate_village_summary_cache();
                ppms_log_action($pdo, $adminUser, 'CREATE', $newId, $vilSave, "Added village (PIN {$form['pincode']}, {$blkSave}, {$distSave})", 'village');
                ppms_set_flash('success', "Added new village #{$newId}: {$vilSave} (PIN {$form['pincode']})!");
                header('Location: ' . get_site_url('ppms/village-form?id=' . $newId), true, 302);
                exit;
            }
        }
    }
}

$districtOptions = [];
if ($form['state_code'] !== '') {
    $ds = $pdo->prepare("SELECT DISTINCT district_code, district FROM op_sdb WHERE state_code = ? AND status = 'ACTIVE' ORDER BY district ASC");
    $ds->execute([$form['state_code']]);
    $districtOptions = $ds->fetchAll();
}
$blockOptions = [];
if ($form['district_code'] !== '') {
    $bs = $pdo->prepare("SELECT block_code, block FROM op_sdb WHERE district_code = ? AND status = 'ACTIVE' ORDER BY block ASC");
    $bs->execute([$form['district_code']]);
    $blockOptions = $bs->fetchAll();
}

$activeNav = $isEdit ? 'villages' : 'village-form';
$ppmsPageTitle = $isEdit ? ('Edit Village #' . $editId) : 'Add New Village & PIN';
require_once __DIR__ . '/includes/layout_top.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <a href="<?= e(get_site_url('ppms/villages')) ?>" class="btn btn-light border btn-sm fw-semibold">
        <i class="fas fa-arrow-left me-1"></i> Back to Villages &amp; PINs
    </a>
    <?php if (is_valid_indian_pincode($form['pincode'])): ?>
        <a href="<?= e(get_pincode_page_url($form['pincode'])) ?>" target="_blank" rel="noopener" class="btn btn-ppms-green btn-sm">
            <i class="fas fa-external-link-alt me-1"></i> View Public PIN Page (/pincode/<?= e($form['pincode']) ?>)
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

<form method="POST" action="<?= e(get_site_url('ppms/village-form' . ($isEdit ? '?id=' . $editId : ''))) ?>">
    <input type="hidden" name="csrf_token" value="<?= e(ppms_csrf_token()) ?>">
    <input type="hidden" name="village_id" value="<?= $editId ?>">
    <input type="hidden" name="form_action" value="save">

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="ppms-card mb-0">
                <div class="ppms-card-header">
                    <h2 class="ppms-card-title">
                        <i class="fas fa-map-pin text-success"></i>
                        <?= $isEdit ? 'Edit Village & PIN Mapping' : 'Add New Village & PIN Mapping' ?>
                    </h2>
                    <?php if ($isEdit): ?>
                        <span class="badge bg-light text-dark border">Village #<?= number_format($editId) ?></span>
                    <?php endif; ?>
                </div>
                <div class="ppms-card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="vState" class="form-label small fw-bold">State / UT <span class="text-danger">*</span></label>
                            <select id="vState" name="state_code" class="form-select" required>
                                <option value="">Select State</option>
                                <?php foreach ($states as $st): ?>
                                    <option value="<?= e((string)$st['state_code']) ?>" <?= $form['state_code'] === (string)$st['state_code'] ? 'selected' : '' ?>>
                                        <?= e($st['state']) ?> (<?= e($st['state_code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label for="vDistrict" class="form-label small fw-bold">District <span class="text-danger">*</span></label>
                            <select id="vDistrict" name="district_code" class="form-select" required>
                                <option value="">Select District</option>
                                <?php
                                $distFound = false;
                                foreach ($districtOptions as $dt):
                                    $sel = $form['district_code'] === (string)$dt['district_code'];
                                    if ($sel) $distFound = true;
                                ?>
                                    <option value="<?= e((string)$dt['district_code']) ?>" <?= $sel ? 'selected' : '' ?>>
                                        <?= e($dt['district']) ?> (<?= e((string)$dt['district_code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                                <?php if (!$distFound && $form['district_code'] !== ''): ?>
                                    <option value="<?= e($form['district_code']) ?>" selected><?= e($form['district'] ?: ('District #' . $form['district_code'])) ?></option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label for="vBlock" class="form-label small fw-bold">Block / Sub-District <span class="text-danger">*</span></label>
                            <select id="vBlock" name="block_code" class="form-select" required>
                                <option value="">Select Block</option>
                                <?php
                                $blkFound = false;
                                foreach ($blockOptions as $bk):
                                    $sel = $form['block_code'] === (string)$bk['block_code'];
                                    if ($sel) $blkFound = true;
                                ?>
                                    <option value="<?= e((string)$bk['block_code']) ?>" <?= $sel ? 'selected' : '' ?>>
                                        <?= e($bk['block']) ?> (<?= e((string)$bk['block_code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                                <?php if (!$blkFound && $form['block_code'] !== ''): ?>
                                    <option value="<?= e($form['block_code']) ?>" selected><?= e($form['block'] ?: ('Block #' . $form['block_code'])) ?></option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="col-md-12">
                            <label for="vName" class="form-label small fw-bold">Village / Locality Name <span class="text-danger">*</span></label>
                            <input type="text" id="vName" name="village" value="<?= e($form['village']) ?>" maxlength="123" class="form-control" required placeholder="e.g. Dahiawan or Semaria Buzurg">
                        </div>

                        <div class="col-md-6">
                            <label for="vPin" class="form-label small fw-bold">6-Digit PIN Code <span class="text-danger">*</span></label>
                            <input type="text" id="vPin" name="pincode" value="<?= e($form['pincode']) ?>" maxlength="6" pattern="[1-9][0-9]{5}" inputmode="numeric" class="form-control" required placeholder="e.g. 841301">
                            <div class="form-text">Immediately powers <code>/pincode/&lt;pin&gt;</code> and search autocomplete.</div>
                        </div>

                        <div class="col-md-6">
                            <label for="vCode" class="form-label small fw-bold">LGD Village Code (Optional)</label>
                            <input type="number" id="vCode" name="village_code" value="<?= e($form['village_code']) ?>" min="1" class="form-control" placeholder="Official Census/LGD code (optional)">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="ppms-card">
                <div class="ppms-card-header">
                    <h3 class="ppms-card-title"><i class="fas fa-sitemap text-info"></i> LGD Hierarchy</h3>
                </div>
                <div class="ppms-card-body small text-secondary">
                    Official district and block names are synchronized automatically from your selected State, District, and Block hierarchy so every PIN code lookup maps cleanly to India's LGD directory.
                </div>
            </div>

            <div class="ppms-card mb-0">
                <div class="ppms-card-header">
                    <h3 class="ppms-card-title"><i class="fas fa-save text-primary"></i> Publish &amp; Actions</h3>
                </div>
                <div class="ppms-card-body">
                    <button type="submit" class="btn btn-ppms-saffron w-100 py-2 mb-2">
                        <i class="fas fa-save me-1"></i> <?= $isEdit ? 'Save Village Changes' : 'Create Village Record' ?>
                    </button>
                    <a href="<?= e(get_site_url('ppms/villages')) ?>" class="btn btn-light border w-100 btn-sm fw-semibold">
                        Cancel
                    </a>

                    <?php if ($isEdit): ?>
                        <hr class="my-3">
                        <button type="button" class="btn btn-outline-danger btn-sm w-100" data-bs-toggle="modal" data-bs-target="#deleteSingleVillageModal">
                            <i class="fas fa-trash-alt me-1"></i> Delete This Village
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</form>

<?php if ($isEdit): ?>
<div class="modal fade" id="deleteSingleVillageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold fs-6"><i class="fas fa-trash-alt me-2"></i> Delete Village #<?= $editId ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body small">
                Permanently remove <strong><?= e($form['village']) ?></strong> (PIN <?= e($form['pincode']) ?>) from <code>village</code>?
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light border btn-sm" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="<?= e(get_site_url('ppms/village-form?id=' . $editId)) ?>" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= e(ppms_csrf_token()) ?>">
                    <input type="hidden" name="village_id" value="<?= $editId ?>">
                    <input type="hidden" name="form_action" value="delete">
                    <button type="submit" class="btn btn-danger btn-sm fw-bold">Delete Permanently</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$locApiJson = json_encode(get_site_url('api/locations'));
$ppmsExtraScripts = <<<HTML
<script>
const VFORM_LOC_API = {$locApiJson};

document.getElementById('vState')?.addEventListener('change', async function () {
    const distSel = document.getElementById('vDistrict');
    const blkSel = document.getElementById('vBlock');
    if (distSel) distSel.innerHTML = '<option value="">Select District</option>';
    if (blkSel) blkSel.innerHTML = '<option value="">Select Block</option>';
    if (!this.value || !distSel) return;
    try {
        const res = await fetch(VFORM_LOC_API + '?type=districts&state_code=' + encodeURIComponent(this.value));
        const json = await res.json();
        if (json.success && Array.isArray(json.data)) {
            json.data.forEach((d) => {
                const opt = document.createElement('option');
                opt.value = d.district_code;
                opt.textContent = d.district + ' (' + d.district_code + ')';
                distSel.appendChild(opt);
            });
        }
    } catch (e) {}
});

document.getElementById('vDistrict')?.addEventListener('change', async function () {
    const blkSel = document.getElementById('vBlock');
    if (blkSel) blkSel.innerHTML = '<option value="">Select Block</option>';
    if (!this.value || !blkSel) return;
    try {
        const res = await fetch(VFORM_LOC_API + '?type=blocks&district_code=' + encodeURIComponent(this.value));
        const json = await res.json();
        if (json.success && Array.isArray(json.data)) {
            json.data.forEach((b) => {
                const opt = document.createElement('option');
                opt.value = b.block_code;
                opt.textContent = b.block + ' (' + b.block_code + ')';
                blkSel.appendChild(opt);
            });
        }
    } catch (e) {}
});
</script>
HTML;
require_once __DIR__ . '/includes/layout_bottom.php';
?>

