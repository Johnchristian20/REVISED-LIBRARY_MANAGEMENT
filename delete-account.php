<?php
require __DIR__ . '/includes/auth.php';
require_login();
require __DIR__ . '/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: security.php');
    exit;
}
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid form token. Reload and try again.');
}
if (($_POST['delete_choice'] ?? 'no') !== 'yes') {
    $_SESSION['security_notice'] = 'Your account is still active.';
    header('Location: security.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$password = (string) ($_POST['current_password'] ?? '');
$stmt = mysqli_prepare($conn, 'SELECT password,role FROM users WHERE user_id=? LIMIT 1');
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$account = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$account || !password_verify($password, $account['password'])) {
    $_SESSION['security_error'] = 'Account deletion was cancelled because the password did not match.';
    header('Location: security.php');
    exit;
}

$active = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM book_loans bl INNER JOIN members m ON m.member_id=bl.member_id WHERE m.user_id=? AND bl.loan_status IN ('Ongoing','Overdue')");
mysqli_stmt_bind_param($active, 'i', $userId);
mysqli_stmt_execute($active);
$activeCount = (int) (mysqli_fetch_assoc(mysqli_stmt_get_result($active))['total'] ?? 0);
mysqli_stmt_close($active);
if ($activeCount > 0) {
    $_SESSION['security_error'] = 'Return all active or overdue books before deleting your account.';
    header('Location: security.php');
    exit;
}

if ($account['role'] === 'Admin') {
    $admins = mysqli_query($conn, "SELECT COUNT(*) AS total FROM users WHERE role='Admin'");
    if ($admins && (int) mysqli_fetch_assoc($admins)['total'] <= 1) {
        $_SESSION['security_error'] = 'The last administrator account cannot be deleted.';
        header('Location: security.php');
        exit;
    }
}

mysqli_begin_transaction($conn);
try {
    // Pending requests are removed by their user_id foreign key; member and loan records remain.
    $delete = mysqli_prepare($conn, 'DELETE FROM users WHERE user_id=?');
    mysqli_stmt_bind_param($delete, 'i', $userId);
    if (!mysqli_stmt_execute($delete) || mysqli_stmt_affected_rows($delete) !== 1) {
        throw new RuntimeException('Could not close this account.');
    }
    mysqli_stmt_close($delete);
    mysqli_commit($conn);
} catch (Throwable $exception) {
    mysqli_rollback($conn);
    error_log('Account deletion failed: ' . $exception->getMessage());
    $_SESSION['security_error'] = 'Could not delete the account. Please try again.';
    header('Location: security.php');
    exit;
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();
session_start();
$_SESSION['account_closed'] = 'Your account has been deleted. Your library history remains on file.';
header('Location: index.php');
exit;
