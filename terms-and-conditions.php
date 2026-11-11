<?php
// terms-and-conditions.php - Terms & Conditions for Local Neta (localneta.com)
define('APP_INIT', true);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Terms & Conditions — Local Neta (localneta.com)';
$pageDescription = 'Read the official Terms & Conditions, Non-Government Disclaimer, and usage policies governing Local Neta (localneta.com), effective from 11 Nov 2026.';
$canonicalUrl = get_site_url('terms-and-conditions');

require_once __DIR__ . '/includes/header.php';
?>

<main class="main-wrapper">

    <!-- Hero Banner -->
    <section class="tiranga-banner" style="flex-direction:column; align-items:flex-start; gap:10px; padding:22px 24px;">
        <div class="legal-hero-badges" style="margin-bottom:2px;">
            <span class="legal-badge-glass">📜 Legal Agreement &amp; Policy</span>
            <span class="legal-badge-date">Last Updated &amp; Effective from: 11 Nov 2026</span>
        </div>
        <h1 style="font-size:1.55rem; font-weight:800; color:#FFFFFF; margin:0;">Terms &amp; Conditions</h1>
        <p style="margin:0; max-width:850px;">
            Please read these Terms &amp; Conditions carefully before browsing or utilizing the directories, public rosters, issue trackers, and tools offered by <strong>Local Neta (localneta.com)</strong> — an initiative of <strong>OfferPlant Technologies Private Limited</strong> — effective from <strong>11 Nov 2026</strong>.
        </p>
    </section>

    <!-- Main Two-Column Legal Layout -->
    <div class="legal-layout-grid">

        <!-- Quick Navigation Index -->
        <aside class="legal-sidebar-card">
            <h2 class="legal-sidebar-title">
                <i class="fas fa-list-ol" style="color:var(--saffron-main);"></i> Document Navigation
            </h2>
            <ul class="legal-toc-list">
                <li><a href="#section-acceptance">1. Acceptance of Terms</a></li>
                <li><a href="#section-disclaimer" class="legal-toc-danger">2. Non-Government Disclaimer</a></li>
                <li><a href="#section-sources">3. Data Accuracy &amp; Sources</a></li>
                <li><a href="#section-use">4. Permitted Use &amp; IP</a></li>
                <li><a href="#section-accounts">5. User Accounts &amp; OTP Auth</a></li>
                <li><a href="#section-candidates">6. Representative Profiles &amp; Listings</a></li>
                <li><a href="#section-advertising">7. Advertising &amp; Sponsorships</a></li>
                <li><a href="#section-privacy">8. Privacy &amp; Number Masking</a></li>
                <li><a href="#section-liability">9. Limitation of Liability</a></li>
                <li><a href="#section-jurisdiction">10. Governing Law &amp; Jurisdiction</a></li>
                <li><a href="#section-contact">11. Grievance &amp; Contact Info</a></li>
            </ul>

            <div class="legal-sidebar-note">
                <div class="legal-sidebar-box">
                    <div style="font-weight:800; color:var(--navy-main); margin-bottom:4px;">Need help or clarification?</div>
                    <div>
                        Contact our legal desk at <a href="mailto:contact@localneta.com">contact@localneta.com</a>.
                    </div>
                    <div style="margin-top:8px; font-size:0.76rem; color:var(--text-muted);">
                        See also our <a href="<?= get_site_url('privacy-policy') ?>">Privacy Policy</a> and <a href="<?= get_site_url('disclaimer') ?>">Disclaimer Notice</a>.
                    </div>
                </div>
            </div>
        </aside>

        <!-- Detailed Policy Terms -->
        <article class="legal-content-card">

            <!-- Section 1: Acceptance -->
            <section class="legal-clause" id="section-acceptance">
                <div class="legal-clause-header">
                    <span class="legal-pill">Clause 1</span>
                    <h2 class="legal-clause-title">1. Acceptance of Terms</h2>
                </div>
                <p class="legal-prose">
                    By accessing, browsing, or utilizing the web platform <strong>Local Neta (localneta.com)</strong>, an initiative of <strong>OfferPlant Technologies Private Limited</strong> (hereinafter referred to as the "Platform", "Website", "We", "Us", or "Our"), effective from <strong>11 Nov 2026</strong>, you acknowledge that you have read, understood, and unequivocally agree to be bound by these Terms &amp; Conditions ("Terms"), our <a href="<?= get_site_url('privacy-policy') ?>">Privacy Policy</a>, and our <a href="<?= get_site_url('disclaimer') ?>">Disclaimer Notice</a>. If you do not agree with any part of these Terms, you must immediately cease accessing or utilizing this Website.
                </p>
            </section>

            <!-- Section 2: Non-Government Disclaimer -->
            <section class="legal-clause" id="section-disclaimer">
                <div class="legal-clause-header">
                    <span class="legal-pill legal-pill-danger">Clause 2</span>
                    <h2 class="legal-clause-title danger">2. Non-Government Entity Disclaimer (Critical Notice)</h2>
                </div>
                <div class="legal-alert-danger">
                    <strong>IMPORTANT NOTICE:</strong> <strong>Local Neta (localneta.com)</strong> — an initiative of <strong>OfferPlant Technologies Private Limited</strong> — is an autonomous, private, and independent Pan-India civic intelligence and representative directory platform. We are <strong>NOT</strong> associated, affiliated, endorsed by, or connected in any capacity with the Election Commission of India (ECI), any State Election Commission (SEC), Parliament of India (Sansad), or the Government of India / any State Government.
                </div>
                <p class="legal-prose">
                    All official voting procedures, formal candidate nominations, voter registration (Form 6/7/8), EVM/VVPAT management, and statutory election notifications remain under the exclusive constitutional jurisdiction of the Election Commission of India (<a href="https://eci.gov.in/" target="_blank" rel="noopener">eci.gov.in</a>) and the respective State Election Commissions.
                </p>
            </section>

            <!-- Section 3: Data Accuracy -->
            <section class="legal-clause" id="section-sources">
                <div class="legal-clause-header">
                    <span class="legal-pill">Clause 3</span>
                    <h2 class="legal-clause-title">3. Data Accuracy, Compilation &amp; Sources</h2>
                </div>
                <p class="legal-prose">
                    Parliamentary profiles (Lok Sabha &amp; Rajya Sabha via <code>sansad.in</code>), Political Party registrations and symbols (<code>eci.gov.in</code>), State Legislature records (MLAs &amp; MLCs via <code>neva.gov.in</code>), administrative area directories (<code>lgdirectory.gov.in</code>), and representative rosters displayed across Local Neta are aggregated from publicly available government portals, gazette notifications, statistical releases, and certified candidate affidavits submitted under the <strong>Representation of the People Act, 1951</strong>.
                </p>
                <p class="legal-prose">
                    While we implement structured verification methodologies, we make no express or implied representations regarding absolute completeness, error-free status, or real-time synchronization of all records. Users are advised to cross-verify vital legal or statutory matters with official government gazettes.
                </p>
            </section>

            <!-- Section 4: Permitted Use & Intellectual Property -->
            <section class="legal-clause" id="section-use">
                <div class="legal-clause-header">
                    <span class="legal-pill">Clause 4</span>
                    <h2 class="legal-clause-title">4. Permitted Use &amp; Intellectual Property Rights</h2>
                </div>
                <p class="legal-prose">
                    All platform organization, curated database schemas, proprietary code, visual interfaces, Poster Studio templates, stylesheets, and custom assets created for <strong>Local Neta (localneta.com)</strong> are the intellectual property of <strong>OfferPlant Technologies Private Limited</strong>.
                </p>
                <ul class="legal-list">
                    <li><strong>Permitted Use:</strong> You are granted a limited, revocable, non-exclusive license to access, view, and cite public civic information for academic research, journalistic reporting, civic education, and personal reference, provided proper attribution to <code>localneta.com</code> is included.</li>
                    <li><strong>Prohibited Actions:</strong> Bulk scraping without written consent, automated harvesting of masked or published contact details, reverse engineering, unauthorized frame-embedding, and commercial redistribution of compiled dataset dumps are strictly prohibited.</li>
                </ul>
            </section>

            <!-- Section 5: User Accounts & OTP Auth -->
            <section class="legal-clause" id="section-accounts">
                <div class="legal-clause-header">
                    <span class="legal-pill">Clause 5</span>
                    <h2 class="legal-clause-title">5. User Registration, Submissions &amp; OTP Authentication</h2>
                </div>
                <p class="legal-prose">
                    Users choosing to register, manage representative dashboards, report civic issues, submit ratings/Q&amp;A, or post community updates agree to:
                </p>
                <ul class="legal-list">
                    <li>Provide accurate, current, and verifiable contact details (such as a valid Indian 10-digit mobile number where required).</li>
                    <li>Complete authentication via TRAI DLT-approved One Time Password (OTP) or authorized login credentials where enabled.</li>
                    <li>Maintain strict confidentiality over account credentials and notify us promptly of any unauthorized access.</li>
                    <li>Refrain from impersonating any citizen, elected representative, political functionary, or election officer.</li>
                </ul>
            </section>

            <!-- Section 6: Candidate Profiles & Listings -->
            <section class="legal-clause" id="section-candidates">
                <div class="legal-clause-header">
                    <span class="legal-pill">Clause 6</span>
                    <h2 class="legal-clause-title">6. Representative Profiles, Citizen Ratings &amp; Submissions</h2>
                </div>
                <p class="legal-prose">
                    Elected representatives, prospective candidates, party workers, and citizens who submit biographies, development work updates, local issues, questions, or community feedback acknowledge that all submitted content must be truthful, lawful, non-defamatory, and free of unconstitutional or inciteful statements.
                </p>
                <p class="legal-prose">
                    In accordance with Intermediary Guidelines under <strong>Section 79 of the Information Technology Act, 2000</strong> and the Model Code of Conduct (MCC), <strong>Local Neta</strong> reserves the right to review, moderate, edit, redact, or remove any submission that violates applicable law or platform standards.
                </p>
            </section>

            <!-- Section 7: Advertising & Sponsorships -->
            <section class="legal-clause" id="section-advertising">
                <div class="legal-clause-header">
                    <span class="legal-pill">Clause 7</span>
                    <h2 class="legal-clause-title">7. Commercial Advertisements &amp; Page Sponsorships</h2>
                </div>
                <p class="legal-prose">
                    Any local business listings, featured civic placements, or commercial banner sponsorships hosted on <strong>localneta.com</strong> are governed by our advertising standards:
                </p>
                <ul class="legal-list">
                    <li>All advertisements must comply with the Advertising Standards Council of India (ASCI) guidelines and applicable Indian laws.</li>
                    <li>Advertisements promoting hate speech, illegal substances, unlawful gambling, misleading electoral claims, or discriminatory matter are strictly prohibited.</li>
                    <li>Display of commercial advertising or sponsorship does not constitute any endorsement by <strong>Local Neta</strong> of the advertised service, business, or political entity.</li>
                </ul>
            </section>

            <!-- Section 8: Privacy & Number Masking -->
            <section class="legal-clause" id="section-privacy">
                <div class="legal-clause-header">
                    <span class="legal-pill">Clause 8</span>
                    <h2 class="legal-clause-title">8. Privacy &amp; Data Masking Policy</h2>
                </div>
                <p class="legal-prose">
                    In conformity with Indian data protection principles, the Information Technology Act, 2000, and our <a href="<?= get_site_url('privacy-policy') ?>">Privacy Policy</a>, personal mobile/telephone numbers and email IDs across representative listings are obfuscated and masked (e.g., <code>+91 98XXX XX123</code>, <code>re******@example.com</code>) to safeguard individuals against automated telemarketing, email harvesting, and phishing spam.
                </p>
            </section>

            <!-- Section 9: Limitation of Liability -->
            <section class="legal-clause" id="section-liability">
                <div class="legal-clause-header">
                    <span class="legal-pill">Clause 9</span>
                    <h2 class="legal-clause-title">9. Limitation of Liability &amp; Disclaimer of Warranties</h2>
                </div>
                <p class="legal-prose">
                    The Website and all contained materials, civic tools, and Poster Studio outputs are provided on an "AS IS" and "AS AVAILABLE" basis without warranties of any kind. Under no circumstances shall <strong>Local Neta (localneta.com)</strong>, its operators, creators, or contributors be liable for any direct, indirect, incidental, consequential, special, or punitive damages arising from the use of, or inability to use, this Website or reliance placed upon data contained herein.
                </p>
            </section>

            <!-- Section 10: Governing Law & Jurisdiction -->
            <section class="legal-clause" id="section-jurisdiction">
                <div class="legal-clause-header">
                    <span class="legal-pill">Clause 10</span>
                    <h2 class="legal-clause-title">10. Governing Law &amp; Dispute Resolution</h2>
                </div>
                <p class="legal-prose">
                    These Terms &amp; Conditions shall be governed by, construed, and enforced in accordance with the laws of the Republic of India. Any disputes, actions, claims, or controversies arising out of or relating to <strong>Local Neta (localneta.com)</strong> shall be subject to the exclusive jurisdiction of the competent civil courts situated in <strong>Patna / Saran, Bihar, India</strong>.
                </p>
            </section>

            <!-- Section 11: Grievance & Contact -->
            <section class="legal-clause" id="section-contact">
                <div class="legal-clause-header">
                    <span class="legal-pill">Clause 11</span>
                    <h2 class="legal-clause-title">11. Grievance Redressal &amp; Contact Information</h2>
                </div>
                <p class="legal-prose">
                    If you have questions, feedback, takedown requests, copyright concerns, or data correction notices regarding these Terms &amp; Conditions, please contact our Grievance Officer:
                </p>
                <div class="legal-contact-box">
                    <div style="font-weight:800; color:var(--navy-main);">Grievance &amp; Legal Officer</div>
                    <div><strong>Local Neta (localneta.com)</strong> — An initiative of <strong>OfferPlant Technologies Private Limited</strong></div>
                    <div>Effective from: <strong>11 Nov 2026</strong></div>
                    <div>Website: <a href="<?= get_site_url('index') ?>">https://localneta.com</a></div>
                    <div>Email: <a href="mailto:contact@localneta.com">contact@localneta.com</a></div>
                    <div>Location: Patna / Saran, Bihar, India</div>
                </div>
            </section>

        </article>
    </div>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

