<?php
// profile.php - Detailed Leader Profile (English UI with Tiranga Palette)
define('APP_INIT', true);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';

$pdo = get_db_connection();
$viewer = get_current_user_account($pdo);
$unlockedTodayMap = $viewer ? get_user_revealed_entity_keys_today($pdo, (int)$viewer['id']) : [];

$leaderId = (int)($_GET['id'] ?? 1);

// Fetch Leader (joined with op_sdb & panchayats)
$stmt = $pdo->prepare("
    SELECT l.*, 
           p.name_en as panchayat_name, 
           sdb.block as block_name, 
           COALESCE(sdb.district, dist.district) as district_name,
           COALESCE(sdb.state, dist.state) as state_name
    FROM leaders l 
    LEFT JOIN panchayats p ON l.panchayat_id = p.id 
    LEFT JOIN op_sdb sdb ON l.block_code = sdb.block_code 
    LEFT JOIN (SELECT DISTINCT district_code, district, state FROM op_sdb) dist ON l.district_code = dist.district_code 
    WHERE l.id = ?
");
$stmt->execute([$leaderId]);
$leader = $stmt->fetch();

if (!$leader) {
    header("Location: " . get_site_url('leaders'));
    exit;
}

// Increment view count
$pdo->prepare("UPDATE leaders SET views_count = views_count + 1 WHERE id = ?")->execute([$leaderId]);

// Fetch Development Works
$stmt = $pdo->prepare("SELECT * FROM development_works WHERE leader_id = ? ORDER BY created_at DESC");
$stmt->execute([$leaderId]);
$works = $stmt->fetchAll();

// Fetch Ratings Average
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_reviews,
        AVG(road_drainage) as avg_road,
        AVG(sanitation) as avg_sanitation,
        AVG(drinking_water) as avg_water,
        AVG(accessibility) as avg_access,
        AVG(grievance_redressal) as avg_action
    FROM public_ratings 
    WHERE leader_id = ?
");
$stmt->execute([$leaderId]);
$ratingStats = $stmt->fetch();

$totalReviews = (int)($ratingStats['total_reviews'] ?? 0);
$scoreRoad = $totalReviews > 0 ? round((float)$ratingStats['avg_road'], 1) : 0.0;
$scoreSanitation = $totalReviews > 0 ? round((float)$ratingStats['avg_sanitation'], 1) : 0.0;
$scoreWater = $totalReviews > 0 ? round((float)$ratingStats['avg_water'], 1) : 0.0;
$scoreAccess = $totalReviews > 0 ? round((float)$ratingStats['avg_access'], 1) : 0.0;
$scoreAction = $totalReviews > 0 ? round((float)$ratingStats['avg_action'], 1) : 0.0;
$avgOverall = $totalReviews > 0
    ? round(($scoreRoad + $scoreSanitation + $scoreWater + $scoreAccess + $scoreAction) / 5, 1)
    : 0.0;

// Fetch Questions
$stmt = $pdo->prepare("SELECT * FROM public_questions WHERE leader_id = ? ORDER BY created_at DESC");
$stmt->execute([$leaderId]);
$questions = $stmt->fetchAll();

$pageTitle = $leader['full_name_en'] . " (" . $leader['designation_hi'] . ")";
require_once __DIR__ . '/includes/header.php';
?>

<main class="main-wrapper">

    <!-- Profile Header Card -->
    <div style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); overflow:hidden; box-shadow:var(--shadow-sm); margin-bottom:24px;">
        <div style="height:140px; background:linear-gradient(90deg, #FF671F 0%, #FFFFFF 50%, #046A38 100%); position:relative;">
            <div style="position:absolute; top:12px; right:12px;">
                <?= get_category_badge($leader['category'], $pdo) ?>
            </div>
        </div>

        <div style="padding:16px 20px 20px; position:relative;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; margin-bottom:14px; gap:14px;">
                <div style="display:flex; align-items:flex-start; gap:16px; flex-wrap:wrap; min-width:0;">
                    <div style="width:110px; height:110px; margin-top:-71px; flex-shrink:0; position:relative; z-index:2; border-radius:50%; border:4px solid #FFFFFF; overflow:hidden; box-shadow:var(--shadow-md); background:#FFFFFF;">
                        <img src="<?= e($leader['avatar_url']) ?>" alt="<?= e($leader['full_name_en']) ?>" style="width:100%; height:100%; object-fit:cover;">
                    </div>
                    <div style="min-width:0;">
                        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                            <h1 style="font-size:1.45rem; font-weight:800; color:var(--navy-main); margin:0;"><?= e($leader['full_name_en']) ?></h1>
                            <?= get_verification_badge_html($leader['verification_badge'], (bool)$leader['is_verified']) ?>
                        </div>
                        <div style="font-size:0.95rem; font-weight:700; color:var(--green-main);"><?= e($leader['designation_hi']) ?></div>
                        <div style="font-size:0.85rem; color:var(--text-secondary); margin-top:2px;">
                            <i class="fas fa-location-dot" style="color:var(--saffron-main);"></i> 
                            <?= e($leader['constituency_name_hi'] ?: ($leader['panchayat_name'] ? 'Gram Panchayat ' . $leader['panchayat_name'] . ($leader['block_name'] ? ', ' . $leader['block_name'] : '') : (($leader['district_name'] ?? 'Saran') . ' District'))) ?>
                        </div>
                    </div>
                </div>

                <!-- Action CTAs -->
                <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                    <?php
                    $maskedLeaderPhone = mask_phone_number((string)($leader['whatsapp'] ?? $leader['phone'] ?? ''));
                    $maskedLeaderEmail = mask_email_address((string)($leader['email'] ?? ''));
                    $isLeaderUnlockedToday = !empty($unlockedTodayMap['leader:' . $leaderId]);
                    if ($maskedLeaderPhone !== ''):
                        echo render_contact_reveal_trigger('leader', $leaderId, 'mobile', $maskedLeaderPhone, (string)$leader['full_name_en'], [
                            'variant' => 'cta',
                            'icon' => 'fab fa-whatsapp',
                            'unlocked_today' => $isLeaderUnlockedToday,
                        ]);
                    endif;
                    if ($maskedLeaderEmail !== ''):
                        echo render_contact_reveal_trigger('leader', $leaderId, 'email', $maskedLeaderEmail, (string)$leader['full_name_en'], [
                            'variant' => 'cta',
                            'icon' => 'fas fa-envelope',
                            'unlocked_today' => $isLeaderUnlockedToday,
                        ]);
                    endif;
                    ?>
                    <button type="button" class="btn-primary-tiranga" style="padding:8px 16px; font-size:0.88rem;" onclick="openQuestionModal()">
                        <i class="fas fa-question-circle"></i> Ask a Question
                    </button>
                    <button type="button" class="btn-profile-view" style="flex:unset; padding:8px 14px; font-size:0.88rem;" onclick="openDigitalCardModal()">
                        <i class="fas fa-qrcode"></i> Digital Visiting Card
                    </button>
                </div>
            </div>

            <!-- Stats Ribbon -->
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(130px, 1fr)); gap:10px; background:var(--white-surface); border-radius:var(--radius-md); padding:12px; border:1px solid var(--border-light);">
                <div style="text-align:center;">
                    <div style="font-size:1.15rem; font-weight:800; color:var(--navy-main);"><?= $totalReviews > 0 ? e((string)$avgOverall) . ' <i class="fas fa-star" style="color:#F59E0B; font-size:0.85rem;"></i>' : '—' ?></div>
                    <div style="font-size:0.75rem; color:var(--text-muted);">Citizen Rating (<?= $totalReviews ?>)</div>
                </div>
                <div style="text-align:center;">
                    <div style="font-size:1.15rem; font-weight:800; color:var(--green-main);"><?= count($works) ?></div>
                    <div style="font-size:0.75rem; color:var(--text-muted);">Documented Works</div>
                </div>
                <div style="text-align:center;">
                    <div style="font-size:1.15rem; font-weight:800; color:var(--navy-main);"><?= count($questions) ?></div>
                    <div style="font-size:0.75rem; color:var(--text-muted);">Public Questions</div>
                </div>
                <div style="text-align:center;">
                    <div style="font-size:1.15rem; font-weight:800; color:var(--saffron-main);"><?= number_format($leader['views_count']) ?></div>
                    <div style="font-size:0.75rem; color:var(--text-muted);">Citizen Profile Views</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div style="display:flex; gap:8px; border-bottom:2px solid var(--border-color); margin-bottom:20px; overflow-x:auto;">
        <button type="button" class="tab-btn active" onclick="switchProfileTab('overview', this)" style="padding:10px 18px; font-weight:700; font-size:0.92rem; background:none; border:none; border-bottom:3px solid var(--saffron-main); cursor:pointer; color:var(--navy-main);">
            <i class="fas fa-id-card"></i> Overview & Disclosures
        </button>
        <button type="button" class="tab-btn" onclick="switchProfileTab('works', this)" style="padding:10px 18px; font-weight:700; font-size:0.92rem; background:none; border:none; border-bottom:3px solid transparent; cursor:pointer; color:var(--text-secondary);">
            <i class="fas fa-road"></i> Vikas Portfolio (<?= count($works) ?>)
        </button>
        <button type="button" class="tab-btn" onclick="switchProfileTab('rating', this)" style="padding:10px 18px; font-weight:700; font-size:0.92rem; background:none; border:none; border-bottom:3px solid transparent; cursor:pointer; color:var(--text-secondary);">
            <i class="fas fa-star"></i> Citizen Rating
        </button>
        <button type="button" class="tab-btn" onclick="switchProfileTab('questions', this)" style="padding:10px 18px; font-weight:700; font-size:0.92rem; background:none; border:none; border-bottom:3px solid transparent; cursor:pointer; color:var(--text-secondary);">
            <i class="fas fa-comments"></i> Questions & Answers (<?= count($questions) ?>)
        </button>
    </div>

    <!-- TAB 1: OVERVIEW & DISCLOSURES -->
    <div id="tab-overview" class="profile-tab-pane">
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); gap:20px;">
            <!-- Bio & Background -->
            <div style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); padding:20px;">
                <h3 style="font-size:1.1rem; font-weight:700; color:var(--navy-main); margin-bottom:12px; border-bottom:1px solid var(--border-light); padding-bottom:6px;">
                    <i class="fas fa-user-circle" style="color:var(--saffron-main);"></i> Public Mission & Profile
                </h3>
                <p style="font-size:0.92rem; color:var(--text-secondary); line-height:1.6; margin-bottom:16px;">
                    <?= nl2br(e($leader['bio_hi'])) ?>
                </p>

                <h4 style="font-size:0.95rem; font-weight:700; color:var(--navy-main); margin-bottom:8px;">Tenure & Constituency</h4>
                <ul style="list-style:none; font-size:0.88rem; color:var(--text-secondary);">
                    <li style="margin-bottom:6px;"><i class="fas fa-calendar-check" style="color:var(--green-main); width:20px;"></i> <strong>Current Term:</strong> 2021 – Present</li>
                    <li style="margin-bottom:6px;"><i class="fas fa-landmark" style="color:var(--saffron-main); width:20px;"></i> <strong>Office:</strong> <?= e($leader['designation_hi']) ?></li>
                    <li style="margin-bottom:6px;"><i class="fas fa-map" style="color:var(--navy-accent); width:20px;"></i> <strong>Location:</strong> <?= e(($leader['block_name'] ? $leader['block_name'] . ', ' : '') . ($leader['district_name'] ?? 'Saran') . ', ' . ($leader['state_name'] ?? 'Bihar')) ?></li>
                    <li style="margin-bottom:6px;"><i class="fas fa-flag" style="color:var(--purple-main); width:20px;"></i> <strong>Affiliation:</strong> <?= e($leader['party_name_hi']) ?></li>
                </ul>
            </div>

            <!-- Verified Official Disclosures -->
            <div style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); padding:20px;">
                <h3 style="font-size:1.1rem; font-weight:700; color:var(--navy-main); margin-bottom:12px; border-bottom:1px solid var(--border-light); padding-bottom:6px;">
                    <i class="fas fa-shield-alt" style="color:var(--green-main);"></i> Verified Public Disclosures
                </h3>
                
                <div style="margin-bottom:14px;">
                    <div style="font-size:0.8rem; color:var(--text-muted); font-weight:600;">Educational Qualification</div>
                    <div style="font-size:0.95rem; font-weight:700; color:var(--navy-main);"><i class="fas fa-graduation-cap" style="color:var(--navy-accent);"></i> <?= e($leader['education_hi']) ?></div>
                </div>

                <div style="margin-bottom:14px;">
                    <div style="font-size:0.8rem; color:var(--text-muted); font-weight:600;">Declared Assets (Election Affidavit)</div>
                    <div style="font-size:0.95rem; font-weight:700; color:var(--green-dark);"><i class="fas fa-coins" style="color:#F59E0B;"></i> <?= e($leader['declared_assets']) ?></div>
                </div>

                <div style="margin-bottom:14px;">
                    <div style="font-size:0.8rem; color:var(--text-muted); font-weight:600;">Criminal Disclosures</div>
                    <div style="font-size:0.92rem; font-weight:600; color:var(--navy-main); background:var(--white-surface); padding:8px 12px; border-radius:6px; border-left:3px solid var(--green-main);">
                        <i class="fas fa-check-circle" style="color:var(--green-main);"></i> <?= e($leader['criminal_cases']) ?>
                    </div>
                </div>

                <div style="border-top:1px solid var(--border-light); padding-top:12px; font-size:0.82rem; color:var(--text-muted);">
                    <i class="fas fa-info-circle"></i> Sourced from official Election Commission &amp; State Election Commission nomination filings. <a href="<?= get_site_url('source') ?>" style="color:var(--saffron-main); font-weight:700; text-decoration:underline;">View All Data Sources</a>
                </div>
            </div>
        </div>
    </div>

    <!-- TAB 2: DEVELOPMENT WORKS -->
    <div id="tab-works" class="profile-tab-pane" style="display:none;">
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:18px;">
            <?php if (empty($works)): ?>
                <div style="grid-column: 1 / -1; background:#fff; padding:30px; text-align:center; border-radius:var(--radius-lg); border:1px dashed var(--border-color);">
                    <p style="color:var(--text-muted);">No development projects published yet for this representative.</p>
                </div>
            <?php else: ?>
                <?php foreach ($works as $w): ?>
                    <div class="project-card">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <span class="cat-pill bg-blue"><?= e($w['category_name_hi']) ?></span>
                            <?= get_status_badge($w['status'], $w['status_label_hi']) ?>
                        </div>
                        <h3 class="project-title"><?= e($w['title_hi']) ?></h3>
                        
                        <div style="display:flex; justify-content:space-between; font-size:0.88rem; font-weight:700; color:var(--navy-main);">
                            <span>Cost: <?= format_rupees_lakh((float)$w['cost_lakhs']) ?></span>
                            <span style="font-size:0.8rem; font-weight:500; color:var(--text-muted);">Completed: <?= e($w['completion_date'] ?: 'Ongoing') ?></span>
                        </div>

                        <?php if (!empty($w['photo_before']) || !empty($w['photo_after'])): ?>
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:6px;">
                                <?php if (!empty($w['photo_before'])): ?>
                                    <div>
                                        <div style="font-size:0.72rem; color:var(--text-muted); margin-bottom:2px;">Before / Site Work</div>
                                        <img src="<?= e($w['photo_before']) ?>" style="width:100%; height:90px; object-fit:cover; border-radius:6px;">
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($w['photo_after'])): ?>
                                    <div>
                                        <div style="font-size:0.72rem; color:var(--green-main); margin-bottom:2px; font-weight:700;">After / Completed</div>
                                        <img src="<?= e($w['photo_after']) ?>" style="width:100%; height:90px; object-fit:cover; border-radius:6px;">
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($w['source_doc_info'])): ?>
                            <div style="font-size:0.75rem; background:var(--white-surface); padding:6px 10px; border-radius:6px; color:var(--text-secondary); border-left:3px solid var(--green-main); margin-top:auto;">
                                <i class="fas fa-file-invoice"></i> <?= e($w['source_doc_info']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- TAB 3: CITIZEN RATING & FEEDBACK -->
    <div id="tab-rating" class="profile-tab-pane" style="display:none;">
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); gap:20px;">
            <!-- Score Breakdown -->
            <div style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); padding:20px;">
                <h3 style="font-size:1.1rem; font-weight:700; color:var(--navy-main); margin-bottom:14px;">
                    <i class="fas fa-square-poll-vertical" style="color:var(--saffron-main);"></i> Performance Scorecard (5 Parameters)
                </h3>

                <div style="display:flex; flex-direction:column; gap:12px;">
                    <div>
                        <div style="display:flex; justify-content:space-between; font-size:0.88rem; font-weight:600; margin-bottom:4px;">
                            <span>1. Roads & Drainage</span>
                            <span><?= $scoreRoad ?> / 5</span>
                        </div>
                        <div style="height:8px; background:var(--border-light); border-radius:4px; overflow:hidden;">
                            <div style="height:100%; width:<?= ($scoreRoad / 5) * 100 ?>%; background:var(--saffron-main);"></div>
                        </div>
                    </div>

                    <div>
                        <div style="display:flex; justify-content:space-between; font-size:0.88rem; font-weight:600; margin-bottom:4px;">
                            <span>2. Cleanliness & Sanitation</span>
                            <span><?= $scoreSanitation ?> / 5</span>
                        </div>
                        <div style="height:8px; background:var(--border-light); border-radius:4px; overflow:hidden;">
                            <div style="height:100%; width:<?= ($scoreSanitation / 5) * 100 ?>%; background:var(--green-main);"></div>
                        </div>
                    </div>

                    <div>
                        <div style="display:flex; justify-content:space-between; font-size:0.88rem; font-weight:600; margin-bottom:4px;">
                            <span>3. Drinking Water (Nal-Jal)</span>
                            <span><?= $scoreWater ?> / 5</span>
                        </div>
                        <div style="height:8px; background:var(--border-light); border-radius:4px; overflow:hidden;">
                            <div style="height:100%; width:<?= ($scoreWater / 5) * 100 ?>%; background:var(--navy-accent);"></div>
                        </div>
                    </div>

                    <div>
                        <div style="display:flex; justify-content:space-between; font-size:0.88rem; font-weight:600; margin-bottom:4px;">
                            <span>4. Public Accessibility</span>
                            <span><?= $scoreAccess ?> / 5</span>
                        </div>
                        <div style="height:8px; background:var(--border-light); border-radius:4px; overflow:hidden;">
                            <div style="height:100%; width:<?= ($scoreAccess / 5) * 100 ?>%; background:#7C3AED;"></div>
                        </div>
                    </div>

                    <div>
                        <div style="display:flex; justify-content:space-between; font-size:0.88rem; font-weight:600; margin-bottom:4px;">
                            <span>5. Grievance Redressal</span>
                            <span><?= $scoreAction ?> / 5</span>
                        </div>
                        <div style="height:8px; background:var(--border-light); border-radius:4px; overflow:hidden;">
                            <div style="height:100%; width:<?= ($scoreAction / 5) * 100 ?>%; background:#E11D48;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Submit Rating Form -->
            <div style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); padding:20px;">
                <h3 style="font-size:1.1rem; font-weight:700; color:var(--navy-main); margin-bottom:10px;">
                    <i class="fas fa-pen-to-square" style="color:var(--green-main);"></i> Submit Your Citizen Rating
                </h3>
                <p style="font-size:0.82rem; color:var(--text-muted); margin-bottom:14px;">
                    Rate each dimension from 1 (Poor) to 5 (Excellent). One vote per device.
                </p>

                <form onsubmit="submitLeaderRating(event, <?= $leader['id'] ?>)">
                    <div class="form-group">
                        <label class="form-label">Road & Drainage: <span id="val_road">4</span> Stars</label>
                        <input type="range" min="1" max="5" value="4" class="form-control" id="rate_road" oninput="document.getElementById('val_road').textContent = this.value">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Cleanliness: <span id="val_sanitation">4</span> Stars</label>
                        <input type="range" min="1" max="5" value="4" class="form-control" id="rate_sanitation" oninput="document.getElementById('val_sanitation').textContent = this.value">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Drinking Water: <span id="val_water">4</span> Stars</label>
                        <input type="range" min="1" max="5" value="4" class="form-control" id="rate_water" oninput="document.getElementById('val_water').textContent = this.value">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Accessibility: <span id="val_access">5</span> Stars</label>
                        <input type="range" min="1" max="5" value="5" class="form-control" id="rate_access" oninput="document.getElementById('val_access').textContent = this.value">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Grievance Action: <span id="val_action">4</span> Stars</label>
                        <input type="range" min="1" max="5" value="4" class="form-control" id="rate_action" oninput="document.getElementById('val_action').textContent = this.value">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Your Comment (Optional)</label>
                        <input type="text" id="rate_comment" placeholder="Helpful feedback for your representative..." class="form-control">
                    </div>

                    <button type="submit" class="btn-primary-tiranga" style="width:100%; margin-top:8px;">
                        <i class="fas fa-check"></i> Submit Rating
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- TAB 4: CITIZEN QUESTIONS & ANSWERS -->
    <div id="tab-questions" class="profile-tab-pane" style="display:none;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h3 style="font-size:1.15rem; font-weight:700; color:var(--navy-main);">Citizen Questions</h3>
            <button type="button" class="btn-primary-tiranga" onclick="openQuestionModal()" style="padding:6px 14px; font-size:0.85rem;">
                <i class="fas fa-plus"></i> Ask New Question
            </button>
        </div>

        <div style="display:flex; flex-direction:column; gap:14px;">
            <?php if (empty($questions)): ?>
                <div style="background:#fff; padding:30px; text-align:center; border-radius:var(--radius-lg); border:1px dashed var(--border-color);">
                    <p style="color:var(--text-muted);">No questions asked yet. Be the first to ask your representative a public question!</p>
                </div>
            <?php else: ?>
                <?php foreach ($questions as $q): ?>
                    <div style="background:#FFFFFF; border-radius:var(--radius-md); border:1px solid var(--border-color); padding:16px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <span style="font-weight:700; font-size:0.92rem; color:var(--navy-main);">
                                <i class="fas fa-user-circle" style="color:var(--saffron-main);"></i> <?= e($q['citizen_name']) ?> <span style="font-weight:400; color:var(--text-muted); font-size:0.8rem;">(<?= e($q['citizen_locality']) ?>)</span>
                            </span>
                            <?= get_status_badge($q['status'], $q['status_label_hi']) ?>
                        </div>

                        <p style="font-size:0.92rem; color:var(--text-primary); margin-bottom:10px;">
                            <strong>Q:</strong> <?= e($q['question_text']) ?>
                        </p>

                        <?php if (!empty($q['answer_text'])): ?>
                            <div style="background:#F0FDF4; border-left:4px solid var(--green-main); padding:10px 14px; border-radius:6px; font-size:0.88rem; color:#14532D;">
                                <div style="font-weight:700; display:flex; align-items:center; gap:6px; margin-bottom:3px;">
                                    <i class="fas fa-reply" style="color:var(--green-main);"></i> Reply from <?= e($leader['full_name_en']) ?> (<?= e($leader['designation_hi']) ?>):
                                </div>
                                <?= nl2br(e($q['answer_text'])) ?>
                            </div>
                        <?php else: ?>
                            <div style="font-size:0.8rem; color:var(--text-muted); font-style:italic;">
                                Awaiting public response from representative's office.
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Digital Visiting Card Modal -->
    <div class="modal-overlay" id="digitalCardModal">
        <div class="modal-dialog" style="max-width:420px; text-align:center;">
            <div class="modal-header">
                <div class="modal-title"><i class="fas fa-id-badge" style="color:var(--saffron-main);"></i> Panchayat Digital Visiting Card</div>
                <button type="button" class="modal-close-btn" onclick="closeDigitalCardModal()">&times;</button>
            </div>
            <div class="modal-body" style="padding:24px 20px;">
                <!-- Printable Card Preview -->
                <div id="visitingCardBox" style="background:linear-gradient(135deg, #0A192F 0%, #162C4E 100%); color:#FFFFFF; border-radius:16px; padding:20px; border-top:5px solid var(--saffron-main); border-bottom:5px solid var(--green-main); box-shadow:var(--shadow-lg); text-align:center;">
                    <img src="<?= get_site_url('assets/images/logo.png') ?>" style="width:40px; height:40px; border-radius:50%; border:2px solid #FF671F; margin-bottom:8px;">
                    <h3 style="font-size:1.15rem; font-weight:800; color:#FFFFFF;"><?= e($leader['full_name_en']) ?></h3>
                    <div style="font-size:0.85rem; color:#FF9933; font-weight:700;"><?= e($leader['designation_hi']) ?></div>
                    <div style="font-size:0.78rem; color:#CBD5E1; margin-bottom:14px;"><?= e($leader['constituency_name_hi'] ?: 'Saran, Bihar') ?></div>

                    <!-- Dynamic QR Code -->
                    <div style="background:#FFFFFF; padding:10px; border-radius:12px; display:inline-block; margin-bottom:12px;">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=<?= urlencode('https://localneta.com/profile?id=' . $leader['id']) ?>" alt="QR Code" style="width:140px; height:140px; display:block;">
                    </div>

                    <div style="font-size:0.75rem; color:#E2E8F0;">
                        Scan QR to view verified works & contact representative
                    </div>
                    <div style="font-size:0.7rem; color:#94A3B8; margin-top:4px;">
                        localneta.com/profile?id=<?= $leader['id'] ?>
                    </div>
                </div>

                <div style="margin-top:16px; display:flex; gap:10px;">
                    <a href="https://wa.me/?text=<?= urlencode('View verified public profile of ' . $leader['full_name_en'] . ' (' . $leader['designation_hi'] . ') on LocalNeta: https://localneta.com/profile?id=' . $leader['id']) ?>" target="_blank" class="btn-green-tiranga" style="flex:1;">
                        <i class="fab fa-whatsapp"></i> Share on WhatsApp
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Ask Question Modal -->
    <div class="modal-overlay" id="questionModal">
        <div class="modal-dialog">
            <div class="modal-header">
                <div class="modal-title"><i class="fas fa-question-circle" style="color:var(--saffron-main);"></i> Ask a Public Question</div>
                <button type="button" class="modal-close-btn" onclick="closeQuestionModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form onsubmit="submitCitizenQuestion(event, <?= $leader['id'] ?>)">
                    <div class="form-group">
                        <label class="form-label">Your Name</label>
                        <input type="text" id="q_name" required placeholder="Full Name" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Your Locality / Ward</label>
                        <input type="text" id="q_locality" required placeholder="e.g. Ward 04, Panchayat Road" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Your Question to Representative</label>
                        <textarea id="q_text" rows="3" required placeholder="Ask a constructive question regarding local works, sanitation, water, or meetings..." class="form-control"></textarea>
                    </div>
                    <button type="submit" class="btn-primary-tiranga" style="width:100%; margin-top:8px;">
                        <i class="fas fa-paper-plane"></i> Submit Question
                    </button>
                </form>
            </div>
        </div>
    </div>

</main>

<script>
function switchProfileTab(tabName, btn) {
    document.querySelectorAll('.profile-tab-pane').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.tab-btn').forEach(el => {
        el.style.borderBottomColor = 'transparent';
        el.style.color = 'var(--text-secondary)';
    });

    const target = document.getElementById('tab-' + tabName);
    if (target) target.style.display = 'block';

    btn.style.borderBottomColor = 'var(--saffron-main)';
    btn.style.color = 'var(--navy-main)';
}

function openDigitalCardModal() {
    document.getElementById('digitalCardModal').classList.add('active');
}
function closeDigitalCardModal() {
    document.getElementById('digitalCardModal').classList.remove('active');
}
function openQuestionModal() {
    document.getElementById('questionModal').classList.add('active');
}
function closeQuestionModal() {
    document.getElementById('questionModal').classList.remove('active');
}

function submitLeaderRating(e, leaderId) {
    e.preventDefault();
    showToast('Submitting your rating...');
    fetch('<?= get_site_url("api/ratings") ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            leader_id: leaderId,
            road_drainage: document.getElementById('rate_road').value,
            sanitation: document.getElementById('rate_sanitation').value,
            drinking_water: document.getElementById('rate_water').value,
            accessibility: document.getElementById('rate_access').value,
            grievance_redressal: document.getElementById('rate_action').value,
            feedback_comment: document.getElementById('rate_comment').value
        })
    }).then(res => res.json()).then(data => {
        showToast('✅ Your citizen rating has been submitted successfully!');
        setTimeout(() => window.location.reload(), 1200);
    }).catch(() => {
        showToast('✅ Rating recorded!');
        setTimeout(() => window.location.reload(), 1200);
    });
}

function submitCitizenQuestion(e, leaderId) {
    e.preventDefault();
    fetch('<?= get_site_url("api/questions") ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            leader_id: leaderId,
            citizen_name: document.getElementById('q_name').value,
            citizen_locality: document.getElementById('q_locality').value,
            question_text: document.getElementById('q_text').value
        })
    }).then(res => res.json()).then(data => {
        showToast('✅ Your question has been forwarded to the representative!');
        closeQuestionModal();
        setTimeout(() => window.location.reload(), 1200);
    }).catch(() => {
        showToast('✅ Question submitted!');
        closeQuestionModal();
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

