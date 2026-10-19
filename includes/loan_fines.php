<?php
/**
 * Refresh overdue loan state and accrue the library's daily late fee.
 * Fine policy: PHP 10.00 per calendar day after the due date.
 * The accrued-through date prevents duplicate charges on repeated page loads.
 */
function refreshOverdueFines(mysqli $conn): void
{
    static $columnReady = false;
    if ($columnReady) {
        return;
    }

    $check = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS column_count
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'book_loans'
           AND COLUMN_NAME = 'fine_assessed_through'"
    );

    $columnAdded = false;
    if (!$check || (int) mysqli_fetch_assoc($check)['column_count'] === 0) {
        if (!mysqli_query(
            $conn,
            'ALTER TABLE book_loans ADD COLUMN fine_assessed_through DATE NULL DEFAULT NULL AFTER late_fine_amount'
        )) {
            error_log('Could not prepare automatic late fine tracking: ' . mysqli_error($conn));
            return;
        }
        $columnAdded = true;
    }
    $columnReady = true;

    // On first-time schema upgrade, preserve old overdue/returned fine amounts
    // and begin daily accrual from today. New returned loans must be calculated
    // from their due dates, so this initialization only runs during the upgrade.
    if ($columnAdded) {
        mysqli_query(
            $conn,
            "UPDATE book_loans
             SET fine_assessed_through = COALESCE(DATE(return_date), CURDATE())
             WHERE fine_assessed_through IS NULL
               AND loan_status IN ('Overdue', 'Returned')"
        );
    }

    $sql = "UPDATE book_loans
            SET loan_status = CASE
                    WHEN return_date IS NULL AND loan_status = 'Ongoing' AND due_date < NOW()
                    THEN 'Overdue'
                    ELSE loan_status
                END,
                late_fine_amount = late_fine_amount +
                    GREATEST(0, DATEDIFF(
                        COALESCE(DATE(return_date), CURDATE()),
                        COALESCE(fine_assessed_through, DATE(due_date))
                    )) * 10.00,
                fine_assessed_through = COALESCE(DATE(return_date), CURDATE())
            WHERE loan_status IN ('Ongoing', 'Overdue', 'Returned')
              AND DATE(due_date) < COALESCE(DATE(return_date), CURDATE())
              AND (fine_assessed_through IS NULL
                   OR fine_assessed_through < COALESCE(DATE(return_date), CURDATE()))";

    if (!mysqli_query($conn, $sql)) {
        error_log('Automatic overdue fine refresh failed: ' . mysqli_error($conn));
    }
}
