# LocalNeta (LocalNeta.com) 🇮🇳
> **"Your Leaders, Your Issues"**  
> Hyperlocal Civic Accountability & Public Engagement Platform × Leader Digital Identity SaaS.

---

## 🌟 Overview
**LocalNeta** is a mobile-first civic technology platform designed specifically for rural and semi-urban India (initial launch: **Saran District / Chapra, Bihar**). 

Unlike standard election news apps that experience severe traffic drops after elections, LocalNeta operates on a **dual-engine flywheel**:
1. **Public Civic Engine (100% Free for Citizens):** Discover your Mukhiya, Sarpanch, Samiti Member, Zila Parishad Member, MLA, and MP; track local development works (Vikas Tracker); participate in community issue reporting and 5-parameter public ratings.
2. **Leader Micro-SaaS Engine (LocalNeta Studio):** Representatives can generate high-resolution festival greeting posters (Chhath Puja, Diwali, Republic Day), developmental project announcements, Hindi slogans, and printable QR visiting cards in 10 seconds without graphic designers.

---

## 🎨 Design System: Indian Tricolor (Tiranga Palette)
- **India Saffron (केसरिया):** `#FF671F` / `#E65100` — Primary action buttons, badges, top ribbon
- **India White (श्वेत):** `#FFFFFF` / `#F8FAFC` — Clean civic surface cards
- **India Green (हरा):** `#046A38` / `#138808` — Verification badges, success states, WhatsApp triggers
- **Ashoka Chakra Navy Blue (चक्र नीला):** `#0A192F` / `#1E3A8A` — Authoritative headings, headers, cards

---

## 🗄️ Database & Environment Setup
1. Copy `includes/config.sample.php` to `includes/config.local.php` (gitignored).
2. Set your MySQL credentials (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`) and optional SMS OTP settings in `includes/config.local.php`, or provide `LOCALNETA_DB_HOST`, `LOCALNETA_DB_NAME`, `LOCALNETA_DB_USER`, `LOCALNETA_DB_PASS` environment variables.
3. Database charset: `utf8mb4` with `utf8mb4_unicode_ci`.

---

## 🔐 Public Repository Security & Files Excluded from Git
This repository (`https://github.com/kgauravindia/localneta.git`) is public. `.gitignore` strictly excludes:
- **Local Secrets & Config:** `/includes/config.local.php`, `/ppms/includes/config.local.php`, `*.local.php`, `.env`, `.env.*`
- **Database Exports & SQL Files:** `*.sql`, `*.sql.gz`, `*.sql.bz2`, `*.sql.zip`, `*.sqlite*`, `*.db`, `*.dump`, `*.bak`, `/backups/`, `/dumps/`
- **Raw Data & PII Seeds:** `/includes/mlc_seed.json`, `/data/` (`*.xls`, `*.xlsx`, `*.pdf`), and all `*.csv` except `/includes/mp_ls18.csv`
- **User Uploads & Runtime Artifacts:** `/assets/uploads/`, `*.log`, `/logs/`, `/tmp/`, `/cache/`, `/vendor/`, `/node_modules/`, `/verify_*.py`

---

## 🔄 Version Control & PPMS GitHub Updates
- Application version metadata lives in `version.json` (`1.2.0`).
- **Superadmins** can check for incoming commits and pull updates (`git pull --ff-only`) safely from GitHub inside PPMS at **`/ppms/updates`**.

### MySQL Relational Tables:
1. `op_sdb` — Official State-District-Block master directory (36 States/UTs, 783 Districts, 7,145 Blocks) powering dynamic location cascades via `api/locations.php`
2. `mp` — Parliament Members directory (789 verified records sourced from `https://sansad.in/`: 545 18th Lok Sabha `type='ls'`, `election='ls-18'` from `includes/mp_ls18.csv` + 244 Rajya Sabha `type='rs'` from `data/MP_RS_2026.xls` where `election` is derived from current `term_period` start year such as `RS 2024` for `2024-2030` or `NULL` for Nominated members, plus `age`, `photo_url`, `term_period`, `total_terms`, and `op_sdb.state_code` mapping)
3. `mukhiya` — State Election Commission elected Gram Panchayat Mukhiya directory (`8,045` records across 38 Bihar districts with names, father/spouse name, block/panchayat, contact number, age, and qualification)
4. `politician` — State Legislature & Parliament directory (`MLA`, `MLC`, `MPLS`, `MPRS` constituency & member records with party affiliation, tenure, and contact number)
5. `panchayat_village` — Official LGD Gram Panchayat & Village directory (`45,945` village mappings covering `8,388` unique Gram Panchayats across `534` Bihar blocks mapped to `op_sdb.block_code`)
6. `leader_types` — Canonical **25-type** taxonomy across **2 groups**:
   - **1. Professionals & Skilled Personnel (24):** `Chairman`, `Ex-Politician`, `Jila Parishad Adhyaksh`, `Jila Parishad Member`, `Jila Parishad Upadhyaksh`, `Minister (Central)`, `Minister (State)`, `MLA`, `MLC`, `MP (Lok Sabha)`, `MP (Rajya Sabha)`, `Mukhiya`, `Nagar Nigam Ward`, `Nagar Panchayat Ward`, `Nagar Parishad Ward`, `Panch`, `Panchayat Samiti Member`, `Pramukh`, `Sarpanch`, `Up Mukhiya`, `Up Pramukh`, `Up Sarpanch`, `Vice Chairman`, `Ward Member`
   - **2. Businesses & Establishments (1):** `Political Party Office` (`political-party-office`, linked to `political_party` ECI offices)
7. `leaders` — Custom claimed representatives mapped to canonical `leader_types.slug` categories and linked via `state_code`, `district_code`, `block_code`, `panchayat_id`
8. `panchayats` — Custom Gram Panchayat mappings linked to `op_sdb` via `state_code`, `district_code`, `block_code`
9. `development_works` — User-published & verified development projects (sanctioned cost, status, before/after photos, source)
10. `public_issues` — Citizen-reported civic issues linked to `op_sdb` location codes, category, locality, and upvotes
11. `public_ratings` — 5-parameter citizen feedback scorecard
12. `public_questions` — Constituent inquiries to representatives with official replies
13. `news_articles` — Verified civic & political announcements
14. `polls` — Community issue priority polls with live percentage bars
15. `political_party` — Official bilingual EN + Hindi directory of National (`6` per ECI `NP_23_Mar_2024.pdf`), State Recognized (`76` across `26` States/UTs per ECI `SP_23_Mar_2024.pdf`), and Registered Unrecognized Political Parties (`3,445` total rows mapped via `op_sdb.state_code`): RUPPs built from main ECI Notification dated `27-03-2024` and updated with ECI Notification `S.O. 57(E)` dated `10-10-2025`, comprising `2,140` `ACTIVE` (`6` National, `76` State, `2,058` Registered), `216` `INACTIVE`, and `1,089` `DELISTED` rows with zero missing Hindi names or addresses

---

## 📱 Core Pages & Navigation
- [index.php](file:///d:/laragon/www/localneta/index.php): **Local Leaders Directory** with State/District/Block hierarchy filter, 25-type leader taxonomy, Gram Panchayat Mukhiyas (`mukhiya`), MLAs & MLCs (`politician`), Parliament MPs (`mp`), and active ECI Political Party Offices (`political_party`).
- [leaders.php](file:///d:/laragon/www/localneta/leaders.php): "Know Your Leaders" Directory with Category Filters and Representative Hierarchy Lookup.
- [profile.php](file:///d:/laragon/www/localneta/profile.php): Detailed Leader Profile with Disclosures, Vikas Portfolio, 5-point Citizen Rating, Citizen Q&A, and Digital QR Card.
- [issues.php](file:///d:/laragon/www/localneta/issues.php): Development Project Tracker & "Report an Issue" citizen submission modal.
- [elections.php](file:///d:/laragon/www/localneta/elections.php): Official ECI & CEO Bihar portals, searchable 789-member Parliament MP Directory (Lok Sabha LS-18 & Rajya Sabha), Voter registration guides, and Civic explainers.
- [parties.php](file:///d:/laragon/www/localneta/parties.php): Searchable 3,445-entry bilingual Political Parties Directory (National `6`, State Recognized `76` with reserved election symbols from `assets/images/symbols/` across 26 States/UTs, and RUPPs across ECI Mar `2024` & Oct `2025` Notifications in English & Hindi — Active, Inactive, and Delisted — mapped via `op_sdb`).
- [political-party.php](file:///d:/laragon/www/localneta/political-party.php) (via `/political-party/<slug>` in [.htaccess](file:///d:/laragon/www/localneta/.htaccess)): Dedicated SEO profile page for each of the 3,445 political parties showing its official ECI Election Symbol graphic (`assets/images/symbols/` when issued by ECI), bilingual EN + Hindi name & headquarters address, ECI status/type, state-wise recognitions, and affiliated Parliament MPs / local representatives.
- [source.php](file:///d:/laragon/www/localneta/source.php): Official Public Data Sources, Attribution & Independent Civic Disclaimer detailing `sansad.in`, `neva.gov.in`, `eci.gov.in`, `voters.eci.gov.in`, `affidavit.eci.gov.in`, `lgdirectory.gov.in`, `egramswaraj.gov.in`, and State Election Commissions alongside Section 79 IT Act voluntary citizen submission guidelines.
- [studio.php](file:///d:/laragon/www/localneta/studio.php): **LocalNeta Studio** — HTML5 Canvas Poster Maker (1:1 Square & 9:16 Vertical formats), Smart Slogan Generator, and QR Visiting Card generator.
- [dashboard.php](file:///d:/laragon/www/localneta/dashboard.php): Leader Self-Service Management Portal (reply to citizen questions, post new development works, view profile analytics).
- [admin.php](file:///d:/laragon/www/localneta/admin.php): Civic Moderation & Verification Console.

---

## 🚀 Running on Laragon
In Laragon, `D:\laragon\www\localneta` is automatically accessible via:
- URL: `http://localhost/localneta/` or `http://localneta.test/`
- Or via PHP built-in server:
  ```bash
  & "D:\laragon\bin\php\php-8.3.28-Win32-vs16-x64\php.exe" -S localhost:8080 -t .
  ```

