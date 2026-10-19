
<?php
mysqli_report(MYSQLI_REPORT_OFF);
$host = "localhost";
$username = "root";
$password = "";
$database = "library_management";
$conn = mysqli_connect($host, $username, $password, $database);
if (!$conn) {
    error_log("Database connection failed: " . mysqli_connect_error());
    http_response_code(500);
    exit("Database connection failed. Start MySQL in Laragon and check database.php settings.");
}
mysqli_set_charset($conn, "utf8mb4");
require_once __DIR__ . '/includes/trash_helpers.php';
purgeExpiredTrash($conn);
// Keep member status synchronized with the expiration date whenever the app is used.
mysqli_query($conn, "UPDATE members SET account_status='Expired' WHERE account_status='Active' AND expiration_date IS NOT NULL AND expiration_date < CURDATE()");
