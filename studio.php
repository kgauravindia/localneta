<?php
// studio.php - LocalNeta Studio (Canvas Poster Maker, Slogan Generator, QR Visiting Card)
define('APP_INIT', true);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';

$pdo = get_db_connection();
$currentUser = require_user_login($pdo);

// Check if a specific leader/representative was selected to auto-populate
$leaderId = (int)($_GET['leader_id'] ?? 0);
$mukhiyaId = (int)($_GET['mukhiya_id'] ?? 0);
$politicianId = (int)($_GET['politician_id'] ?? 0);
$mpId = (int)($_GET['mp_id'] ?? 0);
$mlcId = (int)($_GET['mlc_id'] ?? 0);

$defaultName = '';
$defaultPost = '';
$defaultArea = '';
$defaultPhone = '';
$defaultPhotoUrl = '';
$leaderPhotoUrl = '';
$mpProfileUrl = '';
$mlcProfileUrl = '';

if ($mukhiyaId > 0 && $pdo->query("SHOW TABLES LIKE 'mukhiya'")->fetch()) {
    $stmt = $pdo->prepare("SELECT * FROM mukhiya WHERE id = ?");
    $stmt->execute([$mukhiyaId]);
    if ($mk = $stmt->fetch()) {
        $defaultName = format_civic_title($mk['name']);
        $defaultPost = 'Mukhiya (Gram Panchayat Head)';
        $defaultArea = 'Gram Panchayat ' . format_civic_title($mk['panchayat_name']) . ', ' . format_civic_title($mk['block_name']) . ' (' . format_civic_title($mk['district_name']) . ')';
        if (!empty($mk['photo'])) {
            $leaderPhotoUrl = get_site_url((string)$mk['photo']);
        }
    }
} elseif ($politicianId > 0 && $pdo->query("SHOW TABLES LIKE 'politician'")->fetch()) {
    $stmt = $pdo->prepare("SELECT * FROM politician WHERE id = ?");
    $stmt->execute([$politicianId]);
    if ($pol = $stmt->fetch()) {
        $polType = strtoupper(trim((string)$pol['c_type']));
        $defaultName = format_civic_title($pol['name']);
        $defaultPost = $polType === 'MLC'
            ? 'Member of Legislative Council (MLC)'
            : ($polType === 'MPLS' ? 'MP (Lok Sabha)' : ($polType === 'MPRS' ? 'MP (Rajya Sabha)' : 'Member of Legislative Assembly (MLA)'));
        $defaultArea = format_civic_title($pol['c_name']) . (!empty($pol['political_party']) ? ' • ' . trim((string)$pol['political_party']) : '');
        if (!empty($pol['photo'])) {
            $leaderPhotoUrl = get_site_url((string)$pol['photo']);
        }
    }
} elseif ($mlcId > 0 && $pdo->query("SHOW TABLES LIKE 'mlc'")->fetch()) {
    $stmt = $pdo->prepare("SELECT * FROM mlc WHERE id = ?");
    $stmt->execute([$mlcId]);
    if ($mlcRow = $stmt->fetch()) {
        $defaultName = trim((string)$mlcRow['member_name']);
        $roleText = trim((string)($mlcRow['role_designation'] ?? ''));
        $defaultPost = $roleText !== '' ? ($roleText . ' & MLC') : 'Member of Legislative Council (MLC)';
        $seatText = trim((string)($mlcRow['constituency'] ?: $mlcRow['constituency_type']));
        $stateText = trim((string)($mlcRow['state'] ?? ''));
        $partyText = trim((string)($mlcRow['party_name'] ?? ''));
        $defaultArea = trim($seatText . ($stateText !== '' ? ', ' . $stateText : '') . ($partyText !== '' ? ' • ' . $partyText : ''), ' •,');
        $mlcProfileUrl = get_mlc_page_url((string)($mlcRow['slug'] ?? ''), (int)$mlcRow['id']);
        if (!empty($mlcRow['photo_url'])) {
            $leaderPhotoUrl = (string)$mlcRow['photo_url'];
        }
    }
} elseif ($mpId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM mp WHERE id = ?");
    $stmt->execute([$mpId]);
    if ($mpRow = $stmt->fetch()) {
        $defaultName = trim((string)$mpRow['member_name']);
        $defaultPost = $mpRow['type'] === 'rs' ? 'Member of Parliament (Rajya Sabha)' : 'Member of Parliament (Lok Sabha)';
        $defaultArea = trim((string)$mpRow['constituency']) . (!empty($mpRow['state']) ? ', ' . trim((string)$mpRow['state']) : '');
        $mpProfileUrl = get_mp_page_url((string)($mpRow['slug'] ?? ''), (int)$mpRow['id']);
        if (!empty($mpRow['photo_url'])) {
            $leaderPhotoUrl = (string)$mpRow['photo_url'];
        }
    }
} elseif ($leaderId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM leaders WHERE id = ?");
    $stmt->execute([$leaderId]);
    if ($defaultLeader = $stmt->fetch()) {
        $defaultName = (string)($defaultLeader['full_name_en'] ?? '');
        $defaultPost = (string)($defaultLeader['designation_hi'] ?? '');
        $defaultArea = (string)($defaultLeader['constituency_name_hi'] ?? '');
    }
}

if ($defaultName === '' && $currentUser) {
    $defaultName = trim((string)($currentUser['full_name'] ?: $currentUser['name']));
    if ($defaultPost === '') {
        $defaultPost = trim((string)($currentUser['designation'] ?? ''));
        if ($defaultPost === '') {
            $defaultPost = (($currentUser['account_type'] ?? 'user') === 'politician') ? 'Public Representative' : 'Citizen Member';
        }
    }
    if ($defaultArea === '') {
        $areaParts = array_filter([
            trim((string)($currentUser['constituency'] ?? '')),
            trim((string)($currentUser['block_name'] ?? '')),
            trim((string)($currentUser['district'] ?? '')),
            trim((string)($currentUser['state_name'] ?? '')),
        ], static fn($v): bool => $v !== '');
        $defaultArea = implode(', ', $areaParts);
    }
}

// User photo resolution: Leader Photo -> Logged-in User Profile Photo -> Dummy User Placeholder
$userProfilePhotoUrl = '';
if ($currentUser && !empty($currentUser['profile_photo'])) {
    $userProfilePhotoUrl = function_exists('get_user_avatar_url')
        ? get_user_avatar_url((string)$currentUser['profile_photo'])
        : get_site_url(ltrim((string)$currentUser['profile_photo'], '/'));
}

if ($leaderPhotoUrl !== '') {
    $defaultPhotoUrl = $leaderPhotoUrl;
} elseif ($userProfilePhotoUrl !== '') {
    $defaultPhotoUrl = $userProfilePhotoUrl;
} else {
    $defaultPhotoUrl = get_site_url('assets/images/dummy-user.png') . '?v=' . (@filemtime(__DIR__ . '/assets/images/dummy-user.png') ?: 1);
}

$previewName = $defaultName !== '' ? $defaultName : '[Representative Name]';
$previewPost = $defaultPost !== '' ? $defaultPost : '[Designation]';

$pageTitle = "LocalNeta Studio — Poster Maker & Digital Kit";
require_once __DIR__ . '/includes/header.php';
?>

<main class="main-wrapper">

    <!-- Page Title Banner -->
    <div class="tiranga-banner" style="margin-bottom:20px;">
        <div>
            <h2><i class="fas fa-paint-brush" style="color:var(--saffron-main);"></i> LocalNeta Leader Studio</h2>
            <p>Generate professional festival greeting posters, development project notices, Hindi slogans, and QR visiting cards in 10 seconds.</p>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
            <?php if ($mpProfileUrl !== ''): ?>
                <a href="<?= e($mpProfileUrl) ?>" class="btn-profile-view" style="flex:unset; padding:7px 14px; font-size:0.82rem; background:#FFFFFF;">
                    <i class="fas fa-landmark" style="color:var(--saffron-main);"></i> View MP Profile
                </a>
            <?php elseif ($mlcProfileUrl !== ''): ?>
                <a href="<?= e($mlcProfileUrl) ?>" class="btn-profile-view" style="flex:unset; padding:7px 14px; font-size:0.82rem; background:#FFFFFF;">
                    <i class="fas fa-scroll" style="color:var(--green-main);"></i> View MLC Profile
                </a>
            <?php endif; ?>
            <span class="badge-tag badge-elected" style="background:#046A38; color:#fff; font-size:0.82rem;">
                <i class="fas fa-bolt"></i> Instant 10-Second Generation
            </span>
        </div>
    </div>

    <!-- Studio Two-Column Grid (Canvas on Left/Top, Controls on Right) -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(340px, 1fr)); gap:24px; margin-bottom:30px;">
        
        <!-- COLUMN 1: LIVE CANVAS PREVIEW & DOWNLOAD ACTIONS -->
        <div style="display:flex; flex-direction:column; gap:16px;">
            <div class="canvas-preview-container">
                <canvas id="posterCanvas" width="1080" height="1080" data-default-photo="<?= e($defaultPhotoUrl) ?>" data-dummy-photo="<?= get_site_url('assets/images/dummy-user.png') ?>?v=<?= @filemtime(__DIR__ . '/assets/images/dummy-user.png') ?: 1 ?>"></canvas>
            </div>

            <!-- Export Buttons -->
            <div style="display:flex; gap:10px;">
                <button type="button" class="btn-primary-tiranga" style="flex:1; padding:12px 18px; font-size:1rem;" onclick="downloadPoster()">
                    <i class="fas fa-download"></i> Download HD Poster (PNG)
                </button>
                <button type="button" class="btn-green-tiranga" style="padding:12px 18px; font-size:1rem;" onclick="sharePosterWhatsApp()" title="Share to WhatsApp">
                    <i class="fab fa-whatsapp"></i> Share
                </button>
            </div>
        </div>

        <!-- COLUMN 2: STUDIO CUSTOMIZATION CONTROLS -->
        <div style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); padding:24px; box-shadow:var(--shadow-sm); display:flex; flex-direction:column; gap:16px;">
            <h3 style="font-size:1.15rem; font-weight:800; color:var(--navy-main); border-bottom:1px solid var(--border-light); padding-bottom:10px;">
                <i class="fas fa-sliders" style="color:var(--saffron-main);"></i> Poster Customization
            </h3>

            <!-- 1. Format / Aspect Ratio Selector -->
            <div>
                <label class="form-label" style="font-weight:700;">1. Choose Image Format</label>
                <div style="display:flex; gap:8px;">
                    <button type="button" class="btn-profile-view ratio-btn active" id="btn-ratio-1-1" onclick="setAspectRatio('1:1')" style="flex:1; text-align:center;">
                        <i class="fas fa-square"></i> 1:1 Square (WhatsApp DP / FB)
                    </button>
                    <button type="button" class="btn-profile-view ratio-btn" id="btn-ratio-9-16" onclick="setAspectRatio('9:16')" style="flex:1; text-align:center;">
                        <i class="fas fa-mobile-screen"></i> 9:16 Story (Status / Reels)
                    </button>
                </div>
            </div>

            <!-- 2. Template Selector (4 Days of Chhath Puja & Celebrations) -->
            <div>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                    <label class="form-label" style="font-weight:700; margin:0;">2. Select Occasion Template</label>
                    <span style="font-size:0.75rem; color:var(--saffron-dark); font-weight:700;"><i class="fas fa-sun"></i> छठ महापर्व विशेष</span>
                </div>

                <!-- 4 Days of Chhath Puja Highlight Bar -->
                <div style="display:grid; grid-template-columns:repeat(2, 1fr); gap:8px; margin-bottom:10px; background:linear-gradient(135deg, rgba(255,103,31,0.08) 0%, rgba(220,38,38,0.06) 100%); padding:10px; border-radius:var(--radius-md); border:1px solid rgba(255,103,31,0.25);">
                    <button type="button" class="template-chip active btn-profile-view" id="tpl-chhath-nahay-khaye" onclick="setTemplate('chhath-nahay-khaye')" style="font-size:0.82rem; padding:8px 8px; text-align:left; font-weight:700;">
                        🌅 1. नहाय-खाय (Day 1)
                    </button>
                    <button type="button" class="template-chip btn-profile-view" id="tpl-chhath-kharna" onclick="setTemplate('chhath-kharna')" style="font-size:0.82rem; padding:8px 8px; text-align:left; font-weight:700;">
                        🥣 2. खरना (Day 2)
                    </button>
                    <button type="button" class="template-chip btn-profile-view" id="tpl-chhath-pahla-arg" onclick="setTemplate('chhath-pahla-arg')" style="font-size:0.82rem; padding:8px 8px; text-align:left; font-weight:700;">
                        🌇 3. पहला अर्घ्य (Day 3)
                    </button>
                    <button type="button" class="template-chip btn-profile-view" id="tpl-chhath-dusra-arg" onclick="setTemplate('chhath-dusra-arg')" style="font-size:0.82rem; padding:8px 8px; text-align:left; font-weight:700;">
                        🌅 4. दूसरा अर्घ्य (Day 4)
                    </button>
                </div>

                <!-- Other Occasion Templates -->
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(110px, 1fr)); gap:6px;">
                    <button type="button" class="template-chip btn-profile-view" id="tpl-chhath" onclick="setTemplate('chhath')" style="font-size:0.75rem; padding:6px 6px;">
                        ☀️ छठ महापर्व
                    </button>
                    <button type="button" class="template-chip btn-profile-view" id="tpl-diwali" onclick="setTemplate('diwali')" style="font-size:0.75rem; padding:6px 6px;">
                        🪔 Deepawali
                    </button>
                    <button type="button" class="template-chip btn-profile-view" id="tpl-national" onclick="setTemplate('national')" style="font-size:0.75rem; padding:6px 6px;">
                        🇮🇳 National Day
                    </button>
                    <button type="button" class="template-chip btn-profile-view" id="tpl-holi" onclick="setTemplate('holi')" style="font-size:0.75rem; padding:6px 6px;">
                        🎨 Holi
                    </button>
                    <button type="button" class="template-chip btn-profile-view" id="tpl-vikas" onclick="setTemplate('vikas')" style="font-size:0.75rem; padding:6px 6px;">
                        🏗️ Vikas Launch
                    </button>
                    <button type="button" class="template-chip btn-profile-view" id="tpl-notice" onclick="setTemplate('notice')" style="font-size:0.75rem; padding:6px 6px;">
                        📢 Gram Sabha
                    </button>
                </div>
            </div>

            <!-- 3. Photo Upload (Auto-loads User Profile Photo by default) -->
            <div>
                <label class="form-label" style="font-weight:700;"><i class="fas fa-camera" style="color:var(--green-main);"></i> 3. Representative Photo (Profile Photo by Default)</label>
                <div style="display:flex; align-items:center; gap:10px;">
                    <img id="studioPhotoThumb"
                         src="<?= e($defaultPhotoUrl) ?>"
                         alt="Representative Avatar"
                         style="width:44px; height:44px; border-radius:50%; object-fit:cover; border:2px solid var(--border-color); background:#F1F5F9; flex-shrink:0;">
                    <input type="file" id="studioPhotoInput" accept="image/*" class="form-control" onchange="handlePhotoUpload(event)" style="padding:8px; flex:1;">
                    <button type="button" id="btnResetDummyPhoto" class="btn-profile-view" onclick="resetStudioPhoto()" style="display:none; flex:unset; padding:8px 10px; font-size:0.78rem;" title="Revert to Default Photo">
                        Reset
                    </button>
                </div>
                <p style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">*Auto-loads your profile photo by default. Upload any custom photo to frame inside the gold &amp; tricolor circular badge.</p>
            </div>

            <!-- 4. Text Fields -->
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Representative Name</label>
                    <input type="text" id="posterName" value="<?= e($defaultName) ?>" placeholder="Enter leader or candidate name" class="form-control">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Designation / Post</label>
                    <input type="text" id="posterPost" value="<?= e($defaultPost) ?>" placeholder="e.g. Mukhiya / MLA / MP" class="form-control">
                </div>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label">Panchayat & Block / Constituency</label>
                <input type="text" id="posterArea" value="<?= e($defaultArea) ?>" placeholder="e.g. Gram Panchayat, Block (District)" class="form-control">
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label">Contact / WhatsApp Number (Optional)</label>
                <input type="text" id="posterPhone" value="<?= e($defaultPhone) ?>" placeholder="Optional 10-digit WhatsApp / contact number" class="form-control">
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label">Custom Slogan / Message (Hindi)</label>
                <textarea id="posterSlogan" rows="2" class="form-control">लोक आस्था के चार दिवसीय महापर्व छठ के पावन प्रथम दिवस 'नहाय-खाय' की समस्त क्षेत्रवासियों को हार्दिक शुभकामनाएँ।</textarea>
            </div>
        </div>

    </div>

    <!-- LOWER SECTION: SMART SLOGAN & COPY GENERATOR -->
    <section style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); padding:24px; box-shadow:var(--shadow-sm); margin-bottom:30px;">
        <div class="section-header">
            <h2 class="section-title"><i class="fas fa-wand-magic-sparkles"></i> Smart Hindi Slogan & Social Media Copy Generator</h2>
            <span class="badge-tag badge-elected"><i class="fas fa-bolt"></i> Ready for WhatsApp & Facebook</span>
        </div>
        <p style="font-size:0.88rem; color:var(--text-secondary); margin-bottom:16px;">
            Select your occasion to generate instant Hindi slogans, WhatsApp broadcast messages, and Facebook post captions with hashtags.
        </p>

        <div style="display:flex; gap:10px; margin-bottom:16px; flex-wrap:wrap;">
            <select id="genTopic" class="form-control" style="width:320px;" onchange="generateContent()">
                <option value="chhath-nahay-khaye">🌅 छठ महापर्व — नहाय-खाय (Day 1)</option>
                <option value="chhath-kharna">🥣 छठ महापर्व — खरना (Day 2)</option>
                <option value="chhath-pahla-arg">🌇 छठ महापर्व — पहला अर्घ्य / संध्या अर्घ्य (Day 3)</option>
                <option value="chhath-dusra-arg">🌅 छठ महापर्व — दूसरा अर्घ्य / उषा अर्घ्य (Day 4)</option>
                <option value="chhath">☀️ छठ पूजा महापर्व (सामान्य)</option>
                <option value="diwali">🪔 Shubh Deepawali</option>
                <option value="vikas">🏗️ Panchayat Vikas Work Launch</option>
                <option value="notice">📢 Gram Sabha Meeting Notice</option>
            </select>
            <button type="button" class="btn-primary-tiranga" onclick="generateContent()" style="padding:8px 18px;">
                <i class="fas fa-rotate"></i> Generate Content
            </button>
        </div>

        <!-- Generated Slogans -->
        <div style="margin-bottom:20px;">
            <h4 style="font-size:0.95rem; font-weight:700; color:var(--navy-main); margin-bottom:8px;">Catchy Slogans (Click to Apply to Poster):</h4>
            <div id="generatedSlogansList" style="display:flex; flex-direction:column; gap:8px;">
                <div style="background:#FFFFFF; border:1px solid var(--border-color); border-radius:8px; padding:10px 14px; display:flex; justify-content:space-between; align-items:center;">
                    <span style="font-size:0.9rem; color:var(--navy-main); font-weight:600;">“ लोक आस्था, पवित्रता और अखंड विश्वास का महापर्व — छठ पूजा की हार्दिक शुभकामनाएँ। ”</span>
                    <button type="button" class="btn-profile-view" style="flex:unset; padding:4px 10px; font-size:0.78rem;" onclick="applySloganToPoster('लोक आस्था, पवित्रता और अखंड विश्वास का महापर्व — छठ पूजा की हार्दिक शुभकामनाएँ।')">
                        Use in Poster
                    </button>
                </div>
            </div>
        </div>

        <!-- Generated Broadcast Texts -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:16px;">
            <!-- WhatsApp Box -->
            <div style="background:var(--white-surface); border:1px solid var(--border-color); border-radius:var(--radius-md); padding:16px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <span style="font-weight:700; font-size:0.9rem; color:var(--green-main);"><i class="fab fa-whatsapp"></i> WhatsApp Broadcast Message</span>
                    <button type="button" class="btn-profile-view" style="flex:unset; padding:3px 10px; font-size:0.75rem;" onclick="copyToClipboard('genWhatsappText')">
                        <i class="fas fa-copy"></i> Copy
                    </button>
                </div>
                <textarea id="genWhatsappText" rows="5" readonly class="form-control" style="background:#FFFFFF; font-size:0.85rem; line-height:1.5;">🙏 समस्त ग्रामवासियों एवं क्षेत्रवासियों को लोक आस्था के महापर्व छठ पूजा की हार्दिक शुभकामनाएँ। भगवान सूर्य एवं छठी मइया आप सभी के जीवन में उत्तम स्वास्थ्य, खुशहाली एवं निरंतर प्रगति प्रदान करें।

— विनीत: <?= e($previewName) ?>
<?= e($previewPost) ?>
LocalNeta.com</textarea>
            </div>

            <!-- Facebook Box -->
            <div style="background:var(--white-surface); border:1px solid var(--border-color); border-radius:var(--radius-md); padding:16px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <span style="font-weight:700; font-size:0.9rem; color:var(--navy-accent);"><i class="fab fa-facebook"></i> Facebook / Social Media Post</span>
                    <button type="button" class="btn-profile-view" style="flex:unset; padding:3px 10px; font-size:0.75rem;" onclick="copyToClipboard('genFacebookText')">
                        <i class="fas fa-copy"></i> Copy
                    </button>
                </div>
                <textarea id="genFacebookText" rows="5" readonly class="form-control" style="background:#FFFFFF; font-size:0.85rem; line-height:1.5;">आस्था, नियम और निष्ठा का प्रतीक महापर्व छठ पूजा की समस्त बिहारवासियों को हार्दिक बधाई एवं अनंत शुभकामनाएँ। आइए, इस पावन अवसर पर अपनी पंचायत को स्वच्छ, स्वस्थ और विकसित बनाने का संकल्प लें। #ChhathPuja #LocalNeta #PanchayatVikas</textarea>
            </div>
        </div>
    </section>

    <!-- PRICING & MICRO-SAAS TIERS BANNER -->
    <section style="background:linear-gradient(135deg, #0A192F 0%, #162C4E 100%); border-radius:var(--radius-lg); padding:28px 20px; color:#FFFFFF; margin-bottom:30px; border-top:4px solid var(--saffron-main);">
        <div style="text-align:center; max-width:700px; margin:0 auto 24px;">
            <span class="badge-tag badge-elected" style="background:#FF671F; color:#fff; margin-bottom:8px;">Panchayat Leader Subscription</span>
            <h2 style="font-size:1.4rem; font-weight:800; color:#FFFFFF;">Get Your Complete Panchayat Digital Kit</h2>
            <p style="font-size:0.88rem; color:#CBD5E1; margin-top:4px;">
                Unlimited festive & developmental posters without watermarks, official verified badge on LocalNeta, citizen Q&A dashboard, and digital QR card.
            </p>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:16px; max-width:900px; margin:0 auto;">
            <!-- Free Plan -->
            <div style="background:#FFFFFF; color:var(--navy-main); border-radius:var(--radius-md); padding:18px; border-top:4px solid var(--border-color); text-align:center;">
                <h4 style="font-size:1rem; font-weight:700;">Basic Citizen / Free</h4>
                <div style="font-size:1.5rem; font-weight:800; margin:10px 0;">₹0 <span style="font-size:0.8rem; font-weight:500;">/ Forever</span></div>
                <p style="font-size:0.8rem; color:var(--text-muted); margin-bottom:14px;">Public leader search, track issues, 3 sample watermarked posters/month.</p>
                <button type="button" class="btn-profile-view" style="width:100%;">Included</button>
            </div>

            <!-- Leader Pro (Hero Plan) -->
            <div style="background:#FFFFFF; color:var(--navy-main); border-radius:var(--radius-md); padding:20px; border-top:4px solid var(--saffron-main); text-align:center; box-shadow:0 0 20px rgba(255, 103, 31, 0.4); transform:scale(1.03);">
                <span class="badge-tag badge-elected" style="margin-bottom:6px;">Most Popular for Mukhiyas</span>
                <h4 style="font-size:1.1rem; font-weight:800;">Leader Pro</h4>
                <div style="font-size:1.8rem; font-weight:800; color:var(--saffron-main); margin:8px 0;">₹299 <span style="font-size:0.85rem; font-weight:500; color:var(--text-muted);">/ Month</span></div>
                <p style="font-size:0.82rem; color:var(--text-muted); margin-bottom:14px;"><strong>Unlimited HD 4K Posters</strong> without watermark, Green Verified Badge, Citizen Q&A Panel, Printable QR Visiting Card.</p>
                <a href="<?= get_site_url('dashboard') ?>" class="btn-primary-tiranga" style="width:100%; padding:10px;">
                    Activate Leader Pro
                </a>
            </div>

            <!-- Campaign Election Plan -->
            <div style="background:#FFFFFF; color:var(--navy-main); border-radius:var(--radius-md); padding:18px; border-top:4px solid var(--green-main); text-align:center;">
                <h4 style="font-size:1rem; font-weight:700;">Chunav Campaign Kit</h4>
                <div style="font-size:1.5rem; font-weight:800; margin:10px 0;">₹2,499 <span style="font-size:0.8rem; font-weight:500;">/ Season</span></div>
                <p style="font-size:0.8rem; color:var(--text-muted); margin-bottom:14px;">Candidate profile, manifesto builder, 50+ election campaign templates, volunteer WhatsApp links.</p>
                <a href="<?= get_site_url('dashboard') ?>" class="btn-green-tiranga" style="width:100%; padding:8px;">
                    Election Mode
                </a>
            </div>
        </div>
    </section>

</main>

<!-- Studio JS Scripts -->
<script src="<?= get_site_url('assets/js/poster-studio.js') ?>?v=<?= @filemtime(__DIR__ . '/assets/js/poster-studio.js') ?: 1 ?>"></script>
<script src="<?= get_site_url('assets/js/content-generator.js') ?>?v=<?= @filemtime(__DIR__ . '/assets/js/content-generator.js') ?: 1 ?>"></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

