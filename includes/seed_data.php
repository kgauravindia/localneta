<?php
// includes/seed_data.php - Verified Parliament MP seeders (sansad.in) for LocalNeta

function seed_database_content(PDO $pdo): void {
    // Dummy demo data has been permanently removed.
    // All directory modules query real tables: op_sdb, mp, mukhiya, politician, political_party, panchayat_village, leader_types.
}

function seed_mp_table(PDO $pdo): void {
    $csvPath = __DIR__ . '/mp_ls18.csv';
    if (!file_exists($csvPath)) {
        return;
    }

    $handle = fopen($csvPath, 'r');
    if (!$handle) {
        return;
    }

    // Skip CSV header row
    fgetcsv($handle);

    $stmt = $pdo->prepare("
        INSERT INTO mp (
            member_name, party_name, constituency, state, state_code,
            membership_status, lok_sabha_terms, type, election, sources
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $pdo->beginTransaction();
    try {
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 10 || trim($row[0]) === '') {
                continue;
            }
            $stmt->execute([
                trim($row[0]),
                trim($row[1]),
                trim($row[2]),
                trim($row[3]),
                trim($row[4]) !== '' ? trim($row[4]) : null,
                trim($row[5]) ?: 'Sitting',
                trim($row[6]) ?: '18',
                trim($row[7]) ?: 'ls',
                trim($row[8]) ?: 'ls-18',
                trim($row[9]) ?: 'https://sansad.in/'
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fclose($handle);
        throw $e;
    }

    fclose($handle);
}

function seed_rs_mp_table(PDO $pdo): void {
    $xlsPath = __DIR__ . '/../data/MP_RS_2026.xls';
    if (!file_exists($xlsPath) || !class_exists('DOMDocument')) {
        return;
    }

    $html = file_get_contents($xlsPath);
    if ($html === false || trim($html) === '') {
        return;
    }

    // Build op_sdb state lookup map
    $opStates = [];
    foreach ($pdo->query("SELECT DISTINCT state_code, state FROM op_sdb WHERE status = 'ACTIVE'") as $r) {
        $opStates[strtolower(trim($r['state']))] = $r['state_code'];
    }
    $stateAliases = [
        'keralam' => 'kerala',
        'national capital territory of delhi' => 'delhi',
        'jammu & kashmir' => 'jammu and kashmir',
    ];

    libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();

    $tables = $dom->getElementsByTagName('table');
    if ($tables->length === 0) {
        return;
    }
    $rows = $tables->item(0)->getElementsByTagName('tr');

    $stmt = $pdo->prepare("
        INSERT INTO mp (
            member_name, party_name, constituency, state, state_code,
            membership_status, lok_sabha_terms, age, photo_url, term_period, total_terms,
            type, election, sources
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'rs', ?, 'https://sansad.in/')
    ");

    $pdo->beginTransaction();
    try {
        for ($i = 1; $i < $rows->length; $i++) {
            $tds = [];
            foreach ($rows->item($i)->childNodes as $td) {
                if ($td->nodeType === XML_ELEMENT_NODE && in_array(strtolower($td->nodeName), ['th', 'td'])) {
                    $tds[] = $td;
                }
            }
            if (count($tds) < 6) {
                continue;
            }

            $imgs = $tds[0]->getElementsByTagName('img');
            $photoUrl = $imgs->length > 0 ? trim($imgs->item(0)->getAttribute('src')) : null;

            $rawName = preg_replace('/\s+/', ' ', trim($tds[1]->textContent));
            if ($rawName === '') {
                continue;
            }
            if (str_contains($rawName, ',')) {
                [$last, $first] = array_map('trim', explode(',', $rawName, 2));
                $memberName = trim($first . ' ' . $last);
            } else {
                $memberName = $rawName;
            }

            $party = preg_replace('/\s+/', ' ', trim($tds[2]->textContent));
            $state = preg_replace('/\s+/', ' ', trim($tds[3]->textContent));
            $rawAge = preg_replace('/\s+/', ' ', trim($tds[4]->textContent));
            $age = ctype_digit($rawAge) ? (int)$rawAge : null;

            $rawTerm = preg_replace('/\s+/', ' ', trim($tds[5]->textContent));
            $termPeriod = null;
            $startYear = null;
            $totalTerms = null;
            if (preg_match('/Current\s*:\s*([0-9-]+)/i', $rawTerm, $m)) {
                $termPeriod = trim($m[1]);
                if (preg_match('/^(\d{4})/', $termPeriod, $ym)) {
                    $startYear = $ym[1];
                }
            }
            if (preg_match('/Total Terms\s*:\s*(\d+)/i', $rawTerm, $m)) {
                $totalTerms = (int)$m[1];
            }

            $stateKey = strtolower($state);
            if (isset($stateAliases[$stateKey])) {
                $stateKey = $stateAliases[$stateKey];
            }
            $stateCode = $stateKey === 'nominated' ? null : ($opStates[$stateKey] ?? null);

            $constituency = $state === 'Nominated'
                ? 'Nominated (Rajya Sabha)'
                : 'Rajya Sabha (' . $state . ')';
            $electionVal = ($stateKey === 'nominated' || $startYear === null)
                ? null
                : ('RS ' . $startYear);
            $termsDisplay = trim(($termPeriod ? $termPeriod : 'Rajya Sabha') . ($totalTerms !== null ? ' • Terms: ' . $totalTerms : ''));

            $stmt->execute([
                $memberName,
                $party,
                $constituency,
                $state,
                $stateCode,
                'Sitting',
                $termsDisplay,
                $age,
                $photoUrl,
                $termPeriod,
                $totalTerms,
                $electionVal
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function seed_mlc_table(PDO $pdo): void {
    $jsonPath = __DIR__ . '/mlc_seed.json';
    if (!file_exists($jsonPath)) {
        return;
    }

    $raw = file_get_contents($jsonPath);
    if ($raw === false || trim($raw) === '') {
        return;
    }

    $records = json_decode($raw, true);
    if (!is_array($records) || empty($records)) {
        return;
    }

    if (!function_exists('generate_mlc_prefixed_slug')) {
        require_once __DIR__ . '/functions.php';
    }

    $stmt = $pdo->prepare("
        INSERT INTO mlc (
            neva_member_id, assembly_id, constituency_code, seat_no, member_name, slug, party_name, role_designation,
            constituency, constituency_type, constituency_area, constituency_full, constituency_xls,
            state, state_code, house, membership_status, gender, social_category, father_name, dob, dob_date, age,
            marital_status, spouse_name, children_details, highest_qualification, other_qualification, hobbies,
            term_period, mobile, alternate_mobile, office_tel, email, local_address, permanent_address,
            photo_url, source_url, sources, status
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?
        )
        ON DUPLICATE KEY UPDATE
            assembly_id = VALUES(assembly_id),
            constituency_code = VALUES(constituency_code),
            seat_no = VALUES(seat_no),
            member_name = VALUES(member_name),
            slug = VALUES(slug),
            party_name = VALUES(party_name),
            role_designation = VALUES(role_designation),
            constituency = VALUES(constituency),
            constituency_type = VALUES(constituency_type),
            constituency_area = VALUES(constituency_area),
            constituency_full = VALUES(constituency_full),
            constituency_xls = VALUES(constituency_xls),
            state = VALUES(state),
            house = VALUES(house),
            membership_status = VALUES(membership_status),
            gender = VALUES(gender),
            social_category = VALUES(social_category),
            father_name = VALUES(father_name),
            dob = VALUES(dob),
            dob_date = VALUES(dob_date),
            age = VALUES(age),
            marital_status = VALUES(marital_status),
            spouse_name = VALUES(spouse_name),
            children_details = VALUES(children_details),
            highest_qualification = VALUES(highest_qualification),
            other_qualification = VALUES(other_qualification),
            hobbies = VALUES(hobbies),
            term_period = VALUES(term_period),
            mobile = VALUES(mobile),
            alternate_mobile = VALUES(alternate_mobile),
            office_tel = VALUES(office_tel),
            email = VALUES(email),
            local_address = VALUES(local_address),
            permanent_address = VALUES(permanent_address),
            photo_url = VALUES(photo_url),
            source_url = VALUES(source_url),
            sources = VALUES(sources),
            status = VALUES(status)
    ");

    $usedSlugs = [];
    foreach ($pdo->query("SELECT state_code, state, neva_member_id, slug FROM mlc WHERE slug <> ''") as $existingRow) {
        $exPrefix = get_mlc_state_slug_prefix((string)$existingRow['state_code'], (string)$existingRow['state']);
        $exSlug = strtolower((string)$existingRow['slug']);
        if (str_starts_with($exSlug, $exPrefix . '-')) {
            $ownerKey = trim((string)$existingRow['state_code']) . ':' . (string)$existingRow['neva_member_id'];
            $usedSlugs[$exSlug] = $ownerKey;
        }
    }

    $baseCounts = [];
    foreach ($records as $r) {
        $sc = isset($r['state_code']) && $r['state_code'] !== '' ? trim((string)$r['state_code']) : '';
        $stName = trim((string)($r['state'] ?? ''));
        $b = generate_mlc_prefixed_slug((string)($r['member_name'] ?? ''), $sc, $stName);
        if ($b !== '') {
            $baseCounts[$b] = ($baseCounts[$b] ?? 0) + 1;
        }
    }

    $pdo->beginTransaction();
    try {
        foreach ($records as $idx => $r) {
            $memberName = trim((string)($r['member_name'] ?? ''));
            if ($memberName === '') {
                continue;
            }
            $nevaId = isset($r['neva_member_id']) ? (int)$r['neva_member_id'] : null;
            $stateCode = isset($r['state_code']) && $r['state_code'] !== '' ? trim((string)$r['state_code']) : '';
            $stateName = trim((string)($r['state'] ?? ''));
            $prefix = get_mlc_state_slug_prefix($stateCode, $stateName);
            $ownerKey = $stateCode . ':' . (string)$nevaId;

            $base = generate_mlc_prefixed_slug($memberName, $stateCode, $stateName);
            if ($base === '' || $base === $prefix) {
                $base = $prefix . '-mlc-' . ($nevaId ?: ($idx + 1));
            }
            $slug = $base;
            $takenByOther = isset($usedSlugs[$slug]) && $usedSlugs[$slug] !== $ownerKey;
            if (($baseCounts[$base] ?? 0) > 1 || $takenByOther) {
                $qualRaw = !empty($r['constituency_area']) ? (string)$r['constituency_area'] : (string)($r['constituency_type'] ?? '');
                $qualSlug = generate_mlc_slug($qualRaw, 60, false);
                if ($qualSlug !== '') {
                    $slug = $base . '-' . $qualSlug;
                }
                if (isset($usedSlugs[$slug]) && $usedSlugs[$slug] !== $ownerKey) {
                    $slug = $base . '-' . ($nevaId ?: ($idx + 1));
                }
            }
            $usedSlugs[$slug] = $ownerKey;

            $stmt->execute([
                $nevaId,
                (int)($r['assembly_id'] ?? 1),
                isset($r['constituency_code']) && $r['constituency_code'] !== null ? (int)$r['constituency_code'] : null,
                isset($r['seat_no']) && $r['seat_no'] !== null ? (int)$r['seat_no'] : null,
                $memberName,
                $slug,
                trim((string)($r['party_name'] ?? '')),
                isset($r['role_designation']) && $r['role_designation'] !== '' ? trim((string)$r['role_designation']) : null,
                trim((string)($r['constituency'] ?? '')),
                trim((string)($r['constituency_type'] ?? '')),
                isset($r['constituency_area']) && $r['constituency_area'] !== '' ? trim((string)$r['constituency_area']) : null,
                isset($r['constituency_full']) && $r['constituency_full'] !== '' ? trim((string)$r['constituency_full']) : null,
                isset($r['constituency_xls']) && $r['constituency_xls'] !== '' ? trim((string)$r['constituency_xls']) : null,
                trim((string)($r['state'] ?? '')),
                $stateCode !== '' ? $stateCode : null,
                trim((string)($r['house'] ?? 'Legislative Council')),
                trim((string)($r['membership_status'] ?? 'Sitting')),
                isset($r['gender']) && $r['gender'] !== '' ? trim((string)$r['gender']) : null,
                isset($r['social_category']) && $r['social_category'] !== '' ? trim((string)$r['social_category']) : null,
                isset($r['father_name']) && $r['father_name'] !== '' ? trim((string)$r['father_name']) : null,
                isset($r['dob']) && $r['dob'] !== '' ? trim((string)$r['dob']) : null,
                isset($r['dob_date']) && $r['dob_date'] !== '' ? trim((string)$r['dob_date']) : null,
                isset($r['age']) && $r['age'] !== null ? (int)$r['age'] : null,
                isset($r['marital_status']) && $r['marital_status'] !== '' ? trim((string)$r['marital_status']) : null,
                isset($r['spouse_name']) && $r['spouse_name'] !== '' ? trim((string)$r['spouse_name']) : null,
                isset($r['children_details']) && $r['children_details'] !== '' ? trim((string)$r['children_details']) : null,
                isset($r['highest_qualification']) && $r['highest_qualification'] !== '' ? trim((string)$r['highest_qualification']) : null,
                isset($r['other_qualification']) && $r['other_qualification'] !== '' ? trim((string)$r['other_qualification']) : null,
                isset($r['hobbies']) && $r['hobbies'] !== '' ? trim((string)$r['hobbies']) : null,
                isset($r['term_period']) && $r['term_period'] !== '' ? trim((string)$r['term_period']) : null,
                isset($r['mobile']) && $r['mobile'] !== '' ? trim((string)$r['mobile']) : null,
                isset($r['alternate_mobile']) && $r['alternate_mobile'] !== '' ? trim((string)$r['alternate_mobile']) : null,
                isset($r['office_tel']) && $r['office_tel'] !== '' ? trim((string)$r['office_tel']) : null,
                isset($r['email']) && $r['email'] !== '' ? trim((string)$r['email']) : null,
                isset($r['local_address']) && $r['local_address'] !== '' ? trim((string)$r['local_address']) : null,
                isset($r['permanent_address']) && $r['permanent_address'] !== '' ? trim((string)$r['permanent_address']) : null,
                isset($r['photo_url']) && $r['photo_url'] !== '' ? trim((string)$r['photo_url']) : null,
                isset($r['source_url']) && $r['source_url'] !== '' ? trim((string)$r['source_url']) : null,
                trim((string)($r['sources'] ?? 'https://neva.gov.in/')),
                trim((string)($r['status'] ?? 'ACTIVE')),
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
