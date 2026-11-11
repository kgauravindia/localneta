<?php
// dashboard.php - Citizen, Politician & Leader Self-Service Dashboard (MySQL, Tiranga Palette)
define('APP_INIT', true);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';

$pdo = get_db_connection();
$currentUser = get_current_user_account($pdo);

$todayReveals = [];
$allReveals = [];
$revealsTodayCount = 0;
$revealsRemaining = USER_DAILY_CONTACT_LIMIT;
$completionPct = 0;

if ($currentUser) {
    $uid = (int)$currentUser['id'];
    $todayReveals = get_user_revealed_contacts($pdo, $uid, 'today');
    $allReveals = get_user_revealed_contacts($pdo, $uid, 'all');
    $revealsTodayCount = get_user_daily_reveal_count($pdo, $uid);
    $revealsRemaining = max(0, USER_DAILY_CONTACT_LIMIT - $revealsTodayCount);
    $completionPct = get_profile_completion_percent($currentUser);
}

// Leader session or first available custom representative profile
$currentLeaderId = (int)($_SESSION['leader_id'] ?? 0);
$leader = false;

if ($currentLeaderId > 0) {
    $stmt = $pdo->prepare("
        SELECT l.*, sdb.block as block_name, COALESCE(sdb.district, dist.district) as district_name
        FROM leaders l
        LEFT JOIN op_sdb sdb ON l.block_code = sdb.block_code
        LEFT JOIN (SELECT DISTINCT district_code, district FROM op_sdb) dist ON l.district_code = dist.district_code
        WHERE l.id = ?
    ");
    $stmt->execute([$currentLeaderId]);
    $leader = $stmt->fetch();
}

if (!$leader) {
    $leader = $pdo->query("
        SELECT l.*, sdb.block as block_name, COALESCE(sdb.district, dist.district) as district_name
        FROM leaders l
        LEFT JOIN op_sdb sdb ON l.block_code = sdb.block_code
        LEFT JOIN (SELECT DISTINCT district_code, district FROM op_sdb) dist ON l.district_code = dist.district_code
        ORDER BY l.id ASC LIMIT 1
    ")->fetch();
    $currentLeaderId = (int)($leader['id'] ?? 0);
}

// Handle new project submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_work') {
    if (!$leader || $currentLeaderId <= 0) {
        $errorMsg = "No active representative profile selected to attach a development project.";
    } else {
        $catMap = [
            'road' => 'Roads & Drainage',
            'water' => 'Drinking Water (Nal-Jal)',
            'solar' => 'Street Lighting & Solar',
            'school' => 'School & Education',
            'health' => 'Healthcare Sub-Center'
        ];
        $statusMap = [
            'completed' => 'Verified Complete',
            'in_progress' => 'In Progress',
            'sanctioned' => 'Sanctioned'
        ];

        $title = trim($_POST['title_hi'] ?? '');
        $category = trim($_POST['category'] ?? 'road');
        $categoryName = trim($_POST['category_name_hi'] ?? ($catMap[$category] ?? 'Roads & Drainage'));
        $cost = (float)($_POST['cost_lakhs'] ?? 0);
        $status = trim($_POST['status'] ?? 'in_progress');
        $statusLabel = $statusMap[$status] ?? 'In Progress';
        $sanction = trim($_POST['sanction_date'] ?? date('d-M-Y'));
        $completion = trim($_POST['completion_date'] ?? ($status === 'completed' ? date('d-M-Y') : 'Ongoing'));
        $source = trim($_POST['source_doc_info'] ?? '15th Finance Commission Grant');
        $photoBefore = trim($_POST['photo_before'] ?? '');
        $photoAfter = trim($_POST['photo_after'] ?? '');
        $leaderPanchayatId = !empty($leader['panchayat_id']) ? (int)$leader['panchayat_id'] : null;

        if (!empty($title)) {
            $stmt = $pdo->prepare("
                INSERT INTO development_works (
                    leader_id, panchayat_id, title_hi, category, category_name_hi, cost_lakhs,
                    sanction_date, completion_date, status, status_label_hi, source_doc_info,
                    photo_before, photo_after, upvotes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([$currentLeaderId, $leaderPanchayatId, $title, $category, $categoryName, $cost, $sanction, $completion, $status, $statusLabel, $source, $photoBefore, $photoAfter]);
            $successMsg = "Development project published to your public profile!";
        }
    }
}

$questions = [];
$works = [];
$answeredQuestionsCount = 0;
if ($leader && $currentLeaderId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM public_questions WHERE leader_id = ? ORDER BY created_at DESC");
    $stmt->execute([$currentLeaderId]);
    $questions = $stmt->fetchAll();
    foreach ($questions as $qItem) {
        if (!empty($qItem['answer_text'])) {
            $answeredQuestionsCount++;
        }
    }

    $stmt = $pdo->prepare("SELECT * FROM development_works WHERE leader_id = ? ORDER BY created_at DESC");
    $stmt->execute([$currentLeaderId]);
    $works = $stmt->fetchAll();
}

$pageTitle = $currentUser ? 'My Citizen & Politician Dashboard' : 'Leader Management Dashboard';
require_once __DIR__ . '/includes/header.php';
?>

<main class="main-wrapper">

<?php if ($currentUser):
    $uDisplayName = (string)($currentUser['full_name'] ?: $currentUser['name']);
    $uIsPol = ($currentUser['account_type'] ?? 'user') === 'politician';
    $uPublicUrl = get_user_profile_url((string)($currentUser['username_handle'] ?? ''), (int)$currentUser['id']);
    $uLocParts = array_filter([
        trim((string)($currentUser['block_name'] ?? '')),
        trim((string)($currentUser['district'] ?? '')),
        trim((string)($currentUser['state_name'] ?? '')),
    ]);
    $quotaPct = (int)min(100, round(($revealsTodayCount / max(1, USER_DAILY_CONTACT_LIMIT)) * 100));
?>
    <?php if (isset($_GET['verified'])): ?>
        <div class="alert-box alert-success">
            <i class="fas fa-circle-check" style="margin-top:2px;"></i>
            <div>Welcome, <strong><?= e($uDisplayName) ?></strong>! Your mobile number is verified and you can now unlock full mobile numbers and email IDs across LocalNeta.</div>
        </div>
    <?php endif; ?>

    <!-- Account Welcome Hero -->
    <div style="background:#FFFFFF; border:1px solid var(--border-color); border-top:4px solid var(--saffron-main); border-radius:var(--radius-lg); padding:20px; margin-bottom:20px; box-shadow:var(--shadow-sm); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
        <div style="display:flex; align-items:center; gap:14px;">
            <img src="<?= e(get_user_avatar_url($currentUser['profile_photo'] ?? null)) ?>" alt="<?= e($uDisplayName) ?>" style="width:66px; height:66px; border-radius:50%; object-fit:cover; border:3px solid var(--saffron-main); background:var(--white-surface);">
            <div>
                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                    <h1 style="font-size:1.3rem; font-weight:800; color:var(--navy-main); margin:0;"><?= e($uDisplayName) ?></h1>
                    <span class="cat-pill <?= $uIsPol ? 'bg-amber' : 'bg-blue' ?>"><?= $uIsPol ? 'Registered Politician' : 'Verified Citizen' ?></span>
                    <?php if (!empty($currentUser['is_mobile_verified'])): ?>
                        <span class="badge-tag badge-elected"><i class="fas fa-check-circle"></i> OTP Verified</span>
                    <?php endif; ?>
                </div>
                <div style="font-size:0.84rem; color:var(--green-main); font-weight:700; margin-top:3px;">
                    <?= e($currentUser['designation'] ?: ($uIsPol ? 'Public Representative' : 'Citizen Member')) ?>
                    <?php if (!empty($currentUser['username_handle'])): ?>
                        <span style="color:var(--text-muted); font-weight:600;">• @<?= e($currentUser['username_handle']) ?></span>
                    <?php endif; ?>
                </div>
                <div style="font-size:0.8rem; color:var(--text-muted); margin-top:2px;">
                    <?php if (!empty($uLocParts)): ?>
                        <i class="fas fa-location-dot" style="color:var(--saffron-main);"></i> <?= e(implode(', ', $uLocParts)) ?> •
                    <?php endif; ?>
                    Mobile: <code><?= e(mask_phone_number((string)$currentUser['mobile'])) ?></code>
                    <?php if (!empty($currentUser['email'])): ?>
                        • Email: <code><?= e(mask_email_address((string)$currentUser['email'])) ?></code>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a href="<?= get_site_url('studio') ?>" class="btn-green-tiranga" style="padding:8px 14px; font-size:0.84rem;">
                <i class="fas fa-paint-brush"></i> Poster Studio
            </a>
            <a href="<?= e($uPublicUrl) ?>" class="btn-profile-view" style="flex:unset; padding:8px 14px; font-size:0.84rem;">
                <i class="fas fa-id-badge"></i> Public Card
            </a>
            <a href="<?= get_site_url('edit-profile') ?>" class="btn-primary-tiranga" style="padding:8px 14px; font-size:0.84rem;">
                <i class="fas fa-user-pen"></i> Edit Profile &amp; Privacy
            </a>
            <a href="<?= get_site_url('logout') ?>" class="btn-profile-view" style="flex:unset; padding:8px 14px; font-size:0.84rem; color:#B91C1C; background:#FEF2F2;">
                <i class="fas fa-right-from-bracket"></i> Sign Out
            </a>
        </div>
    </div>

    <!-- Quota & Account Overview Grid -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:16px; margin-bottom:24px;">
        <div style="background:#FFFFFF; border:1px solid var(--border-color); border-left:4px solid var(--saffron-main); border-radius:var(--radius-md); padding:16px; box-shadow:var(--shadow-sm);">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <span style="font-size:0.8rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">Daily Contact Reveals</span>
                <span class="badge-tag badge-verified"><?= $revealsRemaining ?> Remaining</span>
            </div>
            <div style="font-size:1.55rem; font-weight:800; color:var(--navy-main); margin-top:4px;">
                <?= $revealsTodayCount ?> <span style="font-size:0.9rem; color:var(--text-muted); font-weight:600;">/ <?= USER_DAILY_CONTACT_LIMIT ?> used today</span>
            </div>
            <div class="quota-progress-track">
                <div class="quota-progress-fill" style="width:<?= $quotaPct ?>%;"></div>
            </div>
        </div>

        <div style="background:#FFFFFF; border:1px solid var(--border-color); border-left:4px solid var(--green-main); border-radius:var(--radius-md); padding:16px; box-shadow:var(--shadow-sm);">
            <div style="font-size:0.8rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">Profile Completion</div>
            <div style="font-size:1.55rem; font-weight:800; color:var(--green-main); margin-top:4px;">
                <?= $completionPct ?>% <span style="font-size:0.85rem; color:var(--text-muted); font-weight:600;">Complete</span>
            </div>
            <div style="font-size:0.78rem; color:var(--text-secondary); margin-top:6px;">
                <a href="<?= get_site_url('edit-profile') ?>" style="color:var(--navy-accent); font-weight:700; text-decoration:underline;">Update bio, photo &amp; privacy settings &rarr;</a>
            </div>
        </div>

        <div style="background:#FFFFFF; border:1px solid var(--border-color); border-left:4px solid var(--navy-main); border-radius:var(--radius-md); padding:16px; box-shadow:var(--shadow-sm);">
            <div style="font-size:0.8rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">Quick Directory Access</div>
            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
                <a href="<?= get_site_url('elections#mlc-directory') ?>" class="badge-tag badge-verified" style="padding:6px 10px; font-size:0.78rem;">
                    <i class="fas fa-scroll"></i> 231 MLCs
                </a>
                <a href="<?= get_site_url('leaders?cat=mla') ?>" class="badge-tag badge-elected" style="padding:6px 10px; font-size:0.78rem;">
                    <i class="fas fa-building-columns"></i> MLAs
                </a>
                <a href="<?= get_site_url('leaders') ?>" class="badge-tag badge-verified" style="padding:6px 10px; font-size:0.78rem;">
                    <i class="fas fa-users"></i> All Leaders
                </a>
            </div>
        </div>
    </div>

    <!-- Revealed Mobile & Email Contacts History -->
    <section style="background:#FFFFFF; border:1px solid var(--border-color); border-top:4px solid var(--green-main); border-radius:var(--radius-lg); padding:20px; margin-bottom:26px; box-shadow:var(--shadow-sm);">
        <div class="section-header" style="flex-wrap:wrap; gap:10px;">
            <div>
                <h2 class="section-title"><i class="fas fa-address-book"></i> Unlocked Mobile &amp; Email Contacts</h2>
                <p style="font-size:0.82rem; color:var(--text-muted); margin-top:2px;">Contacts you unlocked using your account remain accessible here without re-using daily quota on the same day.</p>
            </div>
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <input type="text" id="dashRevealSearch" class="form-control" placeholder="Search name, phone, email..." oninput="filterDashboardReveals(this.value)" style="width:220px; padding:6px 12px; font-size:0.84rem;">
            </div>
        </div>

        <div class="auth-tabs" style="max-width:360px; margin-top:8px;">
            <button type="button" class="auth-tab-btn active" id="tabTodayReveals" onclick="switchDashRevealTab('today')">
                Today (<?= count($todayReveals) ?>)
            </button>
            <button type="button" class="auth-tab-btn" id="tabAllReveals" onclick="switchDashRevealTab('all')">
                All History (<?= count($allReveals) ?>)
            </button>
        </div>

        <?php
        $renderRevealList = static function (array $rows, string $emptyMsg) {
            if (empty($rows)) {
                echo '<div style="text-align:center; padding:26px; background:var(--white-surface); border:1px dashed var(--border-color); border-radius:var(--radius-md); color:var(--text-muted); font-size:0.88rem;">' . e($emptyMsg) . '</div>';
                return;
            }
            echo '<div style="display:grid; gap:10px;">';
            foreach ($rows as $r) {
                $rTypeLabel = get_entity_type_label((string)$r['entity_type']);
                $rName = (string)($r['target_name'] ?: 'Representative');
                $rUrl = (string)($r['target_url'] ?? '');
                $rMob = trim((string)($r['revealed_mobile'] ?? ''));
                $rEmail = trim((string)($r['revealed_email'] ?? ''));
                $searchHay = strtolower($rName . ' ' . $rTypeLabel . ' ' . $rMob . ' ' . $rEmail);
                ?>
                <div class="reveal-history-card dash-reveal-item" data-search="<?= e($searchHay) ?>">
                    <div style="min-width:220px; flex:1;">
                        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                            <span class="cat-pill bg-indigo"><?= e($rTypeLabel) ?></span>
                            <?php if ($rUrl !== ''): ?>
                                <a href="<?= e($rUrl) ?>" style="font-weight:800; color:var(--navy-main); font-size:0.95rem; text-decoration:none;"><?= e($rName) ?></a>
                            <?php else: ?>
                                <strong style="color:var(--navy-main); font-size:0.95rem;"><?= e($rName) ?></strong>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">
                            <i class="fas fa-clock"></i> Unlocked on <?= e(date('d M Y, h:i A', strtotime((string)$r['created_at']))) ?>
                        </div>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                        <?php if ($rMob !== ''):
                            $mobParts = array_values(array_filter(array_map('trim', preg_split('/\s*[,;\/|]+\s*/', $rMob) ?: [$rMob])));
                            $firstMobDigits = preg_replace('/\D+/', '', $mobParts[0] ?? '') ?? '';
                        ?>
                            <a href="tel:<?= e($firstMobDigits ?: $rMob) ?>" class="contact-unlocked-link">
                                <i class="fas fa-phone"></i> <?= e($rMob) ?>
                            </a>
                        <?php endif; ?>
                        <?php if ($rEmail !== ''):
                            $firstEmail = trim((preg_split('/\s*[,;]+\s*/', $rEmail) ?: [$rEmail])[0] ?? $rEmail);
                        ?>
                            <a href="mailto:<?= e($firstEmail) ?>" class="contact-unlocked-link" style="background:#EFF6FF; border-color:#BFDBFE; color:#1E3A8A;">
                                <i class="fas fa-envelope"></i> <?= e($rEmail) ?>
                            </a>
                        <?php endif; ?>
                        <?php if ($rUrl !== ''): ?>
                            <a href="<?= e($rUrl) ?>" class="btn-profile-view" style="flex:unset; padding:5px 12px; font-size:0.78rem;">View Page</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php
            }
            echo '</div>';
        };
        ?>

        <div id="paneTodayReveals">
            <?php $renderRevealList($todayReveals, 'No mobile numbers or emails unlocked today yet. Visit any MLC, MLA, or Politician page and click "Click to View" on masked contacts.'); ?>
        </div>
        <div id="paneAllReveals" hidden>
            <?php $renderRevealList($allReveals, 'You have not unlocked any contact records yet.'); ?>
        </div>
    </section>
<?php endif; ?>

<?php if (!$leader && !$currentUser): ?>
    <div class="tiranga-banner" style="margin-bottom:24px;">
        <div>
            <h1><i class="fas fa-sliders" style="color:var(--saffron-main);"></i> Citizen, Politician &amp; Leader Dashboard</h1>
            <p>Sign in or register to view unmasked mobile numbers &amp; email IDs, or manage your civic representative profile.</p>
        </div>
    </div>

    <div style="background:#FFFFFF; border:1px solid var(--border-color); border-top:4px solid var(--green-main); border-radius:var(--radius-lg); padding:28px 24px; box-shadow:var(--shadow-sm); margin-bottom:22px;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:18px;">
            <div style="flex:1; min-width:260px;">
                <span class="badge-tag badge-elected" style="margin-bottom:8px;"><i class="fas fa-shield-halved"></i> Contact Access &amp; Public Profiles</span>
                <h2 style="font-size:1.25rem; font-weight:800; color:var(--navy-main); margin-bottom:6px;">Citizen &amp; Politician Account Portal</h2>
                <p style="font-size:0.9rem; color:var(--text-secondary); line-height:1.6;">
                    Mobile numbers and email IDs across MLC, MLA, and public leader directories are masked to stop automated scraping. Create a verified Citizen or Politician account in seconds to view up to <?= USER_DAILY_CONTACT_LIMIT ?> complete contact cards daily.
                </p>
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <a href="<?= get_site_url('login') ?>" class="btn-primary-tiranga" style="padding:10px 20px;">
                    <i class="fas fa-right-to-bracket"></i> Sign In
                </a>
                <a href="<?= get_site_url('register') ?>" class="btn-green-tiranga" style="padding:10px 20px;">
                    <i class="fas fa-user-plus"></i> Citizen / Politician Registration
                </a>
            </div>
        </div>
    </div>

    <div style="background:#FFFFFF; border:1px dashed var(--border-color); border-radius:var(--radius-lg); padding:36px 24px; text-align:center; box-shadow:var(--shadow-sm); margin-bottom:28px;">
        <i class="fas fa-user-check" style="font-size:2.4rem; color:var(--navy-accent); margin-bottom:10px;"></i>
        <h2 style="font-size:1.2rem; font-weight:800; color:var(--navy-main); margin-bottom:8px;">No Claimed Custom Profile Active</h2>
        <p style="font-size:0.9rem; color:var(--text-secondary); max-width:600px; margin:0 auto 20px;">
            Public elected representatives (Bihar Gram Panchayat Mukhiyas, MLAs, MLCs, MPs, and Political Parties) are listed directly in the real Local Leaders Directory. Interactive Q&amp;A and Development Work controls appear once a custom profile is claimed.
        </p>
        <div style="display:flex; justify-content:center; gap:12px; flex-wrap:wrap;">
            <a href="<?= get_site_url('index') ?>" class="btn-primary-tiranga" style="padding:10px 18px;">
                <i class="fas fa-address-book"></i> Browse Local Leaders Directory
            </a>
            <a href="<?= get_site_url('studio') ?>" class="btn-green-tiranga" style="padding:10px 18px;">
                <i class="fas fa-paint-brush"></i> Open Poster Studio
            </a>
        </div>
    </div>
<?php elseif ($leader): ?>

    <!-- Dashboard Top Bar -->
    <div style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); padding:20px; margin-bottom:24px; box-shadow:var(--shadow-sm); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
        <div style="display:flex; align-items:center; gap:14px;">
            <?php if (!empty($leader['avatar_url'])): ?>
                <img src="<?= e($leader['avatar_url']) ?>" alt="<?= e($leader['full_name_en']) ?>" style="width:60px; height:60px; border-radius:50%; border:3px solid var(--saffron-main); object-fit:cover;">
            <?php else: ?>
                <div style="width:60px; height:60px; border-radius:50%; border:3px solid var(--saffron-main); background:var(--navy-light); color:var(--navy-main); display:flex; align-items:center; justify-content:center; font-size:1.35rem;">
                    <i class="fas fa-user-tie"></i>
                </div>
            <?php endif; ?>
            <div>
                <div style="display:flex; align-items:center; gap:8px;">
                    <h1 style="font-size:1.3rem; font-weight:800; color:var(--navy-main);"><?= e($leader['full_name_en']) ?></h1>
                    <span class="badge-tag badge-elected"><i class="fas fa-check-circle"></i> <?= e(strtoupper($leader['plan_tier'] ?: 'FREE')) ?></span>
                </div>
                <div style="font-size:0.88rem; color:var(--green-main); font-weight:700;"><?= e($leader['designation_hi']) ?></div>
                <div style="font-size:0.8rem; color:var(--text-muted);"><?= e($leader['constituency_name_hi'] ?: (($leader['district_name'] ?? '') ? $leader['district_name'] . ' District' : '')) ?></div>
            </div>
        </div>

        <div style="display:flex; gap:10px;">
            <a href="<?= get_site_url('profile?id=' . $leader['id']) ?>" target="_blank" class="btn-profile-view" style="padding:8px 16px; font-size:0.88rem;">
                <i class="fas fa-arrow-up-right-from-square"></i> View Public Profile
            </a>
            <a href="<?= get_site_url('studio?leader_id=' . $leader['id']) ?>" class="btn-primary-tiranga" style="padding:8px 16px; font-size:0.88rem;">
                <i class="fas fa-paint-brush"></i> Create Posters
            </a>
        </div>
    </div>

    <?php if (!empty($errorMsg)): ?>
        <div style="background:#FEF2F2; border:1px solid #DC2626; color:#991B1B; padding:12px 16px; border-radius:var(--radius-md); margin-bottom:20px; font-weight:600;">
            <i class="fas fa-exclamation-triangle"></i> <?= e($errorMsg) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($successMsg)): ?>
        <div style="background:#F0FDF4; border:1px solid var(--green-main); color:var(--green-dark); padding:12px 16px; border-radius:var(--radius-md); margin-bottom:20px; font-weight:600;">
            <i class="fas fa-check-circle"></i> <?= e($successMsg) ?>
        </div>
    <?php endif; ?>

    <!-- Performance Metrics Cards -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:28px;">
        <div style="background:#FFFFFF; border-radius:var(--radius-md); border:1px solid var(--border-color); border-left:4px solid var(--saffron-main); padding:16px; box-shadow:var(--shadow-sm);">
            <div style="font-size:0.8rem; color:var(--text-muted); font-weight:600;">Total Citizen Views</div>
            <div style="font-size:1.6rem; font-weight:800; color:var(--navy-main); margin-top:4px;"><?= number_format((int)$leader['views_count']) ?></div>
            <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">Live public profile impressions</div>
        </div>

        <div style="background:#FFFFFF; border-radius:var(--radius-md); border:1px solid var(--border-color); border-left:4px solid var(--green-main); padding:16px; box-shadow:var(--shadow-sm);">
            <div style="font-size:0.8rem; color:var(--text-muted); font-weight:600;">Public Questions</div>
            <div style="font-size:1.6rem; font-weight:800; color:var(--green-main); margin-top:4px;"><?= count($questions) ?></div>
            <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">Citizen inquiries received</div>
        </div>

        <div style="background:#FFFFFF; border-radius:var(--radius-md); border:1px solid var(--border-color); border-left:4px solid var(--navy-accent); padding:16px; box-shadow:var(--shadow-sm);">
            <div style="font-size:0.8rem; color:var(--text-muted); font-weight:600;">Documented Works</div>
            <div style="font-size:1.6rem; font-weight:800; color:var(--navy-main); margin-top:4px;"><?= count($works) ?></div>
            <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">Published on public portal</div>
        </div>

        <div style="background:#FFFFFF; border-radius:var(--radius-md); border:1px solid var(--border-color); border-left:4px solid #7C3AED; padding:16px; box-shadow:var(--shadow-sm);">
            <div style="font-size:0.8rem; color:var(--text-muted); font-weight:600;">Questions Answered</div>
            <div style="font-size:1.6rem; font-weight:800; color:#7C3AED; margin-top:4px;"><?= $answeredQuestionsCount ?></div>
            <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">Published official replies</div>
        </div>
    </div>

    <!-- Main Dashboard Two-Column Grid -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(340px, 1fr)); gap:24px;">

        <!-- LEFT COLUMN: CITIZEN QUESTIONS REDRESSAL INBOX -->
        <div style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); padding:20px; box-shadow:var(--shadow-sm);">
            <div class="section-header">
                <h2 class="section-title"><i class="fas fa-comments"></i> Citizen Questions Inbox</h2>
                <span class="badge-tag badge-verified"><?= count($questions) ?> Inquiries</span>
            </div>
            <p style="font-size:0.85rem; color:var(--text-muted); margin-bottom:16px;">
                Reply to your constituents' questions directly. Your responses are published on your verified profile for full transparency.
            </p>

            <div style="display:flex; flex-direction:column; gap:16px;">
                <?php if (empty($questions)): ?>
                    <p style="color:var(--text-muted); font-size:0.9rem; text-align:center; padding:30px;">No questions received yet.</p>
                <?php else: ?>
                    <?php foreach ($questions as $q): ?>
                        <div style="border:1px solid var(--border-color); border-radius:var(--radius-md); padding:14px; background:var(--white-surface);" id="q-card-<?= $q['id'] ?>">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                <span style="font-weight:700; font-size:0.9rem; color:var(--navy-main);">
                                    <?= e($q['citizen_name']) ?> <span style="font-weight:400; font-size:0.78rem; color:var(--text-muted);">(<?= e($q['citizen_locality']) ?>)</span>
                                </span>
                                <?= get_status_badge($q['status'], $q['status_label_hi']) ?>
                            </div>

                            <p style="font-size:0.88rem; color:var(--text-primary); margin-bottom:10px;">
                                <strong>Question:</strong> <?= e($q['question_text']) ?>
                            </p>

                            <?php if (!empty($q['answer_text'])): ?>
                                <div style="background:#FFFFFF; border-left:3px solid var(--green-main); padding:8px 12px; border-radius:6px; font-size:0.82rem; color:var(--green-dark);">
                                    <strong>Your Reply:</strong> <?= nl2br(e($q['answer_text'])) ?>
                                </div>
                            <?php else: ?>
                                <div style="margin-top:8px;">
                                    <textarea id="reply-text-<?= $q['id'] ?>" rows="2" placeholder="Write your official response to this citizen..." class="form-control" style="font-size:0.85rem; margin-bottom:8px;"></textarea>
                                    <button type="button" class="btn-green-tiranga" style="padding:6px 14px; font-size:0.8rem;" onclick="replyToQuestion(<?= $q['id'] ?>)">
                                        <i class="fas fa-reply"></i> Publish Answer
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT COLUMN: PUBLISH NEW VIKAS WORK -->
        <div style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); padding:20px; box-shadow:var(--shadow-sm); display:flex; flex-direction:column; gap:14px;">
            <div class="section-header">
                <h2 class="section-title"><i class="fas fa-plus-circle"></i> Document a Development Work</h2>
            </div>
            <p style="font-size:0.85rem; color:var(--text-muted);">
                Showcase your completed or ongoing projects to your constituents with photo evidence and sanction details.
            </p>

            <form action="<?= get_site_url('dashboard') ?>" method="POST">
                <input type="hidden" name="action" value="add_work">

                <div class="form-group">
                    <label class="form-label">Project Title</label>
                    <input type="text" name="title_hi" required placeholder="e.g. 400 ft PCC Road and Drainage in Ward 04" class="form-control">
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <select name="category" class="form-control">
                            <option value="road">Roads & Drainage</option>
                            <option value="water">Drinking Water (Nal-Jal)</option>
                            <option value="solar">Street Lighting & Solar</option>
                            <option value="school">School & Education</option>
                            <option value="health">Healthcare Sub-Center</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Estimated Cost (in ₹ Lakhs)</label>
                        <input type="number" step="0.01" name="cost_lakhs" placeholder="e.g. 6.50" class="form-control">
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                    <div class="form-group">
                        <label class="form-label">Sanction Reference</label>
                        <input type="text" name="source_doc_info" placeholder="15th FC / State Grant 2026" class="form-control">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Current Status</label>
                        <select name="status" class="form-control">
                            <option value="completed">Verified Complete</option>
                            <option value="in_progress">In Progress</option>
                            <option value="sanctioned">Sanctioned</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Before Work Photo URL (Optional)</label>
                    <input type="text" name="photo_before" placeholder="https://..." class="form-control">
                </div>

                <div class="form-group">
                    <label class="form-label">Completed Work Photo URL (Optional)</label>
                    <input type="text" name="photo_after" placeholder="https://..." class="form-control">
                </div>

                <button type="submit" class="btn-primary-tiranga" style="width:100%; margin-top:8px;">
                    <i class="fas fa-check"></i> Publish Development Work
                </button>
            </form>
        </div>

    </div>

<?php endif; ?>

</main>

<script>
function switchDashRevealTab(mode) {
    const isToday = mode === 'today';
    document.getElementById('tabTodayReveals')?.classList.toggle('active', isToday);
    document.getElementById('tabAllReveals')?.classList.toggle('active', !isToday);
    document.getElementById('paneTodayReveals')?.toggleAttribute('hidden', !isToday);
    document.getElementById('paneAllReveals')?.toggleAttribute('hidden', isToday);
}

function filterDashboardReveals(q) {
    const norm = (q || '').trim().toLowerCase();
    document.querySelectorAll('.dash-reveal-item').forEach(el => {
        const hay = el.getAttribute('data-search') || '';
        el.style.display = (norm === '' || hay.includes(norm)) ? '' : 'none';
    });
}

function replyToQuestion(qId) {
    const textEl = document.getElementById('reply-text-' + qId);
    if (!textEl || !textEl.value.trim()) {
        showToast('Please type a response first');
        return;
    }

    fetch('<?= get_site_url("api/questions") ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'reply',
            question_id: qId,
            answer_text: textEl.value.trim()
        })
    }).then(res => res.json()).then(data => {
        if (data.success) {
            showToast('✅ Response published to citizen!');
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showToast(data.message || 'Error publishing response');
        }
    }).catch(() => {
        showToast('Response saved!');
        setTimeout(() => window.location.reload(), 1000);
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

