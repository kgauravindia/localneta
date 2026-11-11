<?php
// includes/footer.php - Global Footer & Navigation for LocalNeta (English UI)
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?: ($_SERVER['PHP_SELF'] ?? 'index.php'), '.php');
?>

    <!-- Mobile Sticky Bottom Navigation (5 Main Pillars) -->
    <nav class="mobile-bottom-nav">
        <a href="<?= get_site_url('index') ?>" class="bnav-item <?= $currentPage === 'index' ? 'active' : '' ?>">
            <i class="fas fa-home"></i>
            <span>Home</span>
        </a>
        <a href="<?= get_site_url('leaders') ?>" class="bnav-item <?= in_array($currentPage, ['leaders', 'profile', 'user-profile'], true) ? 'active' : '' ?>">
            <i class="fas fa-user-tie"></i>
            <span>Leaders</span>
        </a>
        <a href="<?= get_site_url('issues') ?>" class="bnav-item <?= $currentPage === 'issues' ? 'active' : '' ?>">
            <i class="fas fa-bullhorn"></i>
            <span>Issues</span>
        </a>
        <a href="<?= get_site_url('elections') ?>" class="bnav-item <?= in_array($currentPage, ['elections', 'mp', 'mlc'], true) ? 'active' : '' ?>">
            <i class="fas fa-vote-yea"></i>
            <span>Elections</span>
        </a>
        <a href="<?= get_site_url('studio') ?>" class="bnav-item bnav-studio <?= $currentPage === 'studio' ? 'active' : '' ?>">
            <i class="fas fa-paint-brush"></i>
            <span>Studio</span>
        </a>
    </nav>

    <!-- Desktop & Mobile National Footer -->
    <footer class="site-footer">
        <div class="footer-tricolor-bar" aria-hidden="true"></div>

        <div class="footer-container">
            <!-- Col 1: Brand & National Mission -->
            <div class="footer-col">
                <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
                    <img src="<?= get_site_url('assets/images/logo.png') ?>" alt="LocalNeta" style="width:40px; height:40px; border-radius:50%; border:2px solid #FF671F;">
                    <div>
                        <h3 style="margin:0; border:none; color:#FFFFFF; font-size:1.15rem;">LocalNeta.com</h3>
                        <div style="font-size:0.74rem; color:#4ADE80; font-weight:700;">Your Leaders, Your Issues</div>
                    </div>
                </div>
                <p style="color:#CBD5E1; font-size:0.84rem; line-height:1.65;">
                    <strong style="color:#FFFFFF;">Local Neta (localneta.com)</strong> — An initiative of <strong style="color:#FF9933;">OfferPlant</strong>. India's independent national civic information platform connecting citizens from Gram Panchayats and Municipalities to State Legislatures and Parliament.
                </p>
                <div style="margin-top:14px; display:flex; gap:8px; flex-wrap:wrap;">
                    <span class="badge-tag badge-elected"><i class="fas fa-flag"></i> All-India Coverage</span>
                    <span class="badge-tag badge-verified" style="background:rgba(255,255,255,0.1); color:#E2E8F0;"><i class="fas fa-shield-halved"></i> Independent Civic Tech</span>
                </div>
            </div>

            <!-- Col 2: National Directories -->
            <div class="footer-col">
                <h3>National Directories</h3>
                <ul>
                    <li><a href="<?= get_site_url('index') ?>"><i class="fas fa-address-book" style="color:#FF9933; width:14px;"></i> Local Leaders Directory</a></li>
                    <li><a href="<?= get_site_url('leaders') ?>"><i class="fas fa-layer-group" style="color:#FF9933; width:14px;"></i> All 25 Representative Types</a></li>
                    <li><a href="<?= get_site_url('elections#mp-directory') ?>"><i class="fas fa-landmark" style="color:#FF9933; width:14px;"></i> Parliament MPs (Lok Sabha &amp; RS)</a></li>
                    <li><a href="<?= get_site_url('index?cat=mlc') ?>"><i class="fas fa-scroll" style="color:#FF9933; width:14px;"></i> State Legislative Councils (MLCs)</a></li>
                    <li><a href="<?= get_site_url('elections#mlc-directory') ?>"><i class="fas fa-building-columns" style="color:#FF9933; width:14px;"></i> MLC Directory (Bihar, UP &amp; AP)</a></li>
                    <li><a href="<?= get_site_url('pincode') ?>"><i class="fas fa-map-pin" style="color:#FF9933; width:14px;"></i> PIN Code &amp; Village Finder</a></li>
                    <li><a href="<?= get_site_url('parties') ?>"><i class="fas fa-flag" style="color:#FF9933; width:14px;"></i> Political Parties Directory (ECI)</a></li>
                    <li><a href="<?= get_site_url('leaders?cat=political-party-office') ?>"><i class="fas fa-building" style="color:#FF9933; width:14px;"></i> National &amp; State Party Offices</a></li>
                </ul>
            </div>

            <!-- Col 3: Data Sources & Legal Policies -->
            <div class="footer-col">
                <h3>Sources &amp; Legal Policies</h3>
                <ul>
                    <li>
                        <a href="<?= get_site_url('source') ?>" class="footer-link-highlight">
                            <i class="fas fa-database" style="width:14px;"></i> All Public Data Sources
                        </a>
                    </li>
                    <li><a href="<?= get_site_url('privacy-policy') ?>">🔒 Privacy Policy</a></li>
                    <li><a href="<?= get_site_url('disclaimer') ?>">⚠️ Disclaimer Notice</a></li>
                    <li><a href="<?= get_site_url('terms-and-conditions') ?>">📜 Terms &amp; Conditions</a></li>
                    <li><a href="<?= get_site_url('elections#explainers') ?>"><i class="fas fa-book-open" style="color:#FF9933; width:14px;"></i> Voter Rights &amp; RTI Guide</a></li>
                </ul>
            </div>

            <!-- Col 4: Civic Engagement & Digital Studio -->
            <div class="footer-col">
                <h3>Civic Tools &amp; Studio</h3>
                <div class="footer-studio-box">
                    <p style="font-size:0.82rem; color:#CBD5E1; margin-bottom:10px; line-height:1.5;">
                        Create HD civic posters, development updates, Hindi slogans, and QR digital visiting cards in seconds.
                    </p>
                    <a href="<?= get_site_url('studio') ?>" class="btn-primary-tiranga" style="padding:8px 16px; font-size:0.84rem; width:100%;">
                        <i class="fas fa-paint-brush"></i> Open Poster Studio
                    </a>
                </div>
                <ul>
                    <li><a href="<?= get_site_url('issues') ?>"><i class="fas fa-bullhorn" style="color:#FF9933; width:14px;"></i> Report Issues &amp; Vikas Tracker</a></li>
                    <li><a href="<?= get_site_url('dashboard') ?>"><i class="fas fa-sliders" style="color:#FF9933; width:14px;"></i> Leader Portal Dashboard</a></li>
                    <li><a href="<?= get_site_url('login') ?>"><i class="fas fa-right-to-bracket" style="color:#FF9933; width:14px;"></i> Citizen &amp; Leader Sign In</a></li>
                    <li><a href="<?= get_site_url('register') ?>"><i class="fas fa-user-plus" style="color:#FF9933; width:14px;"></i> Free Profile Registration</a></li>
                </ul>
            </div>
        </div>

        <div class="footer-bottom-bar">
            <div class="footer-disclaimer-box">
                <i class="fas fa-circle-info" style="color:#FF9933; margin-right:6px;"></i>
                <strong>Disclaimer:</strong> LocalNeta is an independent civic information platform and is not affiliated with any political party or government entity. Community ratings and survey results reflect voluntary citizen submissions and <a href="<?= get_site_url('source') ?>">various public sources</a>.
                Review our <a href="<?= get_site_url('privacy-policy') ?>">Privacy Policy</a>, <a href="<?= get_site_url('disclaimer') ?>">Disclaimer Notice</a> &amp; <a href="<?= get_site_url('terms-and-conditions') ?>">Terms &amp; Conditions</a>.
            </div>
            <p style="color:#94A3B8; margin:0;">
                © <?= date('Y') ?> <strong style="color:#FFFFFF;">Local Neta (localneta.com)</strong> — An initiative of <strong style="color:#FFFFFF;">OfferPlant</strong>. All rights reserved. |
                Pan-India Civic Directory • Compliant with Intermediary Guidelines under <span style="color:#FF9933;">Section 79 of the Information Technology Act</span>.
            </p>
        </div>
    </footer>

    <!-- Universal Search Modal -->
    <div class="modal-overlay" id="searchModal">
        <div class="modal-dialog">
            <div class="modal-header">
                <div class="modal-title"><i class="fas fa-search" style="color:var(--saffron-main);"></i> Search LocalNeta</div>
                <button type="button" class="modal-close-btn" onclick="closeSearchModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="search-box-wrapper" style="margin-bottom:16px;">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" id="quickSearchInput" placeholder="Representative, MP, Party, PIN code (841301), village..." oninput="handleQuickSearch(this.value)">
                </div>
                <div id="searchResultsBox" style="max-height:300px; overflow-y:auto;">
                    <p style="color:var(--text-muted); font-size:0.85rem; text-align:center; padding:20px;">
                        Type a name, PIN code, or keyword, e.g. "841301", "Rajiv Pratap Rudy", "C.N Gupta", "BJP", "जन सुराज", "Revelganj"...
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Citizen / Politician Login Required Modal (Contact Reveal Gate) -->
    <div class="modal-overlay" id="authRequiredModal">
        <div class="modal-dialog">
            <div class="modal-header">
                <div class="modal-title"><i class="fas fa-shield-halved" style="color:var(--saffron-main);"></i> Sign In to View Contact</div>
                <button type="button" class="modal-close-btn" onclick="closeAuthRequiredModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div style="text-align:center; margin-bottom:16px;">
                    <div style="width:54px; height:54px; border-radius:50%; background:var(--navy-light); color:var(--navy-main); display:inline-flex; align-items:center; justify-content:center; font-size:1.35rem; margin-bottom:10px;">
                        <i class="fas fa-lock"></i>
                    </div>
                    <h3 style="font-size:1.08rem; font-weight:800; color:var(--navy-main); margin-bottom:6px;">
                        Protected Mobile &amp; Email Access
                    </h3>
                    <p id="authRequiredModalDesc" style="font-size:0.87rem; color:var(--text-secondary); line-height:1.55;">
                        To prevent spam and automated harvesting, unmasked mobile numbers and email IDs are available only to signed-in citizens and politicians.
                    </p>
                </div>
                <div style="display:flex; flex-direction:column; gap:10px;">
                    <a href="<?= get_site_url('login') ?>" id="authModalLoginLink" class="btn-primary-tiranga" style="width:100%;">
                        <i class="fas fa-right-to-bracket"></i> Sign In (Password or Mobile OTP)
                    </a>
                    <a href="<?= get_site_url('register') ?>" id="authModalRegisterLink" class="btn-green-tiranga" style="width:100%;">
                        <i class="fas fa-user-plus"></i> Free Citizen / Politician Registration
                    </a>
                </div>
                <p style="font-size:0.76rem; color:var(--text-muted); text-align:center; margin-top:12px;">
                    Quick 30-second mobile verification unlocks up to <?= (int)(defined('USER_DAILY_CONTACT_LIMIT') ? USER_DAILY_CONTACT_LIMIT : 10) ?> full contact cards daily.
                </p>
            </div>
        </div>
    </div>

    <!-- Daily Contact Unlock Limit Modal -->
    <div class="modal-overlay" id="dailyLimitModal">
        <div class="modal-dialog">
            <div class="modal-header">
                <div class="modal-title"><i class="fas fa-gauge-high" style="color:var(--saffron-main);"></i> Daily Unlock Quota Reached</div>
                <button type="button" class="modal-close-btn" onclick="closeDailyLimitModal()">&times;</button>
            </div>
            <div class="modal-body" style="text-align:center;">
                <p id="dailyLimitModalDesc" style="font-size:0.9rem; color:var(--text-secondary); line-height:1.6; margin-bottom:16px;">
                    You have used all <?= (int)(defined('USER_DAILY_CONTACT_LIMIT') ? USER_DAILY_CONTACT_LIMIT : 10) ?> daily contact reveals for today. Your previously unlocked contacts remain saved in your Dashboard.
                </p>
                <a href="<?= get_site_url('dashboard') ?>" class="btn-primary-tiranga" style="width:100%;">
                    <i class="fas fa-chart-line"></i> Open My Unlocked Contacts
                </a>
            </div>
        </div>
    </div>

    <!-- Universal Toast Container -->
    <div class="toast-box" id="globalToast">
        <i class="fas fa-info-circle" style="color:var(--saffron-main);"></i>
        <span id="toastMessage">Notification</span>
    </div>

    <!-- Scripts -->
    <script>
        window.LOCALNETA_BASE_URL = '<?= get_site_url() ?>';
        window.LOCALNETA_LOGGED_IN = <?= is_user_logged_in() ? 'true' : 'false' ?>;
        window.LOCALNETA_CSRF = '<?= e(get_user_csrf_token()) ?>';
    </script>
    <script src="<?= get_site_url('assets/js/app.js') ?>?v=<?= @filemtime(__DIR__ . '/../assets/js/app.js') ?: 1 ?>"></script>
</body>
</html>
