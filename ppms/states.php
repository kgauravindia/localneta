<?php
// ppms/states.php - State / UT Political Party Distribution Breakdown for LocalNeta PPMS
define('PPMS_INIT', true);
require_once __DIR__ . '/includes/auth.php';

$adminUser = ppms_require_auth();
$pdo = ppms_db();
$summary = ppms_party_summary($pdo);
$ppmsTotalParties = $summary['total_rows'];

$stateRows = $pdo->query("
    SELECT s.state_code,
           s.state AS state_name,
           COUNT(pp.id) AS total_cnt,
           SUM(TRIM(pp.type) = 'National') AS national_hq_cnt,
           SUM(TRIM(pp.type) = 'State') AS state_cnt,
           SUM(TRIM(pp.type) = 'Registered') AS registered_cnt,
           SUM(UPPER(TRIM(pp.status)) = 'ACTIVE') AS active_cnt,
           SUM(UPPER(TRIM(pp.status)) = 'INACTIVE') AS inactive_cnt,
           SUM(UPPER(TRIM(pp.status)) = 'DELISTED') AS delisted_cnt,
           SUM(pp.symbol IS NOT NULL AND TRIM(pp.symbol) <> '') AS symbol_cnt
    FROM (SELECT DISTINCT state_code, state FROM op_sdb WHERE status = 'ACTIVE' AND state_code <> '' AND state <> '') s
    LEFT JOIN political_party pp ON pp.state_code = s.state_code
    GROUP BY s.state_code, s.state
    ORDER BY total_cnt DESC, s.state ASC
")->fetchAll();

$activeNav = 'states';
$ppmsPageTitle = 'States & UTs Breakdown';
require_once __DIR__ . '/includes/layout_top.php';
?>

<div class="ppms-card mb-0">
    <div class="ppms-card-header">
        <div>
            <h2 class="ppms-card-title"><i class="fas fa-map-marked-alt text-warning"></i> Political Parties by State &amp; UT</h2>
            <div class="small text-muted">Mapped across all <?= count($stateRows) ?> active States &amp; UTs across India</div>
        </div>
        <a href="<?= e(get_site_url('ppms/parties')) ?>" class="btn btn-ppms-navy btn-sm">
            <i class="fas fa-list me-1"></i> View All Parties
        </a>
    </div>

    <div class="table-responsive">
        <table class="table ppms-table mb-0">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>State / UT</th>
                    <th class="text-end">Total</th>
                    <th class="text-end">National HQ</th>
                    <th class="text-end">State Party</th>
                    <th class="text-end">Registered</th>
                    <th class="text-end">Active</th>
                    <th class="text-end">Inactive</th>
                    <th class="text-end">Delisted</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stateRows as $sr): ?>
                    <tr>
                        <td><code><?= e($sr['state_code']) ?></code></td>
                        <td class="fw-bold text-dark"><?= e($sr['state_name']) ?></td>
                        <td class="text-end fw-bold"><?= number_format((int)$sr['total_cnt']) ?></td>
                        <td class="text-end text-primary fw-semibold"><?= (int)$sr['national_hq_cnt'] ?></td>
                        <td class="text-end text-success fw-semibold"><?= (int)$sr['state_cnt'] ?></td>
                        <td class="text-end"><?= number_format((int)$sr['registered_cnt']) ?></td>
                        <td class="text-end text-success fw-semibold"><?= number_format((int)$sr['active_cnt']) ?></td>
                        <td class="text-end text-warning fw-semibold"><?= number_format((int)$sr['inactive_cnt']) ?></td>
                        <td class="text-end text-danger fw-semibold"><?= number_format((int)$sr['delisted_cnt']) ?></td>
                        <td class="text-end text-nowrap">
                            <a href="<?= e(get_site_url('ppms/parties?state_code=' . urlencode((string)$sr['state_code']))) ?>" class="btn btn-outline-primary btn-sm py-0 px-2">
                                Parties
                            </a>
                            <a href="<?= e(get_site_url('ppms/party-form?state_code=' . urlencode((string)$sr['state_code']))) ?>" class="btn btn-outline-success btn-sm py-0 px-2" title="Add Party in <?= e($sr['state_name']) ?>">
                                <i class="fas fa-plus"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>

