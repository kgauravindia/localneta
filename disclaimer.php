<?php
// disclaimer.php - Official Disclaimer & Non-Government Declaration for Local Neta (localneta.com)
define('APP_INIT', true);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Official Disclaimer & Non-Government Declaration — Local Neta (localneta.com)';
$pageDescription = 'Official disclaimer notice, non-government affiliation declaration, public data sources, and intermediary disclosure for Local Neta (localneta.com), effective from 11 Nov 2026.';
$canonicalUrl = get_site_url('disclaimer');

require_once __DIR__ . '/includes/header.php';
?>

<main class="main-wrapper">

    <!-- Hero Banner -->
    <section class="tiranga-banner" style="flex-direction:column; align-items:flex-start; gap:10px; padding:22px 24px;">
        <div class="legal-hero-badges" style="margin-bottom:2px;">
            <span class="legal-badge-danger">⚠️ Official Non-Affiliation Declaration</span>
            <span class="legal-badge-date">Effective / Updated: 11 Nov 2026</span>
        </div>
        <h1 style="font-size:1.55rem; font-weight:800; color:#FFFFFF; margin:0;">Platform Disclaimer</h1>
        <p style="margin:0; max-width:850px;">
            Transparency, non-governmental declaration, public data attribution, and non-partisan neutrality statement for <strong>Local Neta (localneta.com)</strong> — an initiative of <strong>OfferPlant Technologies Private Limited</strong> — effective from <strong>11 Nov 2026</strong>.
        </p>
    </section>

    <!-- Main Two-Column Legal Layout -->
    <div class="legal-layout-grid">

        <!-- Quick Navigation Sidebar -->
        <aside class="legal-sidebar-card">
            <h2 class="legal-sidebar-title">
                <i class="fas fa-triangle-exclamation" style="color:#DC2626;"></i> Disclaimer Sections
            </h2>
            <ul class="legal-toc-list">
                <li><a href="#section-non-govt" class="legal-toc-danger">1. Non-Government Entity Notice</a></li>
                <li><a href="#section-sources">2. Data Sources &amp; Compilation</a></li>
                <li><a href="#section-neutrality">3. Non-Partisan &amp; No Endorsement</a></li>
                <li><a href="#section-electoral">4. Official Voting Portals</a></li>
                <li><a href="#section-privacy">5. Contact Obfuscation &amp; Privacy</a></li>
                <li><a href="#section-liability">6. Limitation of Liability</a></li>
                <li><a href="#section-contact">7. Corrections &amp; Redressal</a></li>
            </ul>

            <div class="legal-sidebar-note">
                <div class="legal-sidebar-box">
                    <div style="font-weight:800; color:var(--navy-main); margin-bottom:6px;">Official Authorities &amp; Portals:</div>
                    <ul style="list-style:none; display:flex; flex-direction:column; gap:5px;">
                        <li><a href="https://eci.gov.in/" target="_blank" rel="noopener">ECI (eci.gov.in) <i class="fas fa-external-link-alt" style="font-size:0.66rem;"></i></a></li>
                        <li><a href="https://voters.eci.gov.in/" target="_blank" rel="noopener">Voters' Portal <i class="fas fa-external-link-alt" style="font-size:0.66rem;"></i></a></li>
                        <li><a href="https://sansad.in/" target="_blank" rel="noopener">Digital Sansad (sansad.in) <i class="fas fa-external-link-alt" style="font-size:0.66rem;"></i></a></li>
                        <li><a href="https://lgdirectory.gov.in/" target="_blank" rel="noopener">LGD (lgdirectory.gov.in) <i class="fas fa-external-link-alt" style="font-size:0.66rem;"></i></a></li>
                    </ul>
                    <div style="margin-top:8px; font-size:0.76rem; color:var(--text-muted);">
                        See also <a href="<?= get_site_url('source') ?>">All Public Data Sources</a> &bull; <a href="<?= get_site_url('privacy-policy') ?>">Privacy Policy</a>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Detailed Disclaimer Content -->
        <article class="legal-content-card">

            <!-- Section 1: Non-Govt Entity -->
            <section class="legal-clause" id="section-non-govt">
                <div class="legal-clause-header">
                    <span class="legal-pill legal-pill-danger">Notice 1</span>
                    <h2 class="legal-clause-title danger">1. Non-Government Entity Declaration</h2>
                </div>
                <div class="legal-alert-danger">
                    <div style="font-weight:800; margin-bottom:6px;">
                        <i class="fas fa-triangle-exclamation"></i> EXPLICIT NON-AFFILIATION NOTICE:
                    </div>
                    <strong>Local Neta (localneta.com)</strong> — an initiative of <strong>OfferPlant Technologies Private Limited</strong> — is an independent, non-governmental civic intelligence, public directory, and representative information platform (effective from <strong>11 Nov 2026</strong>). We are <strong>NOT</strong> an official government website, agency, statutory authority, or affiliate of the <strong>Election Commission of India (ECI)</strong>, any <strong>State Election Commission (SEC)</strong>, the <strong>Parliament of India (Sansad)</strong>, any <strong>State Legislature</strong>, or the <strong>Government of India / any State Government</strong>.
                </div>
                <p class="legal-prose">
                    The use of civic terms such as "Lok Sabha", "Rajya Sabha", "Vidhan Sabha", "Vidhan Parishad", "Mukhiya", "Sarpanch", "Jila Parishad", "Block Pramukh", "Municipal Ward", or Indian administrative territorial names is strictly for educational, journalistic, and non-partisan public awareness purposes.
                </p>
            </section>

            <!-- Section 2: Data Sources -->
            <section class="legal-clause" id="section-sources">
                <div class="legal-clause-header">
                    <span class="legal-pill">Notice 2</span>
                    <h2 class="legal-clause-title">2. Data Sources &amp; Compilation Methodology</h2>
                </div>
                <p class="legal-prose">
                    The parliamentary member rosters, political party registrations and symbols, state legislative records, Pan-India administrative area mappings, and civic directories published on <strong>Local Neta (localneta.com)</strong> are compiled from publicly available official and community sources, including:
                </p>
                <ul class="legal-list" style="margin-bottom:10px;">
                    <li><strong>Parliament of India (<a href="https://sansad.in/" target="_blank" rel="noopener">sansad.in</a>):</strong> Public Lok Sabha &amp; Rajya Sabha Member of Parliament profiles and terms.</li>
                    <li><strong>Election Commission of India (<a href="https://eci.gov.in/" target="_blank" rel="noopener">eci.gov.in</a>):</strong> National, State &amp; Registered Political Party notifications, reserved symbols, and certified Form 26 affidavits.</li>
                    <li><strong>National e-Vidhan (<a href="https://neva.gov.in/" target="_blank" rel="noopener">neva.gov.in</a>) &amp; State Legislatures:</strong> Assembly (MLA) and Council (MLC) representative disclosures.</li>
                    <li><strong>Local Government Directory (<a href="https://lgdirectory.gov.in/" target="_blank" rel="noopener">lgdirectory.gov.in</a>) &amp; State Election Commissions:</strong> State, District, Block, and Gram Panchayat boundaries and local body rosters.</li>
                    <li><strong>Voluntary Citizen &amp; Representative Submissions:</strong> Ratings, issue reports, development project notes, and Q&amp;A posted by users under Section 79 of the Information Technology Act.</li>
                </ul>
                <p class="legal-prose">
                    While substantial care is taken to maintain accuracy and regular updates across <a href="<?= get_site_url('source') ?>">documented public data sources</a>, <strong>localneta.com</strong> does not warrant that every record is completely exhaustive, real-time synchronized, or error-free.
                </p>
            </section>

            <!-- Section 3: Non-Partisan -->
            <section class="legal-clause" id="section-neutrality">
                <div class="legal-clause-header">
                    <span class="legal-pill">Notice 3</span>
                    <h2 class="legal-clause-title">3. Non-Partisan &amp; No Political Endorsement</h2>
                </div>
                <p class="legal-prose">
                    <strong>Local Neta</strong> operates with strict civic neutrality. The presentation of representative profiles, all ECI-registered National, State, and unrecognised political parties, electoral symbols, constituencies, and public development work logs is purely objective and non-partisan.
                </p>
                <p class="legal-prose">
                    Citizen ratings, poll responses, issue reports, or any commercial advertisement or sponsorship slot displayed on platform pages reflect voluntary third-party submissions and do not imply any recommendation, sponsorship, or political endorsement by <strong>Local Neta (localneta.com)</strong>.
                </p>
            </section>

            <!-- Section 4: Official Voting Portals -->
            <section class="legal-clause" id="section-electoral">
                <div class="legal-clause-header">
                    <span class="legal-pill">Notice 4</span>
                    <h2 class="legal-clause-title">4. Official Voting Portals &amp; Voter Services</h2>
                </div>
                <p class="legal-prose">
                    This website does <strong>NOT</strong> conduct statutory voting, enroll voters on official electoral rolls, issue Voter ID Cards (EPIC), or accept official nomination filings. For official government and election services, citizens must exclusively use authorized government portals:
                </p>
                <div class="legal-portal-grid">
                    <div class="legal-portal-card">
                        <div style="font-weight:800; color:var(--navy-main);">Voters' Services Portal (ECI)</div>
                        <p style="font-size:0.84rem; color:var(--text-secondary); margin:0; line-height:1.5;">
                            For Form 6 (New Voter), Form 7, Form 8, EPIC download, and electoral roll verification across India.
                        </p>
                        <a href="https://voters.eci.gov.in/" target="_blank" rel="noopener" class="btn-profile-view" style="flex:unset; margin-top:auto;">
                            Visit voters.eci.gov.in &rarr;
                        </a>
                    </div>
                    <div class="legal-portal-card">
                        <div style="font-weight:800; color:var(--navy-main);">ECI &amp; Panchayati Raj Portals</div>
                        <p style="font-size:0.84rem; color:var(--text-secondary); margin:0; line-height:1.5;">
                            For official Parliament, State Assembly, LGD boundary codes, and local body election notifications.
                        </p>
                        <a href="https://eci.gov.in/" target="_blank" rel="noopener" class="btn-profile-view" style="flex:unset; margin-top:auto;">
                            Visit eci.gov.in &rarr;
                        </a>
                    </div>
                </div>
            </section>

            <!-- Section 5: Privacy & Obfuscation -->
            <section class="legal-clause" id="section-privacy">
                <div class="legal-clause-header">
                    <span class="legal-pill">Notice 5</span>
                    <h2 class="legal-clause-title">5. Contact Obfuscation &amp; Data Protection</h2>
                </div>
                <p class="legal-prose">
                    In accordance with data privacy norms, our <a href="<?= get_site_url('privacy-policy') ?>">Privacy Policy</a>, and the Information Technology Act, 2000, personal mobile/telephone numbers and email IDs across public directory and admin views are obfuscated and masked (e.g., <code>+91 98XXX XX123</code>, <code>re******@example.com</code>) to protect representatives and citizens against automated telemarketing, bulk spam, email harvesting, and phishing. Elected representatives wishing to update or redact contact records may write to our support desk.
                </p>
            </section>

            <!-- Section 6: Limitation of Liability -->
            <section class="legal-clause" id="section-liability">
                <div class="legal-clause-header">
                    <span class="legal-pill">Notice 6</span>
                    <h2 class="legal-clause-title">6. Limitation of Liability</h2>
                </div>
                <p class="legal-prose">
                    Users rely on the information published on <strong>Local Neta (localneta.com)</strong> at their own discretion and risk. Under no circumstances shall Local Neta, its maintainers, developers, contributors, or content creators be held liable for any direct, indirect, special, incidental, or consequential damages resulting from the use of, or inability to use, the information provided on this website.
                </p>
            </section>

            <!-- Section 7: Corrections & Redressal -->
            <section class="legal-clause" id="section-contact">
                <div class="legal-clause-header">
                    <span class="legal-pill">Notice 7</span>
                    <h2 class="legal-clause-title">7. Data Correction, Feedback &amp; Grievances</h2>
                </div>
                <p class="legal-prose">
                    We welcome factual corrections, Gazette/notification references, and citizen feedback. If you spot any discrepancy in representative listings, misspelled names, party symbols, or outdated records, please contact us:
                </p>
                <div class="legal-contact-box">
                    <div style="font-weight:800; color:var(--navy-main);">Data Verification Desk — Local Neta (localneta.com)</div>
                    <div>An initiative of <strong>OfferPlant Technologies Private Limited</strong></div>
                    <div>Effective / Updated: <strong>11 Nov 2026</strong></div>
                    <div>Email: <a href="mailto:contact@localneta.com">contact@localneta.com</a></div>
                    <div>Website: <a href="<?= get_site_url('index') ?>">https://localneta.com</a></div>
                    <div>Jurisdiction: Saran / Patna, Bihar, India</div>
                </div>
            </section>

        </article>
    </div>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

