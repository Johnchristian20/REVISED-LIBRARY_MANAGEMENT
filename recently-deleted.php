<?php
require __DIR__ . '/includes/auth.php';
require_admin();
require __DIR__ . '/database.php';
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$error = '';
$types = ['books' => 'book', 'members' => 'member', 'loans' => 'loan', 'branches' => 'branch'];
$selectedType = trim($_GET['type'] ?? '');
if (!in_array($selectedType, ['', ...array_values($types)], true)) $selectedType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Invalid form token. Reload and try again.'); }
    if (restoreTrashRecord($conn, (int) ($_POST['trash_id'] ?? 0), $error)) {
        $_SESSION['flash'] = 'Record restored successfully.';
        $redirectType = trim($_POST['type'] ?? '');
        header('Location: recently-deleted.php' . ($redirectType !== '' && in_array($redirectType, $types, true) ? '?type=' . rawurlencode($redirectType) : ''));
        exit;
    }
}
$items = $selectedType === ''
    ? mysqli_query($conn, 'SELECT trash_id,record_type,display_name,deleted_at,GREATEST(0,30-DATEDIFF(NOW(),deleted_at)) AS days_left FROM recently_deleted ORDER BY deleted_at DESC')
    : mysqli_prepare($conn, 'SELECT trash_id,record_type,display_name,deleted_at,GREATEST(0,30-DATEDIFF(NOW(),deleted_at)) AS days_left FROM recently_deleted WHERE record_type=? ORDER BY deleted_at DESC');
if ($selectedType !== '' && $items) { mysqli_stmt_bind_param($items, 's', $selectedType); mysqli_stmt_execute($items); $items = mysqli_stmt_get_result($items); }
$pageTitle = 'Recently deleted';
$basePath = '';
require __DIR__ . '/includes/header.php';
?>
<section class="panel trash-panel">
  <div class="panel-heading"><div><div class="eyebrow">RECOVERY &amp; CLEANUP</div><h2>Recently deleted</h2><p>Restore deleted records within 30 days. Items are permanently removed after that.</p></div></div>
  <?php if (!empty($_SESSION['flash'])): ?><div class="alert alert-success"><?= e($_SESSION['flash']) ?></div><?php unset($_SESSION['flash']); endif; ?>
  <?php if ($error !== ''): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form class="trash-filter" method="get"><label for="trashType">Filter records</label><select id="trashType" name="type"><option value="">All types</option><?php foreach ($types as $label => $value): ?><option value="<?= e($value) ?>" <?= $selectedType === $value ? 'selected' : '' ?>><?= e(ucfirst($label)) ?></option><?php endforeach; ?></select><button class="button button-primary" type="submit">Apply filter</button><?php if ($selectedType !== ''): ?><a class="button button-secondary" href="recently-deleted.php">Clear</a><?php endif; ?></form>
  <div class="table-wrap"><table><thead><tr><th>RECORD</th><th>TYPE</th><th>DELETED ON</th><th>TIME LEFT</th><th>ACTION</th></tr></thead><tbody>
    <?php if ($items && mysqli_num_rows($items)): while ($item = mysqli_fetch_assoc($items)): ?>
      <tr><td><strong><?= e($item['display_name']) ?></strong></td><td><span class="status status-reserved"><?= e(ucfirst($item['record_type'])) ?></span></td><td><?= e(date('M j, Y g:i A', strtotime($item['deleted_at']))) ?></td><td><?= (int) $item['days_left'] ?> days</td><td><form method="post"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="trash_id" value="<?= (int) $item['trash_id'] ?>"><input type="hidden" name="type" value="<?= e($selectedType) ?>"><button class="action-link" type="submit">Restore</button></form></td></tr>
    <?php endwhile; else: ?><tr><td colspan="5" class="empty-state">Nothing has been deleted recently.</td></tr><?php endif; ?>
  </tbody></table></div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
