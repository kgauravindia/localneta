<?php
// user-profile.php - Public Visiting Card for Registered Citizens & Politicians (/user/<handle>)
define('APP_INIT', true);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';

$pdo = get_db_connection();
$viewer = get_current_user_account($pdo);
$viewerId = (int)($viewer['id'] ?? 0);

$handleParam = trim((string)($_GET['handle'] ?? ''));
$idParam = (int)($_GET['id'] ?? 0);

$profile = null;
if ($handleParam !== '') {
    $profile = get_user_by_handle($pdo, $handleParam);
} elseif ($idParam > 0) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$idParam]);
    $profile = $stmt->fetch() ?: null;
}

if (!$profile) {
    http_response_code(404);
    $pageTitle = 'Member Profile Not Found';
    require_once __DIR__ . '/includes/header.php';
    ?>
    <main class="main-wrapper">
        <div style="background:#FFFFFF; border:1px solid var(--border-color); border-top:4px solid var(--saffron-main); border-radius:var(--radius-lg); padding:42px 24px; text-align:center; max-width:620px; margin:20px auto;">
            <i class="fas fa-user-slash" style="font-size:2.6rem; color:var(--text-muted); margin-bottom:12px;"></i>
            <h1 style="font-size:1.3rem; font-weight:800; color:var(--navy-main); margin-bottom:8px;">Member Profile Not Found</h1>
            <p style="font-size:0.9rem; color:var(--text-secondary); margin-bottom:20px;">This user or politician profile is unavailable or has been removed.</p>
            <a href="<?= get_site_url('leaders') ?>" class="btn-primary-tiranga"><i class="fas fa-users"></i> Browse Leaders Directory</a>
        </div>
    </main>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$profileId = (int)$profile['id'];
$isOwner = ($viewerId > 0 && $viewerId === $profileId);

if (!$isOwner && ($profile['profile_visibility'] ?? 'public') === 'private') {
    http_response_code(403);
    $pageTitle = 'Private Member Profile';
    require_once __DIR__ . '/includes/header.php';
    ?>
    <main class="main-wrapper">
        <div style="background:#FFFFFF; border:1px solid var(--border-color); border-top:4px solid var(--navy-main); border-radius:var(--radius-lg); padding:42px 24px; text-align:center; max-width:620px; margin:20px auto;">
            <i class="fas fa-lock" style="font-size:2.6rem; color:var(--navy-main); margin-bottom:12px;"></i>
            <h1 style="font-size:1.3rem; font-weight:800; color:var(--navy-main); margin-bottom:8px;">This Profile is Set to Private</h1>
            <p style="font-size:0.9rem; color:var(--text-secondary); margin-bottom:20px;">Only the account owner can view this profile card right now.</p>
            <a href="<?= get_site_url('index') ?>" class="btn-primary-tiranga"><i class="fas fa-home"></i> Return Home</a>
        </div>
    </main>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

if (!$isOwner) {
    increment_user_profile_views($pdo, $profileId);
    $profile['counter'] = (int)($profile['counter'] ?? 0) + 1;
}

$isPolitician = ($profile['account_type'] ?? 'user') === 'politician';
$displayName = (string)($profile['full_name'] ?: $profile['name']);
$catMeta = !empty($profile['leader_category']) ? get_leader_type_meta((string)$profile['leader_category'], $pdo) : null;

$canShowMobile = $isOwner || (($profile['mobile_visibility'] ?? 'masked') !== 'private');
$canShowEmail = $isOwner || (($profile['email_visibility'] ?? 'masked') !== 'private');
$canShowAddress = $isOwner || (($profile['address_visibility'] ?? 'public') !== 'private');

$maskedMobile = $canShowMobile ? mask_phone_number((string)($profile['mobile'] ?? '')) : '';
$maskedWhatsapp = ($canShowMobile && !empty($profile['whatsapp']) && $profile['whatsapp'] !== $profile['mobile'])
    ? mask_phone_number((string)$profile['whatsapp'])
    : '';
$maskedEmail = ($canShowEmail && !empty($profile['email']) && filter_var($profile['email'], FILTER_VALIDATE_EMAIL))
    ? mask_email_address((string)$profile['email'])
    : '';

$unlockedTodayMap = $viewerId > 0 ? get_user_revealed_entity_keys_today($pdo, $viewerId) : [];
$isUnlockedToday = !empty($unlockedTodayMap['user:' . $profileId]);

$locParts = array_filter([
    trim((string)($profile['panchayat'] ?? '')),
    trim((string)($profile['block_name'] ?? '')),
    trim((string)($profile['district'] ?? '')),
    trim((string)($profile['state_name'] ?? '')),
]);

$pageTitle = $displayName . ' — ' . ($isPolitician ? 'Politician Profile' : 'Citizen Member');
$pageDescription = 'Official LocalNeta civic profile of ' . $displayName . ($locParts ? ' (' . implode(', ', $locParts) . ')' : '') . ' with privacy-masked contact verification.';
$canonicalUrl = get_user_profile_url((string)($profile['username_handle'] ?? ''), $profileId);
require_once __DIR__ . '/includes/header.php';
?>

<main class="main-wrapper">
    <!-- Breadcrumb -->
    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-bottom:14px; font-size:0.84rem; color:var(--text-muted);">
        <div>
            <a href="<?= get_site_url('index') ?>" style="color:var(--navy-accent); font-weight:700;">Home</a>
            <span>/</span>
            <a href="<?= get_site_url('leaders') ?>" style="color:var(--navy-accent); font-weight:700;">Leaders Directory</a>
            <span>/</span>
            <span><?= e($displayName) ?></span>
        </div>
        <?php if ($isOwner): ?>
            <a href="<?= get_site_url('edit-profile') ?>" class="btn-primary-tiranga" style="padding:6px 14px; font-size:0.8rem;">
                <i class="fas fa-user-pen"></i> Edit My Profile
            </a>
        <?php endif; ?>
    </div>

    <!-- Profile Hero Card -->
    <div style="background:#FFFFFF; border:1px solid var(--border-color); border-radius:var(--radius-lg); overflow:hidden; box-shadow:var(--shadow-sm); margin-bottom:22px;">
        <div style="height:110px; background:<?= $isPolitician ? 'linear-gradient(90deg, #FF671F 0%, #0A192F 55%, #046A38 100%)' : 'linear-gradient(135deg, #0A192F 0%, #1E3A8A 100%)' ?>; position:relative;">
            <div style="position:absolute; top:12px; right:14px; display:flex; gap:6px;">
                <span class="cat-pill <?= $isPolitician ? 'bg-amber' : 'bg-blue' ?>">
                    <?= $isPolitician ? ($catMeta ? e($catMeta['name']) : 'Politician / Leader') : 'Registered Citizen' ?>
                </span>
                <?php if (!empty($profile['is_mobile_verified'])): ?>
                    <span class="badge-tag badge-elected"><i class="fas fa-check-circle"></i> Verified Account</span>
                <?php endif; ?>
            </div>
        </div>

        <div style="padding:14px 22px 22px; position:relative;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
                <div style="display:flex; align-items:flex-start; gap:16px; flex-wrap:wrap; min-width:0;">
                    <img src="<?= e(get_user_avatar_url($profile['profile_photo'] ?? null)) ?>" alt="<?= e($displayName) ?>" style="width:96px; height:96px; margin-top:-62px; flex-shrink:0; position:relative; z-index:2; border-radius:50%; object-fit:cover; border:4px solid #FFFFFF; box-shadow:var(--shadow-md); background:#FFFFFF;">
                    <div style="min-width:0;">
                        <h1 style="font-size:1.45rem; font-weight:800; color:var(--navy-main); line-height:1.25; margin:0;"><?= e($displayName) ?></h1>
                        <div style="font-size:0.92rem; font-weight:700; color:var(--green-main); margin-top:2px;">
                            <?= e($profile['designation'] ?: ($isPolitician ? 'Public Representative' : 'Verified Citizen')) ?>
                            <?php if (!empty($profile['username_handle'])): ?>
                                <span style="color:var(--text-muted); font-weight:600;">• @<?= e($profile['username_handle']) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($locParts)): ?>
                            <div style="font-size:0.84rem; color:var(--text-secondary); margin-top:4px;">
                                <i class="fas fa-location-dot" style="color:var(--saffron-main);"></i> <?= e(implode(', ', $locParts)) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <?php if (!empty($profile['party_name'])): ?>
                        <span class="badge-tag badge-verified" style="padding:6px 12px; font-size:0.82rem;">
                            <i class="fas fa-flag" style="color:var(--saffron-main);"></i> <?= e($profile['party_name']) ?>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($profile['constituency'])): ?>
                        <span class="badge-tag badge-elected" style="padding:6px 12px; font-size:0.82rem;">
                            <i class="fas fa-map-pin"></i> <?= e($profile['constituency']) ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); gap:20px;">
        <!-- Masked Mobile & Email Card -->
        <section style="background:#FFFFFF; border:1px solid var(--border-color); border-top:4px solid var(--green-main); border-radius:var(--radius-lg); padding:20px; box-shadow:var(--shadow-sm);">
            <div class="section-header" style="margin-bottom:12px; flex-wrap:wrap; gap:8px;">
                <h2 class="section-title" style="font-size:1.05rem;">
                    <i class="fas fa-address-book"></i> Direct Contact (Privacy Masked)
                </h2>
                <a href="<?= get_site_url('privacy-policy#section-masking') ?>" class="badge-tag badge-verified" style="text-decoration:none; font-size:0.72rem;">
                    <i class="fas fa-shield-halved"></i> Privacy Masked
                </a>
            </div>

            <div style="display:grid; gap:10px; font-size:0.86rem;">
                <?php if ($maskedMobile !== ''): ?>
                    <div style="display:flex; gap:10px; padding:10px 12px; background:var(--white-surface); border:1px solid var(--border-light); border-radius:var(--radius-sm);">
                        <i class="fas fa-mobile-screen-button" style="color:var(--green-main); width:16px; margin-top:4px;"></i>
                        <div style="flex:1; min-width:0;">
                            <div style="font-size:0.73rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:4px;">Mobile Number (Masked)</div>
                            <?= render_contact_reveal_trigger('user', $profileId, 'mobile', $maskedMobile, $displayName, [
                                'variant' => 'box',
                                'icon' => 'fas fa-mobile-screen-button',
                                'unlocked_today' => $isUnlockedToday,
                            ]) ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($maskedWhatsapp !== ''): ?>
                    <div style="display:flex; gap:10px; padding:10px 12px; background:var(--white-surface); border:1px solid var(--border-light); border-radius:var(--radius-sm);">
                        <i class="fab fa-whatsapp" style="color:#25D366; width:16px; margin-top:4px;"></i>
                        <div style="flex:1; min-width:0;">
                            <div style="font-size:0.73rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:4px;">WhatsApp Number (Masked)</div>
                            <?= render_contact_reveal_trigger('user', $profileId, 'whatsapp', $maskedWhatsapp, $displayName, [
                                'variant' => 'box',
                                'icon' => 'fab fa-whatsapp',
                                'unlocked_today' => $isUnlockedToday,
                            ]) ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($maskedEmail !== ''): ?>
                    <div style="display:flex; gap:10px; padding:10px 12px; background:var(--white-surface); border:1px solid var(--border-light); border-radius:var(--radius-sm);">
                        <i class="fas fa-envelope" style="color:var(--saffron-main); width:16px; margin-top:4px;"></i>
                        <div style="flex:1; min-width:0;">
                            <div style="font-size:0.73rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:4px;">Email ID (Masked)</div>
                            <?= render_contact_reveal_trigger('user', $profileId, 'email', $maskedEmail, $displayName, [
                                'variant' => 'box',
                                'icon' => 'fas fa-envelope',
                                'unlocked_today' => $isUnlockedToday,
                            ]) ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($canShowAddress && !empty($profile['address'])): ?>
                    <div style="display:flex; gap:10px; padding:10px 12px; background:var(--white-surface); border:1px solid var(--border-light); border-radius:var(--radius-sm);">
                        <i class="fas fa-location-dot" style="color:var(--navy-accent); width:16px; margin-top:3px;"></i>
                        <div>
                            <div style="font-size:0.73rem; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Office / Civic Address</div>
                            <div style="color:var(--text-secondary); margin-top:2px; line-height:1.5;"><?= nl2br(e($profile['address'])) ?><?= !empty($profile['pincode']) ? ' — ' . e($profile['pincode']) : '' ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($maskedMobile === '' && $maskedEmail === ''): ?>
                    <p style="color:var(--text-muted); margin:0;">Contact details are hidden by the account owner's privacy settings.</p>
                <?php endif; ?>
            </div>
        </section>

        <!-- Civic Bio & Disclosures -->
        <section style="background:#FFFFFF; border:1px solid var(--border-color); border-top:4px solid var(--saffron-main); border-radius:var(--radius-lg); padding:20px; box-shadow:var(--shadow-sm);">
            <h2 class="section-title" style="font-size:1.05rem; margin-bottom:12px;">
                <i class="fas fa-user-tie"></i> Public Bio &amp; Civic Background
            </h2>

            <?php if (!empty($profile['bio'])): ?>
                <p style="font-size:0.9rem; color:var(--text-secondary); line-height:1.65; margin-bottom:16px;">
                    <?= nl2br(e($profile['bio'])) ?>
                </p>
            <?php else: ?>
                <p style="font-size:0.86rem; color:var(--text-muted); margin-bottom:16px;">
                    Verified LocalNeta member profile.
                </p>
            <?php endif; ?>

            <div style="display:grid; gap:8px; font-size:0.85rem;">
                <?php if (!empty($profile['education'])): ?>
                    <div><strong><i class="fas fa-graduation-cap" style="color:var(--navy-accent); width:18px;"></i> Education:</strong> <?= e($profile['education']) ?></div>
                <?php endif; ?>
                <?php if (!empty($profile['languages'])): ?>
                    <div><strong><i class="fas fa-language" style="color:var(--green-main); width:18px;"></i> Languages:</strong> <?= e($profile['languages']) ?></div>
                <?php endif; ?>
                <?php if (!empty($profile['office_hours'])): ?>
                    <div><strong><i class="fas fa-clock" style="color:var(--saffron-main); width:18px;"></i> Public Hours:</strong> <?= e($profile['office_hours']) ?></div>
                <?php endif; ?>
                <div><strong><i class="fas fa-eye" style="color:var(--text-muted); width:18px;"></i> Profile Views:</strong> <?= number_format((int)($profile['counter'] ?? 0)) ?></div>
            </div>

            <?php if (!empty($profile['twitter']) || !empty($profile['facebook']) || !empty($profile['instagram']) || !empty($profile['linkedin']) || !empty($profile['website_url'])): ?>
                <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:16px; padding-top:12px; border-top:1px solid var(--border-light);">
                    <?php foreach ([
                        ['url' => $profile['twitter'] ?? '', 'icon' => 'fab fa-x-twitter', 'label' => 'X / Twitter'],
                        ['url' => $profile['facebook'] ?? '', 'icon' => 'fab fa-facebook', 'label' => 'Facebook'],
                        ['url' => $profile['instagram'] ?? '', 'icon' => 'fab fa-instagram', 'label' => 'Instagram'],
                        ['url' => $profile['linkedin'] ?? '', 'icon' => 'fab fa-linkedin', 'label' => 'LinkedIn'],
                        ['url' => $profile['website_url'] ?? '', 'icon' => 'fas fa-globe', 'label' => 'Website'],
                    ] as $soc): if (empty($soc['url'])) continue; ?>
                        <a href="<?= e($soc['url']) ?>" target="_blank" rel="noopener" class="badge-tag badge-verified" style="padding:5px 10px;">
                            <i class="<?= e($soc['icon']) ?>"></i> <?= e($soc['label']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

