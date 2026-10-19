
<?php
require __DIR__ . '/includes/auth.php';
require_login();
require __DIR__ . '/database.php';
require __DIR__ . '/includes/profile_helpers.php';
require __DIR__ . '/includes/loan_fines.php';
ensureProfileMembershipSchema($conn);
refreshOverdueFines($conn);
$pageTitle = 'Overview';
$basePath = '';
$counts = ['books' => 0, 'members' => 0, 'loans' => 0, 'available' => 0];
foreach ([
  'books' => "SELECT COUNT(*) AS total FROM books",
  'members' => "SELECT COUNT(*) AS total FROM members",
  'loans' => "SELECT COUNT(*) AS total FROM book_loans",
  'available' => "SELECT COUNT(*) AS total FROM books WHERE status='Available'"
] as $key => $sql) {
  $res = mysqli_query($conn, $sql);
  if ($res) {
    $row = mysqli_fetch_assoc($res);
    $counts[$key] = (int) $row['total'];
  }
}
$recentSql = "SELECT b.book_id, b.title, b.author_name, b.genre, b.status, br.branch_name
              FROM books b INNER JOIN branches br ON b.allocated_branch_id = br.branch_id
              ORDER BY b.book_id DESC LIMIT 6";
$recent = mysqli_query($conn, $recentSql);
$isAdmin = (($_SESSION['role'] ?? '') === 'Admin');
$recentMembers = null;
$newMembersThisWeek = 0;
if ($isAdmin) {
  $newMemberCountResult = mysqli_query($conn, "SELECT COUNT(*) AS total FROM members WHERE registration_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
  if ($newMemberCountResult) $newMembersThisWeek = (int) (mysqli_fetch_assoc($newMemberCountResult)['total'] ?? 0);
  $recentMembers = mysqli_query($conn, "SELECT m.member_id,m.membership_number,m.first_name,m.last_name,m.membership_type,m.account_status,m.registration_date,b.branch_name FROM members m LEFT JOIN branches b ON b.branch_id=m.home_branch_id WHERE m.registration_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) ORDER BY m.member_id DESC LIMIT 6");
}
$currentProfile = (($_SESSION['role'] ?? '') === 'User') ? loadUserProfile($conn, (int) $_SESSION['user_id']) : null;
require __DIR__ . '/includes/header.php';
?>
<?php if (!empty($_SESSION['flash'])): ?><div class="alert alert-success"><?= e($_SESSION['flash']) ?></div><?php unset($_SESSION['flash']); endif; ?>
<section class="welcome-banner">
  <div>
    <div class="eyebrow">YOUR LIBRARY, AT A GLANCE</div>
    <h2>Good to see you, <?= e($_SESSION['first_name']) ?>.</h2>
    <p>Here is the latest snapshot of your library collection.</p>
  </div>
  <div class="banner-mark" aria-hidden="true"><svg viewBox="0 0 100 100">
      <path d="M12 24c15-7 28-4 38 5v51C40 71 27 68 12 75zM88 24c-15-7-28-4-38 5v51c10-9 23-12 38-5z" />
      <path d="M22 35l18 5M22 46l18 5M78 35L60 40M78 46L60 51" />
    </svg></div>
</section>
<?php if ($currentProfile && empty($currentProfile['member_id'])): ?>
  <section class="membership-prompt"><div><strong>Become a library member</strong><p>Complete your phone, home branch, and membership type to request books.</p></div><a class="button button-primary" href="profile.php?complete=1">Complete my profile</a></section>
<?php endif; ?>
<div class="stats-grid">
  <article class="stat-card">
    <div class="stat-top"><span>Total titles</span><span class="stat-icon">▤</span></div>
    <strong><?= number_format($counts['books']) ?></strong><small>Records in the catalogue</small>
  </article>
  <article class="stat-card">
    <div class="stat-top"><span>Available books</span><span class="stat-icon">✓</span></div>
    <strong id="available-book-count" aria-live="polite"><?= number_format($counts['available']) ?></strong><small>Ready to be borrowed · updates automatically</small>
  </article>
  <article class="stat-card">
    <div class="stat-top"><span>Registered members</span><span class="stat-icon">♙</span></div>
    <strong><?= number_format($counts['members']) ?></strong><small>Library member records</small>
  </article>
  <article class="stat-card">
    <div class="stat-top"><span>Loan records</span><span class="stat-icon">↗</span></div>
    <strong><?= number_format($counts['loans']) ?></strong><small>Borrowing history entries</small>
  </article>
</div>
<?php if ($isAdmin): ?>
<section class="panel dashboard-member-notifications">
  <div class="panel-heading">
    <div><div class="eyebrow">MEMBER ACTIVITY</div><h2>Recently added members</h2><p>New member records added in the last seven days.</p></div>
    <div class="dashboard-notification-tools"><span class="dashboard-new-count" id="new-member-count" data-count="<?= $newMembersThisWeek ?>" aria-live="polite"><?= number_format($newMembersThisWeek) ?> new</span><a class="button button-outline" href="members/index.php">Open members <span aria-hidden="true">→</span></a></div>
  </div>
  <div class="alert alert-success dashboard-member-alert" id="new-member-alert" role="status" hidden></div>
  <div class="table-wrap"><table><thead><tr><th>MEMBER</th><th>MEMBERSHIP NO.</th><th>TYPE</th><th>BRANCH</th><th>ADDED</th><th>STATUS</th></tr></thead><tbody id="recent-members-body">
  <?php if ($recentMembers && mysqli_num_rows($recentMembers) > 0): while ($member = mysqli_fetch_assoc($recentMembers)): ?>
    <tr><td><strong><?= e($member['first_name'] . ' ' . $member['last_name']) ?></strong></td><td><?= e($member['membership_number']) ?></td><td><?= e($member['membership_type']) ?></td><td><?= e($member['branch_name'] ?: '—') ?></td><td><?= e($member['registration_date']) ?></td><td><span class="status status-<?= strtolower(str_replace(' ', '-', $member['account_status'])) ?>"><?= e($member['account_status']) ?></span></td></tr>
  <?php endwhile; else: ?><tr><td colspan="6" class="empty-state">No new members in the last seven days.</td></tr><?php endif; ?>
  </tbody></table></div>
</section>
<?php endif; ?>
<section class="panel">
  <div class="panel-heading">
    <div>
      <div class="eyebrow">COLLECTION</div>
      <h2>Recently added books</h2>
      <p>Latest records from the book catalogue.</p>
    </div><a class="button button-outline" href="books/index.php">View catalogue <span>→</span></a>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>BOOK TITLE</th>
          <th>AUTHOR</th>
          <th>GENRE</th>
          <th>BRANCH</th>
          <th>STATUS</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($recent && mysqli_num_rows($recent) > 0):
          while ($book = mysqli_fetch_assoc($recent)): ?>
            <tr>
              <td><strong><?= e($book['title']) ?></strong><small class="cell-sub"><?= e($book['book_id']) ?> · Catalogue
                  ID</small></td>
              <td><?= e($book['author_name']) ?></td>
              <td><?= e($book['genre']) ?></td>
              <td><?= e($book['branch_name']) ?></td>
              <td><span
                  class="status status-<?= strtolower(str_replace(' ', '-', $book['status'])) ?>"><?= e($book['status']) ?></span>
              </td>
            </tr>
          <?php endwhile; else: ?>
          <tr>
            <td colspan="5" class="empty-state">No book records found.</td>
          </tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
<script>
(() => {
  const count = document.getElementById('available-book-count');
  const memberBadge = document.getElementById('new-member-count');
  const memberBody = document.getElementById('recent-members-body');
  const memberAlert = document.getElementById('new-member-alert');
  let lastMemberCount = memberBadge ? Number(memberBadge.dataset.count || 0) : 0;
  const refreshAvailableCount = async () => {
    try {
      const response = await fetch('dashboard_stats.php', { cache: 'no-store' });
      if (!response.ok) return;
      const stats = await response.json();
      if (count && Number.isInteger(stats.available)) count.textContent = stats.available.toLocaleString();
      if (memberBadge && Number.isInteger(stats.new_members_count)) {
        memberBadge.textContent = `${stats.new_members_count.toLocaleString()} new`;
        memberBadge.dataset.count = String(stats.new_members_count);
        if (stats.new_members_count > lastMemberCount && memberAlert) {
          const added = stats.new_members_count - lastMemberCount;
          memberAlert.textContent = `${added} new member${added === 1 ? '' : 's'} added. The recent members list has been updated.`;
          memberAlert.hidden = false;
          window.setTimeout(() => { memberAlert.hidden = true; }, 6000);
        }
        lastMemberCount = stats.new_members_count;
      }
      if (memberBody && Array.isArray(stats.recent_members)) {
        memberBody.replaceChildren();
        if (!stats.recent_members.length) {
          const row = document.createElement('tr');
          const cell = document.createElement('td');
          cell.colSpan = 6;
          cell.className = 'empty-state';
          cell.textContent = 'No new members in the last seven days.';
          row.appendChild(cell);
          memberBody.appendChild(row);
        } else {
          stats.recent_members.forEach((member) => {
            const row = document.createElement('tr');
            const values = [`${member.first_name || ''} ${member.last_name || ''}`.trim(), member.membership_number, member.membership_type, member.branch_name || '—', member.registration_date];
            values.forEach((value, index) => {
              const cell = document.createElement('td');
              cell.textContent = value || '—';
              if (index === 0) { const strong = document.createElement('strong'); strong.textContent = cell.textContent; cell.replaceChildren(strong); }
              row.appendChild(cell);
            });
            const statusCell = document.createElement('td');
            const status = document.createElement('span');
            status.textContent = member.account_status || '—';
            status.className = `status status-${String(member.account_status || '').toLowerCase().replaceAll(' ', '-')}`;
            statusCell.appendChild(status);
            row.appendChild(statusCell);
            memberBody.appendChild(row);
          });
        }
      }
    } catch (_) {
      // Keep the last displayed value if the connection is briefly unavailable.
    }
  };
  window.setInterval(refreshAvailableCount, 10000);
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
