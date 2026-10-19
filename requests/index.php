<?php
require __DIR__ . '/../includes/auth.php';
require_login();
require __DIR__ . '/../database.php';
require __DIR__ . '/../includes/profile_helpers.php';
require __DIR__ . '/../includes/loan_fines.php';

if (!ensureProfileMembershipSchema($conn)) {
    http_response_code(500);
    exit('Could not prepare membership linking. Run database/profile-membership-migration.sql.');
}
refreshOverdueFines($conn);

$pageTitle = 'Book requests';
$basePath = '../';
$isAdmin = (($_SESSION['role'] ?? '') === 'Admin');
$selectedBookId = max(0, (int) ($_GET['book_id'] ?? 0));
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$requestSchema = "CREATE TABLE IF NOT EXISTS book_requests (
  request_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  book_id INT NOT NULL,
  pickup_branch_id INT NOT NULL,
  request_note VARCHAR(500) NOT NULL DEFAULT '',
  status ENUM('Pending','Approved','Rejected','Cancelled','Checked Out') NOT NULL DEFAULT 'Pending',
  request_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by INT UNSIGNED DEFAULT NULL,
  reviewed_at DATETIME DEFAULT NULL,
  PRIMARY KEY (request_id),
  KEY idx_requests_user_status (user_id,status),
  KEY idx_requests_book_status (book_id,status),
  CONSTRAINT fk_requests_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_requests_book FOREIGN KEY (book_id) REFERENCES books(book_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_requests_branch FOREIGN KEY (pickup_branch_id) REFERENCES branches(branch_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_requests_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(user_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci";
if (!mysqli_query($conn, $requestSchema)) {
    error_log('Book request schema setup failed: ' . mysqli_error($conn));
    http_response_code(500);
    exit('Book requests need the requests-migration.sql database update.');
}
$statusType = mysqli_query($conn, "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='book_requests' AND COLUMN_NAME='status'");
$statusInfo = $statusType ? mysqli_fetch_assoc($statusType) : null;
if ($statusInfo && strpos($statusInfo['COLUMN_TYPE'], 'Checked Out') === false) {
    if (!mysqli_query($conn, "ALTER TABLE book_requests MODIFY status ENUM('Pending','Approved','Rejected','Cancelled','Checked Out') NOT NULL DEFAULT 'Pending'")) {
        error_log('Book request status update failed: ' . mysqli_error($conn));
        http_response_code(500);
        exit('Run the updated requests-migration.sql to enable checkout tracking.');
    }
}

function req_redirect(): void
{
    header('Location: index.php');
    exit;
}

$uid = (int) $_SESSION['user_id'];
$memberProfile = $isAdmin ? null : loadUserProfile($conn, $uid);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid form token. Reload the page and try again.');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'request' && !$isAdmin) {
        $bookId = (int) ($_POST['book_id'] ?? 0);
        $branchId = (int) ($_POST['pickup_branch_id'] ?? 0);
        $note = trim($_POST['request_note'] ?? '');
        $memberProfile = loadUserProfile($conn, $uid);
        if (empty($memberProfile['member_id'])) {
            header('Location: ../profile.php?complete=1&book_id=' . $bookId);
            exit;
        }
        if (($memberProfile['account_status'] ?? '') !== 'Active' || ($memberProfile['expiration_date'] ?? '') < date('Y-m-d')) {
            $_SESSION['flash'] = 'Your membership is inactive or expired. Contact the librarian before requesting a book.';
            req_redirect();
        }
        $check = mysqli_prepare($conn, "SELECT book_id FROM books WHERE book_id=? AND status='Available'");
        mysqli_stmt_bind_param($check, 'i', $bookId);
        mysqli_stmt_execute($check);
        $found = mysqli_fetch_assoc(mysqli_stmt_get_result($check));
        mysqli_stmt_close($check);
        $branchCheck = mysqli_prepare($conn, 'SELECT branch_id FROM branches WHERE branch_id=?');
        mysqli_stmt_bind_param($branchCheck, 'i', $branchId);
        mysqli_stmt_execute($branchCheck);
        $branchExists = (bool) mysqli_fetch_assoc(mysqli_stmt_get_result($branchCheck));
        mysqli_stmt_close($branchCheck);

        if (!$found || !$branchExists) {
            $_SESSION['flash'] = 'That book is no longer available or the pickup branch is invalid.';
        } else {
            $dupe = mysqli_prepare($conn, "SELECT request_id FROM book_requests WHERE user_id=? AND book_id=? AND status IN ('Pending','Approved') LIMIT 1");
            mysqli_stmt_bind_param($dupe, 'ii', $uid, $bookId);
            mysqli_stmt_execute($dupe);
            $active = mysqli_fetch_assoc(mysqli_stmt_get_result($dupe));
            mysqli_stmt_close($dupe);
            if ($active) {
                $_SESSION['flash'] = 'You already have an active request for this title.';
            } else {
                $insert = mysqli_prepare($conn, 'INSERT INTO book_requests(user_id,book_id,pickup_branch_id,request_note) VALUES(?,?,?,?)');
                mysqli_stmt_bind_param($insert, 'iiis', $uid, $bookId, $branchId, $note);
                $_SESSION['flash'] = mysqli_stmt_execute($insert)
                    ? 'Your request was sent. Your library membership is linked to this account.'
                    : 'The request could not be saved. Please try again.';
                mysqli_stmt_close($insert);
            }
        }
        req_redirect();
    }

    if ($isAdmin && in_array($action, ['approve', 'reject'], true)) {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        mysqli_begin_transaction($conn);
        $stmt = mysqli_prepare($conn, 'SELECT book_id,status FROM book_requests WHERE request_id=? FOR UPDATE');
        mysqli_stmt_bind_param($stmt, 'i', $requestId);
        mysqli_stmt_execute($stmt);
        $request = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if ($request && $request['status'] === 'Pending') {
            $newStatus = 'Rejected';
            if ($action === 'approve') {
                $book = mysqli_prepare($conn, "UPDATE books SET status='Reserved' WHERE book_id=? AND status='Available'");
                mysqli_stmt_bind_param($book, 'i', $request['book_id']);
                mysqli_stmt_execute($book);
                $reserved = mysqli_stmt_affected_rows($book) === 1;
                mysqli_stmt_close($book);
                if ($reserved) $newStatus = 'Approved';
            }
            $reviewer = $uid;
            $update = mysqli_prepare($conn, 'UPDATE book_requests SET status=?,reviewed_by=?,reviewed_at=NOW() WHERE request_id=?');
            mysqli_stmt_bind_param($update, 'sii', $newStatus, $reviewer, $requestId);
            mysqli_stmt_execute($update);
            mysqli_stmt_close($update);
            mysqli_commit($conn);
            $_SESSION['flash'] = $newStatus === 'Approved' ? 'Request approved. The book is reserved until pickup.' : 'Request declined or the book is no longer available.';
        } else {
            mysqli_rollback($conn);
            $_SESSION['flash'] = 'This request was already reviewed.';
        }
        req_redirect();
    }

    if ($isAdmin && $action === 'checkout') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        mysqli_begin_transaction($conn);
        try {
            $stmt = mysqli_prepare($conn, 'SELECT book_id,user_id,pickup_branch_id,status FROM book_requests WHERE request_id=? FOR UPDATE');
            mysqli_stmt_bind_param($stmt, 'i', $requestId);
            mysqli_stmt_execute($stmt);
            $request = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if (!$request || $request['status'] !== 'Approved') throw new RuntimeException('Only an approved request can be checked out.');

            $memberStmt = mysqli_prepare($conn, 'SELECT member_id,account_status,max_borrow_limit,expiration_date FROM members WHERE user_id=? LIMIT 1 FOR UPDATE');
            mysqli_stmt_bind_param($memberStmt, 'i', $request['user_id']);
            mysqli_stmt_execute($memberStmt);
            $member = mysqli_fetch_assoc(mysqli_stmt_get_result($memberStmt));
            mysqli_stmt_close($memberStmt);
            if (!$member || $member['account_status'] !== 'Active' || $member['expiration_date'] < date('Y-m-d')) {
                throw new RuntimeException('The requester needs an active membership before checkout.');
            }

            $activeLoans = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM book_loans WHERE member_id=? AND loan_status IN ('Ongoing','Overdue')");
            mysqli_stmt_bind_param($activeLoans, 'i', $member['member_id']);
            mysqli_stmt_execute($activeLoans);
            $activeCount = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($activeLoans))['total'];
            mysqli_stmt_close($activeLoans);
            if ($activeCount >= (int) $member['max_borrow_limit']) throw new RuntimeException('The member has reached their borrowing limit.');

            $bookStmt = mysqli_prepare($conn, 'SELECT status FROM books WHERE book_id=? FOR UPDATE');
            mysqli_stmt_bind_param($bookStmt, 'i', $request['book_id']);
            mysqli_stmt_execute($bookStmt);
            $book = mysqli_fetch_assoc(mysqli_stmt_get_result($bookStmt));
            mysqli_stmt_close($bookStmt);
            if (!$book || $book['status'] !== 'Reserved') throw new RuntimeException('This book is no longer reserved for the request.');

            $reference = 'LN-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $checkoutDate = date('Y-m-d H:i:s');
            $dueDate = date('Y-m-d H:i:s', strtotime('+14 days'));
            $recordedBy = trim(($_SESSION['first_name'] ?? 'Admin') . ' ' . ($_SESSION['last_name'] ?? ''));
            $loanStmt = mysqli_prepare($conn, "INSERT INTO book_loans (loan_reference,book_id,member_id,checkout_branch_id,checkout_date,due_date,return_date,loan_status,late_fine_amount,renewal_count,recorded_by_staff) VALUES (?,?,?,?,?,?,NULL,'Ongoing',0,0,?)");
            mysqli_stmt_bind_param($loanStmt, 'siiisss', $reference, $request['book_id'], $member['member_id'], $request['pickup_branch_id'], $checkoutDate, $dueDate, $recordedBy);
            if (!mysqli_stmt_execute($loanStmt)) throw new RuntimeException('Could not create the loan record.');
            mysqli_stmt_close($loanStmt);

            $bookUpdate = mysqli_prepare($conn, "UPDATE books SET status='Checked Out' WHERE book_id=? AND status='Reserved'");
            mysqli_stmt_bind_param($bookUpdate, 'i', $request['book_id']);
            mysqli_stmt_execute($bookUpdate);
            if (mysqli_stmt_affected_rows($bookUpdate) !== 1) throw new RuntimeException('Could not update the book availability.');
            mysqli_stmt_close($bookUpdate);

            $requestUpdate = mysqli_prepare($conn, "UPDATE book_requests SET status='Checked Out' WHERE request_id=? AND status='Approved'");
            mysqli_stmt_bind_param($requestUpdate, 'i', $requestId);
            mysqli_stmt_execute($requestUpdate);
            if (mysqli_stmt_affected_rows($requestUpdate) !== 1) throw new RuntimeException('Could not complete the request.');
            mysqli_stmt_close($requestUpdate);

            mysqli_commit($conn);
            $_SESSION['flash'] = 'Book checked out and loan ' . $reference . ' was created with a 14-day due date.';
        } catch (Throwable $exception) {
            mysqli_rollback($conn);
            $_SESSION['flash'] = $exception instanceof RuntimeException ? $exception->getMessage() : 'Checkout could not be completed.';
        }
        req_redirect();
    }

    if ($action === 'cancel' && !$isAdmin) {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $stmt = mysqli_prepare($conn, "UPDATE book_requests SET status='Cancelled' WHERE request_id=? AND user_id=? AND status='Pending'");
        mysqli_stmt_bind_param($stmt, 'ii', $requestId, $uid);
        mysqli_stmt_execute($stmt);
        $cancelled = mysqli_stmt_affected_rows($stmt) > 0;
        mysqli_stmt_close($stmt);
        $_SESSION['flash'] = $cancelled ? 'Request cancelled.' : 'This request can no longer be cancelled.';
        req_redirect();
    }
}

$branches = mysqli_query($conn, 'SELECT branch_id,branch_name FROM branches ORDER BY branch_name');
$sql = 'SELECT r.request_id,r.status,r.request_note,r.request_date,r.reviewed_at,b.title,b.author_name,b.status AS book_status,br.branch_name,u.first_name,u.last_name,u.username
        FROM book_requests r
        JOIN books b ON b.book_id=r.book_id
        JOIN branches br ON br.branch_id=r.pickup_branch_id
        JOIN users u ON u.user_id=r.user_id' . ($isAdmin ? '' : ' WHERE r.user_id=?') . ' ORDER BY r.request_id DESC';
$stmt = mysqli_prepare($conn, $sql);
if (!$isAdmin) mysqli_stmt_bind_param($stmt, 'i', $uid);
mysqli_stmt_execute($stmt);
$requests = mysqli_stmt_get_result($stmt);
require __DIR__ . '/../includes/header.php';
?>
<section class="panel">
  <div class="panel-heading"><div><div class="eyebrow">CIRCULATION</div><h2><?= $isAdmin ? 'Manage book requests' : 'Your book requests' ?></h2><p><?= $isAdmin ? 'Review requests, reserve books, and record pickup.' : 'Request available books and follow their progress.' ?></p></div><a class="button button-outline" href="../books/index.php">Browse catalogue</a></div>
  <?php if (!empty($_SESSION['flash'])): ?><div class="alert alert-success"><?= e($_SESSION['flash']) ?></div><?php unset($_SESSION['flash']); endif; ?>
  <?php if (!$isAdmin && empty($memberProfile['member_id'])): ?>
    <div class="alert alert-info">Complete your profile first. Saving your phone, home branch, and membership type will enroll you automatically.</div>
    <p><a class="button button-primary" href="../profile.php?complete=1<?= $selectedBookId ? '&amp;book_id=' . $selectedBookId : '' ?>">Complete membership profile</a></p>
  <?php elseif (!$isAdmin && ($memberProfile['account_status'] ?? '') !== 'Active'): ?>
    <div class="alert alert-info">Your membership is <?= e($memberProfile['account_status'] ?? 'incomplete') ?>. Contact the librarian to activate it before requesting books.</div>
  <?php elseif (!$isAdmin): ?>
    <form method="post" class="filter-bar" data-loading-label="Sending your request">
      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="request">
      <label>Available book<select name="book_id" required><option value="">Choose a title</option><?php $available = mysqli_query($conn, "SELECT book_id,title,author_name FROM books WHERE status='Available' ORDER BY title"); while ($book = mysqli_fetch_assoc($available)): ?><option value="<?= (int) $book['book_id'] ?>" <?= $selectedBookId === (int) $book['book_id'] ? 'selected' : '' ?>><?= e($book['title'] . ' — ' . $book['author_name']) ?></option><?php endwhile; ?></select></label>
      <label>Pickup branch<select name="pickup_branch_id" required><?php while ($branch = mysqli_fetch_assoc($branches)): ?><option value="<?= (int) $branch['branch_id'] ?>" <?= (int) $memberProfile['home_branch_id'] === (int) $branch['branch_id'] ? 'selected' : '' ?>><?= e($branch['branch_name']) ?></option><?php endwhile; ?></select></label>
      <label>Note (optional)<input name="request_note" maxlength="500" placeholder="Anything the librarian should know?"></label><button class="button button-primary" type="submit">Request book</button>
    </form>
  <?php endif; ?>

  <div class="table-wrap"><table><thead><tr><?php if ($isAdmin): ?><th>REQUESTER</th><?php endif; ?><th>BOOK</th><th>PICKUP BRANCH</th><th>REQUESTED</th><th>STATUS</th><th><?= $isAdmin ? 'ACTIONS' : 'NOTE / ACTION' ?></th></tr></thead><tbody>
  <?php if (mysqli_num_rows($requests)): while ($request = mysqli_fetch_assoc($requests)): ?>
    <tr><?php if ($isAdmin): ?><td><?= e($request['first_name'] . ' ' . $request['last_name']) ?><small class="cell-sub">@<?= e($request['username']) ?></small></td><?php endif; ?>
      <td><strong><?= e($request['title']) ?></strong><small class="cell-sub"><?= e($request['author_name']) ?></small></td><td><?= e($request['branch_name']) ?></td><td><?= e($request['request_date']) ?></td>
      <td><span class="status status-<?= strtolower(str_replace(' ', '-', e($request['status']))) ?>"><?= e($request['status']) ?></span></td>
      <td><?php if ($isAdmin && $request['status'] === 'Pending'): ?><div class="actions">
          <form method="post" data-confirm-message="Approve this book request and reserve the title?" data-confirm-title="Approve request" data-confirm-action="Approve"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="request_id" value="<?= (int) $request['request_id'] ?>"><button class="action-link" type="submit">Approve &amp; reserve</button></form>
          <form method="post" data-confirm-message="Decline this book request?" data-confirm-title="Decline request" data-confirm-action="Decline" data-confirm-danger="true"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="reject"><input type="hidden" name="request_id" value="<?= (int) $request['request_id'] ?>"><button class="action-link danger-link" type="submit">Decline</button></form>
        </div><?php elseif ($isAdmin && $request['status'] === 'Approved'): ?>
          <form method="post" data-confirm-message="This will create a 14-day loan and mark the book checked out. Continue only after pickup." data-confirm-title="Record book pickup" data-confirm-action="Check out book"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="checkout"><input type="hidden" name="request_id" value="<?= (int) $request['request_id'] ?>"><button class="action-link" type="submit">Mark picked up &amp; check out</button></form>
        <?php else: ?><?= e($request['request_note'] ?: ($request['status'] === 'Approved' ? 'Reserved for pickup.' : ($request['status'] === 'Checked Out' ? 'Loan created.' : ''))) ?>
          <?php if (!$isAdmin && $request['status'] === 'Pending'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="request_id" value="<?= (int) $request['request_id'] ?>"><button class="action-link danger-link" type="submit">Cancel request</button></form><?php endif; ?>
        <?php endif; ?></td></tr>
  <?php endwhile; else: ?><tr><td colspan="<?= $isAdmin ? 6 : 5 ?>" class="empty-state">No book requests yet.</td></tr><?php endif; ?>
  </tbody></table></div>
  <?php if ($isAdmin): ?><p class="muted">Approve to reserve a book. After pickup, mark it checked out to create its loan record automatically.</p><?php endif; ?>
</section>
<?php mysqli_stmt_close($stmt); require __DIR__ . '/../includes/footer.php'; ?>
