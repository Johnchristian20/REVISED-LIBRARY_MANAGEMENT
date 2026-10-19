
<?php
require __DIR__ . '/../includes/auth.php';
require_admin();
require __DIR__ . '/../database.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Invalid form token. Reload and try again.'); }
$id = (int) ($_POST['id'] ?? 0);
$error = '';
if (trashRecord($conn, 'member', $id, (int) $_SESSION['user_id'], $error)) {
    $_SESSION['flash'] = 'Member moved to Recently Deleted. You can restore the record for 30 days.';
    header('Location: ../recently-deleted.php');
} else {
    $_SESSION['flash'] = $error ?: 'Could not move the member to Recently Deleted.';
    header('Location: index.php');
}
exit;
