<?php
// issues.php - Public Issues & Development Tracker (MySQL, English UI, Tiranga Palette)
define('APP_INIT', true);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$pdo = get_db_connection();

$activeTab = $_GET['tab'] ?? 'works'; // 'works' or 'issues'
$selectedCat = $_GET['cat'] ?? 'all';

// 1. Fetch Development Projects
$worksSql = "SELECT d.*, l.full_name_en as leader_name, l.designation_hi as leader_post FROM development_works d JOIN leaders l ON d.leader_id = l.id WHERE 1=1";
$worksParams = [];
if ($selectedCat !== 'all') {
    $worksSql .= " AND d.category = ?";
    $worksParams[] = $selectedCat;
}
$worksSql .= " ORDER BY d.created_at DESC";
$stmt = $pdo->prepare($worksSql);
$stmt->execute($worksParams);
$projects = $stmt->fetchAll();

// 2. Fetch Citizen Public Issues
$issuesSql = "SELECT * FROM public_issues WHERE 1=1";
$issuesParams = [];
if ($selectedCat !== 'all') {
    $issuesSql .= " AND category = ?";
    $issuesParams[] = $selectedCat;
}
$issuesSql .= " ORDER BY upvotes DESC, created_at DESC";
$stmt = $pdo->prepare($issuesSql);
$stmt->execute($issuesParams);
$issues = $stmt->fetchAll();

// Fetch States from op_sdb for the Report Issue form (no pre-fixed location)
$issueStates = $pdo->query("SELECT DISTINCT state_code, state FROM op_sdb WHERE status = 'ACTIVE' ORDER BY state ASC")->fetchAll();

$pageTitle = "Issues & Development Tracker";
require_once __DIR__ . '/includes/header.php';
?>

<main class="main-wrapper">

    <!-- Page Header Banner -->
    <div class="tiranga-banner" style="margin-bottom:20px;">
        <div>
            <h2><i class="fas fa-person-digging" style="color:var(--saffron-main);"></i> Development & Public Issues Tracker</h2>
            <p>Track verified public projects, budgets, and submit local community issues for representative attention.</p>
        </div>
        <button type="button" class="btn-primary-tiranga" onclick="openReportIssueModal()" style="padding:8px 16px; font-size:0.88rem;">
            <i class="fas fa-plus-circle"></i> Report an Issue
        </button>
    </div>

    <!-- Main Navigation Tab Pills -->
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
        <div style="display:flex; gap:10px;">
            <a href="<?= get_site_url('issues?tab=works' . ($selectedCat !== 'all' ? '&cat=' . $selectedCat : '')) ?>" 
               style="padding:8px 20px; border-radius:var(--radius-full); font-weight:700; font-size:0.95rem; border:1px solid <?= $activeTab === 'works' ? 'var(--navy-main)' : 'var(--border-color)' ?>; background:<?= $activeTab === 'works' ? 'var(--navy-main)' : '#FFFFFF' ?>; color:<?= $activeTab === 'works' ? '#FFFFFF' : 'var(--text-secondary)' ?>;">
                <i class="fas fa-road"></i> Vikas Tracker (<?= count($projects) ?>)
            </a>
            <a href="<?= get_site_url('issues?tab=issues' . ($selectedCat !== 'all' ? '&cat=' . $selectedCat : '')) ?>" 
               style="padding:8px 20px; border-radius:var(--radius-full); font-weight:700; font-size:0.95rem; border:1px solid <?= $activeTab === 'issues' ? 'var(--navy-main)' : 'var(--border-color)' ?>; background:<?= $activeTab === 'issues' ? 'var(--navy-main)' : '#FFFFFF' ?>; color:<?= $activeTab === 'issues' ? '#FFFFFF' : 'var(--text-secondary)' ?>;">
                <i class="fas fa-bullhorn"></i> Citizen Issues (<?= count($issues) ?>)
            </a>
        </div>

        <!-- Category Dropdown Filter -->
        <div style="display:flex; align-items:center; gap:8px;">
            <span style="font-size:0.85rem; font-weight:600; color:var(--text-muted);"><i class="fas fa-filter"></i> Category:</span>
            <select class="form-control" style="padding:6px 12px; font-size:0.85rem; width:auto;" onchange="window.location.href = '<?= get_site_url('issues?tab=' . $activeTab . '&cat=') ?>' + this.value">
                <option value="all" <?= $selectedCat === 'all' ? 'selected' : '' ?>>All Categories</option>
                <option value="road" <?= $selectedCat === 'road' ? 'selected' : '' ?>>Roads & Drainage</option>
                <option value="water" <?= $selectedCat === 'water' ? 'selected' : '' ?>>Drinking Water (Nal-Jal)</option>
                <option value="solar" <?= $selectedCat === 'solar' ? 'selected' : '' ?>>Street Lighting & Solar</option>
                <option value="school" <?= $selectedCat === 'school' ? 'selected' : '' ?>>Education & Schools</option>
                <option value="health" <?= $selectedCat === 'health' ? 'selected' : '' ?>>Health & Sub-Centers</option>
            </select>
        </div>
    </div>

    <!-- TAB CONTENT 1: VIKAS TRACKER -->
    <?php if ($activeTab === 'works'): ?>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:18px;">
            <?php if (empty($projects)): ?>
                <div style="grid-column: 1 / -1; background:#fff; padding:40px; text-align:center; border-radius:var(--radius-lg); border:1px dashed var(--border-color);">
                    <i class="fas fa-road" style="font-size:2.5rem; color:var(--text-muted); margin-bottom:12px;"></i>
                    <h3 style="font-size:1.1rem; color:var(--navy-main);">No development works found in this category</h3>
                </div>
            <?php else: ?>
                <?php foreach ($projects as $w): ?>
                    <div class="project-card" id="work-<?= $w['id'] ?>">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <span class="cat-pill bg-blue"><?= e($w['category_name_hi']) ?></span>
                            <?= get_status_badge($w['status'], $w['status_label_hi']) ?>
                        </div>

                        <h3 class="project-title"><?= e($w['title_hi']) ?></h3>

                        <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.88rem; font-weight:700; color:var(--navy-main); border-bottom:1px solid var(--border-light); padding-bottom:8px;">
                            <span>Cost: <?= format_rupees_lakh((float)$w['cost_lakhs']) ?></span>
                            <span style="font-size:0.8rem; font-weight:600; color:var(--green-main);"><i class="fas fa-user-check"></i> <?= e($w['leader_name']) ?></span>
                        </div>

                        <?php if (!empty($w['photo_before']) || !empty($w['photo_after'])): ?>
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin:4px 0;">
                                <?php if (!empty($w['photo_before'])): ?>
                                    <div>
                                        <div style="font-size:0.72rem; color:var(--text-muted); margin-bottom:2px;">Before Work</div>
                                        <img src="<?= e($w['photo_before']) ?>" style="width:100%; height:95px; object-fit:cover; border-radius:6px;">
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($w['photo_after'])): ?>
                                    <div>
                                        <div style="font-size:0.72rem; color:var(--green-main); margin-bottom:2px; font-weight:700;">After Completion</div>
                                        <img src="<?= e($w['photo_after']) ?>" style="width:100%; height:95px; object-fit:cover; border-radius:6px;">
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($w['source_doc_info'])): ?>
                            <div style="font-size:0.75rem; background:var(--white-surface); padding:8px 10px; border-radius:6px; color:var(--text-secondary); border-left:3px solid var(--green-main); margin-top:auto;">
                                <strong>Official Grant Reference:</strong> <?= e($w['source_doc_info']) ?>
                            </div>
                        <?php endif; ?>

                        <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.78rem; color:var(--text-muted); border-top:1px solid var(--border-light); padding-top:8px;">
                            <span><i class="fas fa-calendar-alt"></i> Sanction: <?= e($w['sanction_date'] ?: 'N/A') ?></span>
                            <span><i class="fas fa-thumbs-up" style="color:var(--saffron-main);"></i> <?= $w['upvotes'] ?> Verified</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    <!-- TAB CONTENT 2: CITIZEN PUBLIC ISSUES -->
    <?php else: ?>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:18px;">
            <?php if (empty($issues)): ?>
                <div style="grid-column: 1 / -1; background:#fff; padding:40px; text-align:center; border-radius:var(--radius-lg); border:1px dashed var(--border-color);">
                    <i class="fas fa-bullhorn" style="font-size:2.5rem; color:var(--text-muted); margin-bottom:12px;"></i>
                    <h3 style="font-size:1.1rem; color:var(--navy-main);">No citizen issues reported in this category</h3>
                    <p style="font-size:0.85rem; color:var(--text-muted); margin-top:4px;">Be the first to report an issue in your locality.</p>
                </div>
            <?php else: ?>
                <?php foreach ($issues as $iss): ?>
                    <div class="issue-card" id="issue-<?= $iss['id'] ?>">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <span class="cat-pill bg-amber"><?= e($iss['category_name_hi']) ?></span>
                            <?= get_status_badge($iss['status'], $iss['status_label_hi']) ?>
                        </div>

                        <h3 class="issue-title"><?= e($iss['title_hi']) ?></h3>
                        
                        <p style="font-size:0.88rem; color:var(--text-secondary); line-height:1.5;">
                            <?= nl2br(e($iss['description_hi'])) ?>
                        </p>

                        <?php if (!empty($iss['photo_url'])): ?>
                            <img src="<?= e($iss['photo_url']) ?>" style="width:100%; height:140px; object-fit:cover; border-radius:8px; margin:4px 0;">
                        <?php endif; ?>

                        <div class="issue-meta">
                            <span><i class="fas fa-map-pin" style="color:var(--saffron-main);"></i> <?= e($iss['locality_hi']) ?></span>
                            <span><i class="fas fa-user"></i> Reported by: <?= e($iss['reported_by_name']) ?></span>
                        </div>

                        <?php if (!empty($iss['official_response_hi'])): ?>
                            <div style="background:#F0FDF4; border-left:3px solid var(--green-main); padding:8px 12px; border-radius:6px; font-size:0.8rem; color:#14532D; margin-top:auto;">
                                <strong>Official Response:</strong> <?= e($iss['official_response_hi']) ?>
                            </div>
                        <?php else: ?>
                            <div style="font-size:0.75rem; color:var(--text-muted); font-style:italic; margin-top:auto;">
                                Forwarded to concerned Panchayat & Block authority.
                            </div>
                        <?php endif; ?>

                        <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid var(--border-light); padding-top:8px;">
                            <button type="button" class="btn-profile-view" style="flex:unset; padding:6px 14px; font-size:0.82rem;" onclick="upvoteIssue(<?= $iss['id'] ?>, this)">
                                <i class="fas fa-thumbs-up" style="color:var(--saffron-main);"></i> Support This Issue (<?= $iss['upvotes'] ?>)
                            </button>
                            <span style="font-size:0.75rem; color:var(--text-muted);"><i class="fas fa-clock"></i> <?= time_ago_en($iss['created_at']) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Report Public Issue Modal -->
    <div class="modal-overlay" id="reportIssueModal">
        <div class="modal-dialog">
            <div class="modal-header">
                <div class="modal-title"><i class="fas fa-triangle-exclamation" style="color:var(--saffron-main);"></i> Report a Local Public Issue</div>
                <button type="button" class="modal-close-btn" onclick="closeReportIssueModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form action="<?= get_site_url('api/issues') ?>" method="POST">
                    <div class="form-group">
                        <label class="form-label">Your Name</label>
                        <input type="text" name="reported_by_name" required placeholder="Full Name" class="form-control">
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                        <div class="form-group">
                            <label class="form-label">State / UT</label>
                            <select name="state_code" id="issueState" class="form-control" onchange="loadIssueDistricts(this.value)">
                                <option value="">Select State / UT</option>
                                <?php foreach ($issueStates as $st): ?>
                                    <option value="<?= e($st['state_code']) ?>">
                                        <?= e($st['state']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">District</label>
                            <select name="district_code" id="issueDistrict" class="form-control" onchange="loadIssueBlocks(this.value)">
                                <option value="">Select District</option>
                            </select>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                        <div class="form-group">
                            <label class="form-label">Block (Prakhand)</label>
                            <select name="block_code" id="issueBlock" class="form-control" onchange="loadIssuePanchayats(this.value)">
                                <option value="">Select Block (Prakhand)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Gram Panchayat</label>
                            <select name="panchayat_id" id="issuePanchayat" class="form-control">
                                <option value="">All / Block Headquarters</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Specific Locality / Ward</label>
                        <input type="text" name="locality_hi" required placeholder="e.g. Ward 03, Near Primary School" class="form-control">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <select name="category" class="form-control">
                            <option value="road">Roads & Drainage</option>
                            <option value="water">Drinking Water (Nal-Jal)</option>
                            <option value="solar">Street Lights</option>
                            <option value="health">Health Facility</option>
                            <option value="school">School Infrastructure</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Issue Headline</label>
                        <input type="text" name="title_hi" required placeholder="e.g. Broken drainage causing waterlogging on main road" class="form-control">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Detailed Description</label>
                        <textarea name="description_hi" rows="3" required placeholder="Describe what has happened, since when, and how many households are affected..." class="form-control"></textarea>
                    </div>

                    <p style="font-size:0.75rem; color:var(--text-muted); margin-bottom:12px;">
                        *Submissions are reviewed by moderators before public publishing to prevent defamatory allegations.
                    </p>

                    <button type="submit" class="btn-primary-tiranga" style="width:100%;">
                        <i class="fas fa-paper-plane"></i> Submit Issue
                    </button>
                </form>
            </div>
        </div>
    </div>

</main>

<script>
function openReportIssueModal() {
    document.getElementById('reportIssueModal').classList.add('active');
}
function closeReportIssueModal() {
    document.getElementById('reportIssueModal').classList.remove('active');
}

async function loadIssueDistricts(stateCode) {
    const distEl = document.getElementById('issueDistrict');
    const blkEl = document.getElementById('issueBlock');
    const panEl = document.getElementById('issuePanchayat');
    if (blkEl) blkEl.innerHTML = '<option value="">Select Block (Prakhand)</option>';
    if (panEl) panEl.innerHTML = '<option value="">All / Block Headquarters</option>';
    if (!distEl) return;

    if (!stateCode) {
        distEl.innerHTML = '<option value="">Select District</option>';
        return;
    }

    distEl.innerHTML = '<option value="">Loading...</option>';
    const res = await fetch(`<?= get_site_url('api/locations') ?>?type=districts&state_code=${encodeURIComponent(stateCode)}`);
    const json = await res.json();
    distEl.innerHTML = '<option value="">Select District</option>';
    (json.data || []).forEach(d => {
        const opt = document.createElement('option');
        opt.value = d.district_code;
        opt.textContent = d.district;
        distEl.appendChild(opt);
    });
}

async function loadIssueBlocks(districtCode) {
    const blkEl = document.getElementById('issueBlock');
    const panEl = document.getElementById('issuePanchayat');
    if (panEl) panEl.innerHTML = '<option value="">All / Block Headquarters</option>';
    if (!blkEl) return;

    if (!districtCode) {
        blkEl.innerHTML = '<option value="">Select Block (Prakhand)</option>';
        return;
    }

    blkEl.innerHTML = '<option value="">Loading...</option>';
    const res = await fetch(`<?= get_site_url('api/locations') ?>?type=blocks&district_code=${encodeURIComponent(districtCode)}`);
    const json = await res.json();
    blkEl.innerHTML = '<option value="">Select Block (Prakhand)</option>';
    (json.data || []).forEach(b => {
        const opt = document.createElement('option');
        opt.value = b.block_code;
        opt.textContent = b.block;
        blkEl.appendChild(opt);
    });
}

async function loadIssuePanchayats(blockCode) {
    const panEl = document.getElementById('issuePanchayat');
    if (!panEl) return;
    panEl.innerHTML = '<option value="">All / Block Headquarters</option>';
    if (!blockCode) return;

    const res = await fetch(`<?= get_site_url('api/locations') ?>?type=panchayats&block_code=${encodeURIComponent(blockCode)}`);
    const json = await res.json();
    if (json.data && json.data.length > 0) {
        json.data.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.id;
            opt.textContent = p.name_en;
            panEl.appendChild(opt);
        });
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

