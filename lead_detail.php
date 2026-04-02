<?php
require_once __DIR__ . '/config.php';
$user = requireAuth();

$id   = (int)($_GET['id'] ?? 0);
$pdo  = db();

// Fetch lead
$st = $pdo->prepare('SELECT l.*,p.name AS project_name,u.name AS assigned_name
    FROM leads l
    LEFT JOIN projects p ON l.project_id=p.id
    LEFT JOIN users u ON l.assigned_to=u.id
    WHERE l.id=?');
$st->execute([$id]);
$lead = $st->fetch();
if (!$lead) { header('Location: ' . BASE_URL . '/index.php'); exit; }

// Sales manager can only view own leads
if ($user['role'] === 'sales_manager' && $lead['assigned_to'] != $user['id']) {
    header('Location: ' . BASE_URL . '/index.php'); exit;
}

$error = $success = '';

// ── Handle: add comment ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='comment') {
    $comment = trim($_POST['comment'] ?? '');
    if ($comment) {
        $pdo->prepare('UPDATE leads SET comments=? WHERE id=?')->execute([$comment, $id]);
        $lead['comments'] = $comment;
        $success = 'Comment saved.';
    }
}

// ── Handle: add follow-up ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='followup') {
    $response  = trim($_POST['call_response'] ?? '');
    $nextDate  = $_POST['next_followup'] ?? null;
    if ($response) {
        $pdo->prepare('INSERT INTO followups (lead_id,user_id,call_response,next_followup) VALUES (?,?,?,?)')
            ->execute([$id, $user['id'], $response, $nextDate ?: null]);
        $pdo->prepare('UPDATE leads SET call_count=call_count+1 WHERE id=?')->execute([$id]);
        $lead['call_count']++;
        $success = 'Follow-up logged.';
    }
}

// ── Handle: audio upload ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='audio') {
    if (!empty($_FILES['audio_file']['name'])) {
        $ext = strtolower(pathinfo($_FILES['audio_file']['name'], PATHINFO_EXTENSION));
        $allowed = ['mp3','m4a','wav','ogg','aac','mp4','webm'];
        if (!in_array($ext, $allowed)) {
            $error = 'Only audio files allowed (mp3, m4a, wav, ogg, aac).';
        } elseif ($_FILES['audio_file']['size'] > UPLOAD_MAX_MB * 1024 * 1024) {
            $error = 'File too large. Max ' . UPLOAD_MAX_MB . 'MB.';
        } else {
            $filename = 'lead_' . $id . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['audio_file']['tmp_name'], UPLOAD_DIR . $filename)) {
                // Delete old file
                if ($lead['audio_file'] && file_exists(UPLOAD_DIR . $lead['audio_file'])) {
                    unlink(UPLOAD_DIR . $lead['audio_file']);
                }
                $pdo->prepare('UPDATE leads SET audio_file=? WHERE id=?')->execute([$filename, $id]);
                $lead['audio_file'] = $filename;
                $success = 'Audio recording uploaded.';
            } else {
                $error = 'Upload failed. Check folder permissions.';
            }
        }
    }
}

// ── Handle: assign / update quick fields (admin only) ────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='update' && $user['role']==='admin') {
    $oldAssigned = (int)$lead['assigned_to'];
    $fields = ['lead_type','lead_status','assigned_to','project_id'];
    $sets   = [];
    $vals   = [];
    foreach ($fields as $f) {
        if (isset($_POST[$f])) { $sets[] = "{$f}=?"; $vals[] = $_POST[$f] ?: null; }
    }
    if ($sets) {
        $vals[] = $id;
        $pdo->prepare('UPDATE leads SET ' . implode(',',$sets) . ' WHERE id=?')->execute($vals);
        // Refresh
        $st->execute([$id]);
        $lead = $st->fetch();
        $success = 'Lead updated.';

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
}

// Follow-up history
$followups = $pdo->prepare('SELECT f.*,u.name AS by_name FROM followups f LEFT JOIN users u ON f.user_id=u.id WHERE f.lead_id=? ORDER BY f.created_at DESC');
$followups->execute([$id]);
$followups = $followups->fetchAll();

$projects      = $pdo->query('SELECT id,name FROM projects ORDER BY name')->fetchAll();
$salesManagers = $pdo->query("SELECT id,name FROM users WHERE role='sales_manager' AND is_active=1 ORDER BY name")->fetchAll();

$pageTitle = 'Lead · ' . $lead['first_name'] . ' ' . $lead['last_name'];
include __DIR__ . '/includes/header.php';
?>

<div style="display:flex;align-items:center;gap:14px;margin-bottom:24px;flex-wrap:wrap">
  <a href="<?= BASE_URL ?>/index.php" class="btn btn-outline btn-sm">← Back</a>
  <span class="badge badge-<?= $lead['source'] ?>"><?= ucfirst($lead['source']) ?></span>
  <span class="badge badge-<?= $lead['lead_type'] ?>">
    <?= match($lead['lead_type']) { 'hot'=>'🔥 Hot', 'warm'=>'☀️ Warm', 'cold'=>'❄️ Cold', default=>'' } ?>
  </span>
  <span class="badge <?= match($lead['lead_status']) { 'sv_pending'=>'badge-svp', 'sv_done'=>'badge-svd', 'closed'=>'badge-closed', default=>'' } ?>">
    <?= match($lead['lead_status']) { 'sv_pending'=>'SV Pending', 'sv_done'=>'SV Done', 'closed'=>'Closed', default=>'' } ?>
  </span>
  <?php if ($user['role']==='admin'): ?>
    <a href="<?= BASE_URL ?>/lead_edit.php?id=<?= $id ?>" class="btn btn-primary btn-sm" style="margin-left:auto">✏️ Edit Lead</a>
  <?php endif; ?>
</div>

<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start">

  <!-- Left column -->
  <div style="display:flex;flex-direction:column;gap:18px">

    <!-- Lead info card -->
    <div class="card">
      <h2 style="font-family:'DM Serif Display',serif;font-size:24px;margin-bottom:18px">
        <?= htmlspecialchars($lead['first_name'] . ' ' . $lead['last_name']) ?>
      </h2>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <?php $info = [
          ['📞 Mobile',   $lead['mobile']],
          ['📧 Email',    $lead['email']],
          ['🏗️ Project', $lead['project_name']],
          ['📋 Preference', $lead['preference']],
          ['📅 Date',     $lead['sheet_date'] . ' ' . $lead['sheet_time']],
          ['🔗 URL',      $lead['page_url'] ? substr($lead['page_url'],0,50).'…' : ''],
          ['👤 Assigned', $lead['assigned_name'] ?? 'Unassigned'],
          ['📞 Call Count', $lead['call_count'] . ' calls placed'],
        ]; foreach ($info as [$k,$v]): if (!$v) continue; ?>
        <div style="background:var(--bg);border-radius:10px;padding:12px 16px">
          <div style="font-size:10px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--text2);margin-bottom:4px"><?= $k ?></div>
          <div style="font-size:14px;color:var(--text)"><?= htmlspecialchars($v) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Comments -->
    <div class="card">
      <div style="font-size:13px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--text2);margin-bottom:14px">💬 Comments</div>
      <form method="POST">
        <input type="hidden" name="action" value="comment">
        <textarea name="comment" class="form-control" rows="4" placeholder="Add a note about this lead…"><?= htmlspecialchars($lead['comments'] ?? '') ?></textarea>
        <button type="submit" class="btn btn-primary btn-sm" style="margin-top:10px">Save Comment</button>
      </form>
    </div>

    <!-- Add Follow-up -->
    <div class="card">
      <div style="font-size:13px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--text2);margin-bottom:14px">📞 Log Follow-up Call</div>
      <form method="POST">
        <input type="hidden" name="action" value="followup">
        <div class="form-group">
          <label>Call Response / Notes</label>
          <textarea name="call_response" class="form-control" rows="3" placeholder="What did the customer say? Next steps?" required></textarea>
        </div>
        <div class="form-group">
          <label>Next Follow-up Date (optional)</label>
          <input type="date" name="next_followup" class="form-control" min="<?= date('Y-m-d') ?>">
        </div>
        <button type="submit" class="btn btn-primary btn-sm">📞 Log Call</button>
      </form>
    </div>

    <!-- Audio Upload -->
    <div class="card">
      <div style="font-size:13px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--text2);margin-bottom:14px">🎙️ Call Recording</div>

      <?php if ($lead['audio_file']): ?>
        <div style="background:var(--bg);border-radius:10px;padding:14px;margin-bottom:14px">
          <audio controls style="width:100%;outline:none">
            <source src="<?= BASE_URL ?>/assets/uploads/audio/<?= htmlspecialchars($lead['audio_file']) ?>">
            Your browser does not support audio.
          </audio>
          <div style="font-size:11px;color:var(--text2);margin-top:6px"><?= htmlspecialchars($lead['audio_file']) ?></div>
        </div>
      <?php else: ?>
        <div style="color:var(--text2);font-size:13px;margin-bottom:14px">No recording uploaded yet.</div>
      <?php endif; ?>

      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="audio">
        <input type="file" name="audio_file" accept="audio/*,video/mp4,video/webm" class="form-control" style="margin-bottom:10px">
        <div style="font-size:11px;color:var(--text2);margin-bottom:10px">Accepted: mp3, m4a, wav, ogg, aac · Max <?= UPLOAD_MAX_MB ?>MB</div>
        <button type="submit" class="btn btn-primary btn-sm">⬆️ Upload Recording</button>
      </form>
    </div>

  </div>

  <!-- Right column -->
  <div style="display:flex;flex-direction:column;gap:18px">

    <?php if ($user['role']==='admin'): ?>
    <!-- Quick update -->
    <div class="card">
      <div style="font-size:13px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--text2);margin-bottom:14px">⚙️ Update Lead</div>
      <form method="POST">
        <input type="hidden" name="action" value="update">

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

        <div class="form-group">
          <label>Project</label>
          <select name="project_id" class="form-control">
            <option value="">— No project —</option>
            <?php foreach ($projects as $pr): ?>
              <option value="<?= $pr['id'] ?>" <?= $lead['project_id']==$pr['id']?'selected':'' ?>><?= htmlspecialchars($pr['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%">Update Lead</button>
      </form>
    </div>
    <?php endif; ?>

    <!-- Follow-up history -->
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
        <div style="font-size:13px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--text2)">📊 Follow-up History</div>
        <span style="background:rgba(var(--accentRGB),0.15);color:var(--accent);border-radius:20px;padding:2px 10px;font-size:11px;font-weight:700">
          <?= count($followups) ?> call<?= count($followups)!=1?'s':'' ?>
        </span>
      </div>

      <?php if (empty($followups)): ?>
        <div style="color:var(--text2);font-size:13px;text-align:center;padding:20px 0">No follow-ups yet.</div>
      <?php endif; ?>

      <div style="display:flex;flex-direction:column;gap:10px">
        <?php foreach ($followups as $i => $f): ?>
        <div style="background:var(--bg);border-radius:10px;padding:12px 14px;border-left:3px solid var(--accent)">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
            <span style="font-size:11px;font-weight:700;color:var(--accent)">Call #<?= count($followups) - $i ?></span>
            <span style="font-size:11px;color:var(--text2)"><?= date('d M Y, H:i', strtotime($f['created_at'])) ?></span>
          </div>
          <div style="font-size:13px;color:var(--text);line-height:1.5"><?= nl2br(htmlspecialchars($f['call_response'])) ?></div>
          <div style="margin-top:6px;font-size:11px;color:var(--text2)">
            By <?= htmlspecialchars($f['by_name']) ?>
            <?php if ($f['next_followup']): ?>
              · Next: <strong style="color:var(--warn)"><?= date('d M Y', strtotime($f['next_followup'])) ?></strong>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
