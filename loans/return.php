<?php
require __DIR__ . '/../includes/auth.php';
require_admin();
require __DIR__ . '/../database.php';
require __DIR__ . '/../includes/loan_fines.php';

if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid form token. Reload the page and try again.');
}

$loanId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$loanId || $loanId < 1) {
    header('Location: index.php');
    exit;
}

// Accrue any fine through today before closing this active loan.
refreshOverdueFines($conn);

mysqli_begin_transaction($conn);
try {
    $stmt = mysqli_prepare($conn, "SELECT book_id, loan_status FROM book_loans WHERE loan_id=? FOR UPDATE");
    if (!$stmt) throw new RuntimeException('Could not load the loan record.');
    mysqli_stmt_bind_param($stmt, 'i', $loanId);
    mysqli_stmt_execute($stmt);
    $loan = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$loan) throw new RuntimeException('Loan record not found.');
    if (!in_array($loan['loan_status'], ['Ongoing', 'Overdue'], true)) {
        throw new RuntimeException('Only an ongoing or overdue loan can be marked returned.');
    }

    $stmt = mysqli_prepare($conn, "UPDATE book_loans SET return_date=NOW(), loan_status='Returned', fine_assessed_through=CURDATE() WHERE loan_id=? AND loan_status IN ('Ongoing','Overdue')");
    if (!$stmt) throw new RuntimeException('Could not prepare the return update.');
    mysqli_stmt_bind_param($stmt, 'i', $loanId);
    if (!mysqli_stmt_execute($stmt) || mysqli_stmt_affected_rows($stmt) !== 1) {
        mysqli_stmt_close($stmt);
        throw new RuntimeException('Could not mark the book as returned.');
    }
    mysqli_stmt_close($stmt);

    $bookId = (int) $loan['book_id'];
    $stmt = mysqli_prepare(
        $conn,
        "UPDATE books b SET b.status = CASE
            WHEN EXISTS (SELECT 1 FROM book_loans bl WHERE bl.book_id=b.book_id AND bl.loan_status='Lost') THEN 'Lost'
            WHEN EXISTS (SELECT 1 FROM book_loans bl WHERE bl.book_id=b.book_id AND bl.loan_status IN ('Ongoing','Overdue')) THEN 'Checked Out'
            WHEN EXISTS (SELECT 1 FROM book_requests br WHERE br.book_id=b.book_id AND br.status='Approved') THEN 'Reserved'
            ELSE 'Available' END WHERE b.book_id=?"
    );
    if (!$stmt) throw new RuntimeException('Could not prepare the book availability update.');
    mysqli_stmt_bind_param($stmt, 'i', $bookId);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        throw new RuntimeException('Could not update the book availability.');
    }
    mysqli_stmt_close($stmt);

    mysqli_commit($conn);
    $_SESSION['flash'] = 'Book marked as returned. Its overdue count has stopped and book availability was updated.';
} catch (Throwable $exception) {
    mysqli_rollback($conn);
    error_log('Loan return error: ' . $exception->getMessage());
    $_SESSION['flash'] = $exception instanceof RuntimeException ? $exception->getMessage() : 'Could not complete the book return.';
}

header('Location: index.php');
exit;
