<?php
// admin.php - Civic Moderation & Verification Panel (MySQL, English UI, Tiranga Palette)
define('APP_INIT', true);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$pdo = get_db_connection();

$leaderTypeGroups = get_leader_types($pdo, true, 'alpha');
$leaderTypeMap = [];
foreach (get_leader_types($pdo) as $ltItem) {
    $leaderTypeMap[$ltItem['slug']] = $ltItem;
}

// POST Actions: update leader category type across canonical 25-type taxonomy
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_leader_type') {
        $id = (int)($_POST['leader_id'] ?? 0);
        $newCat = normalize_leader_category_slug($_POST['category'] ?? '');
        if ($id > 0 && isset($leaderTypeMap[$newCat])) {
            $typeName = $leaderTypeMap[$newCat]['name'];
            $pdo->prepare("UPDATE leaders SET category = ?, designation_hi = ? WHERE id = ?")->execute([$newCat, $typeName, $id]);
            $msg = "Representative #$id updated to Type: {$typeName} ({$newCat})!";
        }
    }
}

// Actions: verify leader, approve issue, resolve issue
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    $id = (int)($_GET['id'] ?? 0);

    if ($action === 'verify_leader' && $id > 0) {
        $pdo->prepare("UPDATE leaders SET is_verified = 1, verification_badge = 'elected_verified' WHERE id = ?")->execute([$id]);
        $msg = "Representative #$id marked as Elected & Verified!";
    } elseif ($action === 'approve_issue' && $id > 0) {
        $pdo->prepare("UPDATE public_issues SET status = 'notified', status_label_hi = 'Forwarded to Leader' WHERE id = ?")->execute([$id]);
        $msg = "Issue #$id approved and forwarded to representative!";
    } elseif ($action === 'resolve_issue' && $id > 0) {
        $pdo->prepare("UPDATE public_issues SET status = 'resolved', status_label_hi = 'Resolution Verified' WHERE id = ?")->execute([$id]);
        $msg = "Issue #$id marked as resolved!";
    }
}

// Fetch all leaders
$leaders = $pdo->query("SELECT * FROM leaders ORDER BY id ASC")->fetchAll();

// Live type counts across leaders, mukhiya, politician, mp, and political_party
$typeCounts = [];
foreach ($pdo->query("SELECT category, COUNT(*) AS cnt FROM leaders GROUP BY category") as $row) {
    $norm = normalize_leader_category_slug($row['category']);
    $typeCounts[$norm] = ($typeCounts[$norm] ?? 0) + (int)$row['cnt'];
}
if ($pdo->query("SHOW TABLES LIKE 'mukhiya'")->fetch()) {
    $typeCounts['mukhiya'] = ($typeCounts['mukhiya'] ?? 0) + (int)$pdo->query("SELECT COUNT(*) FROM mukhiya")->fetchColumn();
}
if ($pdo->query("SHOW TABLES LIKE 'politician'")->fetch()) {
    foreach ($pdo->query("SELECT c_type, COUNT(*) AS cnt FROM politician WHERE LOWER(TRIM(name)) NOT IN ('', 'vacant') AND c_type IN ('MLA', 'MLC') GROUP BY c_type") as $row) {
        $slugKey = strtolower($row['c_type']);
        $typeCounts[$slugKey] = ($typeCounts[$slugKey] ?? 0) + (int)$row['cnt'];
    }
}
$typeCounts['mp-lok-sabha'] = ($typeCounts['mp-lok-sabha'] ?? 0) + (int)$pdo->query("SELECT COUNT(*) FROM mp WHERE type = 'ls'")->fetchColumn();
$typeCounts['mp-rajya-sabha'] = ($typeCounts['mp-rajya-sabha'] ?? 0) + (int)$pdo->query("SELECT COUNT(*) FROM mp WHERE type = 'rs'")->fetchColumn();
if ($pdo->query("SHOW TABLES LIKE 'political_party'")->fetch()) {
    $typeCounts['political-party-office'] = ($typeCounts['political-party-office'] ?? 0) + (int)$pdo->query("SELECT COUNT(*) FROM political_party WHERE UPPER(TRIM(status)) = 'ACTIVE'")->fetchColumn();
}

// Fetch all issues
$issues = $pdo->query("SELECT * FROM public_issues ORDER BY created_at DESC")->fetchAll();

$pageTitle = "Admin & Moderation Panel";
require_once __DIR__ . '/includes/header.php';
?>

<main class="main-wrapper">

    <div class="tiranga-banner" style="margin-bottom:24px;">
        <div>
            <h2><i class="fas fa-shield-halved" style="color:var(--saffron-main);"></i> LocalNeta Moderation & Verification Console</h2>
            <p>Ensure factual accuracy, verify elected representative credentials, and moderate citizen issue submissions.</p>
        </div>
        <span class="badge-tag badge-elected" style="background:#046A38; color:#fff;">
            Admin Safe-Harbor Desk
        </span>
    </div>

    <?php if (!empty($msg)): ?>
        <div style="background:#F0FDF4; border:1px solid var(--green-main); color:var(--green-dark); padding:12px 16px; border-radius:var(--radius-md); margin-bottom:20px; font-weight:600;">
            <i class="fas fa-check-circle"></i> <?= e($msg) ?>
        </div>
    <?php endif; ?>

    <!-- 1. Citizen Issues Moderation Table -->
    <section style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); padding:20px; margin-bottom:30px; box-shadow:var(--shadow-sm);">
        <div class="section-header">
            <h2 class="section-title"><i class="fas fa-bullhorn"></i> Citizen Issues Moderation Queue</h2>
            <span class="badge-tag badge-verified"><?= count($issues) ?> Total Reports</span>
        </div>

        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:0.88rem; text-align:left;">
                <thead>
                    <tr style="border-bottom:2px solid var(--border-color); background:var(--white-surface);">
                        <th style="padding:10px;">ID</th>
                        <th style="padding:10px;">Issue Title & Locality</th>
                        <th style="padding:10px;">Category</th>
                        <th style="padding:10px;">Reported By</th>
                        <th style="padding:10px;">Status</th>
                        <th style="padding:10px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($issues)): ?>
                        <tr>
                            <td colspan="6" style="padding:24px 12px; text-align:center; color:var(--text-muted);">
                                No citizen issues submitted yet.
                            </td>
                        </tr>
                    <?php else: ?>
                    <?php foreach ($issues as $iss): ?>
                        <tr style="border-bottom:1px solid var(--border-light);">
                            <td style="padding:10px; font-weight:700;">#<?= $iss['id'] ?></td>
                            <td style="padding:10px;">
                                <div style="font-weight:700; color:var(--navy-main);"><?= e($iss['title_hi']) ?></div>
                                <div style="font-size:0.75rem; color:var(--text-muted);"><?= e($iss['locality_hi']) ?></div>
                            </td>
                            <td style="padding:10px;"><span class="cat-pill bg-blue"><?= e($iss['category_name_hi']) ?></span></td>
                            <td style="padding:10px;"><?= e($iss['reported_by_name']) ?></td>
                            <td style="padding:10px;"><?= get_status_badge($iss['status'], $iss['status_label_hi']) ?></td>
                            <td style="padding:10px; white-space:nowrap;">
                                <?php if ($iss['status'] === 'under_review'): ?>
                                    <a href="<?= get_site_url('admin?action=approve_issue&id=' . $iss['id']) ?>" class="btn-primary-tiranga" style="padding:4px 10px; font-size:0.75rem;">
                                        Approve & Forward
                                    </a>
                                <?php elseif ($iss['status'] === 'notified'): ?>
                                    <a href="<?= get_site_url('admin?action=resolve_issue&id=' . $iss['id']) ?>" class="btn-green-tiranga" style="padding:4px 10px; font-size:0.75rem;">
                                        Mark Resolved
                                    </a>
                                <?php else: ?>
                                    <span style="color:var(--green-main); font-weight:700;"><i class="fas fa-check"></i> Complete</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- 2. Leader Verification Directory Table -->
    <section style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); padding:20px; box-shadow:var(--shadow-sm);">
        <div class="section-header">
            <h2 class="section-title"><i class="fas fa-user-check"></i> Representative Verification Directory</h2>
            <span class="badge-tag badge-elected"><?= count($leaders) ?> Registered Leaders</span>
        </div>

        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:0.88rem; text-align:left;">
                <thead>
                    <tr style="border-bottom:2px solid var(--border-color); background:var(--white-surface);">
                        <th style="padding:10px;">ID</th>
                        <th style="padding:10px;">Representative</th>
                        <th style="padding:10px;">Designation / Office</th>
                        <th style="padding:10px;">Category</th>
                        <th style="padding:10px;">Plan Tier</th>
                        <th style="padding:10px;">Verification</th>
                        <th style="padding:10px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($leaders)): ?>
                        <tr>
                            <td colspan="7" style="padding:24px 12px; text-align:center; color:var(--text-muted);">
                                No custom claimed representative profiles registered yet. Verified public records (Mukhiyas, MLAs, MLCs, MPs, and Political Parties) are listed in the directory below.
                            </td>
                        </tr>
                    <?php else: ?>
                    <?php foreach ($leaders as $l): ?>
                        <tr style="border-bottom:1px solid var(--border-light);">
                            <td style="padding:10px; font-weight:700;">#<?= $l['id'] ?></td>
                            <td style="padding:10px;">
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <img src="<?= e($l['avatar_url']) ?>" style="width:32px; height:32px; border-radius:50%; object-fit:cover;">
                                    <div>
                                        <div style="font-weight:700; color:var(--navy-main);"><?= e($l['full_name_en']) ?></div>
                                        <div style="font-size:0.75rem; color:var(--text-muted);"><?= e($l['constituency_name_hi'] ?: 'Saran') ?></div>
                                    </div>
                                </div>
                            </td>
                            <td style="padding:10px; font-weight:600; color:var(--green-main);"><?= e($l['designation_hi']) ?></td>
                            <td style="padding:10px;">
                                <div style="margin-bottom:6px;"><?= get_category_badge($l['category'], $pdo) ?></div>
                                <form method="POST" action="<?= get_site_url('admin') ?>" style="display:flex; gap:4px; align-items:center;">
                                    <input type="hidden" name="action" value="update_leader_type">
                                    <input type="hidden" name="leader_id" value="<?= (int)$l['id'] ?>">
                                    <select name="category" class="form-control" style="padding:3px 6px; font-size:0.74rem; min-width:165px;">
                                        <?php foreach ($leaderTypeGroups as $group): ?>
                                            <optgroup label="<?= (int)$group['group_no'] ?>. <?= e($group['group_name']) ?>">
                                                <?php foreach ($group['items'] as $lt): ?>
                                                    <option value="<?= e($lt['slug']) ?>" <?= normalize_leader_category_slug($l['category']) === $lt['slug'] ? 'selected' : '' ?>>
                                                        <?= e($lt['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn-green-tiranga" style="padding:3px 8px; font-size:0.72rem;" title="Update Leader Type">Save</button>
                                </form>
                            </td>
                            <td style="padding:10px;"><span class="badge-tag badge-elected"><?= strtoupper($l['plan_tier']) ?></span></td>
                            <td style="padding:10px;"><?= get_verification_badge_html($l['verification_badge'], (bool)$l['is_verified']) ?></td>
                            <td style="padding:10px;">
                                <a href="<?= get_site_url('profile?id=' . $l['id']) ?>" target="_blank" class="btn-profile-view" style="padding:4px 10px; font-size:0.75rem;">
                                    Profile
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- 3. Canonical 25-Type Leader Taxonomy Reference (`leader_types`) -->
    <section style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); padding:20px; margin-top:30px; box-shadow:var(--shadow-sm);">
        <div class="section-header" style="flex-wrap:wrap; gap:10px;">
            <h2 class="section-title"><i class="fas fa-layer-group"></i> Type of Leaders Taxonomy (<code>leader_types</code>)</h2>
            <span class="badge-tag badge-verified">25 Canonical Categories • 2 Groups</span>
        </div>

        <?php foreach ($leaderTypeGroups as $group): ?>
            <div style="<?= (int)$group['group_no'] > 1 ? 'margin-top:20px;' : '' ?>">
                <h3 style="font-size:0.95rem; font-weight:800; color:var(--navy-main); margin-bottom:10px;">
                    <?= (int)$group['group_no'] ?>. <?= e($group['group_name']) ?> (<?= count($group['items']) ?>)
                </h3>
                <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(230px, 1fr)); gap:10px;">
                    <?php foreach ($group['items'] as $lt):
                        $cnt = $typeCounts[$lt['slug']] ?? 0;
                    ?>
                        <a href="<?= get_site_url('leaders?cat=' . urlencode($lt['slug'])) ?>"
                           style="display:flex; flex-direction:column; gap:5px; padding:10px 12px; border:1px solid var(--border-color); border-radius:var(--radius-md); background:var(--white-surface); text-decoration:none;">
                            <div style="display:flex; justify-content:space-between; align-items:center; gap:6px;">
                                <?= get_category_badge($lt['slug'], $pdo) ?>
                                <span style="font-size:0.74rem; font-weight:700; color:var(--navy-main);"><?= number_format($cnt) ?> records</span>
                            </div>
                            <div style="font-size:0.75rem; color:var(--text-muted); display:flex; justify-content:space-between; gap:6px;">
                                <span><?= e($lt['governance_tier']) ?></span>
                                <code><?= e($lt['slug']) ?></code>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </section>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

