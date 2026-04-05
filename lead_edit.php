<?php
require_once __DIR__ . '/config.php';
$user = requireAuth('admin');

$id  = (int)($_GET['id'] ?? 0);
$pdo = db();

$lead = $pdo->prepare('SELECT * FROM leads WHERE id=?');
$lead->execute([$id]);
$lead = $lead->fetch();
if (!$lead) { header('Location: ' . BASE_URL . '/index.php'); exit; }

$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $pdo->prepare('UPDATE leads SET is_deleted = 1 WHERE id=?')->execute([$id]);
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }

    $oldAssigned = (int)$lead['assigned_to'];
    $fields = [
        'first_name','last_name','mobile','email','preference',
        'lead_type','lead_status','assigned_to','project_id','comments',
        'page_url','sheet_date','sheet_time'
    ];
    $sets = []; $vals = [];
    foreach ($fields as $f) {
        $sets[] = "{$f}=?";
        if ($f === 'mobile') {
            $country = trim($_POST['country_code'] ?? '+91');
            $num = trim($_POST['mobile'] ?? '');
            $vals[] = $country . ' ' . $num;
        } else {
            $vals[] = $_POST[$f] !== '' ? $_POST[$f] : null;
        }
    }

    // Handle project name → id
    $pname = trim($_POST['project_name'] ?? '');
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
        // Override project_id
        $idx = array_search('project_id=?', $sets);
        if ($idx !== false) $vals[$idx] = $pid;
    }

    $vals[] = $id;
    $pdo->prepare('UPDATE leads SET ' . implode(',', $sets) . ' WHERE id=?')->execute($vals);
    $success = 'Lead updated successfully.';

    $lead = $pdo->prepare('SELECT * FROM leads WHERE id=?');
    $lead->execute([$id]);
    $lead = $lead->fetch();

    // ★ Point 11: Send notification to newly assigned sales manager
    $newAssigned = (int)($lead['assigned_to'] ?? 0);
    if ($newAssigned > 0 && $newAssigned !== $oldAssigned) {
        $leadName = trim($lead['first_name'] . ' ' . $lead['last_name']);
        createNotification(
            $newAssigned,
            '🎯 New Lead Assigned to You',
            "Lead \"{$leadName}\" (#{$id}) has been assigned to you by {$user['name']}.",
            $id,
            'lead_assigned'
        );
    }
}

$projects      = $pdo->query('SELECT id,name FROM projects ORDER BY name')->fetchAll();
$salesManagers = $pdo->query("SELECT id,name FROM users WHERE role='sales_manager' AND is_active=1 ORDER BY name")->fetchAll();

$pageTitle = 'Edit Lead #' . $id;
include __DIR__ . '/includes/header.php';
?>

<div style="max-width:700px">
  <div style="display:flex;align-items:center;gap:14px;margin-bottom:24px">
    <a href="<?= BASE_URL ?>/lead_detail.php?id=<?= $id ?>" class="btn btn-outline btn-sm">← Back</a>
    <h2 style="font-family:'DM Serif Display',serif;font-size:22px">Edit Lead #<?= $id ?></h2>
  </div>

  <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

  <form method="POST" class="card">
    <div class="grid-2">
      <div class="form-group">
        <label>First Name</label>
        <input type="text" name="first_name" class="form-control" value="<?= htmlspecialchars($lead['first_name']) ?>">
      </div>
      <div class="form-group">
        <label>Last Name</label>
        <input type="text" name="last_name" class="form-control" value="<?= htmlspecialchars($lead['last_name']) ?>">
      </div>
      <div class="form-group">
        <label>Mobile</label>
        <?php
          $rawMob = $lead['mobile'] ?? '';
          $ccode = '+91';
          $numOnly = $rawMob;
          if (preg_match('/^(\+\d{1,3})\s*(.*)$/', $rawMob, $m)) {
              $ccode = $m[1];
              $numOnly = $m[2];
          }
        ?>
        <div style="display:flex;gap:8px">
          <select name="country_code" class="form-control" style="width:80px">
            <option value="+91" <?= $ccode==='+91'?'selected':'' ?>>+91</option>
            <option value="+1" <?= $ccode==='+1'?'selected':'' ?>>+1</option>
            <option value="+44" <?= $ccode==='+44'?'selected':'' ?>>+44</option>
            <option value="+971" <?= $ccode==='+971'?'selected':'' ?>>+971</option>
            <option value="+61" <?= $ccode==='+61'?'selected':'' ?>>+61</option>
          </select>
          <input type="text" name="mobile" class="form-control" value="<?= htmlspecialchars($numOnly) ?>" style="flex:1">
        </div>
      </div>
      <div class="form-group">
        <label>Email</label>
        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($lead['email']) ?>">
      </div>
    </div>

    <div class="form-group">
      <label>Project</label>
      <input type="hidden" name="project_id" value="<?= $lead['project_id'] ?>">
      <input type="text" name="project_name" class="form-control" list="proj-list"
             value="<?= htmlspecialchars($pdo->query("SELECT name FROM projects WHERE id={$lead['project_id']}")->fetchColumn() ?: '') ?>">
      <datalist id="proj-list">
        <?php foreach ($projects as $pr): ?>
          <option value="<?= htmlspecialchars($pr['name']) ?>">
        <?php endforeach; ?>
      </datalist>
    </div>

    <div class="form-group">
      <label>Preference / Budget Notes</label>
      <textarea name="preference" class="form-control"><?= htmlspecialchars($lead['preference']) ?></textarea>
    </div>

    <div class="grid-3">
      <div class="form-group">
        <label>Lead Type</label>
        <select name="lead_type" class="form-control">
          <option value="hot"  <?= $lead['lead_type']==='hot'?'selected':'' ?>>🔥 Hot</option>
          <option value="warm" <?= $lead['lead_type']==='warm'?'selected':'' ?>>☀️ Warm</option>
          <option value="cold" <?= $lead['lead_type']==='cold'?'selected':'' ?>>❄️ Cold</option>
        </select>
      </div>
      <div class="form-group">
        <label>Lead Status</label>
        <select name="lead_status" class="form-control">
          <option value="sv_pending" <?= $lead['lead_status']==='sv_pending'?'selected':'' ?>>SV Pending</option>
          <option value="sv_done"    <?= $lead['lead_status']==='sv_done'?'selected':'' ?>>SV Done</option>
          <option value="closed"     <?= $lead['lead_status']==='closed'?'selected':'' ?>>Closed</option>
        </select>
      </div>
      <div class="form-group">
        <label>Assign To</label>
        <select name="assigned_to" class="form-control">
          <option value="">— Unassigned —</option>
          <?php foreach ($salesManagers as $sm): ?>
            <option value="<?= $sm['id'] ?>" <?= $lead['assigned_to']==$sm['id']?'selected':'' ?>><?= htmlspecialchars($sm['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="grid-2">
      <div class="form-group">
        <label>Date</label>
        <input type="date" name="sheet_date" class="form-control" value="<?= htmlspecialchars($lead['sheet_date']) ?>">
      </div>
      <div class="form-group">
        <label>Time</label>
        <input type="time" name="sheet_time" class="form-control" value="<?= htmlspecialchars($lead['sheet_time']) ?>">
      </div>
    </div>

    <div class="form-group">
      <label>Page URL</label>
      <input type="url" name="page_url" class="form-control" value="<?= htmlspecialchars($lead['page_url']) ?>">
    </div>

    <div class="form-group">
      <label>Comments</label>
      <textarea name="comments" class="form-control" rows="4"><?= htmlspecialchars($lead['comments']) ?></textarea>
    </div>
    <div style="display:flex;gap:14px;align-items:center">
      <button type="submit" class="btn btn-primary">💾 Save Changes</button>
      <button type="button" class="btn btn-outline" style="border-color:var(--danger);color:var(--danger)" onclick="if(confirm('Move lead to Recycle Bin?')) document.getElementById('deleteForm').submit();">🗑️ Delete Lead</button>
    </div>
  </form>

  <form id="deleteForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="delete">
  </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
