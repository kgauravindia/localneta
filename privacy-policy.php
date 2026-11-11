<?php
// privacy-policy.php - Privacy Policy for Local Neta (localneta.com)
define('APP_INIT', true);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Privacy Policy — Local Neta (localneta.com)';
$pageDescription = 'Official Privacy Policy of Local Neta (localneta.com), effective from 11 Nov 2026. Learn how we protect citizen data, mask public contact numbers and email IDs, and comply with India DPDP Act 2023 and IT Act 2000.';
$canonicalUrl = get_site_url('privacy-policy');

require_once __DIR__ . '/includes/header.php';
?>

<main class="main-wrapper">

    <!-- Hero Banner -->
    <section class="tiranga-banner" style="flex-direction:column; align-items:flex-start; gap:10px; padding:22px 24px;">
        <div class="legal-hero-badges" style="margin-bottom:2px;">
            <span class="legal-badge-glass">🔒 Data Protection &amp; Privacy</span>
            <span class="legal-badge-date">Effective from: 11 Nov 2026</span>
            <span class="legal-badge-glass">Compliance: DPDP Act 2023 &amp; IT Act 2000</span>
        </div>
        <h1 style="font-size:1.55rem; font-weight:800; color:#FFFFFF; margin:0;">Privacy Policy</h1>
        <p style="margin:0; max-width:850px;">
            How <strong>Local Neta (localneta.com)</strong> — an initiative of <strong>OfferPlant Technologies Private Limited</strong> — collects, protects, masks, and manages citizen and representative data across our platform (effective from <strong>11 Nov 2026</strong>).
        </p>
    </section>

    <!-- Main Two-Column Legal Layout -->
    <div class="legal-layout-grid">

        <!-- Quick Navigation Index -->
        <aside class="legal-sidebar-card">
            <h2 class="legal-sidebar-title">
                <i class="fas fa-shield-halved" style="color:var(--saffron-main);"></i> Privacy Clauses
            </h2>
            <ul class="legal-toc-list">
                <li><a href="#section-overview">1. Overview &amp; Scope</a></li>
                <li><a href="#section-collect">2. Information We Collect</a></li>
                <li><a href="#section-masking" class="legal-toc-danger">3. Contact Masking Policy</a></li>
                <li><a href="#section-usage">4. How We Use Information</a></li>
                <li><a href="#section-cookies">5. Cookies &amp; Google AdSense</a></li>
                <li><a href="#section-sharing">6. Data Sharing &amp; Third Parties</a></li>
                <li><a href="#section-security">7. Data Security &amp; Storage</a></li>
                <li><a href="#section-rights">8. User Data Rights</a></li>
                <li><a href="#section-officer">9. Grievance Officer</a></li>
            </ul>

            <div class="legal-sidebar-note">
                <div class="legal-sidebar-box">
                    <div style="font-weight:800; color:var(--navy-main); margin-bottom:4px;">Privacy or Data Inquiries?</div>
                    <div>
                        Reach out to the <strong>Local Neta (localneta.com)</strong> Data Protection Desk at
                        <a href="mailto:contact@localneta.com">contact@localneta.com</a>.
                    </div>
                    <div style="margin-top:8px; font-size:0.76rem; color:var(--text-muted);">
                        Also review our <a href="<?= get_site_url('disclaimer') ?>">Disclaimer Notice</a> and <a href="<?= get_site_url('terms-and-conditions') ?>">Terms &amp; Conditions</a>.
                    </div>
                </div>
            </div>
        </aside>

        <!-- Detailed Privacy Clauses -->
        <article class="legal-content-card">

            <!-- Section 1: Overview -->
            <section class="legal-clause" id="section-overview">
                <div class="legal-clause-header">
                    <span class="legal-pill">Clause 1</span>
                    <h2 class="legal-clause-title">1. Overview &amp; Scope</h2>
                </div>
                <p class="legal-prose">
                    This Privacy Policy, effective from <strong>11 Nov 2026</strong>, explains how <strong>Local Neta (localneta.com)</strong>, an initiative of <strong>OfferPlant Technologies Private Limited</strong>, handles personal and non-personal data when you access our Pan-India civic intelligence and representative directory portal. We are committed to safeguarding your privacy in strict accordance with the <strong>Digital Personal Data Protection (DPDP) Act, 2023</strong> and the <strong>Information Technology (Reasonable Security Practices and Procedures and Sensitive Personal Data or Information) Rules, 2011</strong>.
                </p>
            </section>

            <!-- Section 2: Information We Collect -->
            <section class="legal-clause" id="section-collect">
                <div class="legal-clause-header">
                    <span class="legal-pill">Clause 2</span>
                    <h2 class="legal-clause-title">2. Information We Collect</h2>
                </div>
                <p class="legal-prose">We collect and process information in the following categories:</p>
                <ul class="legal-list">
                    <li><strong>Public Domain Electoral &amp; Civic Records:</strong> Official candidate affidavits, Lok Sabha &amp; Rajya Sabha MP rosters (<code>sansad.in</code>), State Legislature records (MLAs &amp; MLCs via <code>neva.gov.in</code>), Election Commission of India registered Political Party lists (<code>eci.gov.in</code>), Local Government Directory (LGD) State/District/Block/Panchayat hierarchies, and public gazette representative records (Mukhiya, Sarpanch, Jila Parishad, Block Pramukh, Municipal &amp; Urban representatives).</li>
                    <li><strong>User Provided Information:</strong> When you create a representative/citizen profile, submit a local development issue, rate performance, ask a civic question, vote in community polls, or opt into civic alerts on Local Neta, we may collect your name, mobile number, email address (optional), constituency/area details, and submitted feedback.</li>
                    <li><strong>Automated Technical Data:</strong> Browser user-agent, operating system, approximate geographic location (city/region level), referral URLs, and access timestamps to ensure system security, prevent abuse, and optimize mobile layout delivery.</li>
                </ul>
            </section>

            <!-- Section 3: Contact Masking Policy -->
            <section class="legal-clause" id="section-masking">
                <div class="legal-clause-header">
                    <span class="legal-pill legal-pill-danger">Clause 3</span>
                    <h2 class="legal-clause-title danger">3. Public Directory Contact Masking (Phone &amp; Email) Policy</h2>
                </div>
                <div class="legal-alert-danger">
                    <strong>ANTI-HARASSMENT DIRECTIVE:</strong> To protect elected representatives, community leaders, and local officials from bulk automated telemarketing, SMS/WhatsApp spam bots, email harvesting, and malicious scraping, all mobile/telephone numbers and email IDs displayed across Local Neta pages are automatically obfuscated and masked (e.g., <code>+91 98XXX XX123</code>, <code>re******@example.com</code>).
                </div>
                <p class="legal-prose">
                    Automated harvesting, bulk extraction, or de-anonymization of masked or published contact details from <strong>localneta.com</strong> is strictly prohibited and constitutes a direct breach of our Terms &amp; Conditions.
                </p>
            </section>

            <!-- Section 4: How We Use Information -->
            <section class="legal-clause" id="section-usage">
                <div class="legal-clause-header">
                    <span class="legal-pill">Clause 4</span>
                    <h2 class="legal-clause-title">4. How We Use Your Information</h2>
                </div>
                <p class="legal-prose">We use collected data solely for lawful, legitimate civic and technical purposes:</p>
                <ul class="legal-list">
                    <li>To authenticate registered users and process verification or account recovery requests (including TRAI DLT-approved transactional SMS OTPs where enabled).</li>
                    <li>To verify representative profile submissions, issue reports, development project updates, and public Q&amp;A contributions before display.</li>
                    <li>To render digital posters, civic slogans, and digital visiting cards requested by users inside Poster Studio.</li>
                    <li>To prevent fraudulent activity, mitigate automated abuse or DDoS attacks, and protect platform integrity.</li>
                </ul>
            </section>

            <!-- Section 5: Cookies & Google AdSense -->
            <section class="legal-clause" id="section-cookies">
                <div class="legal-clause-header">
                    <span class="legal-pill">Clause 5</span>
                    <h2 class="legal-clause-title">5. Cookies, Analytics &amp; Google AdSense</h2>
                </div>
                <p class="legal-prose">
                    <strong>Local Neta (localneta.com)</strong> may utilize essential browser cookies or session tokens to support interactive session features, security controls, and user preferences.
                </p>
                <p class="legal-prose">
                    Where advertising services such as <strong>Google AdSense</strong> are enabled, third-party vendors (including Google) may use cookies (such as the DoubleClick cookie) to serve ads based on a user's prior visits to this website or other websites. Users may customize or opt out of personalized advertising by visiting
                    <a href="https://www.google.com/settings/ads" target="_blank" rel="noopener">Google Ads Settings</a> or
                    <a href="https://www.aboutads.info" target="_blank" rel="noopener">aboutads.info</a>.
                </p>
            </section>

            <!-- Section 6: Data Sharing & Third Parties -->
            <section class="legal-clause" id="section-sharing">
                <div class="legal-clause-header">
                    <span class="legal-pill">Clause 6</span>
                    <h2 class="legal-clause-title">6. Data Sharing &amp; Third-Party Processors</h2>
                </div>
                <p class="legal-prose">
                    <strong>We do not sell, rent, or trade your personal information</strong> to any third-party political consulting agencies, data brokers, or commercial marketing enterprises.
                </p>
                <p class="legal-prose">
                    We share operational data only with essential infrastructure service providers (such as authorized TRAI DLT SMS gateway providers for OTP transmission and encrypted database hosting providers) bound by strict confidentiality and data security obligations.
                </p>
            </section>

            <!-- Section 7: Data Security & Storage -->
            <section class="legal-clause" id="section-security">
                <div class="legal-clause-header">
                    <span class="legal-pill">Clause 7</span>
                    <h2 class="legal-clause-title">7. Data Security &amp; Storage</h2>
                </div>
                <p class="legal-prose">
                    All user account credentials are protected using cryptographic password hashing algorithms (Bcrypt/Argon2 with individual salts). We implement SSL/TLS encryption for data in transit, strict prepared-statement database queries to prevent SQL injection, and access-controlled server infrastructure.
                </p>
            </section>

            <!-- Section 8: User Data Rights -->
            <section class="legal-clause" id="section-rights">
                <div class="legal-clause-header">
                    <span class="legal-pill">Clause 8</span>
                    <h2 class="legal-clause-title">8. User Data Rights (DPDP Act 2023)</h2>
                </div>
                <p class="legal-prose">In accordance with applicable Indian data privacy laws, you possess the right to:</p>
                <ul class="legal-list">
                    <li><strong>Access &amp; Review:</strong> Review personal information associated with your submitted profile or account.</li>
                    <li><strong>Rectification:</strong> Request correction or update of inaccurate or outdated profile or directory details.</li>
                    <li><strong>Erasure / Account Deletion:</strong> Request permanent removal of your voluntary account details and registered contact information from our active systems.</li>
                </ul>
            </section>

            <!-- Section 9: Grievance Redressal -->
            <section class="legal-clause" id="section-officer">
                <div class="legal-clause-header">
                    <span class="legal-pill">Clause 9</span>
                    <h2 class="legal-clause-title">9. Grievance Officer &amp; Contact Information</h2>
                </div>
                <p class="legal-prose">
                    For any privacy inquiries, data deletion requests, or grievances regarding compliance with the Information Technology Act, 2000 and the DPDP Act, 2023, please contact our designated Grievance Officer:
                </p>
                <div class="legal-contact-box">
                    <div style="font-weight:800; color:var(--navy-main);">Data Protection &amp; Grievance Officer</div>
                    <div><strong>Local Neta (localneta.com)</strong> — An initiative of <strong>OfferPlant Technologies Private Limited</strong></div>
                    <div>Effective Date: <strong>11 Nov 2026</strong></div>
                    <div>Email: <a href="mailto:contact@localneta.com">contact@localneta.com</a></div>
                    <div>Website: <a href="<?= get_site_url('index') ?>">https://localneta.com</a></div>
                    <div>Jurisdiction: Saran / Patna, Bihar, India</div>
                </div>
            </section>

        </article>
    </div>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
