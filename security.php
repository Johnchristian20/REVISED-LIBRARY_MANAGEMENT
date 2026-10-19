<?php
require __DIR__ . '/includes/auth.php';
require_login();
require __DIR__ . '/database.php';
$pageTitle = 'Security';
$basePath = '';
$error = '';
$accountError = $_SESSION['security_error'] ?? '';
unset($_SESSION['security_error']);
$accountNotice = $_SESSION['security_notice'] ?? '';
unset($_SESSION['security_notice']);
$saved = isset($_GET['saved']);
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Invalid form token. Reload and try again.'); }
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $stmt = mysqli_prepare($conn, 'SELECT password FROM users WHERE user_id=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row || !password_verify($current, $row['password'])) $error = 'Your current password is incorrect.';
    elseif (strlen($new) < 6) $error = 'Your new password must be at least 6 characters.';
    elseif ($new !== $confirm) $error = 'The new passwords do not match.';
    elseif (password_verify($new, $row['password'])) $error = 'Choose a password different from your current one.';
    else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $update = mysqli_prepare($conn, 'UPDATE users SET password=? WHERE user_id=?');
        mysqli_stmt_bind_param($update, 'si', $hash, $_SESSION['user_id']);
        mysqli_stmt_execute($update);
        mysqli_stmt_close($update);
        header('Location: security.php?saved=1');
        exit;
    }
}
require __DIR__ . '/includes/header.php';
?>
<section class="panel security-panel"><div class="panel-heading"><div><div class="eyebrow">ACCOUNT PROTECTION</div><h2>Change password</h2><p>Confirm your current password, then choose a new one.</p></div><div class="security-emblem" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 20 6v5c0 5-3.4 8.5-8 10-4.6-1.5-8-5-8-10V6l8-3Z"/><path d="M9 12V10a3 3 0 0 1 6 0v2M8.5 12h7v5h-7z"/></svg></div></div>
  <?php if ($saved): ?><div class="alert alert-success">Your password has been changed.</div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <?php if ($accountError !== ''): ?><div class="alert alert-error"><?= e($accountError) ?></div><?php endif; ?>
  <?php if ($accountNotice !== ''): ?><div class="alert alert-success"><?= e($accountNotice) ?></div><?php endif; ?>
  <form method="post" class="record-form security-form" data-loading-label="Updating your password"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
    <div class="form-grid">
      <div class="form-field security-full"><label for="current_password">Current password</label><div class="password-wrap"><input id="current_password" name="current_password" type="password" autocomplete="current-password" required><button class="password-toggle" type="button" data-password-toggle aria-controls="current_password" aria-label="Show password" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12S6 5 12 5s9.5 7 9.5 7-3.5 7-9.5 7-9.5-7-9.5-7z"/><circle cx="12" cy="12" r="3"/></svg></button></div></div>
      <div class="form-field"><label for="new_password">New password (at least 6 characters)</label><div class="password-wrap"><input id="new_password" name="new_password" type="password" minlength="6" autocomplete="new-password" required><button class="password-toggle" type="button" data-password-toggle aria-controls="new_password" aria-label="Show password" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12S6 5 12 5s9.5 7 9.5 7-3.5 7-9.5 7-9.5-7-9.5-7z"/><circle cx="12" cy="12" r="3"/></svg></button></div><small>Use at least 6 characters.</small></div>
      <div class="form-field"><label for="confirm_password">Type new password again</label><div class="password-wrap"><input id="confirm_password" name="confirm_password" type="password" minlength="6" autocomplete="new-password" required><button class="password-toggle" type="button" data-password-toggle aria-controls="confirm_password" aria-label="Show password" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12S6 5 12 5s9.5 7 9.5 7-3.5 7-9.5 7-9.5-7-9.5-7z"/><circle cx="12" cy="12" r="3"/></svg></button></div></div>
    </div>
    <div class="form-actions"><button class="button button-primary" type="submit">Save new password</button><a class="text-link" href="forgot-password.php">Forgot your password?</a></div>
  </form>
</section>
<section class="panel account-delete-panel">
  <div class="profile-section-heading"><h3>Delete my account</h3><p>Permanently close your sign-in account.</p></div>
  <div class="account-delete-note">Deleting your account removes your sign-in and pending book requests. Your member record and loan history remain on file. Active or overdue books must be returned first.</div>
  <button class="button button-danger" type="button" data-open-delete-account>Delete my account</button>
</section>
<dialog class="account-delete-dialog" id="deleteAccountDialog" aria-labelledby="deleteAccountTitle" aria-describedby="deleteAccountDescription">
  <div class="account-delete-dialog-heading"><span class="delete-warning-icon" aria-hidden="true">!</span><div><div class="eyebrow">ACCOUNT CLOSURE</div><h2 id="deleteAccountTitle">Are you sure?</h2></div></div>
  <p id="deleteAccountDescription">This will delete your login and remove pending book requests. Your library member and loan history will remain. Enter your current password to confirm.</p>
  <form method="post" action="delete-account.php" class="account-delete-confirm-form" data-loading-label="Closing your account">
    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="delete_choice" value="yes">
    <div class="form-field"><label for="delete_account_password">Current password</label><div class="password-wrap"><input id="delete_account_password" name="current_password" type="password" autocomplete="current-password" required><button class="password-toggle" type="button" data-password-toggle aria-controls="delete_account_password" aria-label="Show password" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12S6 5 12 5s9.5 7 9.5 7-3.5 7-9.5 7-9.5-7-9.5-7z"/><circle cx="12" cy="12" r="3"/></svg></button></div></div>
    <div class="account-delete-dialog-actions"><button class="button button-outline" type="button" data-close-delete-account>Cancel</button><button class="button button-danger" type="submit">Yes, delete my account</button></div>
  </form>
</dialog>
<?php require __DIR__ . '/includes/footer.php'; ?>
