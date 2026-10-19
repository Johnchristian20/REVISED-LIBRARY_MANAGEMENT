<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/database.php';
require __DIR__ . '/includes/password_reset_helpers.php';

ensurePasswordResetTable($conn);
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
if (($_GET['change_email'] ?? '') === '1') {
    unset($_SESSION['password_reset_email'], $_SESSION['password_reset_last_sent_at'], $_SESSION['password_reset_verified_at'], $_SESSION['password_reset_verified_user_id']);
}

function requestResetCode(mysqli $conn, string $email): string
{
    $find = mysqli_prepare($conn, 'SELECT user_id FROM users WHERE email=? LIMIT 1');
    mysqli_stmt_bind_param($find, 's', $email);
    mysqli_stmt_execute($find);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($find));
    mysqli_stmt_close($find);
    if (!$user) return 'sent'; // Keep the response the same for unknown addresses.

    $userId = (int) $user['user_id'];
    $recent = mysqli_prepare($conn, 'SELECT reset_id FROM password_reset_codes WHERE user_id=? AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND) LIMIT 1');
    mysqli_stmt_bind_param($recent, 'i', $userId);
    mysqli_stmt_execute($recent);
    $isLimited = (bool) mysqli_fetch_assoc(mysqli_stmt_get_result($recent));
    mysqli_stmt_close($recent);
    if ($isLimited) return 'limited';

    $code = (string) random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $clear = mysqli_prepare($conn, 'DELETE FROM password_reset_codes WHERE user_id=?');
    mysqli_stmt_bind_param($clear, 'i', $userId);
    mysqli_stmt_execute($clear);
    mysqli_stmt_close($clear);

    $insert = mysqli_prepare($conn, 'INSERT INTO password_reset_codes (user_id,code_hash,expires_at) VALUES (?,?,DATE_ADD(NOW(),INTERVAL 15 MINUTE))');
    mysqli_stmt_bind_param($insert, 'is', $userId, $hash);
    $stored = mysqli_stmt_execute($insert);
    mysqli_stmt_close($insert);
    if (!$stored) return 'failed';

    $mailError = '';
    if (!sendPasswordResetCode($email, $code, $mailError)) {
        error_log('Password reset email failed: ' . $mailError);
        $clear = mysqli_prepare($conn, 'DELETE FROM password_reset_codes WHERE user_id=?');
        mysqli_stmt_bind_param($clear, 'i', $userId);
        mysqli_stmt_execute($clear);
        mysqli_stmt_close($clear);
        return 'failed';
    }
    return 'sent';
}

$email = trim($_SESSION['password_reset_email'] ?? '');
$verifiedAt = (int) ($_SESSION['password_reset_verified_at'] ?? 0);
$verifiedUserId = (int) ($_SESSION['password_reset_verified_user_id'] ?? 0);
$verifiedSession = $verifiedAt > 0 && time() - $verifiedAt <= 600 && $verifiedUserId > 0;
$step = $verifiedSession ? 'password' : (!empty($_SESSION['password_reset_email']) ? 'code' : 'email');
$stepIndex = ['email' => 1, 'code' => 2, 'password' => 3][$step];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid form token. Reload and try again.');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'request') {
        $email = trim($_POST['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
            $step = 'email';
        } else {
            $_SESSION['password_reset_email'] = $email;
            unset($_SESSION['password_reset_verified_at'], $_SESSION['password_reset_verified_user_id']);
            $_SESSION['password_reset_last_sent_at'] = time();
            requestResetCode($conn, $email);
            $step = 'code';
            $message = 'If an account uses that email, a confirmation code has been sent. Check your inbox and spam folder.';
        }
    } elseif ($action === 'resend') {
        $email = trim($_SESSION['password_reset_email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $step = 'email';
            $error = 'Enter your account email to request a code.';
        } else {
            $lastSent = (int) ($_SESSION['password_reset_last_sent_at'] ?? 0);
            $remaining = max(0, 60 - (time() - $lastSent));
            if ($remaining > 0) {
                $error = 'Please wait for the countdown before requesting another code.';
            } else {
                $_SESSION['password_reset_last_sent_at'] = time();
                $result = requestResetCode($conn, $email);
                $message = 'If an account uses that email, a new confirmation code has been sent.';
                if ($result === 'limited') $message = 'A code was requested recently. Please wait for the countdown, then try again.';
                elseif ($result === 'failed') $message = 'We could not send the code. Please check the email setup or try again later.';
            }
            $step = 'code';
        }
    } elseif ($action === 'verify_code') {
        $email = trim($_SESSION['password_reset_email'] ?? '');
        $code = trim($_POST['code'] ?? '');
        if (!preg_match('/^\d{6}$/', $code)) {
            $error = 'Enter the 6-digit confirmation code.';
        } else {
            $find = mysqli_prepare($conn, 'SELECT user_id FROM users WHERE email=? LIMIT 1');
            mysqli_stmt_bind_param($find, 's', $email);
            mysqli_stmt_execute($find);
            $user = mysqli_fetch_assoc(mysqli_stmt_get_result($find));
            mysqli_stmt_close($find);
            $reset = null;
            if ($user) {
                $userId = (int) $user['user_id'];
                $lookup = mysqli_prepare($conn, 'SELECT reset_id,code_hash,attempts,(expires_at > NOW()) AS is_valid FROM password_reset_codes WHERE user_id=? ORDER BY reset_id DESC LIMIT 1');
                mysqli_stmt_bind_param($lookup, 'i', $userId);
                mysqli_stmt_execute($lookup);
                $reset = mysqli_fetch_assoc(mysqli_stmt_get_result($lookup));
                mysqli_stmt_close($lookup);
            }
            if (!$user || !$reset || (int) $reset['attempts'] >= 5 || !(int) $reset['is_valid']) {
                $error = 'That code is invalid or expired. Request a new code and try again.';
            } elseif (!password_verify($code, $reset['code_hash'])) {
                $wrong = mysqli_prepare($conn, 'UPDATE password_reset_codes SET attempts=attempts+1 WHERE reset_id=?');
                mysqli_stmt_bind_param($wrong, 'i', $reset['reset_id']);
                mysqli_stmt_execute($wrong);
                mysqli_stmt_close($wrong);
                $error = 'That code is incorrect. Check your email and try again.';
            } else {
                $_SESSION['password_reset_verified_user_id'] = (int) $user['user_id'];
                $_SESSION['password_reset_verified_at'] = time();
                header('Location: forgot-password.php?step=password');
                exit;
            }
        }
        $step = 'code';
    } elseif ($action === 'change_password') {
        $verifiedUserId = (int) ($_SESSION['password_reset_verified_user_id'] ?? 0);
        $verifiedAt = (int) ($_SESSION['password_reset_verified_at'] ?? 0);
        if (!$verifiedUserId || !$verifiedAt || time() - $verifiedAt > 600) {
            unset($_SESSION['password_reset_verified_at'], $_SESSION['password_reset_verified_user_id']);
            $step = !empty($_SESSION['password_reset_email']) ? 'code' : 'email';
            $error = 'Your verification expired. Please verify a new code.';
        } else {
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            if (strlen($newPassword) < 6) {
                $error = 'Your new password must be at least 6 characters.';
                $step = 'password';
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'The new passwords do not match.';
                $step = 'password';
            } else {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $update = mysqli_prepare($conn, 'UPDATE users SET password=? WHERE user_id=?');
                mysqli_stmt_bind_param($update, 'si', $hash, $verifiedUserId);
                $updated = mysqli_stmt_execute($update);
                mysqli_stmt_close($update);
                if ($updated) {
                    $clear = mysqli_prepare($conn, 'DELETE FROM password_reset_codes WHERE user_id=?');
                    mysqli_stmt_bind_param($clear, 'i', $verifiedUserId);
                    mysqli_stmt_execute($clear);
                    mysqli_stmt_close($clear);
                    unset($_SESSION['password_reset_email'], $_SESSION['password_reset_last_sent_at'], $_SESSION['password_reset_verified_at'], $_SESSION['password_reset_verified_user_id']);
                    $_SESSION['login_success'] = 'Your password was reset. Sign in with your new password.';
                    header('Location: index.php');
                    exit;
                }
                $error = 'Unable to update your password. Please try again.';
                $step = 'password';
            }
        }
    }
}

$lastSent = (int) ($_SESSION['password_reset_last_sent_at'] ?? 0);
$resendRemaining = max(0, 60 - (time() - $lastSent));
$maskedEmail = '';
if ($email !== '' && str_contains($email, '@')) {
    [$emailName, $emailDomain] = explode('@', $email, 2);
    $maskedEmail = substr($emailName, 0, 1) . str_repeat('•', max(3, min(8, strlen($emailName) - 1))) . '@' . $emailDomain;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset password | The Reading Room</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page auth-login-page reset-password-page">
<div class="login-layout">
    <section class="login-art">
        <a class="brand brand-light" href="index.php"><span class="brand-mark"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5.5c3.5-1.5 6.5-.8 9 1.3v13c-2.5-2.1-5.5-2.8-9-1.3zM21 5.5c-3.5-1.5-6.5-.8-9 1.3v13c2.5-2.1 5.5-2.8 9-1.3z"/></svg></span><span><strong>The Reading Room</strong><small>Library Management</small></span></a>
        <div class="art-copy">
            <div class="eyebrow light-eyebrow">ACCOUNT SECURITY</div>
            <h1>A secure way<br>back <em>in.</em></h1>
            <p>We’ll help you get back to your reading space, one secure step at a time.</p>
        </div>
        <div class="art-bottom"><span class="decor-line"></span><span>Your account stays yours.</span></div>
        <div class="orb orb-one"></div><div class="orb orb-two"></div>
    </section>
    <section class="login-panel">
        <div class="login-card reset-card">
            <div class="login-kicker">PASSWORD RESET</div>
            <div class="reset-progress" aria-label="Password reset progress">
                <span class="<?= $stepIndex > 1 ? 'is-done' : ($stepIndex === 1 ? 'is-current' : '') ?>"><i>1</i>Email</span>
                <span class="<?= $stepIndex > 2 ? 'is-done' : ($stepIndex === 2 ? 'is-current' : '') ?>"><i>2</i>Verify</span>
                <span class="<?= $stepIndex === 3 ? 'is-current' : '' ?>"><i>3</i>New password</span>
            </div>

            <?php if ($step === 'email'): ?>
                <h2>Forgot your password?</h2>
                <p class="muted">Enter the email address linked to your library account.</p>
                <?php if ($error !== ''): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
                <form method="post" data-loading-label="Sending your confirmation code">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="request">
                    <label for="email">Account email</label>
                    <input id="email" name="email" type="email" autocomplete="email" required maxlength="100" value="<?= e($email) ?>" placeholder="you@example.com">
                    <button class="button button-primary button-full" type="submit">Send confirmation code <span aria-hidden="true">→</span></button>
                </form>
            <?php elseif ($step === 'code'): ?>
                <h2>Check your email</h2>
                <p class="muted">Enter the 6-digit confirmation code sent to:</p>
                <?php if ($maskedEmail !== ''): ?><div class="reset-email-chip"><span class="reset-email-icon" aria-hidden="true">✉</span><?= e($maskedEmail) ?></div><?php endif; ?>
                <?php if ($message !== ''): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
                <?php if ($error !== ''): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
                <form method="post" data-loading-label="Verifying your code">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="verify_code">
                    <label for="code">Confirmation code</label>
                    <input class="reset-code-input" id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code" placeholder="000000" aria-describedby="code-help">
                    <small id="code-help" class="reset-field-hint">The code expires after 15 minutes.</small>
                    <button class="button button-primary button-full" type="submit">Verify code <span aria-hidden="true">→</span></button>
                </form>
                <div class="reset-resend-row">
                    <span>Didn’t receive it?</span>
                    <form method="post" data-loading-label="Resending your confirmation code">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="resend">
                        <button class="reset-resend-button" type="submit" data-resend-button data-seconds="<?= (int) $resendRemaining ?>">Resend code <span data-resend-countdown></span></button>
                    </form>
                </div>
                <p class="login-help reset-back-link"><a class="text-link" href="forgot-password.php?change_email=1">Use a different email</a></p>
            <?php else: ?>
                <h2>Create a new password</h2>
                <p class="muted">Your email is verified. Choose a strong password for your account.</p>
                <div class="reset-verified-note"><span aria-hidden="true">✓</span> Email verified successfully</div>
                <?php if ($error !== ''): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
                <form method="post" data-loading-label="Updating your password">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="change_password">
                    <label for="new_password">New password (at least 6 characters)</label>
                    <div class="password-wrap"><input id="new_password" name="new_password" type="password" minlength="6" required autocomplete="new-password" placeholder="At least 6 characters"><button class="password-toggle" type="button" data-password-toggle aria-controls="new_password" aria-label="Show password" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12S6 5 12 5s9.5 7 9.5 7-3.5 7-9.5 7-9.5-7-9.5-7z"/><circle cx="12" cy="12" r="3"/></svg></button></div>
                    <label for="confirm_password">Confirm new password</label>
                    <div class="password-wrap"><input id="confirm_password" name="confirm_password" type="password" minlength="6" required autocomplete="new-password" placeholder="Type it again"><button class="password-toggle" type="button" data-password-toggle aria-controls="confirm_password" aria-label="Show password" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12S6 5 12 5s9.5 7 9.5 7-3.5 7-9.5 7-9.5-7-9.5-7z"/><circle cx="12" cy="12" r="3"/></svg></button></div>
                    <button class="button button-primary button-full" type="submit">Save new password <span aria-hidden="true">→</span></button>
                </form>
                <p class="reset-field-hint">For your security, finish this step within 10 minutes.</p>
            <?php endif; ?>
            <p class="login-help"><a class="text-link" href="index.php">← Back to sign in</a></p>
        </div>
    </section>
</div>
<script src="assets/js/app.js"></script>
<script>
document.querySelectorAll('[data-resend-button]').forEach((button) => {
    let remaining = Number(button.dataset.seconds || 0);
    const label = button.querySelector('[data-resend-countdown]');
    const render = () => {
        button.disabled = remaining > 0;
        if (label) label.textContent = remaining > 0 ? `(${String(Math.floor(remaining / 60)).padStart(2, '0')}:${String(remaining % 60).padStart(2, '0')})` : '';
        if (remaining <= 0) button.textContent = 'Resend code';
    };
    render();
    if (remaining > 0) {
        const timer = window.setInterval(() => {
            remaining -= 1;
            render();
            if (remaining <= 0) window.clearInterval(timer);
        }, 1000);
    }
});
</script>
</body>
</html>
