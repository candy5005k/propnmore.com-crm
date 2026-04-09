<?php
require_once __DIR__ . '/config.php';
$user = requireAuth('admin');
$pageTitle = 'Projects';

$pdo   = db();
$error = $success = '';

// ── Auto-Detect New Projects ───────────────────────────────────────────────────
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
    $pdo->prepare('UPDATE leads SET project_id=NULL WHERE project_id=?')->execute([$pid]);
    $pdo->prepare('DELETE FROM projects WHERE id=?')->execute([$pid]);
    $success = 'Project removed.';
}
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['edit_id'], $_POST['new_name'])) {
    $eid = (int)$_POST['edit_id'];
    $newName = trim($_POST['new_name']);
    if ($newName) {
        // Check if the target name already exists (Merge Scenario)
        $checkStmt = $pdo->prepare('SELECT id FROM projects WHERE LOWER(name) = LOWER(?) AND id != ?');
        $checkStmt->execute([$newName, $eid]);
        $existingId = $checkStmt->fetchColumn();
        
        if ($existingId) {
            // MERGE: Move all existing leads from the current project to the target project
            $pdo->prepare('UPDATE leads SET project_id = ?, project_name = ? WHERE project_id = ?')
                ->execute([$existingId, $newName, $eid]);
            // DELETE: Remove the now heavily-depleted duplicate project
            $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$eid]);
            $success = "Successfully merged into existing '{$newName}'. Reflecting system wide.";
        } else {
            // RENAME: Safe standard rename
            $oldP = $pdo->prepare('SELECT name FROM projects WHERE id=?'); $oldP->execute([$eid]); $old = $oldP->fetchColumn();
            $pdo->prepare('UPDATE projects SET name=? WHERE id=?')->execute([$newName, $eid]);
            if ($old) {
                // Ensure legacy schema tags are in absolute sync natively.
                $pdo->prepare('UPDATE leads SET project_name=? WHERE project_id=?')->execute([$newName, $eid]);
            }
            $success = "Project globally renamed to '{$newName}'.";
        }
    }
}

$projects = $pdo->query('SELECT p.*, COUNT(l.id) AS lead_count
    FROM projects p LEFT JOIN leads l ON l.project_id=p.id
    GROUP BY p.id ORDER BY p.name')->fetchAll();

// Group projects by base name
$groups = [];
foreach ($projects as $p) {
    $name = $p['name'];
    $lower = strtolower($name);
    
    // By flattening the grouping system, every database project acts autonomously
    // Admin can use the powerful Merge capability to natively group forms permanently in the DB
    $groupName = $name;

    
    if (!isset($groups[$groupName])) $groups[$groupName] = ['total_leads' => 0, 'items' => []];
    $groups[$groupName]['items'][] = $p;
    $groups[$groupName]['total_leads'] += $p['lead_count'];
}
ksort($groups);

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
    .tr-group-master { cursor: pointer; transition: 0.2s; background: rgba(201,169,110,0.03); }
    .tr-group-master:hover { background: rgba(201,169,110,0.08); }
    .tr-sub-item td { background: rgba(14, 21, 33, 0.5); font-size:13px; }
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
    <table id="projectsTable">
      <thead>
          <tr>
            <th>#</th>
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
        <?php 
          $srNo = 1; 
          foreach ($groups as $groupName => $groupData): 
            $isGroup = count($groupData['items']) > 1;
            $groupId = md5($groupName);
        ?>
            <?php if ($isGroup): ?>
            <!-- Master Category Row -->
            <tr class="tr-group-master" onclick="document.querySelectorAll('.sub-group-<?= $groupId ?>').forEach(e => e.style.display = e.style.display === 'none' ? 'table-row' : 'none');">
              <td style="color:var(--accent);font-size:13px;font-weight:700;"><?= $srNo++ ?></td>
              <td style="font-size:15px;">
                  <strong style="color:var(--accent);">📁 <?= htmlspecialchars($groupName) ?></strong>
                  <span style="font-size:12px;color:var(--text2);margin-left:8px;font-weight:500;">(<?= count($groupData['items']) ?> sub-forms) ▼</span>
              </td>
              <td style="text-align:center;">
                  <span style="display:inline-block;background:rgba(201,169,110,0.2);color:#c9a96e;font-size:14px;padding:4px 14px;border-radius:20px;font-weight:700;">
                    <?= $groupData['total_leads'] ?> total leads
                  </span>
              </td>
              <td colspan="2"></td>
            </tr>
            
            <!-- Sub-items (Hidden by default) -->
            <?php $subIndex = 1; foreach($groupData['items'] as $p): ?>
            <tr class="sub-group-<?= $groupId ?> tr-sub-item" style="display:none;">
              <td style="color:var(--text2);font-size:12px;text-align:right;padding-right:12px;">↳ <?= $subIndex++ ?></td>
              <td style="padding-left:24px;">
                <strong style="font-size:13px;color:#e0e6ef;"><?= htmlspecialchars($p['name']) ?></strong>
              </td>
              <td style="text-align:center;">
                <?php if ($p['lead_count'] > 0): ?>
                    <a href="<?= BASE_URL ?>/index.php?project=<?= $p['id'] ?>" style="display:inline-block;background:transparent;color:var(--text2);text-decoration:none;font-size:13px;padding:4px 12px;border:1px solid #1e2d45;border-radius:20px;transition:0.2s;" onmouseover="this.style.color='#c9a96e';this.style.borderColor='#c9a96e';" onmouseout="this.style.color='var(--text2)';this.style.borderColor='#1e2d45';">
                      <?= $p['lead_count'] ?> leads →
                    </a>
                <?php else: ?>
                    <span style="color:var(--text2);font-size:12px;">0 leads</span>
                <?php endif; ?>
              </td>
              <td style="color:var(--text2);font-size:12px;"><?= date('d M Y', strtotime($p['created_at'])) ?></td>
              <td style="text-align:right;">
                <form method="POST" data-old="<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>" onsubmit="const nn=prompt('Enter new project name:', this.dataset.old); if(nn && nn!==this.dataset.old){ this.new_name.value=nn; return true; } return false;" style="display:inline-block; margin-right:4px;">
                  <input type="hidden" name="edit_id" value="<?= $p['id'] ?>">
                  <input type="hidden" name="new_name" value="">
                  <button type="submit" style="background:transparent;border:1px solid rgba(96,165,250,0.3);color:#60a5fa;border-radius:6px;padding:4px 8px;cursor:pointer;font-size:12px;transition:0.2s;" onmouseover="this.style.background='rgba(96,165,250,0.1)'" onmouseout="this.style.background='transparent'">
                    Edit
                  </button>
                </form>
                <form method="POST" onsubmit="return confirm('Remove sub-project form?')" style="display:inline-block;">
                  <input type="hidden" name="del_id" value="<?= $p['id'] ?>">
                  <button type="submit" style="background:transparent;border:1px solid rgba(239,68,68,0.3);color:#f87171;border-radius:6px;padding:4px 8px;cursor:pointer;font-size:12px;transition:0.2s;" onmouseover="this.style.background='rgba(239,68,68,0.1)'" onmouseout="this.style.background='transparent'">
                    Delete
                  </button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
            
            <?php else: ?>
            <!-- Single standalone project row -->
            <?php $p = $groupData['items'][0]; ?>
            <tr>
              <td style="color:var(--text2);font-size:13px;font-weight:600;"><?= $srNo++ ?></td>
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
                <form method="POST" data-old="<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>" onsubmit="const nn=prompt('Enter new project name:', this.dataset.old); if(nn && nn!==this.dataset.old){ this.new_name.value=nn; return true; } return false;" style="display:inline-block; margin-right:4px;">
                  <input type="hidden" name="edit_id" value="<?= $p['id'] ?>">
                  <input type="hidden" name="new_name" value="">
                  <button type="submit" style="background:transparent;border:1px solid rgba(96,165,250,0.3);color:#60a5fa;border-radius:6px;padding:4px 10px;cursor:pointer;font-size:14px;transition:0.2s;" onmouseover="this.style.background='rgba(96,165,250,0.1)'" onmouseout="this.style.background='transparent'">
                    ✏️ Edit
                  </button>
                </form>
                <form method="POST" onsubmit="return confirm('Remove this project?')" style="display:inline-block;">
                  <input type="hidden" name="del_id" value="<?= $p['id'] ?>">
                  <button type="submit" style="background:transparent;border:1px solid rgba(239,68,68,0.3);color:#f87171;border-radius:6px;padding:4px 10px;cursor:pointer;font-size:14px;transition:0.2s;" onmouseover="this.style.background='rgba(239,68,68,0.1)'" onmouseout="this.style.background='transparent'">
                    🗑 Delete
                  </button>
                </form>
              </td>
            </tr>
            <?php endif; ?>
            
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
