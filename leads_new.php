<?php
require_once __DIR__ . '/config.php';
$user = requireAuth('admin');
$pageTitle = 'Add New Lead';

$pdo   = db();
$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first  = trim($_POST['first_name'] ?? '');
    $mobile = trim($_POST['mobile']     ?? '');

    if (!$first || !$mobile) {
        $error = 'First name and mobile are required.';
    } else {
        // Project
        $pname = trim($_POST['project_name'] ?? '');
        $pid   = null;
        if ($pname) {
            $s = $pdo->prepare('SELECT id FROM projects WHERE name=?');
            $s->execute([$pname]);
            $pr = $s->fetch();
            if (!$pr) {
                $pdo->prepare('INSERT INTO projects (name) VALUES (?)')->execute([$pname]);
                $pid = (int)$pdo->lastInsertId();
            } else {
                $pid = (int)$pr['id'];
            }
        }

        $assignedTo = $_POST['assigned_to'] ?: null;

        $pdo->prepare('INSERT INTO leads
            (source,project_id,first_name,last_name,mobile,email,preference,lead_type,lead_status,assigned_to,comments)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([
            'manual', $pid,
            $first, trim($_POST['last_name'] ?? ''),
            $mobile, trim($_POST['email'] ?? ''),
            trim($_POST['preference'] ?? ''),
            $_POST['lead_type'] ?? 'warm',
            $_POST['lead_status'] ?? 'sv_pending',
            $assignedTo,
            trim($_POST['comments'] ?? ''),
        ]);

        $newId = (int)$pdo->lastInsertId();

        // ★ Point 11: Send notification to assigned sales manager
        if ($assignedTo) {
            $leadName = trim($first . ' ' . ($_POST['last_name'] ?? ''));
            createNotification(
                (int)$assignedTo,
                '🎯 New Lead Assigned to You',
                "Lead \"{$leadName}\" (#{$newId}) has been assigned to you by {$user['name']}.",
                $newId,
                'lead_assigned'
            );
        }

        header('Location: ' . BASE_URL . '/lead_detail.php?id=' . $newId);
        exit;
    }
}

$projects      = $pdo->query('SELECT id,name FROM projects ORDER BY name')->fetchAll();
$salesManagers = $pdo->query("SELECT id,name FROM users WHERE role='sales_manager' AND is_active=1 ORDER BY name")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div style="max-width:680px">
  <div style="display:flex;align-items:center;gap:14px;margin-bottom:24px">
    <a href="<?= BASE_URL ?>/index.php" class="btn btn-outline btn-sm">← Back</a>
    <h2 style="font-family:'DM Serif Display',serif;font-size:22px">Add New Lead</h2>
  </div>

  <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <form method="POST" class="card">
    <div class="grid-2">
      <div class="form-group">
        <label>First Name <span style="color:var(--danger)">*</span></label>
        <input type="text" name="first_name" class="form-control" required>
      </div>
      <div class="form-group">
        <label>Last Name</label>
        <input type="text" name="last_name" class="form-control">
      </div>
      <div class="form-group">
        <label>Mobile <span style="color:var(--danger)">*</span></label>
        <input type="text" name="mobile" class="form-control" required placeholder="91XXXXXXXXXX">
      </div>
      <div class="form-group">
        <label>Email</label>
        <input type="email" name="email" class="form-control">
      </div>
    </div>

    <div class="form-group">
      <label>Project</label>
      <input type="text" name="project_name" class="form-control" list="proj-list" placeholder="Type or select project">
      <datalist id="proj-list">
        <?php foreach ($projects as $pr): ?>
          <option value="<?= htmlspecialchars($pr['name']) ?>">
        <?php endforeach; ?>
      </datalist>
    </div>

    <div class="form-group">
      <label>Preference / Requirements</label>
      <textarea name="preference" class="form-control" placeholder="Budget, BHK preference, location, etc."></textarea>
    </div>

    <div class="grid-3">
      <div class="form-group">
        <label>Lead Type</label>
        <select name="lead_type" class="form-control">
          <option value="warm">☀️ Warm</option>
          <option value="hot">🔥 Hot</option>
          <option value="cold">❄️ Cold</option>
        </select>
      </div>
      <div class="form-group">
        <label>Lead Status</label>
        <select name="lead_status" class="form-control">
          <option value="sv_pending">SV Pending</option>
          <option value="sv_done">SV Done</option>
          <option value="closed">Closed</option>
        </select>
      </div>
      <div class="form-group">
        <label>Assign To</label>
        <select name="assigned_to" class="form-control">
          <option value="">— Unassigned —</option>
          <?php foreach ($salesManagers as $sm): ?>
            <option value="<?= $sm['id'] ?>"><?= htmlspecialchars($sm['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-group">
      <label>Initial Comment / Notes</label>
      <textarea name="comments" class="form-control" rows="3" placeholder="Any notes about this lead…"></textarea>
    </div>

    <button type="submit" class="btn btn-primary">➕ Add Lead</button>
  </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
