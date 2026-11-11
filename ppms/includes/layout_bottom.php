<?php
// ppms/includes/layout_bottom.php - Shared Footer & Scripts for PPMS
if (!defined('PPMS_INIT')) {
    http_response_code(403);
    exit('Forbidden');
}
require_once __DIR__ . '/../../includes/vcs.php';
$ppmsVersionMeta = localneta_app_version();
$ppmsVersionLabel = 'v' . (string)($ppmsVersionMeta['version'] ?? '1.2.0');
?>
        </main>

        <footer class="ppms-footer">
            <div>
                <strong>LocalNeta PPMS</strong> &mdash; Political Party Management System
                <span class="badge bg-light text-dark border ms-1"><?= e($ppmsVersionLabel) ?></span>
            </div>
            <div>
                An initiative of <strong>OfferPlant</strong> &bull; Local Neta (localneta.com)
            </div>
        </footer>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePpmsSidebar(open) {
    const sb = document.getElementById('ppmsSidebar');
    const bd = document.getElementById('ppmsBackdrop');
    if (!sb || !bd) return;
    if (open) {
        sb.classList.add('open');
        bd.classList.add('show');
    } else {
        sb.classList.remove('open');
        bd.classList.remove('show');
    }
}
</script>
<?php if (!empty($ppmsExtraScripts)): ?>
    <?= $ppmsExtraScripts ?>
<?php endif; ?>
</body>
</html>

