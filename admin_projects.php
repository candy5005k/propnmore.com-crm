<?php
require_once __DIR__ . '/config.php';
$user = requireAuth('admin');
$pageTitle = 'Projects';

$pdo   = db();
$error = $success = '';

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['pname'])) {
    $name = trim($_POST['pname']);
    if ($name) {
        $pdo->prepare('INSERT IGNORE INTO projects (name) VALUES (?)')->execute([$name]);
        $success = "Project '{$name}' added.";
    }
}
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['del_id'])) {
    $pdo->prepare('DELETE FROM projects WHERE id=?')->execute([(int)$_POST['del_id']]);
    $success = 'Project removed.';
}

$projects = $pdo->query('SELECT p.*, COUNT(l.id) AS lead_count
    FROM projects p LEFT JOIN leads l ON l.project_id=p.id
    GROUP BY p.id ORDER BY p.name')->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div style="max-width:600px">
  <h2 style="font-family:'DM Serif Display',serif;font-size:22px;margin-bottom:24px">🏗️ Projects</h2>

  <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

  <div class="card" style="margin-bottom:20px">
    <form method="POST" style="display:flex;gap:10px">
      <input type="text" name="pname" class="form-control" placeholder="New project name…" required style="flex:1;margin:0">
      <button type="submit" class="btn btn-primary">Add</button>
    </form>
  </div>

  <div class="tbl-wrap">
    <table>
      <thead><tr><th>#</th><th>Project Name</th><th>Leads</th><th>Action</th></tr></thead>
      <tbody>
        <?php foreach ($projects as $p): ?>
        <tr>
          <td style="color:var(--text2);font-size:12px"><?= $p['id'] ?></td>
          <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
          <td>
            <a href="<?= BASE_URL ?>/index.php?project=<?= $p['id'] ?>" style="color:var(--accent);text-decoration:none;font-size:13px">
              <?= $p['lead_count'] ?> leads →
            </a>
          </td>
          <td>
            <form method="POST" onsubmit="return confirm('Remove project?')">
              <input type="hidden" name="del_id" value="<?= $p['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm">🗑</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
