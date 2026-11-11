<?php
// includes/db.php - MySQL Database Connection & Auto-Initialization for LocalNeta (Using op_sdb)

if (!defined('LOCALNETA_DB_INIT')) {
    define('LOCALNETA_DB_INIT', true);
}

// Load gitignored local configuration before environment fallbacks
$localConfigFile = __DIR__ . '/config.local.php';
if (is_file($localConfigFile)) {
    require_once $localConfigFile;
}

if (!defined('DB_HOST')) {
    define('DB_HOST', (string)(getenv('LOCALNETA_DB_HOST') ?: 'localhost'));
}
if (!defined('DB_NAME')) {
    define('DB_NAME', (string)(getenv('LOCALNETA_DB_NAME') ?: ''));
}
if (!defined('DB_USER')) {
    define('DB_USER', (string)(getenv('LOCALNETA_DB_USER') ?: ''));
}
if (!defined('DB_PASS')) {
    $envDbPass = getenv('LOCALNETA_DB_PASS');
    define('DB_PASS', $envDbPass !== false ? (string)$envDbPass : '');
}

function get_db_connection(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        if (DB_NAME === '' || DB_USER === '') {
            throw new RuntimeException('Database configuration missing. Copy includes/config.sample.php to includes/config.local.php or set LOCALNETA_DB_* environment variables.');
        }

        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Fallback try with 127.0.0.1 if localhost fails
            $dsnFallback = 'mysql:host=127.0.0.1;dbname=' . DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsnFallback, DB_USER, DB_PASS, $options);
        }

        // Check if tables exist, if not init & seed
        check_and_init_mysql($pdo);
    }
    return $pdo;
}

function check_and_init_mysql(PDO $pdo): void {
    $stmt = $pdo->query("SHOW TABLES LIKE 'leaders'");
    $exists = $stmt->fetch();

    if (!$exists) {
        init_mysql_schema($pdo);
    }

    // Ensure leader_types canonical 25-type taxonomy table exists & stays populated
    ensure_leader_types_schema($pdo);

    // Ensure political_party has unique URL slugs populated when table exists
    ensure_political_party_slugs($pdo);

    // Ensure mp table (Lok Sabha / Rajya Sabha Parliament Members) exists with latest columns and seed rows
    $mpStmt = $pdo->query("SHOW TABLES LIKE 'mp'");
    if (!$mpStmt->fetch()) {
        init_mp_schema($pdo);
    } else {
        ensure_mp_columns($pdo);
    }

    require_once __DIR__ . '/seed_data.php';
    $lsCount = (int)$pdo->query("SELECT COUNT(*) FROM mp WHERE type = 'ls' AND election = 'ls-18'")->fetchColumn();
    if ($lsCount === 0) {
        seed_mp_table($pdo);
    }
    $rsCount = (int)$pdo->query("SELECT COUNT(*) FROM mp WHERE type = 'rs'")->fetchColumn();
    if ($rsCount === 0) {
        seed_rs_mp_table($pdo);
    } else {
        // Migrate legacy 'rs-2026' placeholder: NULL for Nominated, 'RS YYYY' from term start year for elected members
        $staleRs = (int)$pdo->query("SELECT COUNT(*) FROM mp WHERE type = 'rs' AND election = 'rs-2026'")->fetchColumn();
        if ($staleRs > 0) {
            $pdo->exec("UPDATE mp SET election = NULL WHERE type = 'rs' AND state = 'Nominated'");
            $pdo->exec("UPDATE mp SET election = CONCAT('RS ', LEFT(term_period, 4)) WHERE type = 'rs' AND state <> 'Nominated' AND term_period REGEXP '^[0-9]{4}'");
            $pdo->exec("UPDATE mp SET election = NULL WHERE type = 'rs' AND election = 'rs-2026'");
        }
    }

    // Ensure every Member of Parliament has a unique SEO URL slug
    ensure_mp_slugs($pdo);

    // Ensure mlc table (Members of Legislative Council) exists, is seeded, and has unique slugs
    $mlcStmt = $pdo->query("SHOW TABLES LIKE 'mlc'");
    if (!$mlcStmt->fetch()) {
        init_mlc_schema($pdo);
    } else {
        ensure_mlc_columns($pdo);
    }

    $mlcNeedsSeed = (int)$pdo->query("
        SELECT (
            (SELECT COUNT(*) FROM mlc WHERE state_code = '28') = 0
            OR (SELECT COUNT(*) FROM mlc WHERE state_code = '9') = 0
            OR (SELECT COUNT(*) FROM mlc WHERE state_code = '10') = 0
            OR EXISTS(SELECT 1 FROM mlc WHERE neva_member_id IS NOT NULL AND constituency_code IS NULL LIMIT 1)
        )
    ")->fetchColumn();
    if ($mlcNeedsSeed > 0) {
        seed_mlc_table($pdo);
    }

    ensure_mlc_slugs($pdo);

    // Ensure citizen/politician users & daily contact reveals tables exist
    ensure_user_auth_schema($pdo);

    // Ensure high-speed indexes exist on `village` and `panchayat_village` for PIN code lookup
    ensure_village_pincode_indexes($pdo);
}

function init_mp_schema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mp (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_name VARCHAR(200) NOT NULL,
            slug VARCHAR(255) NOT NULL DEFAULT '',
            party_name VARCHAR(200) NOT NULL,
            constituency VARCHAR(200) NOT NULL,
            state VARCHAR(100) NOT NULL,
            state_code VARCHAR(10) NULL,
            membership_status VARCHAR(50) NOT NULL DEFAULT 'Sitting',
            lok_sabha_terms VARCHAR(100) NOT NULL DEFAULT '18',
            age INT NULL,
            photo_url TEXT NULL,
            term_period VARCHAR(50) NULL,
            total_terms INT NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'ls',
            election VARCHAR(50) NULL DEFAULT NULL,
            sources VARCHAR(255) NOT NULL DEFAULT 'https://sansad.in/',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_mp_slug (slug),
            INDEX idx_mp_type_election (type, election),
            INDEX idx_mp_state_code (state_code),
            INDEX idx_mp_state (state),
            INDEX idx_mp_party (party_name),
            INDEX idx_mp_constituency (constituency)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
}

function ensure_mp_columns(PDO $pdo): void {
    $existing = [];
    foreach ($pdo->query("SHOW COLUMNS FROM mp") as $col) {
        $existing[$col['Field']] = $col;
    }
    if (!isset($existing['slug'])) {
        $pdo->exec("ALTER TABLE mp ADD COLUMN slug VARCHAR(255) NOT NULL DEFAULT '' AFTER member_name");
    }
    if (!isset($existing['age'])) {
        $pdo->exec("ALTER TABLE mp ADD COLUMN age INT NULL AFTER lok_sabha_terms");
    }
    if (!isset($existing['photo_url'])) {
        $pdo->exec("ALTER TABLE mp ADD COLUMN photo_url TEXT NULL AFTER age");
    }
    if (!isset($existing['term_period'])) {
        $pdo->exec("ALTER TABLE mp ADD COLUMN term_period VARCHAR(50) NULL AFTER photo_url");
    }
    if (!isset($existing['total_terms'])) {
        $pdo->exec("ALTER TABLE mp ADD COLUMN total_terms INT NULL AFTER term_period");
    }
    if (isset($existing['election']) && strtoupper((string)($existing['election']['Null'] ?? '')) === 'NO') {
        $pdo->exec("ALTER TABLE mp MODIFY COLUMN election VARCHAR(50) NULL DEFAULT NULL");
    }
}

function init_mlc_schema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mlc (
            id INT AUTO_INCREMENT PRIMARY KEY,
            neva_member_id INT NULL,
            assembly_id INT NOT NULL DEFAULT 1,
            constituency_code INT NULL,
            seat_no INT NULL,
            member_name VARCHAR(200) NOT NULL,
            slug VARCHAR(255) NOT NULL DEFAULT '',
            party_name VARCHAR(200) NOT NULL,
            role_designation VARCHAR(100) NULL,
            constituency VARCHAR(200) NOT NULL,
            constituency_type VARCHAR(100) NOT NULL,
            constituency_area VARCHAR(150) NULL,
            constituency_full VARCHAR(200) NULL,
            constituency_xls VARCHAR(150) NULL,
            state VARCHAR(100) NOT NULL,
            state_code VARCHAR(10) NULL,
            house VARCHAR(120) NOT NULL DEFAULT 'Legislative Council',
            membership_status VARCHAR(50) NOT NULL DEFAULT 'Sitting',
            gender VARCHAR(20) NULL,
            social_category VARCHAR(30) NULL,
            father_name VARCHAR(200) NULL,
            dob VARCHAR(50) NULL,
            dob_date DATE NULL,
            age INT NULL,
            marital_status VARCHAR(50) NULL,
            spouse_name VARCHAR(200) NULL,
            children_details VARCHAR(255) NULL,
            highest_qualification VARCHAR(150) NULL,
            other_qualification TEXT NULL,
            hobbies VARCHAR(150) NULL,
            term_period VARCHAR(80) NULL,
            mobile VARCHAR(30) NULL,
            alternate_mobile VARCHAR(50) NULL,
            office_tel VARCHAR(50) NULL,
            email VARCHAR(150) NULL,
            local_address TEXT NULL,
            permanent_address TEXT NULL,
            photo_url TEXT NULL,
            source_url VARCHAR(255) NULL,
            sources VARCHAR(255) NOT NULL DEFAULT 'https://neva.gov.in/',
            status VARCHAR(25) NOT NULL DEFAULT 'ACTIVE',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_mlc_slug (slug),
            UNIQUE KEY uk_mlc_neva_state (state_code, neva_member_id),
            INDEX idx_mlc_state_code (state_code),
            INDEX idx_mlc_state (state),
            INDEX idx_mlc_constituency_code (constituency_code),
            INDEX idx_mlc_seat_no (seat_no),
            INDEX idx_mlc_party (party_name),
            INDEX idx_mlc_constituency_type (constituency_type),
            INDEX idx_mlc_constituency (constituency),
            INDEX idx_mlc_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
}

function ensure_mlc_columns(PDO $pdo): void {
    $existing = [];
    foreach ($pdo->query("SHOW COLUMNS FROM mlc") as $col) {
        $existing[$col['Field']] = $col;
    }
    $optionalCols = [
        'constituency_code'     => "ALTER TABLE mlc ADD COLUMN constituency_code INT NULL AFTER assembly_id",
        'slug'                  => "ALTER TABLE mlc ADD COLUMN slug VARCHAR(255) NOT NULL DEFAULT '' AFTER member_name",
        'role_designation'      => "ALTER TABLE mlc ADD COLUMN role_designation VARCHAR(100) NULL AFTER party_name",
        'constituency_type'     => "ALTER TABLE mlc ADD COLUMN constituency_type VARCHAR(100) NOT NULL DEFAULT '' AFTER constituency",
        'constituency_area'     => "ALTER TABLE mlc ADD COLUMN constituency_area VARCHAR(150) NULL AFTER constituency_type",
        'constituency_full'     => "ALTER TABLE mlc ADD COLUMN constituency_full VARCHAR(200) NULL AFTER constituency_area",
        'constituency_xls'      => "ALTER TABLE mlc ADD COLUMN constituency_xls VARCHAR(150) NULL AFTER constituency_full",
        'house'                 => "ALTER TABLE mlc ADD COLUMN house VARCHAR(120) NOT NULL DEFAULT 'Legislative Council' AFTER state_code",
        'gender'                => "ALTER TABLE mlc ADD COLUMN gender VARCHAR(20) NULL AFTER membership_status",
        'social_category'       => "ALTER TABLE mlc ADD COLUMN social_category VARCHAR(30) NULL AFTER gender",
        'father_name'           => "ALTER TABLE mlc ADD COLUMN father_name VARCHAR(200) NULL AFTER social_category",
        'dob'                   => "ALTER TABLE mlc ADD COLUMN dob VARCHAR(50) NULL AFTER father_name",
        'dob_date'              => "ALTER TABLE mlc ADD COLUMN dob_date DATE NULL AFTER dob",
        'age'                   => "ALTER TABLE mlc ADD COLUMN age INT NULL AFTER dob_date",
        'marital_status'        => "ALTER TABLE mlc ADD COLUMN marital_status VARCHAR(50) NULL AFTER age",
        'spouse_name'           => "ALTER TABLE mlc ADD COLUMN spouse_name VARCHAR(200) NULL AFTER marital_status",
        'children_details'      => "ALTER TABLE mlc ADD COLUMN children_details VARCHAR(255) NULL AFTER spouse_name",
        'highest_qualification' => "ALTER TABLE mlc ADD COLUMN highest_qualification VARCHAR(150) NULL AFTER children_details",
        'other_qualification'   => "ALTER TABLE mlc ADD COLUMN other_qualification TEXT NULL AFTER highest_qualification",
        'hobbies'               => "ALTER TABLE mlc ADD COLUMN hobbies VARCHAR(150) NULL AFTER other_qualification",
        'term_period'           => "ALTER TABLE mlc ADD COLUMN term_period VARCHAR(80) NULL AFTER hobbies",
        'mobile'                => "ALTER TABLE mlc ADD COLUMN mobile VARCHAR(30) NULL AFTER term_period",
        'alternate_mobile'      => "ALTER TABLE mlc ADD COLUMN alternate_mobile VARCHAR(50) NULL AFTER mobile",
        'office_tel'            => "ALTER TABLE mlc ADD COLUMN office_tel VARCHAR(50) NULL AFTER alternate_mobile",
        'email'                 => "ALTER TABLE mlc ADD COLUMN email VARCHAR(150) NULL AFTER office_tel",
        'local_address'         => "ALTER TABLE mlc ADD COLUMN local_address TEXT NULL AFTER email",
        'permanent_address'     => "ALTER TABLE mlc ADD COLUMN permanent_address TEXT NULL AFTER local_address",
        'photo_url'             => "ALTER TABLE mlc ADD COLUMN photo_url TEXT NULL AFTER permanent_address",
        'source_url'            => "ALTER TABLE mlc ADD COLUMN source_url VARCHAR(255) NULL AFTER photo_url",
        'status'                => "ALTER TABLE mlc ADD COLUMN status VARCHAR(25) NOT NULL DEFAULT 'ACTIVE' AFTER sources",
    ];
    foreach ($optionalCols as $col => $sql) {
        if (!isset($existing[$col])) {
            $pdo->exec($sql);
        }
    }
    if (isset($existing['children_details']) && stripos((string)($existing['children_details']['Type'] ?? ''), 'varchar(150)') !== false) {
        $pdo->exec("ALTER TABLE mlc MODIFY COLUMN children_details VARCHAR(255) NULL");
    }
    if (!$pdo->query("SHOW INDEX FROM mlc WHERE Key_name = 'idx_mlc_constituency_code'")->fetch()) {
        $pdo->exec("ALTER TABLE mlc ADD INDEX idx_mlc_constituency_code (constituency_code)");
    }
}

function init_mysql_schema(PDO $pdo): void {
    $queries = [
        // Master State-District-Block table (never overwrites existing op_sdb)
        "CREATE TABLE IF NOT EXISTS op_sdb (
            id INT AUTO_INCREMENT PRIMARY KEY,
            state_code VARCHAR(10) DEFAULT NULL,
            state VARCHAR(44) DEFAULT NULL,
            district_code VARCHAR(13) DEFAULT NULL,
            district VARCHAR(38) DEFAULT NULL,
            block_code VARCHAR(10) DEFAULT NULL,
            block VARCHAR(36) DEFAULT NULL,
            c_2001 VARCHAR(6) DEFAULT NULL,
            c_2011 VARCHAR(6) DEFAULT NULL,
            created_by INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by INT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            status VARCHAR(25) NOT NULL DEFAULT 'ACTIVE',
            INDEX idx_op_sdb_state (state_code),
            INDEX idx_op_sdb_district (district_code),
            INDEX idx_op_sdb_block (block_code),
            INDEX idx_op_sdb_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS panchayats (
            id INT AUTO_INCREMENT PRIMARY KEY,
            state_code VARCHAR(10) NOT NULL DEFAULT '10',
            district_code VARCHAR(13) NOT NULL DEFAULT '218',
            block_code VARCHAR(10) NOT NULL,
            name_en VARCHAR(100) NOT NULL,
            name_hi VARCHAR(100) NOT NULL,
            pincode VARCHAR(10),
            total_wards INT DEFAULT 14,
            INDEX idx_panchayat_block_code (block_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS leaders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(150) UNIQUE NOT NULL,
            full_name_hi VARCHAR(150) NOT NULL,
            full_name_en VARCHAR(150) NOT NULL,
            designation_hi VARCHAR(150) NOT NULL,
            category VARCHAR(50) NOT NULL,
            panchayat_id INT NULL,
            state_code VARCHAR(10) NOT NULL DEFAULT '10',
            district_code VARCHAR(13) NOT NULL DEFAULT '218',
            block_code VARCHAR(10) NULL,
            constituency_name_hi VARCHAR(200),
            party_name_hi VARCHAR(150) DEFAULT 'Independent',
            is_verified TINYINT(1) DEFAULT 1,
            verification_badge VARCHAR(50) DEFAULT 'elected_verified',
            phone VARCHAR(30),
            whatsapp VARCHAR(30),
            email VARCHAR(100),
            bio_hi TEXT,
            education_hi VARCHAR(255),
            declared_assets VARCHAR(100),
            criminal_cases VARCHAR(255) DEFAULT 'No criminal cases registered',
            avatar_url TEXT,
            cover_url TEXT,
            views_count INT DEFAULT 1420,
            plan_tier VARCHAR(50) DEFAULT 'leader_pro',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_leader_district_code (district_code),
            INDEX idx_leader_block_code (block_code),
            FOREIGN KEY (panchayat_id) REFERENCES panchayats(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS development_works (
            id INT AUTO_INCREMENT PRIMARY KEY,
            leader_id INT NOT NULL,
            panchayat_id INT NULL,
            title_hi VARCHAR(255) NOT NULL,
            category VARCHAR(50) NOT NULL,
            category_name_hi VARCHAR(100) NOT NULL,
            cost_lakhs DECIMAL(10,2) DEFAULT 0.00,
            sanction_date VARCHAR(50),
            completion_date VARCHAR(50),
            status VARCHAR(50) NOT NULL DEFAULT 'completed',
            status_label_hi VARCHAR(100) NOT NULL DEFAULT 'Verified Complete',
            source_doc_info TEXT,
            photo_before TEXT,
            photo_after TEXT,
            upvotes INT DEFAULT 18,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (leader_id) REFERENCES leaders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS public_issues (
            id INT AUTO_INCREMENT PRIMARY KEY,
            panchayat_id INT NULL,
            state_code VARCHAR(10) NOT NULL DEFAULT '10',
            district_code VARCHAR(13) NOT NULL DEFAULT '218',
            block_code VARCHAR(10) NULL,
            title_hi VARCHAR(255) NOT NULL,
            description_hi TEXT NOT NULL,
            category VARCHAR(50) NOT NULL,
            category_name_hi VARCHAR(100) NOT NULL,
            reported_by_name VARCHAR(150) NOT NULL,
            locality_hi VARCHAR(200) NOT NULL,
            photo_url TEXT,
            status VARCHAR(50) DEFAULT 'under_review',
            status_label_hi VARCHAR(100) DEFAULT 'Verification Pending',
            department_hi VARCHAR(150),
            official_response_hi TEXT,
            upvotes INT DEFAULT 5,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_issue_district_code (district_code),
            INDEX idx_issue_block_code (block_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS public_ratings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            leader_id INT NOT NULL,
            phone_hash VARCHAR(100),
            road_drainage TINYINT NOT NULL,
            sanitation TINYINT NOT NULL,
            drinking_water TINYINT NOT NULL,
            accessibility TINYINT NOT NULL,
            grievance_redressal TINYINT NOT NULL,
            feedback_comment TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (leader_id) REFERENCES leaders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS public_questions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            leader_id INT NOT NULL,
            citizen_name VARCHAR(150) NOT NULL,
            citizen_locality VARCHAR(200) NOT NULL,
            question_text TEXT NOT NULL,
            status VARCHAR(50) DEFAULT 'answered',
            status_label_hi VARCHAR(100) DEFAULT 'Response Published',
            answer_text TEXT,
            answered_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (leader_id) REFERENCES leaders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS news_articles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title_hi VARCHAR(255) NOT NULL,
            summary_hi TEXT NOT NULL,
            content_hi LONGTEXT,
            category_hi VARCHAR(100) NOT NULL,
            location_hi VARCHAR(150) NOT NULL,
            source_hi VARCHAR(150) NOT NULL,
            image_url TEXT,
            views INT DEFAULT 850,
            published_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS polls (
            id INT AUTO_INCREMENT PRIMARY KEY,
            question_hi VARCHAR(255) NOT NULL,
            category_hi VARCHAR(100) NOT NULL,
            option_1 VARCHAR(200) NOT NULL,
            votes_1 INT DEFAULT 0,
            option_2 VARCHAR(200) NOT NULL,
            votes_2 INT DEFAULT 0,
            option_3 VARCHAR(200) NOT NULL,
            votes_3 INT DEFAULT 0,
            option_4 VARCHAR(200) NOT NULL,
            votes_4 INT DEFAULT 0,
            total_votes INT DEFAULT 0,
            status VARCHAR(50) DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
    ];

    foreach ($queries as $q) {
        $pdo->exec($q);
    }
    ensure_leader_types_schema($pdo);
    init_mp_schema($pdo);
    init_mlc_schema($pdo);
}

function ensure_leader_types_schema(PDO $pdo): void {
    $ltExists = $pdo->query("SHOW TABLES LIKE 'leader_types'")->fetch();
    if (!$ltExists) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS leader_types (
                id INT AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(64) NOT NULL,
                name VARCHAR(100) NOT NULL,
                group_no TINYINT NOT NULL DEFAULT 1,
                group_name VARCHAR(80) NOT NULL,
                governance_tier VARCHAR(60) NOT NULL DEFAULT '',
                badge_class VARCHAR(32) NOT NULL DEFAULT 'bg-blue',
                icon_class VARCHAR(64) NOT NULL DEFAULT 'fa-user-tie',
                sort_order INT NOT NULL DEFAULT 100,
                status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_leader_type_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    $ltCount = (int)$pdo->query("SELECT COUNT(*) FROM leader_types")->fetchColumn();
    if ($ltCount === 0 && function_exists('get_default_leader_types')) {
        $ins = $pdo->prepare("
            INSERT IGNORE INTO leader_types (slug, name, group_no, group_name, governance_tier, badge_class, icon_class, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach (get_default_leader_types() as $lt) {
            $ins->execute([
                $lt['slug'],
                $lt['name'],
                $lt['group_no'],
                $lt['group_name'],
                $lt['governance_tier'],
                $lt['badge_class'],
                $lt['icon_class'],
                $lt['sort_order'],
            ]);
        }
        $pdo->exec("UPDATE leaders SET category = 'panchayat-samiti-member' WHERE category = 'samiti'");
        $pdo->exec("UPDATE leaders SET category = 'jila-parishad-member', designation_hi = 'Jila Parishad Member' WHERE category = 'zila_parishad'");
    }
}

function ensure_political_party_slugs(PDO $pdo): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    if (!$pdo->query("SHOW TABLES LIKE 'political_party'")->fetch()) {
        return;
    }

    $hasCol = $pdo->query("SHOW COLUMNS FROM political_party LIKE 'slug'")->fetch();
    if (!$hasCol) {
        $pdo->exec("ALTER TABLE political_party ADD COLUMN slug VARCHAR(255) NOT NULL DEFAULT '' AFTER name");
    }

    $missing = $pdo->query("SELECT id FROM political_party WHERE slug IS NULL OR slug = '' LIMIT 1")->fetch();
    if ($missing) {
        if (!function_exists('generate_party_slug')) {
            require_once __DIR__ . '/functions.php';
        }

        $rows = $pdo->query("
            SELECT id, name, state, type, status, slug
            FROM political_party
            ORDER BY CASE TRIM(type) WHEN 'National' THEN 1 WHEN 'State' THEN 2 ELSE 3 END ASC,
                     CASE UPPER(TRIM(status)) WHEN 'ACTIVE' THEN 1 WHEN 'INACTIVE' THEN 2 WHEN 'DELISTED' THEN 3 ELSE 4 END ASC,
                     id ASC
        ")->fetchAll();

        $used = [];
        foreach ($rows as $r) {
            if (!empty($r['slug'])) {
                $used[strtolower($r['slug'])] = (int)$r['id'];
            }
        }

        $upd = $pdo->prepare("UPDATE political_party SET slug = ? WHERE id = ?");
        $pdo->beginTransaction();
        try {
            foreach ($rows as $r) {
                $id = (int)$r['id'];
                if (!empty($r['slug'])) {
                    continue;
                }
                $base = generate_party_slug((string)$r['name']);
                if ($base === '') {
                    $base = 'party-' . $id;
                }
                $candidate = $base;
                if (isset($used[$candidate])) {
                    $stateSlug = generate_party_slug((string)$r['state'], 50);
                    $candidate = ($stateSlug !== '' && $stateSlug !== 'all') ? ($base . '-' . $stateSlug) : ($base . '-' . $id);
                    if (isset($used[$candidate])) {
                        $candidate = $base . '-' . $id;
                    }
                }
                $used[$candidate] = $id;
                $upd->execute([$candidate, $id]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    $hasIdx = $pdo->query("SHOW INDEX FROM political_party WHERE Key_name = 'uk_pp_slug'")->fetch();
    if (!$hasIdx) {
        $pdo->exec("ALTER TABLE political_party ADD UNIQUE KEY uk_pp_slug (slug)");
    }

    ensure_political_party_columns($pdo);
}

function ensure_political_party_columns(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (!$pdo->query("SHOW TABLES LIKE 'political_party'")->fetch()) {
        return;
    }

    $existingCols = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM political_party");
    if ($stmt) {
        foreach ($stmt->fetchAll() as $col) {
            $existingCols[strtolower((string)($col['Field'] ?? ''))] = true;
        }
    }

    $addMap = [
        'mobile' => "ALTER TABLE political_party ADD COLUMN mobile VARCHAR(40) NULL DEFAULT NULL AFTER url",
        'whatsapp' => "ALTER TABLE political_party ADD COLUMN whatsapp VARCHAR(120) NULL DEFAULT NULL AFTER mobile",
        'misscall' => "ALTER TABLE political_party ADD COLUMN misscall VARCHAR(40) NULL DEFAULT NULL AFTER whatsapp",
        'email' => "ALTER TABLE political_party ADD COLUMN email VARCHAR(150) NULL DEFAULT NULL AFTER misscall",
        'facebook' => "ALTER TABLE political_party ADD COLUMN facebook VARCHAR(255) NULL DEFAULT NULL AFTER email",
        'instagram' => "ALTER TABLE political_party ADD COLUMN instagram VARCHAR(255) NULL DEFAULT NULL AFTER facebook",
        'x' => "ALTER TABLE political_party ADD COLUMN x VARCHAR(255) NULL DEFAULT NULL AFTER instagram",
        'youtube' => "ALTER TABLE political_party ADD COLUMN youtube VARCHAR(255) NULL DEFAULT NULL AFTER x",
        'linkedin' => "ALTER TABLE political_party ADD COLUMN linkedin VARCHAR(255) NULL DEFAULT NULL AFTER youtube",
    ];

    foreach ($addMap as $colName => $ddl) {
        if (empty($existingCols[$colName])) {
            $pdo->exec($ddl);
            $existingCols[$colName] = true;
        }
    }
}

function ensure_mp_slugs(PDO $pdo): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    if (!$pdo->query("SHOW TABLES LIKE 'mp'")->fetch()) {
        return;
    }

    $hasCol = $pdo->query("SHOW COLUMNS FROM mp LIKE 'slug'")->fetch();
    if (!$hasCol) {
        $pdo->exec("ALTER TABLE mp ADD COLUMN slug VARCHAR(255) NOT NULL DEFAULT '' AFTER member_name");
    }

    $missing = $pdo->query("SELECT id FROM mp WHERE slug IS NULL OR slug = '' LIMIT 1")->fetch();
    if ($missing) {
        if (!function_exists('generate_mp_slug')) {
            require_once __DIR__ . '/functions.php';
        }

        $rows = $pdo->query("
            SELECT id, member_name, type, constituency, state, membership_status, slug
            FROM mp
            ORDER BY (membership_status = 'Sitting') DESC, (type = 'ls') DESC, id ASC
        ")->fetchAll();

        $used = [];
        $baseCounts = [];
        foreach ($rows as $r) {
            if (!empty($r['slug'])) {
                $used[strtolower((string)$r['slug'])] = (int)$r['id'];
            }
            $b = generate_mp_slug((string)$r['member_name']);
            if ($b === '') {
                $b = 'mp-' . (int)$r['id'];
            }
            $baseCounts[$b] = ($baseCounts[$b] ?? 0) + 1;
        }

        $upd = $pdo->prepare("UPDATE mp SET slug = ? WHERE id = ?");
        $pdo->beginTransaction();
        try {
            foreach ($rows as $r) {
                $id = (int)$r['id'];
                if (!empty($r['slug'])) {
                    continue;
                }
                $base = generate_mp_slug((string)$r['member_name']);
                if ($base === '') {
                    $base = 'mp-' . $id;
                }

                $candidate = $base;
                $needsQualifier = ($baseCounts[$base] ?? 0) > 1 || isset($used[$candidate]);
                if ($needsQualifier) {
                    $isRs = strtolower((string)($r['type'] ?? '')) === 'rs';
                    $qualRaw = $isRs
                        ? (!empty($r['state']) && strcasecmp((string)$r['state'], 'Nominated') !== 0 ? (string)$r['state'] : (string)$r['constituency'])
                        : (string)preg_replace('/\s*\((?:SC|ST)\)\s*$/i', '', (string)$r['constituency']);
                    $qualSlug = generate_mp_slug($qualRaw, 60, false);
                    if ($qualSlug !== '') {
                        $candidate = $base . '-' . $qualSlug;
                    }
                    if (isset($used[$candidate])) {
                        $candidate = $base . '-' . ($isRs ? 'rs-' : 'ls-') . $id;
                    }
                    if (isset($used[$candidate])) {
                        $candidate = $base . '-' . $id;
                    }
                }

                $used[$candidate] = $id;
                $upd->execute([$candidate, $id]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    $hasIdx = $pdo->query("SHOW INDEX FROM mp WHERE Key_name = 'uk_mp_slug'")->fetch();
    if (!$hasIdx) {
        $pdo->exec("ALTER TABLE mp ADD UNIQUE KEY uk_mp_slug (slug)");
    }
}

function ensure_mlc_slugs(PDO $pdo): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    if (!$pdo->query("SHOW TABLES LIKE 'mlc'")->fetch()) {
        return;
    }

    $hasCol = $pdo->query("SHOW COLUMNS FROM mlc LIKE 'slug'")->fetch();
    if (!$hasCol) {
        $pdo->exec("ALTER TABLE mlc ADD COLUMN slug VARCHAR(255) NOT NULL DEFAULT '' AFTER member_name");
    }

    $missing = $pdo->query("
        SELECT id FROM mlc
        WHERE slug IS NULL
           OR slug = ''
           OR (state_code = '9' AND slug NOT LIKE 'up-%')
           OR (state_code = '10' AND slug NOT LIKE 'br-%')
           OR (state_code = '28' AND slug NOT LIKE 'ap-%')
        LIMIT 1
    ")->fetch();
    if ($missing) {
        if (!function_exists('generate_mlc_prefixed_slug')) {
            require_once __DIR__ . '/functions.php';
        }

        $rows = $pdo->query("
            SELECT id, member_name, constituency_area, constituency_type, state, state_code, seat_no, neva_member_id, slug
            FROM mlc
            ORDER BY state_code ASC, (membership_status = 'Sitting') DESC, seat_no ASC, id ASC
        ")->fetchAll();

        $used = [];
        $baseCounts = [];
        foreach ($rows as $r) {
            $prefix = get_mlc_state_slug_prefix((string)$r['state_code'], (string)$r['state']);
            $curSlug = strtolower(trim((string)($r['slug'] ?? '')));
            if ($curSlug !== '' && str_starts_with($curSlug, $prefix . '-')) {
                $used[$curSlug] = (int)$r['id'];
            }
            $b = generate_mlc_prefixed_slug((string)$r['member_name'], (string)$r['state_code'], (string)$r['state']);
            if ($b === '' || $b === $prefix) {
                $b = $prefix . '-mlc-' . (int)$r['id'];
            }
            $baseCounts[$b] = ($baseCounts[$b] ?? 0) + 1;
        }

        $upd = $pdo->prepare("UPDATE mlc SET slug = ? WHERE id = ?");
        $pdo->beginTransaction();
        try {
            $updatesToApply = [];
            foreach ($rows as $r) {
                $id = (int)$r['id'];
                $prefix = get_mlc_state_slug_prefix((string)$r['state_code'], (string)$r['state']);
                $curSlug = strtolower(trim((string)($r['slug'] ?? '')));
                if ($curSlug !== '' && str_starts_with($curSlug, $prefix . '-')) {
                    continue;
                }

                $base = generate_mlc_prefixed_slug((string)$r['member_name'], (string)$r['state_code'], (string)$r['state']);
                if ($base === '' || $base === $prefix) {
                    $base = $prefix . '-mlc-' . $id;
                }

                $candidate = $base;
                if (($baseCounts[$base] ?? 0) > 1 || isset($used[$candidate])) {
                    $qualRaw = !empty($r['constituency_area']) ? (string)$r['constituency_area'] : (string)($r['constituency_type'] ?? '');
                    $qualSlug = generate_mlc_slug($qualRaw, 60, false);
                    if ($qualSlug !== '') {
                        $candidate = $base . '-' . $qualSlug;
                    }
                    if (isset($used[$candidate])) {
                        $candidate = $base . '-' . ($r['neva_member_id'] ?: $id);
                    }
                    if (isset($used[$candidate])) {
                        $candidate = $base . '-' . $id;
                    }
                }

                $used[$candidate] = $id;
                $updatesToApply[] = [$candidate, $id];
            }

            if (!empty($updatesToApply)) {
                foreach ($updatesToApply as $pair) {
                    $upd->execute(['_mig_mlc_' . $pair[1], $pair[1]]);
                }
                foreach ($updatesToApply as $pair) {
                    $upd->execute([$pair[0], $pair[1]]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    $hasIdx = $pdo->query("SHOW INDEX FROM mlc WHERE Key_name = 'uk_mlc_slug'")->fetch();
    if (!$hasIdx) {
        $pdo->exec("ALTER TABLE mlc ADD UNIQUE KEY uk_mlc_slug (slug)");
    }
}

function ensure_user_auth_schema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_type ENUM('user', 'politician') NOT NULL DEFAULT 'user',
            role VARCHAR(50) NOT NULL DEFAULT 'citizen',
            leader_category VARCHAR(60) NULL,
            name VARCHAR(150) NOT NULL,
            full_name VARCHAR(150) NULL,
            username_handle VARCHAR(80) NULL,
            mobile VARCHAR(20) NOT NULL,
            whatsapp VARCHAR(20) NULL,
            email VARCHAR(150) NULL,
            password VARCHAR(255) NOT NULL,
            state_code VARCHAR(10) NULL,
            district_code VARCHAR(15) NULL,
            block_code VARCHAR(15) NULL,
            state_name VARCHAR(100) NULL,
            district VARCHAR(100) NULL,
            block_name VARCHAR(100) NULL,
            constituency VARCHAR(150) NULL,
            panchayat VARCHAR(150) NULL,
            party_name VARCHAR(150) NULL,
            designation VARCHAR(150) NULL,
            profession_category VARCHAR(100) NULL,
            business_name VARCHAR(150) NULL,
            specialization VARCHAR(200) NULL,
            education VARCHAR(150) NULL,
            experience_years INT NULL,
            languages VARCHAR(150) NULL,
            office_hours VARCHAR(150) NULL,
            gender VARCHAR(20) NULL,
            dob DATE NULL,
            address TEXT NULL,
            pincode VARCHAR(10) NULL,
            bio TEXT NULL,
            profile_photo VARCHAR(255) NULL,
            twitter VARCHAR(255) NULL,
            facebook VARCHAR(255) NULL,
            instagram VARCHAR(255) NULL,
            linkedin VARCHAR(255) NULL,
            website_url VARCHAR(255) NULL,
            profile_visibility ENUM('public', 'private') NOT NULL DEFAULT 'public',
            mobile_visibility ENUM('public', 'masked', 'private') NOT NULL DEFAULT 'masked',
            email_visibility ENUM('public', 'masked', 'private') NOT NULL DEFAULT 'masked',
            address_visibility ENUM('public', 'masked', 'private') NOT NULL DEFAULT 'public',
            counter INT NOT NULL DEFAULT 0,
            status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
            otp_code VARCHAR(10) NULL,
            otp_expiry DATETIME NULL,
            is_mobile_verified TINYINT(1) NOT NULL DEFAULT 0,
            last_login DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_users_mobile (mobile),
            UNIQUE KEY uk_users_handle (username_handle),
            INDEX idx_users_account_type (account_type),
            INDEX idx_users_location (state_code, district_code),
            INDEX idx_users_status_vis (status, profile_visibility)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS contact_reveals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            ip_address VARCHAR(45) NULL,
            entity_type VARCHAR(30) NOT NULL,
            entity_id INT NOT NULL,
            target_name VARCHAR(200) NOT NULL DEFAULT '',
            target_url VARCHAR(255) NOT NULL DEFAULT '',
            revealed_mobile VARCHAR(120) NULL,
            revealed_email VARCHAR(180) NULL,
            revealed_date DATE NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cr_user_date (user_id, revealed_date),
            INDEX idx_cr_user_entity_date (user_id, entity_type, entity_id, revealed_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function ensure_village_pincode_indexes(PDO $pdo): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        if ($pdo->query("SHOW TABLES LIKE 'village'")->fetch()) {
            $hasPk = (bool)$pdo->query("SHOW INDEX FROM village WHERE Key_name = 'PRIMARY'")->fetch();
            if (!$hasPk) {
                $badIds = (int)$pdo->query("
                    SELECT COUNT(*) FROM village WHERE id IS NULL OR id <= 0
                ")->fetchColumn();
                $dupCount = (int)$pdo->query("
                    SELECT COUNT(*) FROM (SELECT id FROM village GROUP BY id HAVING COUNT(*) > 1) t
                ")->fetchColumn();
                if ($badIds > 0 || $dupCount > 0) {
                    $pdo->exec("SET @ln_vid = 0");
                    $pdo->exec("UPDATE village SET id = (@ln_vid := @ln_vid + 1)");
                }
                $pdo->exec("ALTER TABLE village MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)");
            }

            $existingIdx = [];
            foreach ($pdo->query("SHOW INDEX FROM village") as $row) {
                if (!empty($row['Key_name'])) {
                    $existingIdx[(string)$row['Key_name']] = true;
                }
            }
            $alterParts = [];
            $wanted = [
                'idx_village_pincode' => 'ADD INDEX idx_village_pincode (pincode)',
                'idx_village_block' => 'ADD INDEX idx_village_block (block_code, pincode)',
                'idx_village_district' => 'ADD INDEX idx_village_district (district_code, pincode)',
                'idx_village_state' => 'ADD INDEX idx_village_state (state_code, pincode)',
                'idx_village_code' => 'ADD INDEX idx_village_code (village_code)',
                'idx_village_name' => 'ADD INDEX idx_village_name (village(40))',
            ];
            foreach ($wanted as $idxName => $ddl) {
                if (!isset($existingIdx[$idxName])) {
                    $alterParts[] = $ddl;
                }
            }
            if (!empty($alterParts)) {
                $pdo->exec('ALTER TABLE village ' . implode(', ', $alterParts));
            }
        }

        if ($pdo->query("SHOW TABLES LIKE 'panchayat_village'")->fetch()) {
            $hasPvIdx = (bool)$pdo->query("SHOW INDEX FROM panchayat_village WHERE Key_name = 'idx_pv_village_code'")->fetch();
            if (!$hasPvIdx) {
                $pdo->exec("ALTER TABLE panchayat_village ADD INDEX idx_pv_village_code (village_code)");
            }
        }
    } catch (Throwable $e) {
        // Non-fatal if index exists concurrently or permissions differ
    }
}
