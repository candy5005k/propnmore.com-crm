<?php
require_once __DIR__ . '/config.php';
$user = requireAuth('admin');
$pageTitle = 'Projects';

$pdo   = db();
$error = $success = '';

// ── Auto-Detect Projects from Leads ──────────────────────────────────────────
// If leads come in via API/Webhook with a 'project_name', this automatically
// creates the project in the projects table and links the leads permanently.
$pdo->exec("INSERT IGNORE INTO projects (name) 
            SELECT DISTINCT project_name FROM leads 
            WHERE project_name IS NOT NULL AND project_name != ''");
            
$pdo->exec("UPDATE leads l JOIN projects p ON l.project_name = p.name 
            SET l.project_id = p.id 
            WHERE l.project_id IS NULL");

// ── Manual Actions ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['pname'])) {
    $name = trim($_POST['pname']);
    if ($name) {
        $pdo->prepare('INSERT IGNORE INTO projects (name) VALUES (?)')->execute([$name]);
        $success = "Project '{$name}' added.";
    }
}
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['del_id'])) {
    $pid = (int)$_POST['del_id'];
    // Update leads first to unset project_id to prevent constraint issues just in case
    $pdo->prepare('UPDATE leads SET project_id=NULL WHERE project_id=?')->execute([$pid]);
    $pdo->prepare('DELETE FROM projects WHERE id=?')->execute([$pid]);
    $success = 'Project removed.';
}

$projects = $pdo->query('SELECT p.*, COUNT(l.id) AS lead_count
    FROM projects p LEFT JOIN leads l ON l.project_id=p.id
    GROUP BY p.id ORDER BY p.name')->fetchAll();

// Dashboard Stats
$stats = $pdo->query('SELECT 
    (SELECT COUNT(*) FROM projects) as total_projects,
    (SELECT COUNT(DISTINCT project_id) FROM leads WHERE project_id IS NOT NULL) as active_projects,
    (SELECT COUNT(*) FROM leads WHERE project_id IS NOT NULL) as total_leads,
    (SELECT COUNT(*) FROM projects WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as new_projects
')->fetch();

include __DIR__ . '/includes/header.php';
?>

<div style="max-width:900px">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px;">
      <h2 style="font-family:'DM Serif Display',serif;font-size:24px;margin:0;">🏗️ Projects Overview</h2>
  </div>

  <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

  <!-- 4 Tabs Dashboard -->
  <style>
    .si{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px;vertical-align:middle;}
    .si-total{background:#c9a96e;} .si-active{background:#4ade80;} .si-leads{background:#60a5fa;} .si-new{background:#c084fc;}
    .stat-label{font-family:'Inter',sans-serif;font-size:12px;font-weight:600;letter-spacing:0.5px;text-transform:uppercase;color:#8a9ab8;margin-bottom:8px;}
    .stat-value{font-family:'Inter',sans-serif;font-weight:700;font-size:24px;color:#dde3f0;}
  </style>
  <div class="grid-4" style="margin-bottom:24px">
    <div class="stat-card" style="padding:20px;background:#111827;border:1px solid #1e2d45;border-radius:12px;">
      <div class="stat-label"><span class="si si-total"></span>All Projects</div>
      <div class="stat-value" style="color:#c9a96e;"><?= number_format($stats['total_projects'] ?: 0) ?></div>
    </div>
    <div class="stat-card" style="padding:20px;background:#111827;border:1px solid rgba(74, 222, 128, 0.3);border-radius:12px;">
      <div class="stat-label" style="color:#4ade80;"><span class="si si-active"></span>Active Projects</div>
      <div class="stat-value" style="color:#4ade80;"><?= number_format($stats['active_projects'] ?: 0) ?></div>
    </div>
    <div class="stat-card" style="padding:20px;background:#111827;border:1px solid rgba(96, 165, 250, 0.3);border-radius:12px;">
      <div class="stat-label" style="color:#60a5fa;"><span class="si si-leads"></span>Total Leads</div>
      <div class="stat-value" style="color:#60a5fa;"><?= number_format($stats['total_leads'] ?: 0) ?></div>
    </div>
    <div class="stat-card" style="padding:20px;background:#111827;border:1px solid rgba(192, 132, 252, 0.3);border-radius:12px;">
      <div class="stat-label" style="color:#c084fc;"><span class="si si-new"></span>New Last 30 Days</div>
      <div class="stat-value" style="color:#c084fc;"><?= number_format($stats['new_projects'] ?: 0) ?></div>
    </div>
  </div>

  <div class="card" style="margin-bottom:20px">
    <form method="POST" style="display:flex;gap:10px">
      <input type="text" name="pname" class="form-control" placeholder="Create new project manually…" required style="flex:1;margin:0">
      <button type="submit" class="btn btn-primary" style="padding:10px 24px;">➕ Add Project</button>
    </form>
  </div>

  <div class="tbl-wrap">
    <table>
      <thead>
          <tr>
            <th># ID</th>
            <th>Project Name</th>
            <th style="text-align:center;">Leads Received</th>
            <th>Added On</th>
            <th style="text-align:right;">Action</th>
          </tr>
      </thead>
      <tbody>
        <?php if (empty($projects)): ?>
        <tr><td colspan="5" style="text-align:center;padding:30px;color:var(--text2);">No projects found.</td></tr>
        <?php endif; ?>
        <?php foreach ($projects as $p): ?>
        <tr>
          <td style="color:var(--text2);font-size:12px">#<?= $p['id'] ?></td>
          <td><strong style="font-size:14px;"><?= htmlspecialchars($p['name']) ?></strong></td>
          <td style="text-align:center;">
            <?php if ($p['lead_count'] > 0): ?>
                <a href="<?= BASE_URL ?>/index.php?project=<?= $p['id'] ?>" style="display:inline-block;background:rgba(201,169,110,0.15);color:#c9a96e;text-decoration:none;font-size:13px;padding:4px 12px;border-radius:20px;font-weight:600;transition:0.2s;">
                  <?= $p['lead_count'] ?> leads →
                </a>
            <?php else: ?>
                <span style="color:var(--text2);font-size:13px;padding:4px 12px;">0 leads</span>
            <?php endif; ?>
          </td>
          <td style="color:var(--text2);font-size:13px;"><?= date('d M Y', strtotime($p['created_at'])) ?></td>
          <td style="text-align:right;">
            <form method="POST" onsubmit="return confirm('Remove this project? Leads will not be deleted, but they will be unlinked from it.')">
              <input type="hidden" name="del_id" value="<?= $p['id'] ?>">
              <button type="submit" style="background:transparent;border:1px solid rgba(239,68,68,0.3);color:#f87171;border-radius:6px;padding:4px 10px;cursor:pointer;font-size:14px;transition:0.2s;" onmouseover="this.style.background='rgba(239,68,68,0.1)'" onmouseout="this.style.background='transparent'">
                🗑 Delete
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
