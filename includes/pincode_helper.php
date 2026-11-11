<?php
// includes/pincode_helper.php - Pan-India PIN Code & Village Search powered by `village` & `op_sdb`

function normalize_pincode_digits(?string $raw): string {
    $digits = preg_replace('/\D+/', '', trim((string)$raw)) ?? '';
    return $digits;
}

function is_valid_indian_pincode(?string $raw): bool {
    $clean = trim((string)$raw);
    return preg_match('/^[1-9][0-9]{5}$/', $clean) === 1;
}

function is_pincode_prefix_query(?string $raw): bool {
    $clean = trim((string)$raw);
    return preg_match('/^[1-9][0-9]{2,4}$/', $clean) === 1;
}

function get_pincode_prefix_bounds(string $prefix): ?array {
    if (!is_pincode_prefix_query($prefix)) {
        return null;
    }
    $len = strlen($prefix);
    $factor = (int)pow(10, 6 - $len);
    $min = ((int)$prefix) * $factor;
    $max = $min + $factor - 1;
    return [$min, $max];
}

function has_village_pincode_table(PDO $pdo): bool {
    static $cache = [];
    $key = spl_object_id($pdo);
    if (!isset($cache[$key])) {
        $cache[$key] = (bool)$pdo->query("SHOW TABLES LIKE 'village'")->fetch();
    }
    return $cache[$key];
}

function get_op_sdb_lookup_maps(PDO $pdo): array {
    static $cache = [];
    $key = spl_object_id($pdo);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $states = [];
    $districts = [];
    $blocks = [];

    $stmt = $pdo->query("
        SELECT state_code, state, district_code, district, block_code, block, c_2001, c_2011
        FROM op_sdb
        WHERE status = 'ACTIVE'
    ");
    if ($stmt) {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $sc = (string)($row['state_code'] ?? '');
            $dc = (string)($row['district_code'] ?? '');
            $bc = (string)($row['block_code'] ?? '');

            if ($sc !== '' && !isset($states[$sc])) {
                $states[$sc] = (string)$row['state'];
            }
            if ($dc !== '' && !isset($districts[$dc])) {
                $districts[$dc] = [
                    'state_code' => $sc,
                    'state' => (string)$row['state'],
                    'district_code' => $dc,
                    'district' => (string)$row['district'],
                ];
            }
            if ($bc !== '' && !isset($blocks[$bc])) {
                $blocks[$bc] = [
                    'state_code' => $sc,
                    'state' => (string)$row['state'],
                    'district_code' => $dc,
                    'district' => (string)$row['district'],
                    'block_code' => $bc,
                    'block' => (string)$row['block'],
                    'c_2001' => (string)($row['c_2001'] ?? ''),
                    'c_2011' => (string)($row['c_2011'] ?? ''),
                ];
            }
        }
    }

    $cache[$key] = [
        'states' => $states,
        'districts' => $districts,
        'blocks' => $blocks,
    ];
    return $cache[$key];
}

function resolve_village_op_sdb_meta(array $row, array $sdbMaps): array {
    $sc = (string)($row['state_code'] ?? '');
    $dc = (string)($row['district_code'] ?? '');
    $bc = (string)($row['block_code'] ?? '');

    $blk = $sdbMaps['blocks'][$bc] ?? null;
    $dist = $sdbMaps['districts'][$dc] ?? null;

    $stateName = trim((string)($row['sdb_state'] ?? ''));
    if ($stateName === '' && $blk !== null) {
        $stateName = $blk['state'];
    }
    if ($stateName === '' && $dist !== null) {
        $stateName = $dist['state'];
    }
    if ($stateName === '' && isset($sdbMaps['states'][$sc])) {
        $stateName = $sdbMaps['states'][$sc];
    }

    $districtName = trim((string)($row['sdb_district'] ?? ''));
    if ($districtName === '' && $blk !== null) {
        $districtName = $blk['district'];
    }
    if ($districtName === '' && $dist !== null) {
        $districtName = $dist['district'];
    }
    if ($districtName === '') {
        $districtName = trim((string)($row['district'] ?? ''));
    }

    $blockName = trim((string)($row['sdb_block'] ?? ''));
    if ($blockName === '' && $blk !== null) {
        $blockName = $blk['block'];
    }
    if ($blockName === '') {
        $blockName = trim((string)($row['block'] ?? ''));
    }

    return [
        'state_code' => $sc,
        'state_name' => $stateName,
        'district_code' => $dc,
        'district_name' => $districtName,
        'block_code' => $bc,
        'block_name' => $blockName,
        'c_2001' => (string)($row['c_2001'] ?? ($blk['c_2001'] ?? '')),
        'c_2011' => (string)($row['c_2011'] ?? ($blk['c_2011'] ?? '')),
        'lgd_matched' => $blk !== null,
    ];
}

function get_pincode_dataset_stats(PDO $pdo): array {
    static $cache = [];
    $key = spl_object_id($pdo);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $stats = [
        'villages' => 0,
        'pincodes' => 0,
        'village_states' => 0,
        'sdb_states' => (int)$pdo->query("SELECT COUNT(DISTINCT state_code) FROM op_sdb WHERE status = 'ACTIVE'")->fetchColumn(),
        'sdb_districts' => (int)$pdo->query("SELECT COUNT(DISTINCT district_code) FROM op_sdb WHERE status = 'ACTIVE'")->fetchColumn(),
        'sdb_blocks' => (int)$pdo->query("SELECT COUNT(*) FROM op_sdb WHERE status = 'ACTIVE'")->fetchColumn(),
    ];

    if (has_village_pincode_table($pdo)) {
        $stats['villages'] = (int)$pdo->query("SELECT COUNT(*) FROM village")->fetchColumn();
        $stats['pincodes'] = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT DISTINCT pincode FROM village WHERE pincode IS NOT NULL AND pincode > 0) p")->fetchColumn();
        $stats['village_states'] = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT DISTINCT state_code FROM village WHERE state_code IS NOT NULL) s")->fetchColumn();
    }

    $cache[$key] = $stats;
    return $stats;
}

function get_pincode_area_summary(PDO $pdo, string $pincode): ?array {
    $cleanPin = trim($pincode);
    if (!is_valid_indian_pincode($cleanPin) || !has_village_pincode_table($pdo)) {
        return null;
    }

    $pinInt = (int)$cleanPin;
    $hasPv = (bool)$pdo->query("SHOW TABLES LIKE 'panchayat_village'")->fetch();
    $pvSelect = $hasPv ? ", COUNT(DISTINCT NULLIF(TRIM(pv.panchayat_code), '')) AS panchayat_count" : ", 0 AS panchayat_count";
    $pvJoin = $hasPv ? "LEFT JOIN panchayat_village pv ON pv.village_code = v.village_code" : "";

    $stmt = $pdo->prepare("
        SELECT v.state_code,
               v.district_code,
               MAX(v.district) AS v_district,
               v.block_code,
               MAX(v.block) AS v_block,
               MAX(sb.state) AS sdb_state,
               MAX(sb.district) AS sdb_district,
               MAX(sb.block) AS sdb_block,
               MAX(sb.c_2001) AS c_2001,
               MAX(sb.c_2011) AS c_2011,
               COUNT(DISTINCT v.id) AS village_count
               {$pvSelect}
        FROM village v
        LEFT JOIN op_sdb sb
               ON sb.block_code = CAST(v.block_code AS CHAR) COLLATE utf8mb4_unicode_ci
              AND sb.status = 'ACTIVE'
        {$pvJoin}
        WHERE v.pincode = ?
        GROUP BY v.state_code, v.district_code, v.block_code
        ORDER BY village_count DESC, sdb_block ASC, v_block ASC
    ");
    $stmt->execute([$pinInt]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($groups)) {
        return null;
    }

    $sdbMaps = get_op_sdb_lookup_maps($pdo);
    $states = [];
    $districts = [];
    $blocks = [];
    $totalVillages = 0;
    $totalPanchayats = 0;

    foreach ($groups as $grp) {
        $meta = resolve_village_op_sdb_meta([
            'state_code' => $grp['state_code'],
            'district_code' => $grp['district_code'],
            'block_code' => $grp['block_code'],
            'district' => $grp['v_district'],
            'block' => $grp['v_block'],
            'sdb_state' => $grp['sdb_state'],
            'sdb_district' => $grp['sdb_district'],
            'sdb_block' => $grp['sdb_block'],
            'c_2001' => $grp['c_2001'],
            'c_2011' => $grp['c_2011'],
        ], $sdbMaps);

        $vCount = (int)$grp['village_count'];
        $pCount = (int)($grp['panchayat_count'] ?? 0);
        $totalVillages += $vCount;
        $totalPanchayats += $pCount;

        $sc = $meta['state_code'];
        if ($sc !== '' && !isset($states[$sc])) {
            $states[$sc] = [
                'state_code' => $sc,
                'state_name' => $meta['state_name'],
                'village_count' => 0,
            ];
        }
        if ($sc !== '') {
            $states[$sc]['village_count'] += $vCount;
        }

        $dc = $meta['district_code'];
        if ($dc !== '' && !isset($districts[$dc])) {
            $districts[$dc] = [
                'state_code' => $sc,
                'state_name' => $meta['state_name'],
                'district_code' => $dc,
                'district_name' => $meta['district_name'],
                'village_count' => 0,
            ];
        }
        if ($dc !== '') {
            $districts[$dc]['village_count'] += $vCount;
        }

        $bc = $meta['block_code'];
        $blocks[] = [
            'state_code' => $sc,
            'state_name' => $meta['state_name'],
            'district_code' => $dc,
            'district_name' => $meta['district_name'],
            'block_code' => $bc,
            'block_name' => $meta['block_name'],
            'c_2001' => $meta['c_2001'],
            'c_2011' => $meta['c_2011'],
            'lgd_matched' => $meta['lgd_matched'],
            'village_count' => $vCount,
            'panchayat_count' => $pCount,
        ];
    }

    $primary = $blocks[0];
    if (!$primary['lgd_matched']) {
        foreach ($blocks as $blkItem) {
            if ($blkItem['lgd_matched']) {
                $primary = $blkItem;
                break;
            }
        }
    }

    return [
        'pincode' => $cleanPin,
        'village_count' => $totalVillages,
        'panchayat_count' => $totalPanchayats,
        'states' => array_values($states),
        'districts' => array_values($districts),
        'blocks' => $blocks,
        'primary' => $primary,
    ];
}

function build_pincode_village_where(PDO $pdo, array $filters, bool $forceSubstring = false): array {
    $where = ["v.pincode IS NOT NULL", "v.pincode > 0"];
    $params = [];

    $pincode = trim((string)($filters['pincode'] ?? ''));
    $stateCode = trim((string)($filters['state_code'] ?? ''));
    $districtCode = trim((string)($filters['district_code'] ?? ''));
    $blockCode = trim((string)($filters['block_code'] ?? ''));
    $q = trim((string)($filters['q'] ?? ''));

    if ($stateCode !== '' && ctype_digit($stateCode)) {
        $where[] = "v.state_code = ?";
        $params[] = (int)$stateCode;
    }
    if ($districtCode !== '' && ctype_digit($districtCode)) {
        $where[] = "v.district_code = ?";
        $params[] = (int)$districtCode;
    }
    if ($blockCode !== '' && ctype_digit($blockCode)) {
        $where[] = "v.block_code = ?";
        $params[] = (int)$blockCode;
    }

    if ($pincode !== '' && is_valid_indian_pincode($pincode)) {
        $where[] = "v.pincode = ?";
        $params[] = (int)$pincode;
    } elseif ($q !== '') {
        if (is_valid_indian_pincode($q)) {
            $where[] = "v.pincode = ?";
            $params[] = (int)$q;
        } elseif (is_pincode_prefix_query($q)) {
            [$minPin, $maxPin] = get_pincode_prefix_bounds($q);
            $where[] = "v.pincode BETWEEN ? AND ?";
            $params[] = $minPin;
            $params[] = $maxPin;
        } else {
            // Fast path: match `op_sdb` blocks & districts first (7,145 rows -> < 1ms) + indexed village prefix
            $subConds = [];
            $prefixParam = $q . '%';
            $wildParam = '%' . $q . '%';

            if ($forceSubstring || ($blockCode !== '' && ctype_digit($blockCode)) || ($districtCode !== '' && ctype_digit($districtCode))) {
                $subConds[] = "v.village LIKE ?";
                $params[] = $wildParam;
                $subConds[] = "v.block LIKE ?";
                $params[] = $wildParam;
                $subConds[] = "v.district LIKE ?";
                $params[] = $wildParam;
            } else {
                $subConds[] = "v.village LIKE ?";
                $params[] = $prefixParam;

                // Resolve matching op_sdb block_codes / district_codes (< 1ms on indexed 7k table)
                $sdbMatchSql = "
                    SELECT block_code, district_code
                    FROM op_sdb
                    WHERE status = 'ACTIVE'
                      AND (block LIKE ? OR district LIKE ? OR state LIKE ?)
                ";
                $sdbArgs = [$wildParam, $wildParam, $wildParam];
                if ($stateCode !== '' && ctype_digit($stateCode)) {
                    $sdbMatchSql .= " AND state_code = ?";
                    $sdbArgs[] = $stateCode;
                }
                $sdbMatchSql .= " LIMIT 200";
                $sdbStmt = $pdo->prepare($sdbMatchSql);
                $sdbStmt->execute($sdbArgs);

                $matchedBlocks = [];
                $matchedDists = [];
                foreach ($sdbStmt->fetchAll(PDO::FETCH_ASSOC) as $sr) {
                    if (!empty($sr['block_code']) && ctype_digit((string)$sr['block_code'])) {
                        $matchedBlocks[] = (int)$sr['block_code'];
                    }
                    if (!empty($sr['district_code']) && ctype_digit((string)$sr['district_code'])) {
                        $matchedDists[] = (int)$sr['district_code'];
                    }
                }
                $matchedBlocks = array_values(array_unique($matchedBlocks));
                $matchedDists = array_values(array_unique($matchedDists));

                if (!empty($matchedBlocks)) {
                    $ph = implode(',', array_fill(0, count($matchedBlocks), '?'));
                    $subConds[] = "v.block_code IN ({$ph})";
                    $params = array_merge($params, $matchedBlocks);
                } elseif (!empty($matchedDists)) {
                    $ph = implode(',', array_fill(0, count($matchedDists), '?'));
                    $subConds[] = "v.district_code IN ({$ph})";
                    $params = array_merge($params, $matchedDists);
                }
            }

            if (!empty($subConds)) {
                $where[] = '(' . implode(' OR ', $subConds) . ')';
            }
        }
    }

    return [implode(' AND ', $where), $params];
}

function search_pincode_villages(PDO $pdo, array $filters, int $limit = 60, int $offset = 0): array {
    if (!has_village_pincode_table($pdo)) {
        return ['total' => 0, 'rows' => []];
    }

    $limit = max(1, min(200, $limit));
    $offset = max(0, $offset);

    [$whereSql, $params] = build_pincode_village_where($pdo, $filters, false);

    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM village v WHERE {$whereSql}");
    $cntStmt->execute($params);
    $total = (int)$cntStmt->fetchColumn();

    // If fast prefix / op_sdb lookup found 0 rows on a text query, retry with mid-string LIKE
    $q = trim((string)($filters['q'] ?? ''));
    if ($total === 0 && $q !== '' && !ctype_digit($q) && empty($filters['pincode'])) {
        [$whereSql, $params] = build_pincode_village_where($pdo, $filters, true);
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM village v WHERE {$whereSql}");
        $cntStmt->execute($params);
        $total = (int)$cntStmt->fetchColumn();
    }

    if ($total === 0) {
        return ['total' => 0, 'rows' => []];
    }

    $hasPv = (bool)$pdo->query("SHOW TABLES LIKE 'panchayat_village'")->fetch();
    $pvSel = $hasPv ? ", pv.panchayat_code, pv.panchayat_name" : ", '' AS panchayat_code, '' AS panchayat_name";
    $pvJoin = $hasPv ? "LEFT JOIN panchayat_village pv ON pv.village_code = v.village_code" : "";

    $sql = "
        SELECT v.id,
               v.state_code,
               v.district_code,
               v.district,
               v.block_code,
               v.block,
               v.village_code,
               v.village,
               v.pincode,
               sb.state AS sdb_state,
               sb.district AS sdb_district,
               sb.block AS sdb_block,
               sb.c_2001,
               sb.c_2011
               {$pvSel}
        FROM village v
        LEFT JOIN op_sdb sb
               ON sb.block_code = CAST(v.block_code AS CHAR) COLLATE utf8mb4_unicode_ci
              AND sb.status = 'ACTIVE'
        {$pvJoin}
        WHERE {$whereSql}
        ORDER BY v.pincode ASC, v.village ASC, v.id ASC
        LIMIT {$limit} OFFSET {$offset}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sdbMaps = get_op_sdb_lookup_maps($pdo);
    $rows = [];
    foreach ($rawRows as $r) {
        $meta = resolve_village_op_sdb_meta($r, $sdbMaps);
        $rows[] = [
            'id' => (int)$r['id'],
            'pincode' => str_pad((string)$r['pincode'], 6, '0', STR_PAD_LEFT),
            'village_code' => (int)$r['village_code'],
            'village' => trim((string)$r['village']),
            'panchayat_code' => trim((string)($r['panchayat_code'] ?? '')),
            'panchayat_name' => trim((string)($r['panchayat_name'] ?? '')) !== '' ? format_civic_title((string)$r['panchayat_name']) : '',
            'state_code' => $meta['state_code'],
            'state_name' => $meta['state_name'],
            'district_code' => $meta['district_code'],
            'district_name' => $meta['district_name'],
            'block_code' => $meta['block_code'],
            'block_name' => $meta['block_name'],
            'c_2001' => $meta['c_2001'],
            'c_2011' => $meta['c_2011'],
            'lgd_matched' => $meta['lgd_matched'],
        ];
    }

    return ['total' => $total, 'rows' => $rows];
}

function search_matching_pincode_groups(PDO $pdo, array $filters, int $limit = 36): array {
    if (!has_village_pincode_table($pdo)) {
        return [];
    }

    $limit = max(1, min(100, $limit));
    [$whereSql, $params] = build_pincode_village_where($pdo, $filters, false);

    $grpSql = "
        SELECT v.pincode,
               MIN(v.state_code) AS state_code,
               MIN(v.district_code) AS district_code,
               MIN(v.district) AS v_district,
               MIN(v.block_code) AS block_code,
               MIN(v.block) AS v_block,
               GROUP_CONCAT(DISTINCT COALESCE(NULLIF(TRIM(sb.block), ''), v.block) ORDER BY COALESCE(NULLIF(TRIM(sb.block), ''), v.block) SEPARATOR ', ') AS blocks_list,
               GROUP_CONCAT(DISTINCT v.village ORDER BY v.village ASC SEPARATOR ', ') AS sample_villages,
               COUNT(*) AS village_count,
               MAX(sb.state) AS sdb_state,
               MAX(sb.district) AS sdb_district,
               MAX(sb.block) AS sdb_block
        FROM village v
        LEFT JOIN op_sdb sb
               ON sb.block_code = CAST(v.block_code AS CHAR) COLLATE utf8mb4_unicode_ci
              AND sb.status = 'ACTIVE'
        WHERE {$whereSql}
        GROUP BY v.pincode
        ORDER BY v.pincode ASC
        LIMIT {$limit}
    ";
    $stmt = $pdo->prepare($grpSql);
    $stmt->execute($params);
    $rawGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $q = trim((string)($filters['q'] ?? ''));
    if (empty($rawGroups) && $q !== '' && !ctype_digit($q) && empty($filters['pincode'])) {
        [$whereSql, $params] = build_pincode_village_where($pdo, $filters, true);
        $grpSqlSub = "
            SELECT v.pincode,
                   MIN(v.state_code) AS state_code,
                   MIN(v.district_code) AS district_code,
                   MIN(v.district) AS v_district,
                   MIN(v.block_code) AS block_code,
                   MIN(v.block) AS v_block,
                   GROUP_CONCAT(DISTINCT COALESCE(NULLIF(TRIM(sb.block), ''), v.block) ORDER BY COALESCE(NULLIF(TRIM(sb.block), ''), v.block) SEPARATOR ', ') AS blocks_list,
                   GROUP_CONCAT(DISTINCT v.village ORDER BY v.village ASC SEPARATOR ', ') AS sample_villages,
                   COUNT(*) AS village_count,
                   MAX(sb.state) AS sdb_state,
                   MAX(sb.district) AS sdb_district,
                   MAX(sb.block) AS sdb_block
            FROM village v
            LEFT JOIN op_sdb sb
                   ON sb.block_code = CAST(v.block_code AS CHAR) COLLATE utf8mb4_unicode_ci
                  AND sb.status = 'ACTIVE'
            WHERE {$whereSql}
            GROUP BY v.pincode
            ORDER BY v.pincode ASC
            LIMIT {$limit}
        ";
        $stmt = $pdo->prepare($grpSqlSub);
        $stmt->execute($params);
        $rawGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $sdbMaps = get_op_sdb_lookup_maps($pdo);
    $groups = [];
    foreach ($rawGroups as $g) {
        $meta = resolve_village_op_sdb_meta([
            'state_code' => $g['state_code'],
            'district_code' => $g['district_code'],
            'block_code' => $g['block_code'],
            'district' => $g['v_district'],
            'block' => $g['v_block'],
            'sdb_state' => $g['sdb_state'],
            'sdb_district' => $g['sdb_district'],
            'sdb_block' => $g['sdb_block'],
        ], $sdbMaps);

        $sampleRaw = array_values(array_filter(array_map('trim', explode(',', (string)($g['sample_villages'] ?? '')))));
        $sampleSlice = array_slice($sampleRaw, 0, 5);
        $pinStr = str_pad((string)$g['pincode'], 6, '0', STR_PAD_LEFT);

        $groups[] = [
            'pincode' => $pinStr,
            'state_code' => $meta['state_code'],
            'state_name' => $meta['state_name'],
            'district_code' => $meta['district_code'],
            'district_name' => $meta['district_name'],
            'block_code' => $meta['block_code'],
            'block_name' => $meta['block_name'],
            'blocks_list' => trim((string)($g['blocks_list'] ?? $meta['block_name'])),
            'village_count' => (int)$g['village_count'],
            'sample_villages' => $sampleSlice,
            'url' => get_pincode_page_url($pinStr),
        ];
    }

    return $groups;
}

