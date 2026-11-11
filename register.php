<?php
// register.php - Citizen & Politician Registration (OTP Verified, Tiranga Civic UI)
define('APP_INIT', true);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';

$pdo = get_db_connection();

$redirectTarget = is_safe_local_redirect($_GET['redirect'] ?? ($_POST['redirect'] ?? ''));

if (is_user_logged_in()) {
    header('Location: ' . ($redirectTarget !== '' ? $redirectTarget : get_site_url('dashboard')));
    exit;
}

$errorMsg = '';
$states = $pdo->query("SELECT DISTINCT state_code, state FROM op_sdb WHERE status = 'ACTIVE' ORDER BY state ASC")->fetchAll();
$stateMap = [];
foreach ($states as $s) {
    $stateMap[(string)$s['state_code']] = (string)$s['state'];
}

$leaderTypes = array_values(array_filter(
    get_leader_types($pdo),
    fn($lt) => ($lt['slug'] ?? '') !== 'political-party-office'
));
$leaderTypeSlugs = [];
foreach ($leaderTypes as $lt) {
    $leaderTypeSlugs[(string)$lt['slug']] = (string)$lt['name'];
}

$requestedType = strtolower(trim((string)($_REQUEST['type'] ?? $_REQUEST['account_type'] ?? 'user')));
$formAccountType = in_array($requestedType, ['user', 'politician'], true) ? $requestedType : 'user';
$formRole = trim((string)($_POST['role'] ?? ($formAccountType === 'politician' ? 'politician' : 'citizen')));
$formLeaderCat = trim((string)($_POST['leader_category'] ?? 'mla'));
$formName = trim((string)($_POST['name'] ?? ''));
$formMobile = trim((string)($_POST['mobile'] ?? ''));
$formEmail = trim((string)($_POST['email'] ?? ''));
$formHandle = trim((string)($_POST['username_handle'] ?? ''));
$formPincode = substr(preg_replace('/\D+/', '', trim((string)($_POST['pincode'] ?? ''))) ?? '', 0, 10);
$formStateCode = trim((string)($_POST['state_code'] ?? ''));
$formDistrictCode = trim((string)($_POST['district_code'] ?? ''));
$formBlockCode = trim((string)($_POST['block_code'] ?? ''));
$formConstituency = trim((string)($_POST['constituency'] ?? ''));
$formPartyName = trim((string)($_POST['party_name'] ?? ''));
$formDesignation = trim((string)($_POST['designation'] ?? ''));
$formBio = trim((string)($_POST['bio'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if (!verify_user_csrf_token($csrf)) {
        $errorMsg = 'Security token expired. Please reload the page and try again.';
    } elseif (mb_strlen($formName) < 2) {
        $errorMsg = 'Please enter your full name (at least 2 characters).';
    } else {
        $cleanMobile = normalize_indian_mobile($formMobile);
        if ($cleanMobile === '') {
            $errorMsg = 'Please enter a valid 10-digit Indian mobile number starting with 6-9.';
        } elseif ($formEmail !== '' && !filter_var($formEmail, FILTER_VALIDATE_EMAIL)) {
            $errorMsg = 'Please enter a valid email address.';
        } elseif ($formAccountType === 'politician' && $formEmail === '') {
            $errorMsg = 'Please provide an email address for your Politician profile (automatically masked on public pages).';
        } elseif ($password !== '' && strlen($password) < 6) {
            $errorMsg = 'Password must be at least 6 characters long (or leave blank to use Mobile OTP login).';
        } else {
            $chkStmt = $pdo->prepare("SELECT id, name, mobile, is_mobile_verified FROM users WHERE mobile = ? LIMIT 1");
            $chkStmt->execute([$cleanMobile]);
            $existing = $chkStmt->fetch();

            if ($existing) {
                if ((int)$existing['is_mobile_verified'] === 1) {
                    $errorMsg = 'This mobile number is already registered. Please sign in using Password or OTP Login.';
                } else {
                    $_SESSION['pending_user_id'] = (int)$existing['id'];
                    $_SESSION['pending_mobile'] = (string)$existing['mobile'];
                    $_SESSION['pending_purpose'] = 'registration';
                    if ($redirectTarget !== '') {
                        $_SESSION['pending_redirect'] = $redirectTarget;
                    }
                    send_user_otp($pdo, (int)$existing['id'], 'registration');
                    header('Location: ' . get_site_url('verify-otp'));
                    exit;
                }
            } else {
                // Resolve state / district / block labels from op_sdb
                $stateName = $formStateCode !== '' ? ($stateMap[$formStateCode] ?? '') : '';
                $districtName = '';
                $blockName = '';

                if ($formDistrictCode !== '') {
                    $dStmt = $pdo->prepare("SELECT district, state FROM op_sdb WHERE district_code = ? AND status = 'ACTIVE' LIMIT 1");
                    $dStmt->execute([$formDistrictCode]);
                    if ($dRow = $dStmt->fetch()) {
                        $districtName = (string)$dRow['district'];
                        if ($stateName === '') {
                            $stateName = (string)$dRow['state'];
                        }
                    } else {
                        $formDistrictCode = '';
                    }
                }
                if ($formBlockCode !== '') {
                    $bStmt = $pdo->prepare("SELECT block FROM op_sdb WHERE block_code = ? AND status = 'ACTIVE' LIMIT 1");
                    $bStmt->execute([$formBlockCode]);
                    if ($bRow = $bStmt->fetch()) {
                        $blockName = (string)$bRow['block'];
                    } else {
                        $formBlockCode = '';
                    }
                }

                $validRole = $formAccountType === 'politician'
                    ? (in_array($formRole, ['politician', 'candidate', 'representative'], true) ? $formRole : 'politician')
                    : (in_array($formRole, ['citizen', 'voter', 'volunteer', 'journalist'], true) ? $formRole : 'citizen');

                $validLeaderCat = ($formAccountType === 'politician' && isset($leaderTypeSlugs[$formLeaderCat]))
                    ? $formLeaderCat
                    : ($formAccountType === 'politician' ? 'social-worker' : null);

                $finalDesignation = $formDesignation;
                if ($finalDesignation === '' && $validLeaderCat !== null && isset($leaderTypeSlugs[$validLeaderCat])) {
                    $finalDesignation = $leaderTypeSlugs[$validLeaderCat];
                } elseif ($finalDesignation === '' && $formAccountType === 'user') {
                    $finalDesignation = ucfirst($validRole);
                }

                $uniqueHandle = generate_unique_user_handle($pdo, $formName, $formHandle);
                $passHash = password_hash($password !== '' ? $password : bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

                $ins = $pdo->prepare("
                    INSERT INTO users (
                        account_type, role, leader_category, name, full_name, username_handle,
                        mobile, whatsapp, email, password,
                        state_code, district_code, block_code, state_name, district, block_name, pincode,
                        constituency, party_name, designation, bio,
                        profile_visibility, mobile_visibility, email_visibility, address_visibility,
                        status, is_mobile_verified
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        'public', 'masked', 'masked', 'public',
                        'active', 0
                    )
                ");
                $ins->execute([
                    $formAccountType,
                    $validRole,
                    $validLeaderCat,
                    $formName,
                    $formName,
                    $uniqueHandle,
                    $cleanMobile,
                    $cleanMobile,
                    $formEmail !== '' ? $formEmail : null,
                    $passHash,
                    $formStateCode !== '' ? $formStateCode : null,
                    $formDistrictCode !== '' ? $formDistrictCode : null,
                    $formBlockCode !== '' ? $formBlockCode : null,
                    $stateName !== '' ? $stateName : null,
                    $districtName !== '' ? $districtName : null,
                    $blockName !== '' ? $blockName : null,
                    $formPincode !== '' ? $formPincode : null,
                    $formConstituency !== '' ? $formConstituency : null,
                    $formPartyName !== '' ? $formPartyName : null,
                    $finalDesignation !== '' ? $finalDesignation : null,
                    $formBio !== '' ? $formBio : null,
                ]);

                $newUserId = (int)$pdo->lastInsertId();
                $_SESSION['pending_user_id'] = $newUserId;
                $_SESSION['pending_mobile'] = $cleanMobile;
                $_SESSION['pending_purpose'] = 'registration';
                if ($redirectTarget !== '') {
                    $_SESSION['pending_redirect'] = $redirectTarget;
                }

                send_user_otp($pdo, $newUserId, 'registration');
                header('Location: ' . get_site_url('verify-otp'));
                exit;
            }
        }
    }
}

// Preload districts/blocks if state/district was submitted on validation error
$preDistricts = [];
if ($formStateCode !== '') {
    $ds = $pdo->prepare("SELECT DISTINCT district_code, district FROM op_sdb WHERE state_code = ? AND status = 'ACTIVE' ORDER BY district ASC");
    $ds->execute([$formStateCode]);
    $preDistricts = $ds->fetchAll();
}
$preBlocks = [];
if ($formDistrictCode !== '') {
    $bs = $pdo->prepare("SELECT block_code, block FROM op_sdb WHERE district_code = ? AND status = 'ACTIVE' ORDER BY block ASC");
    $bs->execute([$formDistrictCode]);
    $preBlocks = $bs->fetchAll();
}

$pageTitle = 'Citizen & Politician Registration';
$pageDescription = 'Register on LocalNeta as a Citizen or Politician to build your civic profile and view verified mobile numbers & email IDs safely.';
require_once __DIR__ . '/includes/header.php';
?>

<main class="main-wrapper">
    <div class="auth-shell auth-shell-wide">
        <div class="auth-card">
            <div class="auth-card-header">
                <h1><i class="fas fa-user-plus" style="color:var(--saffron-main);"></i> Citizen &amp; Politician Registration</h1>
                <p>Create your free account to unlock masked mobile numbers &amp; email IDs of MLCs, MLAs, and representatives, or publish your public politician card.</p>
            </div>

            <div class="auth-card-body">
                <?php if ($errorMsg !== ''): ?>
                    <div class="alert-box alert-error">
                        <i class="fas fa-circle-exclamation" style="margin-top:2px;"></i>
                        <div><?= e($errorMsg) ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="<?= get_site_url('register') ?>">
                    <input type="hidden" name="csrf_token" value="<?= e(get_user_csrf_token()) ?>">
                    <input type="hidden" name="redirect" value="<?= e($redirectTarget) ?>">

                    <div class="form-group">
                        <label class="form-label">Select Account Type <span style="color:#DC2626;">*</span></label>
                        <div class="account-type-grid">
                            <label class="account-type-option <?= $formAccountType === 'user' ? 'selected' : '' ?>" id="cardTypeUser" onclick="switchRegAccountType('user')">
                                <input type="radio" name="account_type" value="user" <?= $formAccountType === 'user' ? 'checked' : '' ?>>
                                <div>
                                    <div style="font-weight:800; color:var(--navy-main); font-size:0.95rem;"><i class="fas fa-user-check" style="color:var(--green-main);"></i> Citizen / Voter</div>
                                    <div style="font-size:0.78rem; color:var(--text-muted); margin-top:2px;">Verify mobile to view masked mobile &amp; email contacts across India.</div>
                                </div>
                            </label>
                            <label class="account-type-option <?= $formAccountType === 'politician' ? 'selected' : '' ?>" id="cardTypePolitician" onclick="switchRegAccountType('politician')">
                                <input type="radio" name="account_type" value="politician" <?= $formAccountType === 'politician' ? 'checked' : '' ?>>
                                <div>
                                    <div style="font-weight:800; color:var(--navy-main); font-size:0.95rem;"><i class="fas fa-landmark" style="color:var(--saffron-main);"></i> Politician / Leader</div>
                                    <div style="font-size:0.78rem; color:var(--text-muted); margin-top:2px;">Publish your official civic profile card with privacy-masked phone &amp; email.</div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label" for="regName">Full Name <span style="color:#DC2626;">*</span></label>
                            <input type="text" id="regName" name="name" value="<?= e($formName) ?>" class="form-control" placeholder="e.g. Amit Kumar Singh" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="regMobile">10-Digit Mobile Number <span style="color:#DC2626;">*</span></label>
                            <input type="tel" id="regMobile" name="mobile" value="<?= e($formMobile) ?>" class="form-control" placeholder="e.g. 9876543210" maxlength="13" required>
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label" for="regEmail">
                                Email Address
                                <span id="emailReqBadge" style="font-size:0.74rem; color:<?= $formAccountType === 'politician' ? '#DC2626' : 'var(--text-muted)' ?>;">
                                    <?= $formAccountType === 'politician' ? '(Required — Displayed Masked)' : '(Optional — Displayed Masked)' ?>
                                </span>
                            </label>
                            <input type="email" id="regEmail" name="email" value="<?= e($formEmail) ?>" class="form-control" placeholder="e.g. contact@example.com" <?= $formAccountType === 'politician' ? 'required' : '' ?>>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="regPassword">Password <span style="font-size:0.74rem; color:var(--text-muted);">(Min 6 chars or blank for OTP-only)</span></label>
                            <input type="password" id="regPassword" name="password" class="form-control" placeholder="Create password (optional)">
                        </div>
                    </div>

                    <div class="form-group" style="background:var(--white-surface); border:1px solid var(--border-color); border-radius:var(--radius-md); padding:10px 12px;">
                        <label class="form-label" for="regPincode" style="margin-bottom:4px;">PIN Code <span style="font-size:0.74rem; color:var(--text-muted);">(Optional — Auto-detects State, District &amp; Block)</span></label>
                        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                            <input type="text" id="regPincode" name="pincode" value="<?= e($formPincode) ?>" maxlength="6" inputmode="numeric" class="form-control" placeholder="e.g. 841301" style="flex:1; min-width:150px;" oninput="if(this.value.replace(/\D/g,'').length===6) lookupRegPincode();">
                            <button type="button" onclick="lookupRegPincode()" class="btn-profile-view" style="flex:unset; padding:8px 14px; font-size:0.82rem; white-space:nowrap;">
                                <i class="fas fa-map-pin" style="color:var(--saffron-main);"></i> Detect Area
                            </button>
                        </div>
                        <div id="regPinStatus" style="font-size:0.76rem; color:var(--text-muted); margin-top:4px;"></div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label" for="regState">State / Union Territory</label>
                            <select id="regState" name="state_code" class="form-control" onchange="loadRegDistricts(this.value)">
                                <option value="">Select State / UT</option>
                                <?php foreach ($states as $st): ?>
                                    <option value="<?= e($st['state_code']) ?>" <?= $formStateCode === (string)$st['state_code'] ? 'selected' : '' ?>><?= e($st['state']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="regDistrict">District</label>
                            <select id="regDistrict" name="district_code" class="form-control" onchange="loadRegBlocks(this.value)">
                                <option value="">Select District</option>
                                <?php foreach ($preDistricts as $dt): ?>
                                    <option value="<?= e($dt['district_code']) ?>" <?= $formDistrictCode === (string)$dt['district_code'] ? 'selected' : '' ?>><?= e($dt['district']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Politician Specific Section -->
                    <div id="politicianExtraFields" <?= $formAccountType === 'politician' ? '' : 'hidden' ?> style="background:var(--white-surface); border:1px solid var(--border-color); border-radius:var(--radius-md); padding:14px; margin-bottom:16px;">
                        <div style="font-size:0.86rem; font-weight:800; color:var(--navy-main); margin-bottom:12px;">
                            <i class="fas fa-landmark" style="color:var(--saffron-main);"></i> Public Representative / Politician Details
                        </div>
                        <div class="form-grid-2">
                            <div class="form-group">
                                <label class="form-label" for="regLeaderCat">Representative Category</label>
                                <select id="regLeaderCat" name="leader_category" class="form-control">
                                    <?php foreach ($leaderTypes as $lt): ?>
                                        <option value="<?= e($lt['slug']) ?>" <?= $formLeaderCat === $lt['slug'] ? 'selected' : '' ?>><?= e($lt['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="regBlock">Block (Prakhand / Taluka)</label>
                                <select id="regBlock" name="block_code" class="form-control">
                                    <option value="">All / District-Level</option>
                                    <?php foreach ($preBlocks as $blk): ?>
                                        <option value="<?= e($blk['block_code']) ?>" <?= $formBlockCode === (string)$blk['block_code'] ? 'selected' : '' ?>><?= e($blk['block']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-grid-2">
                            <div class="form-group">
                                <label class="form-label" for="regConstituency">Constituency / Panchayat / Ward</label>
                                <input type="text" id="regConstituency" name="constituency" value="<?= e($formConstituency) ?>" class="form-control" placeholder="e.g. Chapra Assembly / Ward 12">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="regParty">Political Party / Independent</label>
                                <input type="text" id="regParty" name="party_name" value="<?= e($formPartyName) ?>" class="form-control" placeholder="e.g. Independent / Party Name">
                            </div>
                        </div>
                        <div class="form-grid-2">
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label" for="regDesignation">Current Designation / Title</label>
                                <input type="text" id="regDesignation" name="designation" value="<?= e($formDesignation) ?>" class="form-control" placeholder="e.g. Mukhiya Candidate / Ward Councillor">
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label" for="regHandle">Public Profile Handle (Optional)</label>
                                <input type="text" id="regHandle" name="username_handle" value="<?= e($formHandle) ?>" class="form-control" placeholder="e.g. amit-singh-chapra">
                            </div>
                        </div>
                        <div class="form-group" style="margin-top:12px; margin-bottom:0;">
                            <label class="form-label" for="regBio">Short Civic Bio / Public Mission</label>
                            <textarea id="regBio" name="bio" rows="2" class="form-control" placeholder="Brief introduction about your public work and constituency priorities..."><?= e($formBio) ?></textarea>
                        </div>
                    </div>

                    <!-- Citizen Role Field -->
                    <div id="citizenExtraFields" class="form-group" <?= $formAccountType === 'user' ? '' : 'hidden' ?>>
                        <label class="form-label" for="regCitizenRole">Civic Participation Role</label>
                        <select id="regCitizenRole" name="role" class="form-control">
                            <option value="citizen" <?= $formRole === 'citizen' ? 'selected' : '' ?>>Citizen / Voter</option>
                            <option value="volunteer" <?= $formRole === 'volunteer' ? 'selected' : '' ?>>Social Worker / Civic Volunteer</option>
                            <option value="journalist" <?= $formRole === 'journalist' ? 'selected' : '' ?>>Journalist / Election Observer</option>
                        </select>
                    </div>

                    <div class="alert-box alert-info" style="font-size:0.8rem;">
                        <i class="fas fa-shield-halved" style="margin-top:2px;"></i>
                        <div>
                            Your mobile number and email ID remain <strong>privacy-masked</strong> (e.g. <code>98XXX XX210</code>, <code>am******@example.com</code>) across public pages per our <a href="<?= get_site_url('privacy-policy#section-masking') ?>" style="color:var(--navy-accent); font-weight:700; text-decoration:underline;">Privacy Policy</a>.
                        </div>
                    </div>

                    <button type="submit" class="btn-primary-tiranga" style="width:100%; padding:12px 20px; font-size:0.96rem;">
                        <i class="fas fa-shield-check"></i> Register &amp; Send Verification OTP
                    </button>
                </form>

                <div style="text-align:center; margin-top:18px; padding-top:16px; border-top:1px solid var(--border-light); font-size:0.88rem; color:var(--text-secondary);">
                    Already registered?
                    <a href="<?= get_site_url('login' . ($redirectTarget !== '' ? '?redirect=' . urlencode($redirectTarget) : '')) ?>" style="color:var(--saffron-dark); font-weight:700; text-decoration:underline;">
                        Sign In to Your Account
                    </a>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
function switchRegAccountType(type) {
    const userCard = document.getElementById('cardTypeUser');
    const polCard = document.getElementById('cardTypePolitician');
    const polBox = document.getElementById('politicianExtraFields');
    const citBox = document.getElementById('citizenExtraFields');
    const emailInput = document.getElementById('regEmail');
    const emailBadge = document.getElementById('emailReqBadge');

    const isPol = type === 'politician';
    if (userCard) userCard.classList.toggle('selected', !isPol);
    if (polCard) polCard.classList.toggle('selected', isPol);
    if (polBox) polBox.toggleAttribute('hidden', !isPol);
    if (citBox) citBox.toggleAttribute('hidden', isPol);
    if (emailInput) emailInput.required = isPol;
    if (emailBadge) {
        emailBadge.textContent = isPol ? '(Required — Displayed Masked)' : '(Optional — Displayed Masked)';
        emailBadge.style.color = isPol ? '#DC2626' : 'var(--text-muted)';
    }
}

async function loadRegDistricts(stateCode) {
    const distEl = document.getElementById('regDistrict');
    const blkEl = document.getElementById('regBlock');
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

async function loadRegBlocks(districtCode) {
    const blkEl = document.getElementById('regBlock');
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

async function lookupRegPincode() {
    const pinEl = document.getElementById('regPincode');
    const stEl = document.getElementById('regState');
    const distEl = document.getElementById('regDistrict');
    const blkEl = document.getElementById('regBlock');
    const statusEl = document.getElementById('regPinStatus');
    if (!pinEl || !statusEl) return;

    const pin = (pinEl.value || '').replace(/\D+/g, '').slice(0, 6);
    pinEl.value = pin;
    if (!/^[1-9][0-9]{5}$/.test(pin)) {
        statusEl.textContent = 'Enter a valid 6-digit PIN code (e.g. 841301).';
        statusEl.style.color = '#DC2626';
        return;
    }

    statusEl.textContent = 'Looking up PIN code...';
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
            await loadRegDistricts(pri.state_code);
            if (distEl && pri.district_code) {
                distEl.value = pri.district_code;
                await loadRegBlocks(pri.district_code);
                if (blkEl && pri.block_code) {
                    blkEl.value = pri.block_code;
                }
            }
        }
        statusEl.textContent = `Detected: ${pri.block_name}, ${pri.district_name}, ${pri.state_name} (${data.summary.village_count} villages)`;
        statusEl.style.color = 'var(--green-dark)';
    } catch (e) {
        statusEl.textContent = 'Unable to resolve PIN right now.';
        statusEl.style.color = '#DC2626';
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

