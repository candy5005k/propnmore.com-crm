<?php
require_once __DIR__ . '/config.php';
$user = requireAuth('admin'); // Only admin sees recycle bin
$pageTitle = 'Recycle Bin';

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $leadId = (int)($_POST['lead_id'] ?? 0);

    if ($action === 'restore' && $leadId) {
        $pdo->prepare('UPDATE leads SET is_deleted=0 WHERE id=?')->execute([$leadId]);
    } elseif ($action === 'delete_forever' && $leadId) {
        $pdo->prepare('DELETE FROM leads WHERE id=?')->execute([$leadId]);
    } elseif ($action === 'empty_bin') {
        $pdo->query('DELETE FROM leads WHERE is_deleted=1');
    }
    header('Location: ' . BASE_URL . '/recycle_bin.php');
    exit;
}

$leads = $pdo->query('SELECT * FROM leads WHERE is_deleted=1 ORDER BY updated_at DESC')->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px">
  <h2 style="font-family:'DM Serif Display',serif;font-size:24px;color:var(--danger)">🗑️ Recycle Bin</h2>
  
  <?php if (count($leads) > 0): ?>
  <form method="POST" onsubmit="return confirm('WARNING: This will permanently delete ALL leads in the recycle bin. Continue?')">
    <input type="hidden" name="action" value="empty_bin">
    <button type="submit" class="btn btn-outline" style="border-color:var(--danger);color:var(--danger)">Empty Recycle Bin</button>
  </form>
  <?php endif; ?>
</div>

<div class="tbl-wrap">
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Name</th>
        <th>Mobile</th>
        <th>Deleted At</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($leads)): ?>
        <tr><td colspan="5" style="text-align:center;padding:40px;color:var(--text2)">Recycle bin is empty.</td></tr>
      <?php endif; ?>
      <?php foreach ($leads as $l): ?>
        <tr>
          <td>#<?= $l['id'] ?></td>
          <td><strong><?= htmlspecialchars($l['first_name'] . ' ' . $l['last_name']) ?></strong></td>
          <td><?= htmlspecialchars($l['mobile']) ?></td>
          <td><?= date('d M Y, H:i', strtotime($l['updated_at'])) ?></td>
          <td>
            <div style="display:flex;gap:10px">
              <form method="POST">
                <input type="hidden" name="action" value="restore">
                <input type="hidden" name="lead_id" value="<?= $l['id'] ?>">
                <button type="submit" class="btn btn-primary btn-sm">♻️ Restore</button>
              </form>
              <form method="POST" onsubmit="return confirm('Permanently delete this lead?')">
                <input type="hidden" name="action" value="delete_forever">
                <input type="hidden" name="lead_id" value="<?= $l['id'] ?>">
                <button type="submit" class="btn btn-outline btn-sm" style="border-color:var(--danger);color:var(--danger)">✕ Perma-delete</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
