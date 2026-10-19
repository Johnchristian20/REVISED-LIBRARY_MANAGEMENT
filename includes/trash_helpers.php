<?php
function ensureTrashTable(mysqli $conn): void
{
    static $ready = false;
    if ($ready) return;
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS recently_deleted (
        trash_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        record_type VARCHAR(32) NOT NULL,
        record_id INT UNSIGNED NOT NULL,
        display_name VARCHAR(255) NOT NULL,
        payload LONGTEXT NOT NULL,
        deleted_by INT UNSIGNED DEFAULT NULL,
        deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (trash_id),
        KEY idx_recently_deleted_date (deleted_at),
        KEY idx_recently_deleted_type (record_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ready = true;
}

function purgeExpiredTrash(mysqli $conn): void
{
    ensureTrashTable($conn);
    mysqli_query($conn, 'DELETE FROM recently_deleted WHERE deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
}

function trashRecord(mysqli $conn, string $type, int $id, int $actorId, string &$error): bool
{
    $tables = [
        'book' => ['table' => 'books', 'id' => 'book_id', 'label' => 'title'],
        'branch' => ['table' => 'branches', 'id' => 'branch_id', 'label' => 'branch_name'],
        'member' => ['table' => 'members', 'id' => 'member_id', 'label' => 'membership_number'],
        'loan' => ['table' => 'book_loans', 'id' => 'loan_id', 'label' => 'loan_reference']
    ];
    if (!isset($tables[$type]) || $id < 1) { $error = 'Invalid record.'; return false; }
    ensureTrashTable($conn);
    $meta = $tables[$type];
    $sql = 'SELECT * FROM `' . $meta['table'] . '` WHERE `' . $meta['id'] . '`=? LIMIT 1';
    $select = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($select, 'i', $id);
    mysqli_stmt_execute($select);
    $record = mysqli_fetch_assoc(mysqli_stmt_get_result($select));
    mysqli_stmt_close($select);
    if (!$record) { $error = 'Record was not found.'; return false; }
    $label = trim((string) ($record[$meta['label']] ?? ''));
    if ($type === 'member') $label = trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')) . ' · ' . $label;
    if ($type === 'loan') $label = $label . ' · ' . ($record['loan_status'] ?? '');

    mysqli_begin_transaction($conn);
    try {
        $payload = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $insert = mysqli_prepare($conn, 'INSERT INTO recently_deleted (record_type,record_id,display_name,payload,deleted_by) VALUES (?,?,?,?,?)');
        mysqli_stmt_bind_param($insert, 'sissi', $type, $id, $label, $payload, $actorId);
        if (!mysqli_stmt_execute($insert)) throw new RuntimeException('Could not move this record to Recently Deleted.');
        mysqli_stmt_close($insert);

        $delete = mysqli_prepare($conn, 'DELETE FROM `' . $meta['table'] . '` WHERE `' . $meta['id'] . '`=?');
        mysqli_stmt_bind_param($delete, 'i', $id);
        if (!mysqli_stmt_execute($delete) || mysqli_stmt_affected_rows($delete) !== 1) {
            $errno = mysqli_stmt_errno($delete);
            mysqli_stmt_close($delete);
            if ($errno === 1451) throw new RuntimeException('This record is still connected to other library records and cannot be moved to Recently Deleted.');
            throw new RuntimeException('Could not delete the record.');
        }
        mysqli_stmt_close($delete);

        if ($type === 'loan' && in_array($record['loan_status'] ?? '', ['Ongoing', 'Overdue'], true)) {
            $bookId = (int) $record['book_id'];
            $active = mysqli_prepare($conn, "SELECT COUNT(*) AS n FROM book_loans WHERE book_id=? AND loan_status IN ('Ongoing','Overdue')");
            mysqli_stmt_bind_param($active, 'i', $bookId);
            mysqli_stmt_execute($active);
            $count = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($active))['n'];
            mysqli_stmt_close($active);
            if ($count === 0) mysqli_query($conn, "UPDATE books SET status='Available' WHERE book_id=" . $bookId . " AND status='Checked Out'");
        }
        mysqli_commit($conn);
        return true;
    } catch (Throwable $exception) {
        mysqli_rollback($conn);
        $error = $exception->getMessage();
        return false;
    }
}

function restoreTrashRecord(mysqli $conn, int $trashId, string &$error): bool
{
    ensureTrashTable($conn);
    $select = mysqli_prepare($conn, 'SELECT record_type,payload FROM recently_deleted WHERE trash_id=? LIMIT 1');
    mysqli_stmt_bind_param($select, 'i', $trashId);
    mysqli_stmt_execute($select);
    $entry = mysqli_fetch_assoc(mysqli_stmt_get_result($select));
    mysqli_stmt_close($select);
    if (!$entry) { $error = 'That item is no longer in Recently Deleted.'; return false; }
    $tables = [
        'book' => ['table' => 'books', 'id' => 'book_id'],
        'branch' => ['table' => 'branches', 'id' => 'branch_id'],
        'member' => ['table' => 'members', 'id' => 'member_id'],
        'loan' => ['table' => 'book_loans', 'id' => 'loan_id']
    ];
    $type = $entry['record_type'];
    $record = json_decode($entry['payload'], true);
    if (!isset($tables[$type]) || !is_array($record)) { $error = 'This deleted item cannot be restored.'; return false; }
    $meta = $tables[$type];
    $id = (int) ($record[$meta['id']] ?? 0);
    if ($id < 1) { $error = 'This deleted item is missing its record ID.'; return false; }

    mysqli_begin_transaction($conn);
    try {
        $columns = array_keys($record);
        $escapedColumns = array_map(static fn($column) => '`' . str_replace('`', '``', $column) . '`', $columns);
        $values = array_map(static function ($value) use ($conn) {
            if ($value === null) return 'NULL';
            return "'" . mysqli_real_escape_string($conn, (string) $value) . "'";
        }, array_values($record));
        $insertSql = 'INSERT INTO `' . $meta['table'] . '` (' . implode(',', $escapedColumns) . ') VALUES (' . implode(',', $values) . ')';
        if (!mysqli_query($conn, $insertSql)) {
            if (mysqli_errno($conn) === 1062) throw new RuntimeException('A record with the same ID already exists.');
            if (mysqli_errno($conn) === 1452) throw new RuntimeException('Restore the related branch, book, or member first, then try again.');
            throw new RuntimeException('Could not restore this record.');
        }
        if ($type === 'loan' && in_array($record['loan_status'] ?? '', ['Ongoing', 'Overdue'], true)) {
            $bookId = (int) $record['book_id'];
            $update = mysqli_prepare($conn, "UPDATE books SET status='Checked Out' WHERE book_id=? AND status='Available'");
            mysqli_stmt_bind_param($update, 'i', $bookId);
            mysqli_stmt_execute($update);
            mysqli_stmt_close($update);
        }
        $remove = mysqli_prepare($conn, 'DELETE FROM recently_deleted WHERE trash_id=?');
        mysqli_stmt_bind_param($remove, 'i', $trashId);
        mysqli_stmt_execute($remove);
        mysqli_stmt_close($remove);
        mysqli_commit($conn);
        return true;
    } catch (Throwable $exception) {
        mysqli_rollback($conn);
        $error = $exception->getMessage();
        return false;
    }
}
