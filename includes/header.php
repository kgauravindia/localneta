<?php
// includes/header.php - Global Header for LocalNeta (English UI with Tiranga Civic Palette)
if (!defined('APP_INIT')) {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/functions.php';
}
require_once __DIR__ . '/user_auth.php';

$headerUser = null;
if (function_exists('get_db_connection') && is_user_logged_in()) {
    try {
        $headerUser = get_current_user_account(get_db_connection());
    } catch (Throwable $e) {
        $headerUser = null;
    }
}

// Active page detection
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?: ($_SERVER['PHP_SELF'] ?? 'index.php'), '.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' | ' : '' ?>LocalNeta — Your Leaders, Your Issues | India</title>
    
    <!-- Meta & Civic SEO -->
    <meta name="description" content="<?= isset($pageDescription) ? e($pageDescription) : 'LocalNeta.com — India’s National Civic Directory covering 36 States & UTs, 25 Representative Categories (Mukhiya, Sarpanch, Jila Parishad, Chairman, MLA, MLC, Minister, MP & Political Party Offices), Election Data, and Poster Studio.' ?>">
    <?php if (!empty($canonicalUrl)): ?>
        <link rel="canonical" href="<?= e($canonicalUrl) ?>">
    <?php endif; ?>
    <meta name="theme-color" content="#FF671F">

    <!-- Favicons -->
    <link rel="icon" type="image/png" sizes="32x32" href="<?= get_site_url('assets/images/favicon-32x32.png') ?>?v=<?= @filemtime(__DIR__ . '/../assets/images/favicon-32x32.png') ?: 1 ?>">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= get_site_url('assets/images/favicon-192x192.png') ?>?v=<?= @filemtime(__DIR__ . '/../assets/images/favicon-192x192.png') ?: 1 ?>">
    <link rel="icon" href="<?= get_site_url('favicon.ico') ?>?v=<?= @filemtime(__DIR__ . '/../favicon.ico') ?: 1 ?>" sizes="any">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= get_site_url('assets/images/apple-touch-icon.png') ?>?v=<?= @filemtime(__DIR__ . '/../assets/images/apple-touch-icon.png') ?: 1 ?>">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Hind:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- FontAwesome 6 Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Custom Tiranga Stylesheet -->
    <link rel="stylesheet" href="<?= get_site_url('assets/css/style.css') ?>?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: 1 ?>">
</head>
<body>

    <!-- 1. Indian Flag / Tiranga Top Ribbon -->
    <div class="tiranga-top-ribbon" title="Nation First • Satyameva Jayate"></div>

    <!-- 2. Main Site Header -->
    <header class="site-header">
        <div class="header-container">
            <!-- Brand -->
            <a href="<?= get_site_url('index') ?>" class="brand-wrapper" title="LocalNeta.com Home">
                <img src="<?= get_site_url('assets/images/logo.png') ?>" alt="LocalNeta Emblem" class="brand-logo-img">
                <div class="brand-text">
                    <h1>Local<span>Neta</span></h1>
                    <div class="brand-tagline">Your Leaders, Your Issues 🇮🇳</div>
                </div>
            </a>

            <!-- Desktop Nav Links -->
            <nav class="desktop-nav">
                <a href="<?= get_site_url('index') ?>" class="nav-link <?= $currentPage === 'index' ? 'active' : '' ?>">
                    <i class="fas fa-home"></i> Home
                </a>
                <a href="<?= get_site_url('leaders') ?>" class="nav-link <?= $currentPage === 'leaders' || $currentPage === 'profile' ? 'active' : '' ?>">
                    <i class="fas fa-user-tie"></i> Leaders
                </a>
                <a href="<?= get_site_url('issues') ?>" class="nav-link <?= $currentPage === 'issues' ? 'active' : '' ?>">
                    <i class="fas fa-bullhorn"></i> Issues & Projects
                </a>
                <a href="<?= get_site_url('elections') ?>" class="nav-link <?= ($currentPage === 'elections' || $currentPage === 'mp' || $currentPage === 'mlc') ? 'active' : '' ?>">
                    <i class="fas fa-vote-yea"></i> Elections
                </a>
                <a href="<?= get_site_url('parties') ?>" class="nav-link <?= ($currentPage === 'parties' || $currentPage === 'political-party') ? 'active' : '' ?>">
                    <i class="fas fa-flag"></i> Parties
                </a>
                <a href="<?= get_site_url('dashboard') ?>" class="nav-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
                    <i class="fas fa-chart-line"></i> Dashboard
                </a>
            </nav>

            <!-- Header Actions -->
            <div class="header-actions">
                <button type="button" class="btn-search-trigger" onclick="openSearchModal()" style="background:none; border:none; cursor:pointer; font-size:1.15rem; color:var(--navy-main); padding:6px;" title="Search">
                    <i class="fas fa-search"></i>
                </button>
                <a href="<?= get_site_url('studio') ?>" class="btn-studio-cta" title="Create Posters & Slogans in Studio">
                    <i class="fas fa-paint-brush"></i>
                    <span>Studio</span>
                </a>
                <?php if ($headerUser): ?>
                    <div class="user-nav-dropdown" id="headerUserDropdown">
                        <button type="button" class="user-nav-trigger" onclick="toggleHeaderUserMenu(event)" aria-expanded="false" title="Account Menu">
                            <img src="<?= e(get_user_avatar_url($headerUser['profile_photo'] ?? null)) ?>" alt="<?= e($headerUser['name']) ?>" class="user-nav-avatar">
                            <span class="user-nav-name"><?= e($headerUser['name']) ?></span>
                            <i class="fas fa-chevron-down" style="font-size:0.65rem; color:var(--text-muted);"></i>
                        </button>
                        <div class="user-nav-menu" id="headerUserMenu" hidden>
                            <div class="user-nav-menu-header">
                                <div style="font-size:0.86rem; font-weight:800; color:var(--navy-main);"><?= e($headerUser['full_name'] ?: $headerUser['name']) ?></div>
                                <div style="font-size:0.74rem; color:var(--green-main); font-weight:700;">
                                    <?= ($headerUser['account_type'] ?? 'user') === 'politician' ? 'Registered Politician' : 'Verified Citizen' ?>
                                    <?php if (!empty($headerUser['username_handle'])): ?>
                                        • @<?= e($headerUser['username_handle']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <a href="<?= get_site_url('dashboard') ?>"><i class="fas fa-chart-line" style="color:var(--saffron-main); width:16px;"></i> My Dashboard</a>
                            <a href="<?= get_site_url('studio') ?>"><i class="fas fa-paint-brush" style="color:#E0520B; width:16px;"></i> Poster Studio</a>
                            <a href="<?= get_site_url('edit-profile') ?>"><i class="fas fa-user-pen" style="color:var(--navy-accent); width:16px;"></i> Edit Profile &amp; Privacy</a>
                            <a href="<?= e(get_user_profile_url((string)($headerUser['username_handle'] ?? ''), (int)$headerUser['id'])) ?>"><i class="fas fa-id-badge" style="color:var(--green-main); width:16px;"></i> My Public Card</a>
                            <a href="<?= get_site_url('logout') ?>" class="danger-link"><i class="fas fa-right-from-bracket" style="width:16px;"></i> Sign Out</a>
                        </div>
                    </div>
                <?php else: ?>
                    <a href="<?= get_site_url('login') ?>" class="btn-header-login" title="Sign In to view masked mobile &amp; email">
                        <i class="fas fa-right-to-bracket"></i>
                        <span>Login</span>
                    </a>
                    <a href="<?= get_site_url('register') ?>" class="btn-header-register" title="Citizen or Politician Registration">
                        <i class="fas fa-user-plus"></i>
                        <span>Register</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>
