<?php
// includes/functions.php - Helper utility functions for LocalNeta (English UI)

function e(?string $string): string {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

function time_ago_en(string $datetime): string {
    $time = strtotime($datetime);
    if (!$time) return 'Recently';
    $diff = time() - $time;

    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' mins ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    if ($diff < 2592000) return floor($diff / 604800) . ' weeks ago';
    return date('d M, Y', $time);
}

function get_verification_badge_html(string $badgeType, bool $isVerified = true): string {
    if (!$isVerified) {
        return '<span class="badge-tag badge-unverified"><i class="fas fa-clock"></i> Unverified</span>';
    }
    if ($badgeType === 'elected_verified') {
        return '<span class="badge-tag badge-elected" title="Verified Elected Representative"><i class="fas fa-check-circle"></i> Elected & Verified</span>';
    }
    return '<span class="badge-tag badge-verified" title="Identity Verified"><i class="fas fa-shield-alt"></i> ID Verified</span>';
}

function get_default_leader_types(): array {
    return [
        ['slug' => 'minister-central', 'name' => 'Minister (Central)', 'group_no' => 1, 'group_name' => 'Professionals & Skilled Personnel', 'governance_tier' => 'Parliament & Union', 'badge_class' => 'bg-rose', 'icon_class' => 'fa-crown', 'sort_order' => 1],
        ['slug' => 'mp-lok-sabha', 'name' => 'MP (Lok Sabha)', 'group_no' => 1, 'group_name' => 'Professionals & Skilled Personnel', 'governance_tier' => 'Parliament & Union', 'badge_class' => 'bg-rose', 'icon_class' => 'fa-landmark', 'sort_order' => 2],
        ['slug' => 'mp-rajya-sabha', 'name' => 'MP (Rajya Sabha)', 'group_no' => 1, 'group_name' => 'Professionals & Skilled Personnel', 'governance_tier' => 'Parliament & Union', 'badge_class' => 'bg-emerald', 'icon_class' => 'fa-landmark', 'sort_order' => 3],
        ['slug' => 'minister-state', 'name' => 'Minister (State)', 'group_no' => 1, 'group_name' => 'Professionals & Skilled Personnel', 'governance_tier' => 'State Legislature', 'badge_class' => 'bg-indigo', 'icon_class' => 'fa-award', 'sort_order' => 4],
        ['slug' => 'mla', 'name' => 'MLA', 'group_no' => 1, 'group_name' => 'Professionals & Skilled Personnel', 'governance_tier' => 'State Legislature', 'badge_class' => 'bg-indigo', 'icon_class' => 'fa-building-columns', 'sort_order' => 5],
        ['slug' => 'mlc', 'name' => 'MLC', 'group_no' => 1, 'group_name' => 'Professionals & Skilled Personnel', 'governance_tier' => 'State Legislature', 'badge_class' => 'bg-indigo', 'icon_class' => 'fa-scroll', 'sort_order' => 6],
        ['slug' => 'jila-parishad-adhyaksh', 'name' => 'Jila Parishad Adhyaksh', 'group_no' => 1, 'group_name' => 'Professionals & Skilled Personnel', 'governance_tier' => 'District (Zila)', 'badge_class' => 'bg-amber', 'icon_class' => 'fa-landmark-flag', 'sort_order' => 7],
        ['slug' => 'jila-parishad-upadhyaksh', 'name' => 'Jila Parishad Upadhyaksh', 'group_no' => 1, 'group_name' => 'Professionals & Skilled Personnel', 'governance_tier' => 'District (Zila)', 'badge_class' => 'bg-amber', 'icon_class' => 'fa-user-shield', 'sort_order' => 8],
        ['slug' => 'jila-parishad-member', 'name' => 'Jila Parishad Member', 'group_no' => 1, 'group_name' => 'Professionals & Skilled Personnel', 'governance_tier' => 'District (Zila)', 'badge_class' => 'bg-amber', 'icon_class' => 'fa-people-group', 'sort_order' => 9],
        ['slug' => 'pramukh', 'name' => 'Pramukh', 'group_no' => 1, 'group_name' => 'Professionals & Skilled Personnel', 'governance_tier' => 'Block / Samiti', 'badge_class' => 'bg-blue', 'icon_class' => 'fa-sitemap', 'sort_order' => 10],
        ['slug' => 'up-pramukh', 'name' => 'Up Pramukh', 'group_no' => 1, 'group_name' => 'Professionals & Skilled Personnel', 'governance_tier' => 'Block / Samiti', 'badge_class' => 'bg-blue', 'icon_class' => 'fa-user-plus', 'sort_order' => 11],
        ['slug' => 'panchayat-samiti-member', 'name' => 'Panchayat Samiti Member', 'group_no' => 1, 'group_name' => 'Professionals & Skilled Personnel', 'governance_tier' => 'Block / Samiti', 'badge_class' => 'bg-blue', 'icon_class' => 'fa-users-gear', 'sort_order' => 12],
        ['slug' => 'chairman', 'name' => 'Chairman', 'group_no' => 1, 'group_name' => 'Professionals & Skilled Personnel', 'governance_tier' => 'Urban / Statutory Body', 'badge_class' => 'bg-indigo', 'icon_class' => 'fa-user-tie', 'sort_order' => 13],
        ['slug' => 'vice-chairman', 'name' => 'Vice Chairman', 'group_no' => 1, 'group_name' => 'Professionals & Skilled Personnel', 'governance_tier' => 'Urban / Statutory Body', 'badge_class' => 'bg-indigo', 'icon_class' => 'fa-user-tie', 'sort_order' => 14],
        ['slug' => 'mukhiya', 'name' => 'Mukhiya', 'group_no' => 1, 'group_name' => 'Professionals & Skilled Personnel', 'governance_tier' => 'Gram Panchayat', 'badge_class' => 'bg-emerald', 'icon_class' => 'fa-user-check', 'sort_order' => 15],
        ['slug' => 'up-mukhiya', 'name' => 'Up Mukhiya', 'group_no' => 1, 'group_name' => 'Professionals & Skilled Personnel', 'governance_tier' => 'Gram Panchayat', 'badge_class' => 'bg-emerald', 'icon_class' => 'fa-user-plus', 'sort_order' => 16],
        ['slug' => 'sarpanch', 'name' => 'Sarpanch', 'group_no' => 1, 'group_name' => 'Professionals & Skilled Personnel', 'governance_tier' => 'Gram Kachehri', 'badge_class' => 'bg-purple', 'icon_class' => 'fa-gavel', 'sort_order' => 17],
        ['slug' => 'up-sarpanch', 'name' => 'Up Sarpanch', 'group_no' => 1, 'group_name' => 'Professionals & Skilled Personnel', 'governance_tier' => 'Gram Kachehri', 'badge_class' => 'bg-purple', 'icon_class' => 'fa-scale-unbalanced', 'sort_order' => 18],
        ['slug' => 'panch', 'name' => 'Panch', 'group_no' => 1, 'group_name' => 'Professionals & Skilled Personnel', 'governance_tier' => 'Gram Kachehri', 'badge_class' => 'bg-purple', 'icon_class' => 'fa-scale-balanced', 'sort_order' => 19],
        ['slug' => 'ward-member', 'name' => 'Ward Member', 'group_no' => 1, 'group_name' => 'Professionals & Skilled Personnel', 'governance_tier' => 'Gram Panchayat', 'badge_class' => 'bg-emerald', 'icon_class' => 'fa-house-user', 'sort_order' => 20],
        ['slug' => 'nagar-nigam-ward', 'name' => 'Nagar Nigam Ward', 'group_no' => 1, 'group_name' => 'Professionals & Skilled Personnel', 'governance_tier' => 'Urban Local Body', 'badge_class' => 'bg-blue', 'icon_class' => 'fa-city', 'sort_order' => 21],
        ['slug' => 'nagar-parishad-ward', 'name' => 'Nagar Parishad Ward', 'group_no' => 1, 'group_name' => 'Professionals & Skilled Personnel', 'governance_tier' => 'Urban Local Body', 'badge_class' => 'bg-blue', 'icon_class' => 'fa-city', 'sort_order' => 22],
        ['slug' => 'nagar-panchayat-ward', 'name' => 'Nagar Panchayat Ward', 'group_no' => 1, 'group_name' => 'Professionals & Skilled Personnel', 'governance_tier' => 'Urban Local Body', 'badge_class' => 'bg-blue', 'icon_class' => 'fa-building', 'sort_order' => 23],
        ['slug' => 'ex-politician', 'name' => 'Ex-Politician', 'group_no' => 1, 'group_name' => 'Professionals & Skilled Personnel', 'governance_tier' => 'Former Representative', 'badge_class' => 'bg-purple', 'icon_class' => 'fa-clock-rotate-left', 'sort_order' => 24],
        ['slug' => 'political-party-office', 'name' => 'Political Party Office', 'group_no' => 2, 'group_name' => 'Businesses & Establishments', 'governance_tier' => 'Party Establishment', 'badge_class' => 'bg-amber', 'icon_class' => 'fa-flag', 'sort_order' => 25],
    ];
}

function normalize_leader_category_slug(?string $category): string {
    $slug = strtolower(trim((string)$category));
    $aliases = [
        'samiti' => 'panchayat-samiti-member',
        'zila_parishad' => 'jila-parishad-member',
        'zila-parishad-member' => 'jila-parishad-member',
    ];
    return $aliases[$slug] ?? $slug;
}

function get_leader_types(?PDO $pdo = null, bool $grouped = false, string $sort = 'hierarchy'): array {
    static $cache = [];
    $cacheKey = ($pdo ? spl_object_id($pdo) : 0) . ':' . $sort;

    if (!isset($cache[$cacheKey])) {
        $rows = null;
        if ($pdo instanceof PDO) {
            try {
                $orderSql = $sort === 'alpha'
                    ? 'ORDER BY group_no ASC, name ASC'
                    : 'ORDER BY group_no ASC, sort_order ASC, name ASC';
                $stmt = $pdo->query("SELECT slug, name, group_no, group_name, governance_tier, badge_class, icon_class, sort_order FROM leader_types WHERE status = 'ACTIVE' {$orderSql}");
                $fetched = $stmt ? $stmt->fetchAll() : [];
                if (!empty($fetched)) {
                    $rows = $fetched;
                }
            } catch (Throwable $e) {
                $rows = null;
            }
        }
        if ($rows === null) {
            $rows = get_default_leader_types();
            if ($sort === 'alpha') {
                usort($rows, static function (array $a, array $b): int {
                    return [$a['group_no'], $a['name']] <=> [$b['group_no'], $b['name']];
                });
            }
        }
        $cache[$cacheKey] = $rows;
    }

    $items = $cache[$cacheKey];
    if (!$grouped) {
        return $items;
    }

    $groups = [];
    foreach ($items as $item) {
        $gNo = (int)$item['group_no'];
        if (!isset($groups[$gNo])) {
            $groups[$gNo] = [
                'group_no' => $gNo,
                'group_name' => $item['group_name'],
                'items' => [],
            ];
        }
        $groups[$gNo]['items'][] = $item;
    }
    ksort($groups);
    return $groups;
}

function get_leader_type_meta(string $category, ?PDO $pdo = null): array {
    $norm = normalize_leader_category_slug($category);
    if ($norm === 'mp') {
        return [
            'slug' => 'mp',
            'name' => 'MP (Parliament)',
            'group_no' => 1,
            'group_name' => 'Professionals & Skilled Personnel',
            'governance_tier' => 'Parliament & Union',
            'badge_class' => 'bg-rose',
            'icon_class' => 'fa-landmark',
        ];
    }

    foreach (get_leader_types($pdo) as $item) {
        if ($item['slug'] === $norm) {
            return $item;
        }
    }

    $label = ucwords(str_replace(['-', '_'], ' ', $norm ?: 'Representative'));
    return [
        'slug' => $norm,
        'name' => $label,
        'group_no' => 1,
        'group_name' => 'Professionals & Skilled Personnel',
        'governance_tier' => 'Civic Representative',
        'badge_class' => 'bg-indigo',
        'icon_class' => 'fa-user-tie',
    ];
}

function get_district_and_higher_leader_slugs(): array {
    return [
        'minister-central',
        'mp-lok-sabha',
        'mp-rajya-sabha',
        'minister-state',
        'mla',
        'mlc',
        'jila-parishad-adhyaksh',
        'jila-parishad-upadhyaksh',
        'jila-parishad-member',
        'chairman',
        'vice-chairman',
        'ex-politician',
        'political-party-office',
        'zila_parishad',
        'mp',
    ];
}

function get_leader_category_order_sql(string $col = 'l.category'): string {
    return "CASE {$col}
        WHEN 'mukhiya' THEN 1
        WHEN 'up-mukhiya' THEN 2
        WHEN 'sarpanch' THEN 3
        WHEN 'up-sarpanch' THEN 4
        WHEN 'panch' THEN 5
        WHEN 'ward-member' THEN 6
        WHEN 'pramukh' THEN 7
        WHEN 'up-pramukh' THEN 8
        WHEN 'panchayat-samiti-member' THEN 9
        WHEN 'samiti' THEN 9
        WHEN 'nagar-nigam-ward' THEN 10
        WHEN 'nagar-parishad-ward' THEN 11
        WHEN 'nagar-panchayat-ward' THEN 12
        WHEN 'chairman' THEN 13
        WHEN 'vice-chairman' THEN 14
        WHEN 'jila-parishad-adhyaksh' THEN 15
        WHEN 'jila-parishad-upadhyaksh' THEN 16
        WHEN 'jila-parishad-member' THEN 17
        WHEN 'zila_parishad' THEN 17
        WHEN 'mla' THEN 18
        WHEN 'mlc' THEN 19
        WHEN 'minister-state' THEN 20
        WHEN 'mp-lok-sabha' THEN 21
        WHEN 'mp-rajya-sabha' THEN 22
        WHEN 'mp' THEN 23
        WHEN 'minister-central' THEN 24
        WHEN 'ex-politician' THEN 25
        WHEN 'political-party-office' THEN 26
        ELSE 99 END";
}

function get_category_badge(string $category, ?PDO $pdo = null): string {
    $cat = get_leader_type_meta($category, $pdo);
    return '<span class="cat-pill ' . e($cat['badge_class']) . '" title="' . e($cat['group_name'] . ' • ' . $cat['governance_tier']) . '">'
        . e($cat['name']) . '</span>';
}

function format_rupees_lakh(float $amount): string {
    if ($amount <= 0) return 'Cost under estimation';
    if ($amount < 1.0) {
        return '₹' . number_format($amount * 100000) . ' INR';
    }
    return '₹' . number_format($amount, 2) . ' Lakh';
}

function get_status_badge(string $status, string $label): string {
    $classMap = [
        'completed' => 'status-success',
        'in_progress' => 'status-warning',
        'sanctioned' => 'status-info',
        'verification_pending' => 'status-muted',
        'resolved' => 'status-success',
        'under_review' => 'status-warning',
        'notified' => 'status-info',
        'new' => 'status-danger'
    ];
    $cls = $classMap[$status] ?? 'status-muted';
    return '<span class="status-chip ' . $cls . '"><span class="status-dot"></span> ' . e($label) . '</span>';
}

function get_site_url(string $path = ''): string {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $scriptDir = preg_replace('#/(?:api|ppms(?:/.*)?|political-party(?:/.*)?|mp(?:/.*)?|mlc(?:/.*)?|user(?:/.*)?|pincode(?:/.*)?)$#', '', $scriptDir);
    $base = rtrim($scriptDir, '/');
    $trimmedPath = ltrim($path, '/');
    if ($trimmedPath === '') {
        return $base;
    }
    return $base . '/' . $trimmedPath;
}

function get_pincode_page_url(string $pincode = ''): string {
    $cleanPin = preg_replace('/\D+/', '', trim($pincode)) ?? '';
    if (preg_match('/^[1-9][0-9]{5}$/', $cleanPin)) {
        return get_site_url('pincode/' . $cleanPin);
    }
    return get_site_url('pincode');
}

function get_user_profile_url(?string $handle, ?int $id = null): string {
    $cleanHandle = ltrim(trim((string)$handle), '@');
    if ($cleanHandle !== '') {
        return get_site_url('user/' . rawurlencode($cleanHandle));
    }
    if ($id !== null && $id > 0) {
        return get_site_url('user-profile?id=' . $id);
    }
    return get_site_url('leaders');
}

function clean_party_display_text(?string $text): string {
    if ($text === null) {
        return '';
    }
    $text = str_replace("\xC2\xA0", ' ', $text);
    return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
}

function clean_party_display_name(?string $name): string {
    $clean = clean_party_display_text($name);
    return trim(preg_replace('/^\d+\.\s*/u', '', $clean) ?? $clean);
}

function generate_party_slug(string $text, int $maxLength = 180): string {
    $clean = clean_party_display_name($text);
    // Remove apostrophes/annotation marks before slugifying punctuation
    $clean = str_replace(["'", "’", "`", "*", "#"], '', $clean);
    $clean = str_replace('&', ' and ', $clean);
    // Transliterate any remaining accented/unicode punctuation where possible
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $clean);
        if (is_string($converted) && $converted !== '') {
            $clean = $converted;
        }
    }
    $slug = strtolower($clean);
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '', '-');
    if (strlen($slug) > $maxLength) {
        $slug = rtrim(substr($slug, 0, $maxLength), '-');
    }
    return $slug;
}

function get_party_symbol_image(?string $symbolText): ?array {
    $raw = clean_party_display_text($symbolText);
    if ($raw === '' || in_array(strtoupper($raw), ['-', 'NA', 'N/A', 'NONE', 'NIL'], true)) {
        return null;
    }

    // Extract clean ECI symbol title without dispute/para-10 footnotes for matching PNG filenames
    $short = preg_replace('/[\*#\[].*$/u', '', $raw) ?? $raw;
    $short = preg_replace('/,\s*restricted\b.*$/iu', '', $short) ?? $short;
    $short = trim($short, " \t\n\r\0\x0B.,");
    if ($short === '') {
        $short = $raw;
    }

    $normalized = strtolower(str_replace('&', ' and ', $short));
    $key = trim(preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? '', '_');

    $aliases = [
        'hammer_sickle_and_star' => 'hummer_sickle_and_star.png',
    ];

    $filename = $aliases[$key] ?? ($key !== '' ? $key . '.png' : null);
    $symDir = __DIR__ . '/../assets/images/symbols/';
    $url = null;
    $matchedFile = null;

    if ($filename !== null && is_file($symDir . $filename)) {
        $matchedFile = $filename;
        $url = get_site_url('assets/images/symbols/' . $filename);
    }

    return [
        'label' => $short,
        'raw' => $raw,
        'filename' => $matchedFile,
        'url' => $url,
    ];
}

function get_party_page_url(string $slug, ?int $id = null): string {
    $cleanSlug = trim($slug);
    if ($cleanSlug !== '') {
        return get_site_url('political-party/' . rawurlencode($cleanSlug));
    }
    if ($id !== null && $id > 0) {
        return get_site_url('political-party?id=' . $id);
    }
    return get_site_url('parties');
}

function clean_mp_display_name(?string $name): string {
    return clean_party_display_text($name);
}

function strip_mp_honorific_prefix(string $name): string {
    $clean = clean_mp_display_name($name);
    if ($clean === '') {
        return '';
    }
    $stripped = preg_replace(
        '/^(?:(?:Shri|Smt\.?|Dr\.?|Prof\.?|Ms\.?|Mrs\.?|Mr\.?|Km\.?|Kumari|Adv\.?|Thiru|Com\.)\s+)+/iu',
        '',
        $clean
    );
    $stripped = trim((string)$stripped);
    return $stripped !== '' ? $stripped : $clean;
}

function generate_mp_slug(string $text, int $maxLength = 140, bool $stripHonorific = true): string {
    $clean = $stripHonorific ? strip_mp_honorific_prefix($text) : clean_mp_display_name($text);
    $clean = str_replace(["'", "’", "`", "*", "#"], '', $clean);
    $clean = str_replace('&', ' and ', $clean);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $clean);
        if (is_string($converted) && $converted !== '') {
            $clean = $converted;
        }
    }
    $slug = strtolower($clean);
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '', '-');
    if (strlen($slug) > $maxLength) {
        $slug = rtrim(substr($slug, 0, $maxLength), '-');
    }
    return $slug;
}

function get_mp_page_url(?string $slug, ?int $id = null): string {
    $cleanSlug = trim((string)$slug);
    if ($cleanSlug !== '') {
        return get_site_url('mp/' . rawurlencode($cleanSlug));
    }
    if ($id !== null && $id > 0) {
        return get_site_url('mp?id=' . $id);
    }
    return get_site_url('elections#mp-directory');
}

function clean_mlc_display_name(?string $name): string {
    return clean_party_display_text($name);
}

function strip_mlc_honorific_prefix(string $name): string {
    $clean = clean_mlc_display_name($name);
    if ($clean === '') {
        return '';
    }
    $stripped = preg_replace(
        '/^(?:\(?\s*(?:Sri|Shri|Sh|Smt|Dr|Prof|Ms|Mrs|Mr|Km|Kumari|Adv|Er|Thiru|Com)\.?\s*\)?\s+)+/iu',
        '',
        $clean
    );
    $stripped = trim((string)$stripped);
    return $stripped !== '' ? $stripped : $clean;
}

function get_mlc_state_slug_prefix(?string $stateCode, string $state = ''): string {
    $code = ltrim(trim((string)$stateCode), '0');
    $byCode = [
        '9'  => 'up',
        '10' => 'br',
        '28' => 'ap',
        '27' => 'mh',
        '29' => 'ka',
        '36' => 'ts',
    ];
    if ($code !== '' && isset($byCode[$code])) {
        return $byCode[$code];
    }

    $stateNorm = strtolower(trim($state));
    $byName = [
        'uttar pradesh' => 'up',
        'bihar' => 'br',
        'andhra pradesh' => 'ap',
        'maharashtra' => 'mh',
        'karnataka' => 'ka',
        'telangana' => 'ts',
    ];
    if ($stateNorm !== '' && isset($byName[$stateNorm])) {
        return $byName[$stateNorm];
    }

    $cleanState = preg_replace('/[^a-z]/', '', $stateNorm) ?? '';
    return strlen($cleanState) >= 2 ? substr($cleanState, 0, 2) : 'in';
}

function generate_mlc_slug(string $text, int $maxLength = 140, bool $stripHonorific = true): string {
    $clean = $stripHonorific ? strip_mlc_honorific_prefix($text) : clean_mlc_display_name($text);
    $clean = str_replace(["'", "’", "`", "*", "#"], '', $clean);
    $clean = str_replace('&', ' and ', $clean);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $clean);
        if (is_string($converted) && $converted !== '') {
            $clean = $converted;
        }
    }
    $slug = strtolower($clean);
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '', '-');
    if (strlen($slug) > $maxLength) {
        $slug = rtrim(substr($slug, 0, $maxLength), '-');
    }
    return $slug;
}

function generate_mlc_prefixed_slug(string $memberName, ?string $stateCode = '', string $state = '', int $maxLength = 140): string {
    $prefix = get_mlc_state_slug_prefix($stateCode, $state);
    $nameMax = max(20, $maxLength - strlen($prefix) - 1);
    $nameSlug = generate_mlc_slug($memberName, $nameMax, true);
    if ($nameSlug === '') {
        return $prefix;
    }
    if (str_starts_with($nameSlug, $prefix . '-')) {
        return $nameSlug;
    }
    return $prefix . '-' . $nameSlug;
}

function get_mlc_page_url(?string $slug, ?int $id = null): string {
    $cleanSlug = trim((string)$slug);
    if ($cleanSlug !== '') {
        return get_site_url('mlc/' . rawurlencode($cleanSlug));
    }
    if ($id !== null && $id > 0) {
        return get_site_url('mlc?id=' . $id);
    }
    return get_site_url('elections#mlc-directory');
}

function format_civic_title(?string $text): string {
    $clean = trim(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', (string)$text)) ?? '');
    if ($clean === '') {
        return '';
    }
    // Preserve strings that already contain meaningful mixed case (unless >80% uppercase letters)
    $lettersOnly = preg_replace('/[^A-Za-z]/', '', $clean) ?? '';
    $upperOnly = preg_replace('/[^A-Z]/', '', $clean) ?? '';
    if ($lettersOnly !== '' && (strlen($upperOnly) / strlen($lettersOnly)) >= 0.75) {
        $titled = mb_convert_case(mb_strtolower($clean, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        // Keep dotted initials uppercase (e.g. C.N or C. N. -> C.N)
        $titled = preg_replace_callback('/\b([A-Za-z])\.([A-Za-z])\b/', static fn($m) => strtoupper($m[1]) . '.' . strtoupper($m[2]), $titled);
        return $titled;
    }
    return $clean;
}

function resolve_mukhiya_block_name(PDO $pdo, string $districtCode, string $opBlockName): ?string {
    static $distBlocksCache = [];
    $dcInt = (int)$districtCode;
    $opBlockClean = trim($opBlockName);
    if ($dcInt <= 0 || $opBlockClean === '') {
        return null;
    }

    if (!isset($distBlocksCache[$dcInt])) {
        $distBlocksCache[$dcInt] = [];
        try {
            if ($pdo->query("SHOW TABLES LIKE 'mukhiya'")->fetch()) {
                $stmt = $pdo->prepare("SELECT DISTINCT TRIM(block_name) FROM mukhiya WHERE district_code = ? AND TRIM(block_name) <> ''");
                $stmt->execute([$dcInt]);
                $distBlocksCache[$dcInt] = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
        } catch (Throwable $e) {
            $distBlocksCache[$dcInt] = [];
        }
    }

    $candidates = $distBlocksCache[$dcInt];
    if (empty($candidates)) {
        return null;
    }

    // 1. Exact case-insensitive match
    foreach ($candidates as $cb) {
        if (strcasecmp($cb, $opBlockClean) === 0) {
            return $cb;
        }
    }

    // 2. Explicit district/block alias table for HQ/renamed blocks between op_sdb and SEC Bihar mukhiya list
    $opLower = strtolower($opBlockClean);
    $explicitMap = [
        212 => [
            'patna rural' => 'Patna Sadar',
            'dinapur-cum-khagaul' => 'Danapur',
            'phulwari' => 'Phulwari Sharif',
            'fatwah' => 'Fatuha',
            'mokameh' => 'Mokama',
        ],
        218 => [
            'revelganj' => 'Rivilganj',
            'ishupur' => 'Ishuapur',
            'mashrakh' => 'Mashrak',
        ],
        207 => [
            'munger' => 'Sadar Munger',
            'kharagpur' => 'Haveli Kharagpur',
        ],
        211 => [
            'bagaha' => 'Bagaha - 2',
        ],
        214 => [
            'purnia east' => 'Purnia Purw',
            'barhara' => 'Barahara Kothi',
        ],
        222 => [
            'siwan' => 'Siwan sadar',
        ],
    ];
    if (isset($explicitMap[$dcInt][$opLower])) {
        $mapped = $explicitMap[$dcInt][$opLower];
        foreach ($candidates as $cb) {
            if (strcasecmp($cb, $mapped) === 0) {
                return $cb;
            }
        }
    }

    // 3. Phonetic + punctuation compact normalization
    $foldBlock = static function (string $s): string {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]/', '', $s) ?? '';
        $s = str_replace(['aa', 'ee', 'oo', 'ou', 'ri', 'ph', 'w'], ['a', 'i', 'u', 'u', 'i', 'f', 'v'], $s);
        return $s;
    };

    $targetFold = $foldBlock($opBlockClean);
    foreach ($candidates as $cb) {
        if ($foldBlock($cb) === $targetFold) {
            return $cb;
        }
    }

    // 4. Tight edit-distance fallback inside same district only
    $best = null;
    $bestDist = 999;
    foreach ($candidates as $cb) {
        $d = levenshtein(strtolower($opBlockClean), strtolower($cb));
        if ($d < $bestDist) {
            $bestDist = $d;
            $best = $cb;
        }
    }
    $maxAllowed = max(2, (int)floor(strlen($opBlockClean) * 0.35));
    if ($best !== null && $bestDist <= $maxAllowed) {
        return $best;
    }

    return null;
}

function get_district_politician_where(PDO $pdo, string $districtCode, string $districtName): array {
    $terms = [];
    $cleanDist = trim($districtName);
    if ($cleanDist !== '') {
        $terms[] = $cleanDist;
    }

    // District HQ / alternative official spellings mapped to Bihar Assembly/Council constituency names
    $distAliases = [
        'saran' => ['Chapra', 'Chhapra'],
        'bhojpur' => ['Arrah', 'Ara'],
        'rohtas' => ['Sasaram'],
        'kaimur (bhabua)' => ['Kaimur', 'Bhabua', 'Chainpur', 'Mohania'],
        'kaimur' => ['Bhabua'],
        'purbi champaran' => ['Motihari', 'East Champaran'],
        'pashchim champaran' => ['Bettiah', 'West Champaran', 'Valmiki Nagar'],
        'vaishali' => ['Hajipur'],
        'nalanda' => ['Biharsharif'],
        'aurangabad' => ['Aurangabad'],
    ];
    $dKey = strtolower($cleanDist);
    if (isset($distAliases[$dKey])) {
        $terms = array_merge($terms, $distAliases[$dKey]);
    }

    if ($districtCode !== '') {
        try {
            $bStmt = $pdo->prepare("SELECT DISTINCT block FROM op_sdb WHERE district_code = ? AND status = 'ACTIVE'");
            $bStmt->execute([$districtCode]);
            foreach ($bStmt->fetchAll(PDO::FETCH_COLUMN) as $bName) {
                $bClean = trim((string)$bName);
                if (strlen($bClean) >= 4) {
                    $terms[] = $bClean;
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    $terms = array_values(array_unique(array_filter(array_map('trim', $terms))));
    if (empty($terms)) {
        return ['1=0', []];
    }

    $conds = [];
    $params = [];
    foreach ($terms as $t) {
        $lt = strtolower($t);
        $conds[] = "LOWER(TRIM(c_name)) = ? OR LOWER(TRIM(c_name)) = ? OR LOWER(c_name) LIKE ? OR LOWER(c_name) LIKE ? OR LOWER(c_name) LIKE ? OR LOWER(c_name) LIKE ? OR LOWER(c_name) LIKE ? OR LOWER(c_name) LIKE ?";
        array_push(
            $params,
            $lt,
            '(' . $lt . ')',
            $lt . ' (%',
            $lt . '(%',
            $lt . ' %',
            '%, ' . $lt,
            '%, ' . $lt . ',%',
            '%& ' . $lt
        );
    }

    return ['(' . implode(' OR ', $conds) . ')', $params];
}

function resolve_party_card_meta(PDO $pdo, ?string $partyRaw): ?array {
    static $cache = [];
    $cleanRaw = clean_party_display_text($partyRaw);
    if ($cleanRaw === '') {
        return null;
    }

    $key = strtolower($cleanRaw);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stripped = trim(preg_replace('/\s*\((?:nda|upa|india)\)\s*$/i', '', $cleanRaw) ?? $cleanRaw);
    $normKey = strtolower($stripped);

    if (in_array($normKey, ['ind', 'ind.', 'independent', 'independent / public servant', 'nom.', 'nominated', "teachers' group (non-political)", 'teachers’ group (non-political)'], true)) {
        $dispLabel = 'Independent';
        if ($normKey === 'nom.' || $normKey === 'nominated') {
            $dispLabel = 'Nominated';
        } elseif (str_contains($normKey, 'teachers')) {
            $dispLabel = "Teachers' Group (Non-Political)";
        }
        $res = [
            'is_independent' => true,
            'name' => $dispLabel,
            'name_hi' => '',
            'short' => $cleanRaw,
            'slug' => null,
            'url' => null,
            'symbol_label' => null,
            'symbol_url' => null,
        ];
        $cache[$key] = $res;
        return $res;
    }

    $abbrToSlug = [
        'bjp' => 'bharatiya-janata-party',
        'bharatiya janata party' => 'bharatiya-janata-party',
        'inc' => 'indian-national-congress',
        'indian national congress' => 'indian-national-congress',
        'rjd' => 'rashtriya-janata-dal',
        'rashtriya janata dal' => 'rashtriya-janata-dal',
        'rashtriya janta dal' => 'rashtriya-janata-dal',
        'jd(u)' => 'janata-dal-united',
        'j.d.(u)' => 'janata-dal-united',
        'j.d(u)' => 'janata-dal-united',
        'jdu' => 'janata-dal-united',
        'janata dal (united)' => 'janata-dal-united',
        'janta dal (united)' => 'janata-dal-united',
        'ljsp' => 'lok-janshakti-party-ram-vilas',
        'ljp(rv)' => 'lok-janshakti-party-ram-vilas',
        'lok jan shakti party (ram vilas)' => 'lok-janshakti-party-ram-vilas',
        'lok janshakti party (ram vilas)' => 'lok-janshakti-party-ram-vilas',
        'lok janshakti party - rv' => 'lok-janshakti-party-ram-vilas',
        'ljp' => 'lok-jan-shakti-party',
        'lok jan shakti party' => 'lok-jan-shakti-party',
        'cpi(ml)' => 'communist-party-of-india-marxist-leninist-liberation',
        'cpi(ml)l' => 'communist-party-of-india-marxist-leninist-liberation',
        'communist party of india (marxist-leninist) liberation' => 'communist-party-of-india-marxist-leninist-liberation',
        'communist party of india - ml (lib.)' => 'communist-party-of-india-marxist-leninist-liberation',
        'hindustani awam mourcha' => 'hindustani-awam-morcha-secular',
        'hindustani awam morcha (secular)' => 'hindustani-awam-morcha-secular',
        'hindustani awamee morcha (secular)' => 'hindustani-awam-morcha-secular',
        'ham' => 'hindustani-awam-morcha-secular',
        'apna dal (s) party' => 'apna-dal-soneylal',
        'apna dal (s)' => 'apna-dal-soneylal',
        'apna dal (soneylal)' => 'apna-dal-soneylal',
        'nishad party' => 'nirbal-indian-shoshit-hamara-aam-dal',
        'all india majlis-e-ittehadul muslimeen' => 'all-india-majlis-e-ittehadul-muslimeen',
        'all india majlis-e- ittehadul muslimeen' => 'all-india-majlis-e-ittehadul-muslimeen',
        'aimim' => 'all-india-majlis-e-ittehadul-muslimeen',
        'aap' => 'aam-aadmi-party',
        'aam aadmi party' => 'aam-aadmi-party',
        'bsp' => 'bahujan-samaj-party',
        'bahujan samaj party' => 'bahujan-samaj-party',
        'sp' => 'samajwadi-party',
        'samajwadi party' => 'samajwadi-party',
        'aitc' => 'all-india-trinamool-congress',
        'all india trinamool congress' => 'all-india-trinamool-congress',
        'dmk' => 'dravida-munnetra-kazhagam',
        'dravida munnetra kazhagam' => 'dravida-munnetra-kazhagam',
        'aiadmk' => 'all-india-anna-dravida-munnetra-kazhagam',
        'tdp' => 'telugu-desam',
        'telugu desam party' => 'telugu-desam',
        'ysrcp' => 'yuvajana-sramika-rythu-congress-party',
        'bjd' => 'biju-janata-dal',
        'jmm' => 'jharkhand-mukti-morcha',
        'ncp' => 'nationalist-congress-party',
        'ncp-sp' => 'nationalist-congress-party-sharadchandra-pawar',
        'ncp-scp' => 'nationalist-congress-party-sharadchandra-pawar',
        'nationalist congress party - sharadchandra pawar' => 'nationalist-congress-party-sharadchandra-pawar',
        'ss' => 'shiv-sena',
        'shiv sena' => 'shiv-sena',
        'ss(ubt)' => 'shiv-sena-uddhav-balasaheb-thackrey',
        'ss-ubt' => 'shiv-sena-uddhav-balasaheb-thackrey',
        'cpi' => 'communist-party-of-india',
        'cpi(m)' => 'communist-party-of-india-marxist',
        'j&knc' => 'jammu-and-kashmir-national-conference',
        'brs' => 'bharat-rashtra-samithi',
        'npp' => 'national-peoples-party',
        'iuml' => 'indian-union-muslim-league',
        'agp' => 'asom-gana-parishad',
        'rld' => 'rashtriya-lok-dal',
        'rlm' => 'rashtriya-lok-morcha',
        'jsp' => 'janasena-party',
        'kc(m)' => 'kerala-congress-m',
        'jd(s)' => 'janata-dal-secular',
        'pmk' => 'pattali-makkal-katchi',
        'dmdk' => 'desiya-murpokku-dravida-kazhagam',
        'zpm' => 'zoram-peoples-movement',
        'zoram peoples movement' => 'zoram-peoples-movement',
        'rpi (atwl)' => 'republican-party-of-india-athawale',
    ];

    $row = null;
    try {
        if ($pdo->query("SHOW TABLES LIKE 'political_party'")->fetch()) {
            $targetSlug = $abbrToSlug[$normKey] ?? generate_party_slug($stripped);
            if ($targetSlug !== '') {
                $s = $pdo->prepare("SELECT id, slug, name, name_hi, symbol, type, status FROM political_party WHERE slug = ? LIMIT 1");
                $s->execute([$targetSlug]);
                $row = $s->fetch() ?: null;
            }
            if (!$row && $stripped !== '') {
                $s = $pdo->prepare("
                    SELECT id, slug, name, name_hi, symbol, type, status
                    FROM political_party
                    WHERE name LIKE ?
                    ORDER BY CASE TRIM(type) WHEN 'National' THEN 1 WHEN 'State' THEN 2 ELSE 3 END ASC,
                             CASE UPPER(TRIM(status)) WHEN 'ACTIVE' THEN 1 ELSE 2 END ASC,
                             id ASC
                    LIMIT 1
                ");
                $s->execute(['%' . $stripped . '%']);
                $row = $s->fetch() ?: null;
            }
        }
    } catch (Throwable $e) {
        $row = null;
    }

    if ($row) {
        $sym = get_party_symbol_image($row['symbol'] ?? '');
        $res = [
            'is_independent' => false,
            'name' => clean_party_display_name($row['name']),
            'name_hi' => clean_party_display_text($row['name_hi'] ?? ''),
            'short' => $cleanRaw,
            'slug' => (string)$row['slug'],
            'url' => get_party_page_url((string)$row['slug'], (int)$row['id']),
            'symbol_label' => $sym['label'] ?? null,
            'symbol_url' => $sym['url'] ?? null,
        ];
    } else {
        $res = [
            'is_independent' => false,
            'name' => $cleanRaw,
            'name_hi' => '',
            'short' => $cleanRaw,
            'slug' => null,
            'url' => null,
            'symbol_label' => null,
            'symbol_url' => null,
        ];
    }

    $cache[$key] = $res;
    return $res;
}

function render_party_chip_html(?array $partyMeta): string {
    if (!$partyMeta) {
        return '';
    }
    $label = e($partyMeta['name'] ?? $partyMeta['short'] ?? '');
    if ($label === '') {
        return '';
    }

    $imgHtml = '';
    if (!empty($partyMeta['symbol_url'])) {
        $imgHtml = '<img src="' . e($partyMeta['symbol_url']) . '" alt="' . e($partyMeta['symbol_label'] ?? $label) . '" style="width:16px; height:16px; object-fit:contain; background:#FFFFFF; border-radius:3px; padding:1px; border:1px solid var(--border-light); flex-shrink:0;">';
    } else {
        $imgHtml = '<i class="fas fa-flag" style="font-size:0.72rem; color:var(--green-main); flex-shrink:0;"></i>';
    }

    $inner = $imgHtml . '<span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">' . $label . '</span>';
    if (!empty($partyMeta['url'])) {
        return '<a href="' . e($partyMeta['url']) . '" style="display:inline-flex; align-items:center; gap:6px; padding:3px 9px; border-radius:var(--radius-full); border:1px solid var(--border-color); background:var(--white-surface); font-size:0.76rem; font-weight:700; color:var(--navy-main); text-decoration:none; max-width:100%;" title="Open Political Party Page">' . $inner . '</a>';
    }
    return '<span style="display:inline-flex; align-items:center; gap:6px; padding:3px 9px; border-radius:var(--radius-full); border:1px solid var(--border-color); background:var(--white-surface); font-size:0.76rem; font-weight:700; color:var(--text-secondary); max-width:100%;">' . $inner . '</span>';
}

function format_eci_date(?string $rawDate): string {
    $clean = clean_party_display_text($rawDate);
    if ($clean === '') {
        return '';
    }
    foreach (['!d-m-y', '!d-m-Y', '!Y-m-d', '!d/m/y', '!d/m/Y'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $clean);
        if ($dt instanceof DateTime && $dt->format(ltrim($fmt, '!')) === $clean) {
            return $dt->format('d M Y');
        }
    }
    return $clean;
}

function mask_single_phone_number(?string $raw): string {
    $clean = trim((string)$raw);
    if ($clean === '') {
        return '';
    }
    $digits = preg_replace('/\D+/', '', $clean) ?? '';
    $len = strlen($digits);
    if ($len === 0) {
        return '';
    }

    if ($len === 10) {
        return substr($digits, 0, 2) . 'XXX XX' . substr($digits, -3);
    }
    if ($len === 12 && str_starts_with($digits, '91')) {
        return '+91 ' . substr($digits, 2, 2) . 'XXX XX' . substr($digits, -3);
    }
    if ($len === 11 && str_starts_with($digits, '0')) {
        return '0' . substr($digits, 1, 2) . 'XXX XX' . substr($digits, -3);
    }
    if ($len >= 5) {
        $head = $len >= 8 ? 2 : 1;
        $tail = $len >= 7 ? 2 : 1;
        return substr($digits, 0, $head) . str_repeat('X', $len - $head - $tail) . substr($digits, -$tail);
    }
    if ($len >= 2) {
        return str_repeat('X', $len - 1) . substr($digits, -1);
    }
    return 'X';
}

function mask_phone_number(?string $raw): string {
    $clean = trim((string)$raw);
    if ($clean === '') {
        return '';
    }
    $parts = preg_split('/\s*[,;\/|]+\s*/', $clean) ?: [$clean];
    $masked = [];
    foreach ($parts as $part) {
        $m = mask_single_phone_number($part);
        if ($m !== '') {
            $masked[] = $m;
        }
    }
    return implode(', ', $masked);
}

function mask_email_address(?string $raw): string {
    $clean = trim(str_replace("\xC2\xA0", ' ', (string)$raw));
    if ($clean === '') {
        return '';
    }

    $parts = preg_split('/\s*[,;]+\s*/', $clean) ?: [$clean];
    $out = [];
    foreach ($parts as $part) {
        $item = trim($part);
        if ($item === '') {
            continue;
        }
        $atPos = strrpos($item, '@');
        if ($atPos === false) {
            $itemLen = mb_strlen($item, 'UTF-8');
            $keep = $itemLen > 3 ? 2 : ($itemLen > 1 ? 1 : 0);
            $out[] = ($keep > 0 ? mb_substr($item, 0, $keep, 'UTF-8') : '') . str_repeat('*', max(3, min(6, $itemLen - $keep)));
            continue;
        }

        $local = trim(substr($item, 0, $atPos));
        $domain = trim(substr($item, $atPos + 1));
        $lLen = mb_strlen($local, 'UTF-8');

        if ($lLen <= 1) {
            $maskedLocal = '***';
        } elseif ($lLen <= 4) {
            $maskedLocal = mb_substr($local, 0, 1, 'UTF-8') . '***';
        } else {
            $maskedLocal = mb_substr($local, 0, 2, 'UTF-8') . str_repeat('*', min(6, max(3, $lLen - 2)));
        }

        $out[] = $domain !== '' ? ($maskedLocal . '@' . $domain) : $maskedLocal;
    }

    return implode(', ', $out);
}

function normalize_party_social_url(?string $raw, string $platform = 'web'): string {
    $val = trim(str_replace("\xC2\xA0", ' ', (string)$raw));
    if ($val === '') {
        return '';
    }

    if (preg_match('/^(?:javascript|data|vbscript):/i', $val)) {
        return '';
    }

    if (!preg_match('#^https?://#i', $val)) {
        $clean = ltrim($val, '/');
        if ($platform === 'x') {
            if (preg_match('/^(?:www\.)?(?:x\.com|twitter\.com)\//i', $clean)) {
                $val = 'https://' . $clean;
            } else {
                $handle = ltrim($clean, '@');
                $val = preg_match('/^[A-Za-z0-9_.-]{1,60}$/', $handle)
                    ? ('https://x.com/' . $handle)
                    : ('https://' . $clean);
            }
        } elseif ($platform === 'instagram') {
            if (preg_match('/^(?:www\.)?instagram\.com\//i', $clean)) {
                $val = 'https://' . $clean;
            } else {
                $handle = ltrim($clean, '@');
                $val = preg_match('/^[A-Za-z0-9_.]{1,60}$/', $handle)
                    ? ('https://instagram.com/' . $handle)
                    : ('https://' . $clean);
            }
        } elseif ($platform === 'facebook') {
            if (preg_match('/^(?:www\.|m\.)?(?:facebook\.com|fb\.com)\//i', $clean)) {
                $val = 'https://' . $clean;
            } else {
                $handle = ltrim($clean, '@');
                $val = preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $handle)
                    ? ('https://facebook.com/' . $handle)
                    : ('https://' . $clean);
            }
        } elseif ($platform === 'youtube') {
            if (preg_match('/^(?:www\.|m\.)?(?:youtube\.com|youtu\.be)\//i', $clean)) {
                $val = 'https://' . $clean;
            } elseif (str_starts_with($clean, '@') && preg_match('/^@[A-Za-z0-9_.-]{2,80}$/', $clean)) {
                $val = 'https://youtube.com/' . $clean;
            } elseif (preg_match('/^[A-Za-z0-9_.-]{2,80}$/', $clean) && !str_contains($clean, '.')) {
                $val = 'https://youtube.com/@' . $clean;
            } else {
                $val = 'https://' . $clean;
            }
        } elseif ($platform === 'linkedin') {
            if (preg_match('/^(?:www\.)?linkedin\.com\//i', $clean)) {
                $val = 'https://' . $clean;
            } elseif (preg_match('#^(?:company|in|showcase)/[A-Za-z0-9_.-]+/?$#i', $clean)) {
                $val = 'https://linkedin.com/' . $clean;
            } elseif (preg_match('/^[A-Za-z0-9_.-]{2,80}$/', ltrim($clean, '@'))) {
                $val = 'https://linkedin.com/company/' . ltrim($clean, '@');
            } else {
                $val = 'https://' . $clean;
            }
        } elseif ($platform === 'whatsapp') {
            $val = 'https://' . $clean;
        } else {
            $val = 'https://' . $clean;
        }
    }

    $checked = filter_var($val, FILTER_VALIDATE_URL);
    if ($checked === false) {
        return '';
    }
    $scheme = strtolower((string)parse_url($checked, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return '';
    }
    return substr($checked, 0, 255);
}

function build_whatsapp_action_url(?string $raw): string {
    $val = trim((string)$raw);
    if ($val === '') {
        return '';
    }
    if (preg_match('#^(?:https?://|(?:www\.)?(?:wa\.me|api\.whatsapp\.com|whatsapp\.com|chat\.whatsapp\.com)/)#i', $val)) {
        return normalize_party_social_url($val, 'whatsapp');
    }
    $digits = preg_replace('/\D+/', '', $val) ?? '';
    if (strlen($digits) === 10 && preg_match('/^[6-9]\d{9}$/', $digits)) {
        $digits = '91' . $digits;
    }
    if (strlen($digits) >= 10 && strlen($digits) <= 15) {
        return 'https://wa.me/' . $digits;
    }
    return '';
}
