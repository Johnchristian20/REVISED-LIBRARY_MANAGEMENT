<?php
require __DIR__ . '/includes/auth.php';
require_login();
require __DIR__ . '/database.php';
require __DIR__ . '/includes/profile_helpers.php';

if (!ensureProfileMembershipSchema($conn)) {
    http_response_code(500);
    exit('Could not prepare profile and membership fields. Run database/profile-membership-migration.sql.');
}

$userId = (int) $_SESSION['user_id'];
$isUser = (($_SESSION['role'] ?? '') === 'User');
$pageTitle = 'My profile';
$basePath = '';
$error = '';
$accountError = $_SESSION['profile_error'] ?? '';
unset($_SESSION['profile_error']);
$accountNotice = $_SESSION['profile_notice'] ?? '';
unset($_SESSION['profile_notice']);
$saved = isset($_GET['saved']);
$completeFlow = isset($_GET['complete']) || isset($_POST['complete_flow']);
$returnBookId = (int) ($_GET['book_id'] ?? $_POST['book_id'] ?? 0);
$profile = loadUserProfile($conn, $userId);
if (!$profile) {
    http_response_code(404);
    exit('Account not found.');
}
$hasMembership = !empty($profile['member_id']);
$phoneCodes = ['+63' => 'Philippines (+63)', '+1' => 'United States / Canada (+1)', '+44' => 'United Kingdom (+44)', '+61' => 'Australia (+61)', '+64' => 'New Zealand (+64)', '+60' => 'Malaysia (+60)', '+62' => 'Indonesia (+62)', '+65' => 'Singapore (+65)', '+66' => 'Thailand (+66)', '+81' => 'Japan (+81)', '+82' => 'South Korea (+82)', '+84' => 'Vietnam (+84)', '+86' => 'China (+86)', '+91' => 'India (+91)', '+49' => 'Germany (+49)', '+33' => 'France (+33)', '+971' => 'United Arab Emirates (+971)'];
$phoneCode = '+63';
$phoneLocalNumber = trim((string) ($profile['phone_number'] ?? ''));
if (preg_match('/^(\+\d{1,4})[\s-]*(.*)$/', $phoneLocalNumber, $phoneParts)) {
    $phoneCode = $phoneParts[1];
    $phoneLocalNumber = trim($phoneParts[2]);
}
if (!isset($phoneCodes[$phoneCode])) $phoneCodes[$phoneCode] = $phoneCode;

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid form token. Reload the page and try again.');
    }
    $formAction = $_POST['profile_action'] ?? 'profile';
    if ($isUser && $formAction === 'membership' && !$hasMembership) {
        $_POST['membership_first_name'] = trim($_POST['membership_first_name'] ?? '') ?: $profile['first_name'];
        $_POST['membership_last_name'] = trim($_POST['membership_last_name'] ?? '') ?: $profile['last_name'];
        $_POST['phone_number'] = trim($_POST['phone_number'] ?? '') ?: (string) ($profile['phone_number'] ?? '');
        $_POST['membership_type'] = trim($_POST['membership_type'] ?? '') ?: 'Regular';
        if (empty($_POST['home_branch_id'])) {
            $_POST['home_branch_id'] = (int) ($profile['home_branch_id'] ?? 0);
            if ((int) $_POST['home_branch_id'] < 1) {
                $defaultBranch = mysqli_query($conn, 'SELECT branch_id FROM branches ORDER BY branch_name LIMIT 1');
                $branchRow = $defaultBranch ? mysqli_fetch_assoc($defaultBranch) : null;
                $_POST['home_branch_id'] = (int) ($branchRow['branch_id'] ?? 0);
            }
        }
        if ((int) $_POST['home_branch_id'] < 1) {
            $error = 'There are no library branches available yet. Ask the administrator to add a branch, then try again.';
        }
    }
    // The membership form itself is the intent; do not depend on the submit
    // button's name surviving browser validation or a scripted form submit.
    $joinMembership = $isUser && $formAction === 'membership';
    if ($error === '' && saveUserProfile($conn, $userId, $_POST, $_FILES, $error, $joinMembership)) {
        $_SESSION['first_name'] = trim($_POST['first_name']);
        $_SESSION['last_name'] = trim($_POST['last_name']);
        $_SESSION['username'] = trim($_POST['username']);
        $refresh = loadUserProfile($conn, $userId);
        $_SESSION['profile_picture'] = $refresh['profile_picture'] ?? '';
        header('Location: profile.php?saved=1' . ($joinMembership ? '&member=1' : ''));
        exit;
    }
}

$profile = loadUserProfile($conn, $userId);
$branches = mysqli_query($conn, 'SELECT branch_id,branch_name FROM branches ORDER BY branch_name');
require __DIR__ . '/includes/header.php';
?>
<section class="profile-overview-card">
  <div class="profile-cover-art"><?php if (!empty($profile['profile_cover'])): ?><img class="profile-cover-image" src="<?= e($profile['profile_cover']) ?>" alt=""><?php endif; ?><span class="profile-cover-orb profile-cover-orb-one" aria-hidden="true"></span><span class="profile-cover-orb profile-cover-orb-two" aria-hidden="true"></span><span class="profile-cover-bookmark" aria-hidden="true">THE READING ROOM</span><button class="profile-cover-upload" type="button" data-photo-menu="cover"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="12" cy="12" r="3"/><path d="m7 5 1.2-2h3.6L13 5"/></svg> <?= !empty($profile['profile_cover']) ? 'Edit cover photo' : 'Add cover photo' ?></button><div class="profile-photo-menu cover-photo-menu" id="coverPhotoMenu" hidden><?php if (!empty($profile['profile_cover'])): ?><button type="button" data-show-photo="cover"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6Z"/><circle cx="12" cy="12" r="2.5"/></svg>See cover photo</button><?php endif; ?><button type="button" data-trigger-file="profile_cover"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="2"/><circle cx="9" cy="9" r="1.5"/><path d="m20 15-5-5L5 19"/></svg>Add cover photo</button></div></div>
  <div class="profile-overview-main">
    <div class="profile-overview-avatar"><?php if (!empty($profile['profile_picture'])): ?><img src="<?= e($profile['profile_picture']) ?>" alt="Profile photo of <?= e($profile['first_name'] . ' ' . $profile['last_name']) ?>"><?php else: ?><span><?= e(strtoupper(substr($profile['first_name'],0,1) . substr($profile['last_name'],0,1))) ?></span><?php endif; ?><button class="profile-avatar-upload" type="button" data-photo-menu="profile" aria-label="Profile picture options" title="Profile picture options"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h3l1.5-2h7L17 8h3v11H4z"/><circle cx="12" cy="13" r="3.5"/><path d="M8 5h2"/></svg></button><div class="profile-photo-menu avatar-photo-menu" id="profilePhotoMenu" hidden><?php if (!empty($profile['profile_picture'])): ?><button type="button" data-show-photo="profile"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6Z"/><circle cx="12" cy="12" r="2.5"/></svg>See profile picture</button><?php endif; ?><button type="button" data-trigger-file="profile_picture"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="2"/><circle cx="9" cy="9" r="1.5"/><path d="m20 15-5-5L5 19"/></svg><?= !empty($profile['profile_picture']) ? 'Choose profile picture' : 'Add profile picture' ?></button></div></div>
    <div class="profile-overview-copy"><span class="profile-role-pill"><?= e($_SESSION['role'] ?? 'User') ?> account</span><h2><?= e($profile['first_name'] . ' ' . $profile['last_name']) ?></h2><p><?= e($profile['bio'] ?: 'A reader at The Reading Room. Add a short bio to tell us what you love to read.') ?></p><button type="button" class="profile-bio-link" data-edit-profile-field="bio"><?= $profile['bio'] ? 'Edit bio' : 'Add bio' ?></button></div>
    <button class="button button-outline profile-edit-trigger" type="button" data-open-profile-editor><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg>Edit profile</button>
  </div>
  <dl class="profile-overview-facts">
    <div><dt>Email</dt><dd><?= e($profile['email']) ?></dd></div><div><dt>Username</dt><dd><?= e($profile['username']) ?></dd></div>
    <?php if ($isUser): ?><div><dt>Phone</dt><dd><?= e($profile['phone_number'] ?: 'Not added yet') ?></dd></div><div><dt>Home branch</dt><dd><?= e($profile['home_branch_name'] ?: 'Not selected yet') ?></dd></div><div><dt>Membership</dt><dd><?= !empty($profile['member_id']) ? e(($profile['membership_type'] ?: 'Member') . ' · ' . $profile['account_status']) : 'Not completed' ?></dd></div><?php endif; ?>
  </dl>
</section>
<dialog class="profile-photo-dialog" id="profilePhotoDialog" aria-label="Profile picture preview"><button class="profile-photo-dialog-close" type="button" data-close-photo-dialog aria-label="Close">×</button><img id="profilePhotoDialogImage" alt="Profile picture"><p>Your profile picture</p></dialog>
<dialog class="profile-photo-dialog cover-preview-dialog" id="coverPhotoDialog" aria-label="Cover photo preview"><button class="profile-photo-dialog-close" type="button" data-close-photo-dialog aria-label="Close">×</button><img id="coverPhotoDialogImage" alt="Cover photo"><p>Your cover photo</p></dialog>
<section class="panel profile-panel">
  <div class="panel-heading">
    <div><div class="eyebrow">ACCOUNT SETTINGS</div><h2>Profile &amp; membership</h2><p>Your account profile and library membership are saved separately.</p></div>
  </div>
  <?php if ($saved): ?><div class="alert alert-success">Your profile has been updated<?= isset($_GET['member']) ? ' and your library membership is saved' : '' ?>.</div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <?php if ($accountError !== ''): ?><div class="alert alert-error"><?= e($accountError) ?></div><?php endif; ?>
  <?php if ($accountNotice !== ''): ?><div class="alert alert-success"><?= e($accountNotice) ?></div><?php endif; ?>
  <div class="profile-edit-body">
  <form method="post" enctype="multipart/form-data" class="record-form profile-form" data-loading-label="Saving your profile">
    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="profile_action" value="profile">
    <input id="profile_picture" name="profile_picture" type="file" accept="image/jpeg,image/png,image/webp" hidden>
    <input id="profile_cover" name="profile_cover" type="file" accept="image/jpeg,image/png,image/webp" hidden>
    <div id="profilePhotoPreview" hidden></div>
    <?php if ($completeFlow): ?><input type="hidden" name="complete_flow" value="1"><input type="hidden" name="book_id" value="<?= $returnBookId ?>"><?php endif; ?>
    <details class="profile-edit-details profile-editor-accordion" id="profileEditor" open>
      <summary aria-expanded="true"><span><strong>My Profile</strong><small>Edit your name, email, username, and bio.</small></span><span class="profile-accordion-actions"><small class="profile-accordion-state">Click to minimize</small><span class="profile-edit-chevron" aria-hidden="true">⌄</span></span></summary>
    <section class="profile-settings-section">
      <div class="profile-section-heading"><h3>Edit profile</h3><p>Update the information people see on your library profile.</p></div>
      <div class="form-grid">
        <div class="form-field"><label for="first_name">First name *</label><input id="first_name" name="first_name" maxlength="50" required value="<?= e($profile['first_name']) ?>"></div>
        <div class="form-field"><label for="last_name">Last name *</label><input id="last_name" name="last_name" maxlength="50" required value="<?= e($profile['last_name']) ?>"></div>
        <div class="form-field"><label for="email">Email address *</label><input id="email" name="email" type="email" maxlength="100" required value="<?= e($profile['email']) ?>"></div>
        <div class="form-field"><label for="username">Username *</label><input id="username" name="username" maxlength="50" required value="<?= e($profile['username']) ?>"></div>
        <div class="form-field profile-bio"><label for="bio">Short bio</label><textarea id="bio" name="bio" maxlength="500" rows="4" placeholder="Tell us a little about what you enjoy reading."><?= e($profile['bio']) ?></textarea></div>
      </div>
    </section>
    </details>
    <dialog class="profile-crop-section profile-photo-crop-dialog" id="profileCropSection" aria-labelledby="profileCropHeading">
      <button class="profile-photo-dialog-close" type="button" data-close-photo-dialog aria-label="Close">×</button>
      <div class="profile-crop-copy"><div class="eyebrow">PHOTO PREVIEW</div><h3 id="profileCropHeading">Crop your profile photo</h3><p>Drag the photo to position it. Use the slider to zoom, then save your new profile picture.</p></div>
      <div class="profile-crop-editor"><div class="profile-crop-circle"><canvas id="profileCropCanvas" width="260" height="260" hidden aria-label="Circular crop preview"></canvas></div><div class="profile-crop-tools"><label for="profileCropZoom">Zoom</label><input id="profileCropZoom" type="range" min="1" max="2.5" step="0.05" value="1"><button class="button button-outline" type="button" data-trigger-file="profile_picture">Choose another photo</button><button class="button button-outline" type="button" data-close-photo-dialog>Cancel</button><button class="button button-primary" id="profileCropApply" type="button">Preview crop</button><button class="button button-primary" id="saveProfilePhoto" type="submit" disabled>Save profile photo</button><span class="profile-photo-status" id="profilePhotoStatus" aria-live="polite"></span></div></div>
    </dialog>
    <dialog class="profile-crop-section cover-crop-section profile-photo-crop-dialog" id="coverCropSection" aria-labelledby="coverCropHeading">
      <button class="profile-photo-dialog-close" type="button" data-close-photo-dialog aria-label="Close">×</button>
      <div class="profile-crop-copy"><div class="eyebrow">COVER PREVIEW</div><h3 id="coverCropHeading">Position your cover photo</h3><p>Drag and zoom the image inside the guide, preview the crop, then save it to your profile.</p></div>
      <div class="cover-crop-editor"><div class="profile-cover-crop-frame"><canvas id="coverCropCanvas" width="900" height="300" hidden aria-label="Cover photo crop preview"></canvas><span class="cover-crop-placeholder" id="coverCropPlaceholder">Choose an image to see the cover crop guide</span><span class="cover-crop-indicator"><span class="cover-indicator-dot"></span><span id="coverCropIndicatorText">COVER AREA · 3:1</span></span></div><div class="profile-crop-tools"><label for="coverCropZoom">Zoom</label><input id="coverCropZoom" type="range" min="1" max="2.5" step="0.05" value="1"><button class="button button-outline" type="button" data-trigger-file="profile_cover">Choose another cover</button><button class="button button-outline" type="button" data-close-photo-dialog>Cancel</button><button class="button button-primary" id="coverCropApply" type="button">Preview crop</button><button class="button button-primary" id="saveCoverPhoto" type="submit" disabled>Save cover photo</button><span class="profile-photo-status" id="coverPhotoStatus" aria-live="polite">Choose a cover image to start.</span></div></div>
    </dialog>

    <div class="form-actions"><button class="button button-primary" type="submit">Save changes</button></div>
  </form>
  </div>
  <?php if ($isUser): ?>
    <form method="post" class="record-form membership-form" data-loading-label="Saving your membership">
      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="profile_action" value="membership">
      <input type="hidden" name="first_name" value="<?= e($profile['first_name']) ?>">
      <input type="hidden" name="last_name" value="<?= e($profile['last_name']) ?>">
      <input type="hidden" name="email" value="<?= e($profile['email']) ?>">
      <input type="hidden" name="username" value="<?= e($profile['username']) ?>">
      <section class="profile-settings-section membership-settings-section">
        <div class="profile-section-heading"><h3>Membership</h3><p>Keep your library contact and branch details up to date.</p></div>
        <?php if (!$hasMembership): ?><div class="membership-prompt"><div><strong>Not a member yet?</strong><p>Complete the membership form below, then submit it to join the library.</p></div></div><?php endif; ?>
        <div class="membership-progress <?= !empty($profile['member_id']) ? 'is-complete' : '' ?>">
          <div class="membership-progress-mark" aria-hidden="true"><?= !empty($profile['member_id']) ? '✓' : '1' ?></div>
          <div><strong><?= !empty($profile['member_id']) ? 'Library membership connected' : 'Complete your membership profile' ?></strong><p><?= !empty($profile['member_id']) ? 'You are enrolled as member ' . e($profile['membership_number']) . ' · ' . e($profile['account_status']) : 'Add your contact, name, and branch details, then choose Become a member.' ?></p></div>
        </div>
        <details class="membership-form-details" id="membershipFormDetails" data-existing-member="<?= $hasMembership ? '1' : '0' ?>" open>
          <summary><?= $hasMembership ? 'Edit membership details' : 'Complete membership form' ?></summary>
          <div class="form-grid">
            <div class="form-field"><label for="membership_first_name">First name on membership *</label><input id="membership_first_name" name="membership_first_name" maxlength="50" required value="<?= e($profile['member_id'] ? ($profile['member_first_name'] ?? $profile['first_name']) : $profile['first_name']) ?>"></div>
            <div class="form-field"><label for="membership_last_name">Last name on membership *</label><input id="membership_last_name" name="membership_last_name" maxlength="50" required value="<?= e($profile['member_id'] ? ($profile['member_last_name'] ?? $profile['last_name']) : $profile['last_name']) ?>"></div>
            <div class="form-field"><label for="phone_number">Phone number *</label><div class="phone-input-group"><select id="phone_country_code" name="phone_country_code" aria-label="Country calling code" required><?php foreach ($phoneCodes as $code => $label): ?><option value="<?= e($code) ?>" <?= $phoneCode === $code ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select><input id="phone_number" name="phone_number" type="tel" inputmode="tel" maxlength="20" required value="<?= e($phoneLocalNumber) ?>" placeholder="912 345 6789"></div></div>
            <div class="form-field"><label for="home_branch_id">Home branch *</label><select id="home_branch_id" name="home_branch_id" required><option value="">Choose your branch</option><?php if ($branches): while ($branch = mysqli_fetch_assoc($branches)): ?><option value="<?= (int) $branch['branch_id'] ?>" <?= (string) ($profile['home_branch_id'] ?? '') === (string) $branch['branch_id'] ? 'selected' : '' ?>><?= e($branch['branch_name']) ?></option><?php endwhile; endif; ?></select></div>
            <div class="form-field"><label for="membership_type">Membership type *</label><select id="membership_type" name="membership_type" required><option value="">Choose type</option><?php foreach (['Student','Faculty','Regular','Senior','Corporate'] as $type): ?><option value="<?= e($type) ?>" <?= ($profile['membership_type'] ?? '') === $type ? 'selected' : '' ?>><?= e($type) ?></option><?php endforeach; ?></select></div>
          </div>
          <div class="form-actions membership-submit"><button class="button button-primary" type="submit" name="<?= $hasMembership ? 'save_membership' : 'become_member' ?>" value="1"><?= $hasMembership ? 'Save membership changes' : 'Become a member' ?></button></div>
        </details>
      </section>
    </form>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
