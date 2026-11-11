<?php
// ppms/symbols.php - ECI Election Symbols Catalog for LocalNeta PPMS
define('PPMS_INIT', true);
require_once __DIR__ . '/includes/auth.php';

$adminUser = ppms_require_auth();
$pdo = ppms_db();

// Handle PNG Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_symbol') {
    if (!ppms_verify_csrf((string)($_POST['csrf_token'] ?? ''))) {
        ppms_set_flash('error', 'Security token expired. Please try again.');
        header('Location: ' . get_site_url('ppms/symbols'), true, 302);
        exit;
    }

    $rawLabel = trim((string)($_POST['symbol_label'] ?? ''));
    if ($rawLabel === '') {
        ppms_set_flash('error', 'Please provide a symbol name (e.g. "Flying Bird").');
    } elseif (empty($_FILES['symbol_png']) || (int)$_FILES['symbol_png']['error'] !== UPLOAD_ERR_OK) {
        ppms_set_flash('error', 'Please choose a valid PNG symbol file to upload.');
    } elseif ((int)$_FILES['symbol_png']['size'] > 512 * 1024) {
        ppms_set_flash('error', 'Symbol PNG file size must be 512 KB or less.');
    } else {
        $tmp = (string)$_FILES['symbol_png']['tmp_name'];
        if (@exif_imagetype($tmp) !== IMAGETYPE_PNG) {
            ppms_set_flash('error', 'Only genuine PNG image files are accepted.');
        } else {
            $symKey = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(str_replace('&', ' and ', $rawLabel))) ?? '', '_');
            if ($symKey === '') {
                ppms_set_flash('error', 'Invalid symbol name.');
            } else {
                $destDir = __DIR__ . '/../../assets/images/symbols/';
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0755, true);
                }
                $filename = $symKey . '.png';
                if (move_uploaded_file($tmp, $destDir . $filename)) {
                    ppms_log_action($pdo, $adminUser, 'SYMBOL_UPLOAD', null, $rawLabel, "Uploaded symbol icon {$filename}");
                    ppms_set_flash('success', "Saved symbol PNG '{$filename}' for '{$rawLabel}'.");
                } else {
                    ppms_set_flash('error', 'Could not save uploaded symbol file.');
                }
            }
        }
    }
    header('Location: ' . get_site_url('ppms/symbols'), true, 302);
    exit;
}

$catalog = ppms_get_symbol_catalog();
$summary = ppms_party_summary($pdo);
$ppmsTotalParties = $summary['total_rows'];

// Count usage of symbols across political_party
$usageCounts = [];
$symRows = $pdo->query("SELECT symbol FROM political_party WHERE symbol IS NOT NULL AND TRIM(symbol) <> ''")->fetchAll(PDO::FETCH_COLUMN);
foreach ($symRows as $sTxt) {
    $info = get_party_symbol_image((string)$sTxt);
    if ($info && !empty($info['filename'])) {
        $fn = $info['filename'];
        $usageCounts[$fn] = ($usageCounts[$fn] ?? 0) + 1;
    }
}

$activeNav = 'symbols';
$ppmsPageTitle = 'Election Symbols Catalog';
require_once __DIR__ . '/includes/layout_top.php';
?>

<div class="row g-3 mb-3">
    <div class="col-lg-7">
        <div class="ppms-card h-100 mb-0">
            <div class="ppms-card-header">
                <h2 class="ppms-card-title"><i class="fas fa-icons text-warning"></i> Reserved &amp; Free Election Symbols (<?= count($catalog) ?> PNGs)</h2>
            </div>
            <div class="ppms-card-body">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light"><i class="fas fa-search"></i></span>
                    <input type="text" id="symbolGalleryFilter" class="form-control" placeholder="Search symbol PNG by name...">
                </div>
                <div class="small text-muted mt-2">Click any symbol tile to filter all political parties assigned that symbol.</div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="ppms-card h-100 mb-0">
            <div class="ppms-card-header">
                <h3 class="ppms-card-title"><i class="fas fa-upload text-success"></i> Upload New Symbol PNG</h3>
            </div>
            <div class="ppms-card-body">
                <form method="POST" action="<?= e(get_site_url('ppms/symbols')) ?>" enctype="multipart/form-data" class="row g-2 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?= e(ppms_csrf_token()) ?>">
                    <input type="hidden" name="action" value="upload_symbol">
                    <div class="col-sm-6">
                        <label class="form-label small fw-bold mb-1">Symbol Name</label>
                        <input type="text" name="symbol_label" class="form-control form-control-sm" placeholder="e.g. Electric Pole" required>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small fw-bold mb-1">PNG File (&le;512 KB)</label>
                        <input type="file" name="symbol_png" accept="image/png" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-ppms-green btn-sm w-100">
                            <i class="fas fa-cloud-upload-alt me-1"></i> Upload PNG Icon
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="ppms-card mb-0">
    <div class="ppms-card-body">
        <div class="ppms-sym-grid" id="symbolGalleryGrid">
            <?php foreach ($catalog as $item):
                $cnt = $usageCounts[$item['filename']] ?? 0;
            ?>
                <a href="<?= e(get_site_url('ppms/parties?q=' . urlencode($item['label']))) ?>"
                   class="ppms-sym-tile"
                   data-label="<?= e(strtolower($item['label'] . ' ' . $item['filename'])) ?>">
                    <img src="<?= e($item['url']) ?>" alt="<?= e($item['label']) ?>" loading="lazy">
                    <div class="ppms-sym-tile-label"><?= e($item['label']) ?></div>
                    <div class="text-muted mt-1" style="font-size:0.68rem;">
                        <?= $cnt > 0 ? ($cnt . ' ' . ($cnt === 1 ? 'party' : 'parties')) : 'Available icon' ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php
$ppmsExtraScripts = <<<'HTML'
<script>
document.getElementById('symbolGalleryFilter')?.addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    document.querySelectorAll('#symbolGalleryGrid .ppms-sym-tile').forEach((tile) => {
        const hay = tile.getAttribute('data-label') || '';
        tile.style.display = (q === '' || hay.includes(q)) ? '' : 'none';
    });
});
</script>
HTML;
require_once __DIR__ . '/includes/layout_bottom.php';
?>

