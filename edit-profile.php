<?php
// edit-profile.php - Citizen & Politician Profile, Location, Social & Privacy Controls
define('APP_INIT', true);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';

$pdo = get_db_connection();
$currentUser = require_user_login($pdo);
$userId = (int)$currentUser['id'];

$states = $pdo->query("SELECT DISTINCT state_code, state FROM op_sdb WHERE status = 'ACTIVE' ORDER BY state ASC")->fetchAll();
$stateMap = [];
foreach ($states as $s) {
    $stateMap[(string)$s['state_code']] = (string)$s['state'];
}

$leaderTypes = array_values(array_filter(
    get_leader_types($pdo),
    fn($lt) => ($lt['slug'] ?? '') !== 'political-party-office'
));
$leaderTypeMap = [];
foreach ($leaderTypes as $lt) {
    $leaderTypeMap[(string)$lt['slug']] = (string)$lt['name'];
}

$errorMsg = '';
$successMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!verify_user_csrf_token($csrf)) {
        $errorMsg = 'Security token expired. Please refresh and try again.';
    } else {
        // Optional avatar upload
        if (!empty($_FILES['avatar_photo']) && ($_FILES['avatar_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $upRes = upload_user_avatar($pdo, $userId, $_FILES['avatar_photo']);
            if (empty($upRes['success'])) {
                $errorMsg = (string)($upRes['message'] ?? 'Avatar upload failed.');
            }
        }

        if ($errorMsg === '') {
            $accountType = strtolower(trim((string)($_POST['account_type'] ?? ($currentUser['account_type'] ?? 'user'))));
            if (!in_array($accountType, ['user', 'politician'], true)) {
                $accountType = 'user';
            }

            $name = trim((string)($_POST['name'] ?? ''));
            $fullName = trim((string)($_POST['full_name'] ?? ''));
            $handleRaw = trim((string)($_POST['username_handle'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $whatsappRaw = trim((string)($_POST['whatsapp'] ?? ''));
            $stateCode = trim((string)($_POST['state_code'] ?? ''));
            $districtCode = trim((string)($_POST['district_code'] ?? ''));
            $blockCode = trim((string)($_POST['block_code'] ?? ''));
            $leaderCat = trim((string)($_POST['leader_category'] ?? ''));
            $partyName = trim((string)($_POST['party_name'] ?? ''));
            $constituency = trim((string)($_POST['constituency'] ?? ''));
            $panchayat = trim((string)($_POST['panchayat'] ?? ''));
            $designation = trim((string)($_POST['designation'] ?? ''));
            $education = trim((string)($_POST['education'] ?? ''));
            $languages = trim((string)($_POST['languages'] ?? ''));
            $officeHours = trim((string)($_POST['office_hours'] ?? ''));
            $gender = trim((string)($_POST['gender'] ?? ''));
            $dobRaw = trim((string)($_POST['dob'] ?? ''));
            $expYears = trim((string)($_POST['experience_years'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));
            $pincode = trim((string)($_POST['pincode'] ?? ''));
            $bio = trim((string)($_POST['bio'] ?? ''));
            $twitter = trim((string)($_POST['twitter'] ?? ''));
            $facebook = trim((string)($_POST['facebook'] ?? ''));
            $instagram = trim((string)($_POST['instagram'] ?? ''));
            $linkedin = trim((string)($_POST['linkedin'] ?? ''));
            $websiteUrl = trim((string)($_POST['website_url'] ?? ''));
            $profVis = in_array($_POST['profile_visibility'] ?? '', ['public', 'private'], true) ? $_POST['profile_visibility'] : 'public';
            $mobVis = in_array($_POST['mobile_visibility'] ?? '', ['public', 'masked', 'private'], true) ? $_POST['mobile_visibility'] : 'masked';
            $emailVis = in_array($_POST['email_visibility'] ?? '', ['public', 'masked', 'private'], true) ? $_POST['email_visibility'] : 'masked';
            $addrVis = in_array($_POST['address_visibility'] ?? '', ['public', 'masked', 'private'], true) ? $_POST['address_visibility'] : 'public';
            $newPassword = (string)($_POST['new_password'] ?? '');

            $cleanWhatsapp = '';
            if ($whatsappRaw !== '') {
                $cleanWhatsapp = normalize_indian_mobile($whatsappRaw);
            }

            $dobVal = null;
            if ($dobRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dobRaw)) {
                $dobVal = $dobRaw;
            }

            if (mb_strlen($name) < 2) {
                $errorMsg = 'Display name must be at least 2 characters.';
            } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errorMsg = 'Please enter a valid email address.';
            } elseif ($whatsappRaw !== '' && $cleanWhatsapp === '') {
                $errorMsg = 'Please enter a valid 10-digit WhatsApp mobile number (or leave blank).';
            } elseif ($newPassword !== '' && strlen($newPassword) < 6) {
                $errorMsg = 'New password must be at least 6 characters.';
            } else {
                foreach (['Twitter/X' => $twitter, 'Facebook' => $facebook, 'Instagram' => $instagram, 'LinkedIn' => $linkedin, 'Website' => $websiteUrl] as $lbl => $uVal) {
                    if ($uVal !== '' && !preg_match('#^https?://#i', $uVal)) {
                        $errorMsg = "{$lbl} URL must start with http:// or https://.";
                        break;
                    }
                }
            }

            if ($errorMsg === '') {
                $stateName = $stateCode !== '' ? ($stateMap[$stateCode] ?? '') : '';
                $districtName = '';
                $blockName = '';

                if ($districtCode !== '') {
                    $ds = $pdo->prepare("SELECT district, state FROM op_sdb WHERE district_code = ? AND status = 'ACTIVE' LIMIT 1");
                    $ds->execute([$districtCode]);
                    if ($dr = $ds->fetch()) {
                        $districtName = (string)$dr['district'];
                        if ($stateName === '') {
                            $stateName = (string)$dr['state'];
                        }
                    } else {
                        $districtCode = '';
                    }
                }
                if ($blockCode !== '') {
                    $bs = $pdo->prepare("SELECT block FROM op_sdb WHERE block_code = ? AND status = 'ACTIVE' LIMIT 1");
                    $bs->execute([$blockCode]);
                    if ($br = $bs->fetch()) {
                        $blockName = (string)$br['block'];
                    } else {
                        $blockCode = '';
                    }
                }

                $validCat = ($accountType === 'politician' && isset($leaderTypeMap[$leaderCat])) ? $leaderCat : null;
                $uniqueHandle = generate_unique_user_handle($pdo, $name, $handleRaw !== '' ? $handleRaw : ($currentUser['username_handle'] ?? $name), $userId);
                $roleVal = $accountType === 'politician' ? 'politician' : ($currentUser['role'] === 'politician' ? 'citizen' : (string)$currentUser['role']);

                $params = [
                    $accountType,
                    $roleVal,
                    $validCat,
                    $name,
                    $fullName !== '' ? $fullName : $name,
                    $uniqueHandle,
                    $cleanWhatsapp !== '' ? $cleanWhatsapp : null,
                    $email !== '' ? $email : null,
                    $stateCode !== '' ? $stateCode : null,
                    $districtCode !== '' ? $districtCode : null,
                    $blockCode !== '' ? $blockCode : null,
                    $stateName !== '' ? $stateName : null,
                    $districtName !== '' ? $districtName : null,
                    $blockName !== '' ? $blockName : null,
                    $constituency !== '' ? $constituency : null,
                    $panchayat !== '' ? $panchayat : null,
                    $partyName !== '' ? $partyName : null,
                    $designation !== '' ? $designation : null,
                    $education !== '' ? $education : null,
                    $languages !== '' ? $languages : null,
                    $officeHours !== '' ? $officeHours : null,
                    $gender !== '' ? $gender : null,
                    $dobVal,
                    $expYears !== '' ? max(0, min(80, (int)$expYears)) : null,
                    $address !== '' ? $address : null,
                    $pincode !== '' ? $pincode : null,
                    $bio !== '' ? $bio : null,
                    $twitter !== '' ? $twitter : null,
                    $facebook !== '' ? $facebook : null,
                    $instagram !== '' ? $instagram : null,
                    $linkedin !== '' ? $linkedin : null,
                    $websiteUrl !== '' ? $websiteUrl : null,
                    $profVis,
                    $mobVis,
                    $emailVis,
                    $addrVis,
                ];

                $sql = "
                    UPDATE users SET
                        account_type = ?, role = ?, leader_category = ?, name = ?, full_name = ?, username_handle = ?,
                        whatsapp = ?, email = ?, state_code = ?, district_code = ?, block_code = ?,
                        state_name = ?, district = ?, block_name = ?, constituency = ?, panchayat = ?,
                        party_name = ?, designation = ?, education = ?, languages = ?, office_hours = ?,
                        gender = ?, dob = ?, experience_years = ?, address = ?, pincode = ?, bio = ?,
                        twitter = ?, facebook = ?, instagram = ?, linkedin = ?, website_url = ?,
                        profile_visibility = ?, mobile_visibility = ?, email_visibility = ?, address_visibility = ?
                ";

                if ($newPassword !== '') {
                    $sql .= ", password = ?";
                    $params[] = password_hash($newPassword, PASSWORD_DEFAULT);
                }

                $sql .= " WHERE id = ?";
                $params[] = $userId;

                $upd = $pdo->prepare($sql);
                $upd->execute($params);

                $currentUser = get_current_user_account($pdo, true) ?? $currentUser;
                $_SESSION['user_name'] = (string)$currentUser['name'];
                $_SESSION['user_handle'] = (string)$currentUser['username_handle'];
                $_SESSION['user_account_type'] = (string)$currentUser['account_type'];
                $successMsg = 'Profile, contact settings & privacy controls updated cleanly!';
            }
        }
    }
}

$curState = (string)($currentUser['state_code'] ?? '');
$curDist = (string)($currentUser['district_code'] ?? '');
$curBlock = (string)($currentUser['block_code'] ?? '');

$districts = [];
if ($curState !== '') {
    $ds = $pdo->prepare("SELECT DISTINCT district_code, district FROM op_sdb WHERE state_code = ? AND status = 'ACTIVE' ORDER BY district ASC");
    $ds->execute([$curState]);
    $districts = $ds->fetchAll();
}
$blocks = [];
if ($curDist !== '') {
    $bs = $pdo->prepare("SELECT block_code, block FROM op_sdb WHERE district_code = ? AND status = 'ACTIVE' ORDER BY block ASC");
    $bs->execute([$curDist]);
    $blocks = $bs->fetchAll();
}

$completionPct = get_profile_completion_percent($currentUser);
$publicCardUrl = get_user_profile_url((string)($currentUser['username_handle'] ?? ''), $userId);

$pageTitle = 'Edit Profile & Privacy Controls';
require_once __DIR__ . '/includes/header.php';
?>

<main class="main-wrapper">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:18px;">
        <div>
            <h1 style="font-size:1.35rem; font-weight:800; color:var(--navy-main);">
                <i class="fas fa-user-pen" style="color:var(--saffron-main);"></i> Edit Profile &amp; Privacy Settings
            </h1>
            <p style="font-size:0.86rem; color:var(--text-muted);">
                Profile completion: <strong><?= $completionPct ?>%</strong> • Public URL:
                <a href="<?= e($publicCardUrl) ?>" style="color:var(--navy-accent); font-weight:700; text-decoration:underline;">
                    /user/<?= e($currentUser['username_handle'] ?: $userId) ?>
                </a>
            </p>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a href="<?= get_site_url('dashboard') ?>" class="btn-profile-view" style="flex:unset; padding:8px 14px;">
                <i class="fas fa-arrow-left"></i> Dashboard
            </a>
            <a href="<?= e($publicCardUrl) ?>" class="btn-green-tiranga" style="padding:8px 14px; font-size:0.86rem;">
                <i class="fas fa-id-badge"></i> View Public Card
            </a>
        </div>
    </div>

    <?php if ($errorMsg !== ''): ?>
        <div class="alert-box alert-error"><i class="fas fa-circle-exclamation"></i><div><?= e($errorMsg) ?></div></div>
    <?php endif; ?>
    <?php if ($successMsg !== ''): ?>
        <div class="alert-box alert-success"><i class="fas fa-check-circle"></i><div><?= e($successMsg) ?></div></div>
    <?php endif; ?>

    <form method="POST" action="<?= get_site_url('edit-profile') ?>" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= e(get_user_csrf_token()) ?>">

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:20px; align-items:start;">
            <!-- Left Column: Identity, Contact & Location -->
            <div style="background:#FFFFFF; border:1px solid var(--border-color); border-top:4px solid var(--saffron-main); border-radius:var(--radius-lg); padding:20px; box-shadow:var(--shadow-sm);">
                <h2 style="font-size:1.05rem; font-weight:800; color:var(--navy-main); margin-bottom:14px;">
                    <i class="fas fa-id-card" style="color:var(--saffron-main);"></i> Account &amp; Contact Information
                </h2>

                <div style="display:flex; align-items:center; gap:14px; padding:12px; background:var(--white-surface); border:1px solid var(--border-light); border-radius:var(--radius-md); margin-bottom:16px;">
                    <img id="avatarPreviewImg" src="<?= e(get_user_avatar_url($currentUser['profile_photo'] ?? null)) ?>" alt="<?= e($currentUser['name']) ?>" style="width:64px; height:64px; border-radius:50%; object-fit:cover; border:2px solid var(--saffron-main);">
                    <div style="flex:1; min-width:0;">
                        <label class="form-label" for="avatarInput" style="margin-bottom:4px;">Profile Photo (JPG / PNG / WEBP, max 50 KB)</label>
                        <input type="file" id="avatarInput" name="avatar_photo" accept="image/jpeg,image/png,image/webp" class="form-control" style="padding:6px 10px; font-size:0.82rem;" onchange="validateAvatarSize(this)">
                        <div id="avatarSizeStatus" style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;"></div>
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label" for="editAccType">Account Type</label>
                        <select id="editAccType" name="account_type" class="form-control" onchange="toggleEditPolSection(this.value)">
                            <option value="user" <?= ($currentUser['account_type'] ?? 'user') === 'user' ? 'selected' : '' ?>>Citizen / Voter</option>
                            <option value="politician" <?= ($currentUser['account_type'] ?? 'user') === 'politician' ? 'selected' : '' ?>>Politician / Representative</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="editHandle">Public @Handle</label>
                        <input type="text" id="editHandle" name="username_handle" value="<?= e($currentUser['username_handle'] ?? '') ?>" class="form-control" placeholder="e.g. amit-singh">
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label" for="editName">Display Name <span style="color:#DC2626;">*</span></label>
                        <input type="text" id="editName" name="name" value="<?= e($currentUser['name']) ?>" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="editFullName">Full Formal Name</label>
                        <input type="text" id="editFullName" name="full_name" value="<?= e($currentUser['full_name'] ?? $currentUser['name']) ?>" class="form-control">
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label">Verified Mobile (Masked)</label>
                        <input type="text" value="<?= e(mask_phone_number((string)$currentUser['mobile'])) ?>" class="form-control" disabled style="background:var(--white-surface); font-family:monospace;">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="editWa">WhatsApp Number (Displayed Masked)</label>
                        <input type="tel" id="editWa" name="whatsapp" value="<?= e($currentUser['whatsapp'] ?? '') ?>" class="form-control" placeholder="10-digit WhatsApp number">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="editEmail">Email Address (Displayed Masked on Public Profile)</label>
                    <input type="email" id="editEmail" name="email" value="<?= e($currentUser['email'] ?? '') ?>" class="form-control" placeholder="name@example.com">
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label" for="editState">State / UT</label>
                        <select id="editState" name="state_code" class="form-control" onchange="loadEditDistricts(this.value)">
                            <option value="">Select State / UT</option>
                            <?php foreach ($states as $st): ?>
                                <option value="<?= e($st['state_code']) ?>" <?= $curState === (string)$st['state_code'] ? 'selected' : '' ?>><?= e($st['state']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="editDistrict">District</label>
                        <select id="editDistrict" name="district_code" class="form-control" onchange="loadEditBlocks(this.value)">
                            <option value="">Select District</option>
                            <?php foreach ($districts as $dt): ?>
                                <option value="<?= e($dt['district_code']) ?>" <?= $curDist === (string)$dt['district_code'] ? 'selected' : '' ?>><?= e($dt['district']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label" for="editBlock">Block (Prakhand / Taluka)</label>
                        <select id="editBlock" name="block_code" class="form-control">
                            <option value="">All / District-Level</option>
                            <?php foreach ($blocks as $blk): ?>
                                <option value="<?= e($blk['block_code']) ?>" <?= $curBlock === (string)$blk['block_code'] ? 'selected' : '' ?>><?= e($blk['block']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="editPincode">PIN Code <span style="font-size:0.72rem; color:var(--text-muted);">(Auto-fills Area)</span></label>
                        <div style="display:flex; gap:6px; align-items:center;">
                            <input type="text" id="editPincode" name="pincode" value="<?= e($currentUser['pincode'] ?? '') ?>" maxlength="10" inputmode="numeric" class="form-control" placeholder="e.g. 841301" style="flex:1;">
                            <button type="button" onclick="lookupEditPincode()" class="btn-profile-view" style="flex:unset; padding:8px 10px; font-size:0.78rem; white-space:nowrap;">
                                <i class="fas fa-map-pin" style="color:var(--saffron-main);"></i> Detect
                            </button>
                        </div>
                        <div id="editPinStatus" style="font-size:0.74rem; color:var(--text-muted); margin-top:3px;"></div>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" for="editAddress">Office / Residential Address</label>
                    <textarea id="editAddress" name="address" rows="2" class="form-control" placeholder="Ward, Mohalla / Village, Landmark..."><?= e($currentUser['address'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- Right Column: Politician Fields, Bio, Social & Privacy -->
            <div style="display:flex; flex-direction:column; gap:20px;">
                <div style="background:#FFFFFF; border:1px solid var(--border-color); border-top:4px solid var(--green-main); border-radius:var(--radius-lg); padding:20px; box-shadow:var(--shadow-sm);">
                    <h2 style="font-size:1.05rem; font-weight:800; color:var(--navy-main); margin-bottom:14px;">
                        <i class="fas fa-landmark" style="color:var(--green-main);"></i> Civic &amp; Political Profile
                    </h2>

                    <div id="editPoliticianFields" <?= ($currentUser['account_type'] ?? 'user') === 'politician' ? '' : 'hidden' ?>>
                        <div class="form-grid-2">
                            <div class="form-group">
                                <label class="form-label" for="editCat">Leader Category</label>
                                <select id="editCat" name="leader_category" class="form-control">
                                    <option value="">Select Category</option>
                                    <?php foreach ($leaderTypes as $lt): ?>
                                        <option value="<?= e($lt['slug']) ?>" <?= ($currentUser['leader_category'] ?? '') === $lt['slug'] ? 'selected' : '' ?>><?= e($lt['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="editParty">Political Party / Independent</label>
                                <input type="text" id="editParty" name="party_name" value="<?= e($currentUser['party_name'] ?? '') ?>" class="form-control">
                            </div>
                        </div>

                        <div class="form-grid-2">
                            <div class="form-group">
                                <label class="form-label" for="editConst">Constituency / Seat</label>
                                <input type="text" id="editConst" name="constituency" value="<?= e($currentUser['constituency'] ?? '') ?>" class="form-control">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="editPanchayat">Gram Panchayat / Ward</label>
                                <input type="text" id="editPanchayat" name="panchayat" value="<?= e($currentUser['panchayat'] ?? '') ?>" class="form-control">
                            </div>
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label" for="editDesig">Designation / Civic Role</label>
                            <input type="text" id="editDesig" name="designation" value="<?= e($currentUser['designation'] ?? '') ?>" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="editEdu">Education Qualification</label>
                            <input type="text" id="editEdu" name="education" value="<?= e($currentUser['education'] ?? '') ?>" class="form-control">
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label" for="editLang">Languages Spoken</label>
                            <input type="text" id="editLang" name="languages" value="<?= e($currentUser['languages'] ?? '') ?>" class="form-control" placeholder="Hindi, English, Bhojpuri">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="editHours">Public Meeting Hours</label>
                            <input type="text" id="editHours" name="office_hours" value="<?= e($currentUser['office_hours'] ?? '') ?>" class="form-control" placeholder="10 AM – 5 PM">
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label" for="editGender">Gender</label>
                            <select id="editGender" name="gender" class="form-control">
                                <option value="">Prefer not to specify</option>
                                <?php foreach (['Male', 'Female', 'Other'] as $gOpt): ?>
                                    <option value="<?= e($gOpt) ?>" <?= ($currentUser['gender'] ?? '') === $gOpt ? 'selected' : '' ?>><?= e($gOpt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="editExp">Public Service Experience (Years)</label>
                            <input type="number" id="editExp" name="experience_years" min="0" max="80" value="<?= e((string)($currentUser['experience_years'] ?? '')) ?>" class="form-control">
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label" for="editBio">Public Bio / Mission Statement</label>
                        <textarea id="editBio" name="bio" rows="3" class="form-control" placeholder="Share your civic initiatives, office background, and constituency focus..."><?= e($currentUser['bio'] ?? '') ?></textarea>
                    </div>
                </div>

                <!-- Privacy Visibility Controls & Social Links -->
                <div style="background:#FFFFFF; border:1px solid var(--border-color); border-top:4px solid var(--navy-main); border-radius:var(--radius-lg); padding:20px; box-shadow:var(--shadow-sm);">
                    <h2 style="font-size:1.05rem; font-weight:800; color:var(--navy-main); margin-bottom:14px;">
                        <i class="fas fa-shield-halved" style="color:var(--navy-accent);"></i> Privacy Masking &amp; Visibility Controls
                    </h2>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label" for="visProfile">Profile Visibility</label>
                            <select id="visProfile" name="profile_visibility" class="form-control">
                                <option value="public" <?= ($currentUser['profile_visibility'] ?? 'public') === 'public' ? 'selected' : '' ?>>Public (Discoverable in Directory)</option>
                                <option value="private" <?= ($currentUser['profile_visibility'] ?? '') === 'private' ? 'selected' : '' ?>>Private (Visible Only to Me)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="visMobile">Mobile Number Privacy</label>
                            <select id="visMobile" name="mobile_visibility" class="form-control">
                                <option value="masked" <?= ($currentUser['mobile_visibility'] ?? 'masked') !== 'private' ? 'selected' : '' ?>>Masked to Guests / Signed-In Click to Reveal</option>
                                <option value="private" <?= ($currentUser['mobile_visibility'] ?? '') === 'private' ? 'selected' : '' ?>>Completely Hidden</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label" for="visEmail">Email Privacy</label>
                            <select id="visEmail" name="email_visibility" class="form-control">
                                <option value="masked" <?= ($currentUser['email_visibility'] ?? 'masked') !== 'private' ? 'selected' : '' ?>>Masked to Guests / Signed-In Click to Reveal</option>
                                <option value="private" <?= ($currentUser['email_visibility'] ?? '') === 'private' ? 'selected' : '' ?>>Completely Hidden</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="visAddr">Address Visibility</label>
                            <select id="visAddr" name="address_visibility" class="form-control">
                                <option value="public" <?= ($currentUser['address_visibility'] ?? 'public') === 'public' ? 'selected' : '' ?>>Show Area / Office Address</option>
                                <option value="private" <?= ($currentUser['address_visibility'] ?? '') === 'private' ? 'selected' : '' ?>>Hide Detailed Street Address</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label" for="editTw">X / Twitter URL</label>
                            <input type="url" id="editTw" name="twitter" value="<?= e($currentUser['twitter'] ?? '') ?>" class="form-control" placeholder="https://x.com/...">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="editFb">Facebook URL</label>
                            <input type="url" id="editFb" name="facebook" value="<?= e($currentUser['facebook'] ?? '') ?>" class="form-control" placeholder="https://facebook.com/...">
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom:14px;">
                        <label class="form-label" for="editNewPass">Update Password (Optional — leave blank to keep existing)</label>
                        <input type="password" id="editNewPass" name="new_password" class="form-control" placeholder="New password (min 6 chars)">
                    </div>

                    <button type="submit" class="btn-primary-tiranga" style="width:100%; padding:12px 20px; font-size:0.96rem;">
                        <i class="fas fa-floppy-disk"></i> Save Profile &amp; Privacy Settings
                    </button>
                </div>
            </div>
        </div>
    </form>
</main>

<script>
const MAX_AVATAR_BYTES = 50 * 1024;
function validateAvatarSize(input) {
    const statusEl = document.getElementById('avatarSizeStatus');
    const previewEl = document.getElementById('avatarPreviewImg');
    if (!input || !input.files || !input.files[0]) {
        if (statusEl) statusEl.textContent = '';
        return;
    }
    const file = input.files[0];
    if (file.size > MAX_AVATAR_BYTES) {
        const kb = (file.size / 1024).toFixed(1);
        input.value = '';
        if (statusEl) {
            statusEl.textContent = `Selected file (${kb} KB) exceeds the 50 KB limit. Please choose a smaller photo.`;
            statusEl.style.color = '#DC2626';
        }
        return;
    }
    if (statusEl) {
        statusEl.textContent = `Ready (${(file.size / 1024).toFixed(1)} KB)`;
        statusEl.style.color = 'var(--green-dark)';
    }
    if (previewEl && /^image\/(jpeg|png|webp)$/i.test(file.type)) {
        const reader = new FileReader();
        reader.onload = e => { if (e.target && e.target.result) previewEl.src = e.target.result; };
        reader.readAsDataURL(file);
    }
}

function toggleEditPolSection(val) {
    const el = document.getElementById('editPoliticianFields');
    if (el) el.toggleAttribute('hidden', val !== 'politician');
}

async function loadEditDistricts(stateCode) {
    const distEl = document.getElementById('editDistrict');
    const blkEl = document.getElementById('editBlock');
    if (!distEl) return;
    distEl.innerHTML = '<option value="">Select District</option>';
    if (blkEl) blkEl.innerHTML = '<option value="">All / District-Level</option>';
    if (!stateCode) return;

    distEl.innerHTML = '<option value="">Loading...</option>';
    const res = await fetch(`<?= get_site_url('api/locations') ?>?type=districts&state_code=${encodeURIComponent(stateCode)}`);
    const json = await res.json();
    distEl.innerHTML = '<option value="">Select District</option>';
    (json.data || []).forEach(d => {
        const opt = document.createElement('option');
        opt.value = d.district_code;
        opt.textContent = d.district;
        distEl.appendChild(opt);
    });
}

async function loadEditBlocks(districtCode) {
    const blkEl = document.getElementById('editBlock');
    if (!blkEl) return;
    blkEl.innerHTML = '<option value="">All / District-Level</option>';
    if (!districtCode) return;

    const res = await fetch(`<?= get_site_url('api/locations') ?>?type=blocks&district_code=${encodeURIComponent(districtCode)}`);
    const json = await res.json();
    (json.data || []).forEach(b => {
        const opt = document.createElement('option');
        opt.value = b.block_code;
        opt.textContent = b.block;
        blkEl.appendChild(opt);
    });
}

async function lookupEditPincode() {
    const pinEl = document.getElementById('editPincode');
    const stEl = document.getElementById('editState');
    const distEl = document.getElementById('editDistrict');
    const blkEl = document.getElementById('editBlock');
    const statusEl = document.getElementById('editPinStatus');
    if (!pinEl || !statusEl) return;

    const pin = (pinEl.value || '').replace(/\D+/g, '').slice(0, 6);
    if (!/^[1-9][0-9]{5}$/.test(pin)) {
        statusEl.textContent = 'Enter a valid 6-digit PIN code (e.g. 841301).';
        statusEl.style.color = '#DC2626';
        return;
    }

    statusEl.textContent = 'Resolving PIN area...';
    statusEl.style.color = 'var(--text-muted)';
    try {
        const res = await fetch(`<?= get_site_url('api/pincode') ?>?pincode=${encodeURIComponent(pin)}`);
        const data = await res.json();
        if (!data.success || !data.summary || !data.summary.primary) {
            statusEl.textContent = data.message || 'PIN code not found.';
            statusEl.style.color = '#DC2626';
            return;
        }
        const pri = data.summary.primary;
        if (stEl && pri.state_code) {
            stEl.value = pri.state_code;
            await loadEditDistricts(pri.state_code);
            if (distEl && pri.district_code) {
                distEl.value = pri.district_code;
                await loadEditBlocks(pri.district_code);
                if (blkEl && pri.block_code) {
                    blkEl.value = pri.block_code;
                }
            }
        }
        statusEl.textContent = `Detected: ${pri.block_name}, ${pri.district_name}, ${pri.state_name}`;
        statusEl.style.color = 'var(--green-dark)';
    } catch (e) {
        statusEl.textContent = 'Unable to detect PIN right now.';
        statusEl.style.color = '#DC2626';
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

