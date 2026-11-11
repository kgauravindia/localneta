<?php
// ppms/villages.php - Villages & PIN Codes CRUD Registry for LocalNeta PPMS (`village` + `op_sdb`)
define('PPMS_INIT', true);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/pincode_helper.php';

$adminUser = ppms_require_auth();
$pdo = ppms_db();

// Handle POST Actions (Safe Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    $returnQs = trim((string)($_POST['return_qs'] ?? ''));
    $redirectTarget = get_site_url('ppms/villages' . ($returnQs !== '' ? '?' . ltrim($returnQs, '?') : ''));

    if (!ppms_verify_csrf($csrf)) {
        ppms_set_flash('error', 'Security token expired. Please try again.');
        header('Location: ' . $redirectTarget, true, 302);
        exit;
    }

    $action = trim((string)($_POST['action'] ?? ''));
    $villageId = (int)($_POST['village_id'] ?? 0);

    if ($action === 'delete_village' && $villageId > 0) {
        $curStmt = $pdo->prepare("SELECT id, village, pincode, block, district FROM village WHERE id = ? LIMIT 1");
        $curStmt->execute([$villageId]);
        $curRow = $curStmt->fetch();
        if ($curRow) {
            $del = $pdo->prepare("DELETE FROM village WHERE id = ? LIMIT 1");
            $del->execute([$villageId]);
            $vName = trim((string)($curRow['village'] ?? 'Village #' . $villageId));
            $pinStr = !empty($curRow['pincode']) ? str_pad((string)$curRow['pincode'], 6, '0', STR_PAD_LEFT) : '-';
            ppms_invalidate_village_summary_cache();
            ppms_log_action(
                $pdo,
                $adminUser,
                'DELETE',
                $villageId,
                $vName,
                "Deleted village (PIN {$pinStr}, {$curRow['block']}, {$curRow['district']})",
                'village'
            );
            ppms_set_flash('success', "Deleted village #{$villageId}: {$vName} (PIN {$pinStr})");
        } else {
            ppms_set_flash('error', "Village #{$villageId} was not found.");
        }
        header('Location: ' . $redirectTarget, true, 302);
        exit;
    }
}

// GET Filters
$filterState = trim((string)($_GET['state_code'] ?? ''));
if ($filterState !== '' && !ctype_digit($filterState)) {
    $filterState = '';
}
$filterDistrict = trim((string)($_GET['district_code'] ?? ''));
if ($filterDistrict !== '' && !ctype_digit($filterDistrict)) {
    $filterDistrict = '';
}
$filterBlock = trim((string)($_GET['block_code'] ?? ''));
if ($filterBlock !== '' && !ctype_digit($filterBlock)) {
    $filterBlock = '';
}
$filterPin = normalize_pincode_digits((string)($_GET['pincode'] ?? ''));
$filterQuery = trim((string)($_GET['q'] ?? ''));
$sortKey = trim((string)($_GET['sort'] ?? 'id_desc'));
if (!in_array($sortKey, ['id_desc', 'id_asc', 'name_asc', 'pincode'], true)) {
    $sortKey = 'id_desc';
}
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

$ppmsVillageStats = ppms_village_summary($pdo);
$stateOptions = ppms_get_states($pdo);

$districtOptions = [];
if ($filterState !== '') {
    $ds = $pdo->prepare("SELECT DISTINCT district_code, district FROM op_sdb WHERE state_code = ? AND status = 'ACTIVE' ORDER BY district ASC");
    $ds->execute([$filterState]);
    $districtOptions = $ds->fetchAll();
}
$blockOptions = [];
if ($filterDistrict !== '') {
    $bs = $pdo->prepare("SELECT block_code, block FROM op_sdb WHERE district_code = ? AND status = 'ACTIVE' ORDER BY block ASC");
    $bs->execute([$filterDistrict]);
    $blockOptions = $bs->fetchAll();
}

$isUnfiltered = ($filterState === '' && $filterDistrict === '' && $filterBlock === '' && $filterPin === '' && $filterQuery === '');

$buildVillageWhere = static function (bool $forceSubstring = false) use ($filterState, $filterDistrict, $filterBlock, $filterPin, $filterQuery): array {
    $conds = [];
    $params = [];

    if ($filterState !== '') {
        $conds[] = "v.state_code = ?";
        $params[] = (int)$filterState;
    }
    if ($filterDistrict !== '') {
        $conds[] = "v.district_code = ?";
        $params[] = (int)$filterDistrict;
    }
    if ($filterBlock !== '') {
        $conds[] = "v.block_code = ?";
        $params[] = (int)$filterBlock;
    }
    if ($filterPin !== '') {
        if (is_valid_indian_pincode($filterPin)) {
            $conds[] = "v.pincode = ?";
            $params[] = (int)$filterPin;
        } elseif (is_pincode_prefix_query($filterPin)) {
            [$minPin, $maxPin] = get_pincode_prefix_bounds($filterPin);
            $conds[] = "v.pincode BETWEEN ? AND ?";
            $params[] = $minPin;
            $params[] = $maxPin;
        } else {
            $conds[] = "v.pincode = ?";
            $params[] = (int)$filterPin;
        }
    }
    if ($filterQuery !== '') {
        if (ctype_digit($filterQuery)) {
            if (is_valid_indian_pincode($filterQuery)) {
                $conds[] = "(v.pincode = ? OR v.village_code = ? OR v.id = ?)";
                array_push($params, (int)$filterQuery, (int)$filterQuery, (int)$filterQuery);
            } elseif (is_pincode_prefix_query($filterQuery)) {
                [$minPin, $maxPin] = get_pincode_prefix_bounds($filterQuery);
                $conds[] = "((v.pincode BETWEEN ? AND ?) OR v.id = ? OR v.village_code = ?)";
                array_push($params, $minPin, $maxPin, (int)$filterQuery, (int)$filterQuery);
            } else {
                $conds[] = "(v.id = ? OR v.village_code = ?)";
                array_push($params, (int)$filterQuery, (int)$filterQuery);
            }
        } else {
            if ($forceSubstring || $filterDistrict !== '' || $filterBlock !== '') {
                $conds[] = "(v.village LIKE ? OR v.block LIKE ? OR v.district LIKE ?)";
                $wild = '%' . $filterQuery . '%';
                array_push($params, $wild, $wild, $wild);
            } else {
                $conds[] = "v.village LIKE ?";
                $params[] = $filterQuery . '%';
            }
        }
    }

    $whereSql = empty($conds) ? " WHERE 1=1" : (" WHERE " . implode(" AND ", $conds));
    return [$whereSql, $params];
};

if ($isUnfiltered) {
    $filteredTotal = (int)$ppmsVillageStats['total_rows'];
    $whereSql = " WHERE 1=1";
    $params = [];
} else {
    [$whereSql, $params] = $buildVillageWhere(false);
    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM village v {$whereSql}");
    $cntStmt->execute($params);
    $filteredTotal = (int)$cntStmt->fetchColumn();

    if ($filteredTotal === 0 && $filterQuery !== '' && !ctype_digit($filterQuery)) {
        [$whereSql, $params] = $buildVillageWhere(true);
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM village v {$whereSql}");
        $cntStmt->execute($params);
        $filteredTotal = (int)$cntStmt->fetchColumn();
    }
}

$totalPages = max(1, (int)ceil($filteredTotal / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$orderBySql = match ($sortKey) {
    'id_asc' => "v.id ASC",
    'name_asc' => "v.village ASC, v.id ASC",
    'pincode' => "v.pincode ASC, v.village ASC, v.id ASC",
    default => "v.id DESC",
};

$dataSql = "
    SELECT v.id, v.state_code, v.district_code, v.district, v.block_code, v.block,
           v.village_code, v.village, v.pincode
    FROM village v
    {$whereSql}
    ORDER BY {$orderBySql}
    LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
$stmt = $pdo->prepare($dataSql);
$stmt->execute($params);
$rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sdbMaps = get_op_sdb_lookup_maps($pdo);
$rows = [];
foreach ($rawRows as $r) {
    $meta = resolve_village_op_sdb_meta($r, $sdbMaps);
    $rows[] = $r + [
        'state_name' => $meta['state_name'],
        'district_name' => $meta['district_name'],
        'block_name' => $meta['block_name'],
        'lgd_matched' => $meta['lgd_matched'],
    ];
}

$currentFilterParams = array_filter([
    'state_code' => $filterState,
    'district_code' => $filterDistrict,
    'block_code' => $filterBlock,
    'pincode' => $filterPin,
    'q' => $filterQuery,
    'sort' => $sortKey !== 'id_desc' ? $sortKey : '',
], static fn($v): bool => $v !== '');
$returnQs = http_build_query($currentFilterParams + ($page > 1 ? ['page' => $page] : []));

$activeNav = 'villages';
$ppmsPageTitle = 'Manage Villages & PIN Codes';
require_once __DIR__ . '/includes/layout_top.php';
?>

<!-- Filter & Quick Stats Card -->
<div class="ppms-card">
    <div class="ppms-card-header">
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2">
                <i class="fas fa-map-pin me-1"></i> <?= number_format($ppmsVillageStats['total_rows']) ?> Villages
            </span>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2">
                <i class="fas fa-mail-bulk me-1"></i> <?= number_format($ppmsVillageStats['distinct_pincodes']) ?> Unique PINs
            </span>
            <span class="badge bg-light text-dark border px-3 py-2">
                <?= number_format($ppmsVillageStats['sdb_blocks']) ?> LGD Blocks &bull; <?= number_format($ppmsVillageStats['sdb_districts']) ?> Districts
            </span>
        </div>

        <a href="<?= e(get_site_url('ppms/village-form')) ?>" class="btn btn-ppms-green btn-sm px-3">
            <i class="fas fa-plus me-1"></i> New Village / PIN
        </a>
    </div>

    <div class="ppms-card-body">
        <form method="GET" action="<?= e(get_site_url('ppms/villages')) ?>" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-bold text-secondary mb-1">Search (Village Name / Code / ID / PIN)</label>
                <input type="text" name="q" value="<?= e($filterQuery) ?>" class="form-control form-control-sm" placeholder="e.g. Dahiawan, 841301, 217000...">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-bold text-secondary mb-1">PIN Filter</label>
                <input type="text" name="pincode" value="<?= e($filterPin) ?>" maxlength="6" class="form-control form-control-sm" placeholder="e.g. 841301 or 841">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-bold text-secondary mb-1">State / UT</label>
                <select name="state_code" id="vfState" class="form-select form-select-sm">
                    <option value="">All States</option>
                    <?php foreach ($stateOptions as $st): ?>
                        <option value="<?= e((string)$st['state_code']) ?>" <?= $filterState === (string)$st['state_code'] ? 'selected' : '' ?>>
                            <?= e($st['state']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-bold text-secondary mb-1">District</label>
                <select name="district_code" id="vfDistrict" class="form-select form-select-sm">
                    <option value="">All Districts</option>
                    <?php foreach ($districtOptions as $dt): ?>
                        <option value="<?= e((string)$dt['district_code']) ?>" <?= $filterDistrict === (string)$dt['district_code'] ? 'selected' : '' ?>>
                            <?= e($dt['district']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-bold text-secondary mb-1">Block</label>
                <select name="block_code" id="vfBlock" class="form-select form-select-sm">
                    <option value="">All Blocks</option>
                    <?php foreach ($blockOptions as $bk): ?>
                        <option value="<?= e((string)$bk['block_code']) ?>" <?= $filterBlock === (string)$bk['block_code'] ? 'selected' : '' ?>>
                            <?= e($bk['block']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1 d-flex gap-1">
                <button type="submit" class="btn btn-ppms-navy btn-sm flex-fill" title="Apply Filter"><i class="fas fa-filter"></i></button>
                <?php if (!empty($currentFilterParams)): ?>
                    <a href="<?= e(get_site_url('ppms/villages')) ?>" class="btn btn-light border btn-sm" title="Reset Filters"><i class="fas fa-times"></i></a>
                <?php endif; ?>
            </div>
            <div class="col-12 d-flex justify-content-between align-items-center pt-1">
                <div class="small text-muted">
                    Quick sample: <a href="<?= e(get_site_url('ppms/villages?pincode=841301')) ?>" class="text-decoration-none">841301 (Chapra)</a> &bull;
                    <a href="<?= e(get_site_url('ppms/villages?pincode=226002')) ?>" class="text-decoration-none">226002 (Lucknow)</a> &bull;
                    <a href="<?= e(get_site_url('ppms/villages?pincode=110010')) ?>" class="text-decoration-none">110010 (Delhi)</a>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="small text-secondary fw-semibold">Sort:</span>
                    <select name="sort" onchange="this.form.submit()" class="form-select form-select-sm" style="width:155px;">
                        <option value="id_desc" <?= $sortKey === 'id_desc' ? 'selected' : '' ?>>Newest ID First</option>
                        <option value="pincode" <?= $sortKey === 'pincode' ? 'selected' : '' ?>>PIN Code Asc</option>
                        <option value="name_asc" <?= $sortKey === 'name_asc' ? 'selected' : '' ?>>Village Name A-Z</option>
                        <option value="id_asc" <?= $sortKey === 'id_asc' ? 'selected' : '' ?>>ID Asc</option>
                    </select>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Results Table Card -->
<div class="ppms-card mb-0">
    <div class="ppms-card-header">
        <span class="small text-secondary fw-semibold">
            Showing <strong><?= count($rows) ?></strong> of <strong><?= number_format($filteredTotal) ?></strong> villages
            (Page <?= number_format($page) ?> of <?= number_format($totalPages) ?>)
        </span>
        <span class="small text-muted">Synced with India LGD District &amp; Block hierarchy</span>
    </div>

    <div class="table-responsive">
        <table class="table ppms-table mb-0">
            <thead>
                <tr>
                    <th>ID / LGD Code</th>
                    <th>Village Name</th>
                    <th>PIN Code</th>
                    <th>Block / Sub-District</th>
                    <th>District &amp; State</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-5 text-muted">
                            <i class="fas fa-map-pin fa-2x mb-2 d-block opacity-50"></i>
                            No villages matched your current search or filter criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row):
                        $vid = (int)$row['id'];
                        $vName = trim((string)($row['village'] ?? ''));
                        $pinNum = (int)($row['pincode'] ?? 0);
                        $pinStr = $pinNum > 0 ? str_pad((string)$pinNum, 6, '0', STR_PAD_LEFT) : '';
                        $publicPinUrl = $pinStr !== '' ? get_pincode_page_url($pinStr) : get_site_url('pincode');
                    ?>
                        <tr>
                            <td class="text-nowrap">
                                <div class="fw-bold text-dark">#<?= number_format($vid) ?></div>
                                <?php if (!empty($row['village_code'])): ?>
                                    <div class="text-muted" style="font-size:0.72rem;">Code: <?= e((string)$row['village_code']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="min-width:210px;">
                                <a href="<?= e(get_site_url('ppms/village-form?id=' . $vid)) ?>" class="fw-bold text-decoration-none text-dark d-block">
                                    <?= e($vName ?: ('Village #' . $vid)) ?>
                                </a>
                            </td>
                            <td class="text-nowrap">
                                <?php if ($pinStr !== ''): ?>
                                    <a href="<?= e(get_site_url('ppms/villages?pincode=' . $pinStr)) ?>" class="badge bg-warning-subtle text-dark border border-warning-subtle text-decoration-none fs-6" style="font-size:0.8rem!important;">
                                        <?= e($pinStr) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <div class="fw-semibold text-dark"><?= e($row['block_name'] ?: ($row['block'] ?: '-')) ?></div>
                                <?php if (!empty($row['block_code'])): ?>
                                    <div class="text-muted" style="font-size:0.7rem;">
                                        Blk #<?= e((string)$row['block_code']) ?>
                                        <?= $row['lgd_matched'] ? '<span class="text-success">&bull; LGD</span>' : '' ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <div class="text-dark fw-semibold"><?= e($row['district_name'] ?: ($row['district'] ?: '-')) ?></div>
                                <div class="text-muted" style="font-size:0.72rem;"><?= e($row['state_name'] ?: ('State #' . (string)$row['state_code'])) ?></div>
                            </td>
                            <td class="text-end text-nowrap">
                                <a href="<?= e(get_site_url('ppms/village-form?id=' . $vid)) ?>" class="btn btn-outline-primary btn-sm py-1 px-2" title="Edit Village">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="<?= e($publicPinUrl) ?>" target="_blank" rel="noopener" class="btn btn-outline-success btn-sm py-1 px-2" title="View Public PIN Page">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <button type="button"
                                        class="btn btn-outline-danger btn-sm py-1 px-2"
                                        title="Delete Village"
                                        data-village-id="<?= $vid ?>"
                                        data-village-name="<?= e($vName . ($pinStr !== '' ? ' (PIN ' . $pinStr . ')' : '')) ?>"
                                        onclick="openDeleteVillageModal(this)">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="ppms-card-body border-top py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="small text-muted">Page <?= number_format($page) ?> of <?= number_format($totalPages) ?></div>
            <div class="d-flex gap-1 flex-wrap">
                <?php if ($page > 1): ?>
                    <a href="<?= e(get_site_url('ppms/villages?' . http_build_query($currentFilterParams + ['page' => 1]))) ?>" class="btn btn-light border btn-sm">&laquo; First</a>
                    <a href="<?= e(get_site_url('ppms/villages?' . http_build_query($currentFilterParams + ['page' => $page - 1]))) ?>" class="btn btn-light border btn-sm">&lsaquo; Prev</a>
                <?php endif; ?>

                <?php
                $winStart = max(1, $page - 2);
                $winEnd = min($totalPages, $page + 2);
                for ($p = $winStart; $p <= $winEnd; $p++):
                ?>
                    <a href="<?= e(get_site_url('ppms/villages?' . http_build_query($currentFilterParams + ['page' => $p]))) ?>"
                       class="btn btn-sm <?= $p === $page ? 'btn-ppms-navy' : 'btn-light border' ?>">
                        <?= number_format($p) ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="<?= e(get_site_url('ppms/villages?' . http_build_query($currentFilterParams + ['page' => $page + 1]))) ?>" class="btn btn-light border btn-sm">Next &rsaquo;</a>
                    <a href="<?= e(get_site_url('ppms/villages?' . http_build_query($currentFilterParams + ['page' => $totalPages]))) ?>" class="btn btn-light border btn-sm">Last &raquo;</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Safe Delete Confirmation Modal -->
<div class="modal fade" id="ppmsDeleteVillageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold fs-6"><i class="fas fa-exclamation-triangle me-2"></i> Confirm Delete Village</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2 small text-secondary">Are you sure you want to permanently remove this record from <code>village</code>?</p>
                <div class="p-2 rounded bg-light border fw-bold text-dark small" id="deleteModalVillageName"></div>
                <p class="text-danger mt-2 mb-0" style="font-size:0.76rem;">This immediately updates public PIN code search results and the XML sitemap.</p>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light border btn-sm" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="<?= e(get_site_url('ppms/villages')) ?>" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= e(ppms_csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete_village">
                    <input type="hidden" name="village_id" id="deleteModalVillageId" value="">
                    <input type="hidden" name="return_qs" value="<?= e($returnQs) ?>">
                    <button type="submit" class="btn btn-danger btn-sm fw-bold">
                        <i class="fas fa-trash-alt me-1"></i> Delete Permanently
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
$locApiJson = json_encode(get_site_url('api/locations'));
$ppmsExtraScripts = <<<HTML
<script>
const PPMS_LOC_API = {$locApiJson};

function openDeleteVillageModal(btn) {
    const id = btn.getAttribute('data-village-id') || '';
    const name = btn.getAttribute('data-village-name') || '';
    document.getElementById('deleteModalVillageId').value = id;
    document.getElementById('deleteModalVillageName').textContent = '#' + id + ' — ' + name;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('ppmsDeleteVillageModal')).show();
}

document.getElementById('vfState')?.addEventListener('change', async function () {
    const distSel = document.getElementById('vfDistrict');
    const blkSel = document.getElementById('vfBlock');
    if (distSel) distSel.innerHTML = '<option value="">All Districts</option>';
    if (blkSel) blkSel.innerHTML = '<option value="">All Blocks</option>';
    if (!this.value || !distSel) return;
    try {
        const res = await fetch(PPMS_LOC_API + '?type=districts&state_code=' + encodeURIComponent(this.value));
        const json = await res.json();
        if (json.success && Array.isArray(json.data)) {
            json.data.forEach((d) => {
                const opt = document.createElement('option');
                opt.value = d.district_code;
                opt.textContent = d.district;
                distSel.appendChild(opt);
            });
        }
    } catch (e) {}
});

document.getElementById('vfDistrict')?.addEventListener('change', async function () {
    const blkSel = document.getElementById('vfBlock');
    if (blkSel) blkSel.innerHTML = '<option value="">All Blocks</option>';
    if (!this.value || !blkSel) return;
    try {
        const res = await fetch(PPMS_LOC_API + '?type=blocks&district_code=' + encodeURIComponent(this.value));
        const json = await res.json();
        if (json.success && Array.isArray(json.data)) {
            json.data.forEach((b) => {
                const opt = document.createElement('option');
                opt.value = b.block_code;
                opt.textContent = b.block;
                blkSel.appendChild(opt);
            });
        }
    } catch (e) {}
});
</script>
HTML;
require_once __DIR__ . '/includes/layout_bottom.php';
?>

