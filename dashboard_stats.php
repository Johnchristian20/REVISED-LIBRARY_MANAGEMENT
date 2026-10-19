<?php
require __DIR__ . '/includes/auth.php';
require_login();
require __DIR__ . '/database.php';
require __DIR__ . '/includes/loan_fines.php';

refreshOverdueFines($conn);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$result = mysqli_query($conn, "SELECT COUNT(*) AS available FROM books WHERE status='Available'");
if (!$result) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not load the current availability count.']);
    exit;
}

$row = mysqli_fetch_assoc($result);
$payload = ['available' => (int) $row['available']];
if (($_SESSION['role'] ?? '') === 'Admin') {
    $countResult = mysqli_query($conn, "SELECT COUNT(*) AS total FROM members WHERE registration_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
    $membersResult = mysqli_query($conn, "SELECT m.membership_number,m.first_name,m.last_name,m.membership_type,m.account_status,m.registration_date,b.branch_name FROM members m LEFT JOIN branches b ON b.branch_id=m.home_branch_id WHERE m.registration_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) ORDER BY m.member_id DESC LIMIT 6");
    $payload['new_members_count'] = $countResult ? (int) (mysqli_fetch_assoc($countResult)['total'] ?? 0) : 0;
    $payload['recent_members'] = [];
    if ($membersResult) while ($member = mysqli_fetch_assoc($membersResult)) $payload['recent_members'][] = $member;
}
echo json_encode($payload);
