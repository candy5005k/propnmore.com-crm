<?php
require_once __DIR__ . '/config.php';
$user = requireAuth();

$id   = (int)($_GET['id'] ?? 0);
$pdo  = db();

// Fetch lead
$st = $pdo->prepare('SELECT l.*, COALESCE(p.name, l.project_name) AS project_name,
    l.campaign_name, l.ad_name, l.form_name, l.notes,
    u.name AS assigned_name
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
        $pdo->prepare('INSERT INTO followups (lead_id,user_id,call_response) VALUES (?,?,?)')->execute([$id, $user['id'], "[💬 COMMENT] " . $comment]);
        $success = 'Comment saved.';
    }
}

// ── Handle: increment call manually ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='increment_call') {
    $pdo->prepare('UPDATE leads SET call_count=call_count+1 WHERE id=?')->execute([$id]);
    $lead['call_count']++;
    $success = 'Call count incremented.';
}

// ── Handle: decrement call manually ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='decrement_call') {
    if ($lead['call_count'] > 0) {
        $pdo->prepare('UPDATE leads SET call_count=call_count-1 WHERE id=?')->execute([$id]);
        $lead['call_count']--;
        $success = 'Call count decreased.';
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
                $pdo->prepare('INSERT INTO followups (lead_id,user_id,call_response) VALUES (?,?,?)')->execute([$id, $user['id'], "[🎙️ RECORDING] " . $filename]);
                $success = 'Audio recording uploaded.';
            } else {
                $error = 'Upload failed. Check folder permissions.';
            }
        }
    }
}

// ── Handle: assign / update quick fields ────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='update') {
    $oldAssigned = (int)$lead['assigned_to'];
    $fields = ['lead_type','lead_status'];
    if ($user['role'] === 'admin') {
        $fields[] = 'assigned_to';
        $fields[] = 'project_id';
    }
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
        
        $changeNote = "Lead meta fields updated.";
        $n_status = $_POST['lead_status'] ?? '';
        if ($n_status !== $lead['lead_status']) {
            $changeNote .= ($changeNote ? ', ' : '') . "Status: {$lead['lead_status']} → {$n_status}";
            if ($n_status === 'spam' && !empty($_POST['spam_note'])) {
                $changeNote .= " | Spam Reason: " . trim($_POST['spam_note']);
            }
        }
        
        $pdo->prepare('INSERT INTO followups (lead_id,user_id,call_response) VALUES (?,?,?)')->execute([$id, $user['id'], "[⚙️ UPDATE] " . $changeNote]);
        $success = 'Lead updated.';

        // ★ Point 11: Send notification to newly assigned sales manager
        $newAssigned = (int)($lead['assigned_to'] ?? 0);
        if ($newAssigned > 0 && $newAssigned !== $oldAssigned) {
            $leadName    = trim($lead['first_name'] . ' ' . $lead['last_name']);
            $projectName = $lead['project_name'] ?? 'N/A';
            $leadMobile  = $lead['mobile'] ?? '';
            $leadEmail   = $lead['email'] ?? '';
            $leadPref    = $lead['preference'] ?? '';
            $assignerName = $user['name'];

            // In-app notification
            createNotification(
                $newAssigned,
                '🎯 New Lead Assigned to You',
                "Lead \"{$leadName}\" (#{$id}) has been assigned to you by {$assignerName}.",
                $id,
                'lead_assigned'
            );

            // ★ Email + SMS notification to sales manager
            $smData = $pdo->prepare('SELECT name, email, mobile FROM users WHERE id=?');
            $smData->execute([$newAssigned]);
            $smInfo = $smData->fetch();

            if ($smInfo && $smInfo['email']) {
                $smName  = $smInfo['name'];
                $smEmail = $smInfo['email'];
                $leadUrl = BASE_URL . '/lead_detail.php?id=' . $id;
                $dateStr = date('d M Y, h:i A');

                $emailBody = '
<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#0a0e17;font-family:Arial,Helvetica,sans-serif">
<div style="max-width:600px;margin:30px auto;background:#0f1623;border:1px solid #1a2640;border-radius:16px;overflow:hidden">

  <!-- Header -->
  <div style="background:linear-gradient(135deg,#c9a96e,#e8c98a);padding:28px 32px;text-align:center">
    <h1 style="margin:0;font-size:22px;color:#0a0e17;font-weight:800;letter-spacing:0.5px">🎯 New Lead Assigned</h1>
    <p style="margin:8px 0 0;font-size:13px;color:rgba(10,14,23,0.7)">Propnmore CRM · LSR LEADS 2026</p>
  </div>

  <!-- Body -->
  <div style="padding:28px 32px">
    <p style="color:#dde3f0;font-size:15px;margin:0 0 20px;line-height:1.6">
      Hi <strong>' . htmlspecialchars($smName) . '</strong>,<br><br>
      A new lead has been assigned to you by <strong style="color:#c9a96e">' . htmlspecialchars($assignerName) . '</strong>.
      Please follow up at the earliest.
    </p>

    <!-- Lead Card -->
    <div style="background:#080d16;border:1px solid #1a2640;border-radius:12px;padding:20px;margin-bottom:20px">
      <table style="width:100%;border-collapse:collapse">
        <tr>
          <td style="padding:8px 0;color:#8a9ab8;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px;width:120px">Lead Name</td>
          <td style="padding:8px 0;color:#dde3f0;font-size:15px;font-weight:600">' . htmlspecialchars($leadName) . '</td>
        </tr>
        <tr>
          <td style="padding:8px 0;color:#8a9ab8;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px">📞 Mobile</td>
          <td style="padding:8px 0;color:#dde3f0;font-size:15px"><a href="tel:' . htmlspecialchars($leadMobile) . '" style="color:#c9a96e;text-decoration:none">' . htmlspecialchars($leadMobile) . '</a></td>
        </tr>
        ' . ($leadEmail ? '<tr>
          <td style="padding:8px 0;color:#8a9ab8;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px">📧 Email</td>
          <td style="padding:8px 0;color:#dde3f0;font-size:15px">' . htmlspecialchars($leadEmail) . '</td>
        </tr>' : '') . '
        <tr>
          <td style="padding:8px 0;color:#8a9ab8;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px">🏗️ Project</td>
          <td style="padding:8px 0;color:#e8c98a;font-size:15px;font-weight:600">' . htmlspecialchars($projectName) . '</td>
        </tr>
        ' . ($leadPref ? '<tr>
          <td style="padding:8px 0;color:#8a9ab8;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px">📋 Preference</td>
          <td style="padding:8px 0;color:#dde3f0;font-size:15px">' . htmlspecialchars($leadPref) . '</td>
        </tr>' : '') . '
        <tr>
          <td style="padding:8px 0;color:#8a9ab8;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px">📅 Assigned</td>
          <td style="padding:8px 0;color:#dde3f0;font-size:14px">' . $dateStr . '</td>
        </tr>
        <tr>
          <td style="padding:8px 0;color:#8a9ab8;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px">👤 By</td>
          <td style="padding:8px 0;color:#c9a96e;font-size:15px;font-weight:600">' . htmlspecialchars($assignerName) . '</td>
        </tr>
      </table>
    </div>

    <!-- CTA Button -->
    <div style="text-align:center;margin:24px 0 8px">
      <a href="' . $leadUrl . '" style="display:inline-block;background:linear-gradient(135deg,#c9a96e,#e8c98a);color:#0a0e17;text-decoration:none;padding:14px 36px;border-radius:10px;font-size:15px;font-weight:700;letter-spacing:0.3px">
        View Lead Details →
      </a>
    </div>
  </div>

  <!-- Footer -->
  <div style="border-top:1px solid #1a2640;padding:18px 32px;text-align:center">
    <p style="margin:0;font-size:11px;color:#8a9ab8">
      Propnmore CRM · LSR LEADS 2026<br>
      <a href="' . BASE_URL . '" style="color:#c9a96e;text-decoration:none">crm.propnmore.com</a>
    </p>
  </div>

</div>
</body></html>';

                $emailSent = sendMail(
                    $smEmail,
                    "🎯 New Lead Assigned: {$leadName} — {$projectName}",
                    $emailBody
                );

                if ($emailSent) {
                    error_log("CRM: ✅ Assignment email sent to {$smEmail} for lead #{$id}");
                } else {
                    error_log("CRM: ❌ Failed to send assignment email to {$smEmail}");
                }

                // ★ SMS alert to sales manager (via Fast2SMS)
                $smMobile = $smInfo['mobile'] ?? '';
                if ($smMobile) {
                    $smsText = "LSR CRM: New lead - {$leadName}, Ph: {$leadMobile}, Project: {$projectName}. Login: crm.propnmore.com";
                    $smsSent = sendSMS($smMobile, $smsText);
                    error_log("CRM: SMS to SM {$smMobile} for lead #{$id} — " . ($smsSent ? '✅ sent' : '❌ failed'));
                } else {
                    error_log("CRM: ⚠️ No mobile set for SM #{$newAssigned} — SMS skipped.");
                }
            }
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
  <button id="btn-back" class="btn btn-outline btn-sm" onclick="goBackOrHome()">← Back</button>
  <script>
  function goBackOrHome() {
      const lastUrl = sessionStorage.getItem('lastIndexUrl');
      if (lastUrl) {
          window.location.href = lastUrl;
      } else {
          window.location.href = '<?= BASE_URL ?>/index.php';
      }
  }
  </script>
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
        ]; foreach ($info as [$k,$v]): if (!$v && $k !== '📞 Call Count') continue; ?>
        <div style="background:var(--bg);border-radius:10px;padding:12px 16px; <?php if($k === '📞 Call Count') echo 'display:flex;align-items:center;justify-content:space-between'; ?>">
          <div>
            <div style="font-size:10px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--text2);margin-bottom:4px"><?= $k ?></div>
            <div style="font-size:14px;color:var(--text)"><?= htmlspecialchars($v) ?></div>
          </div>
          <?php if ($k === '📞 Call Count'): ?>
          <div style="display:flex;gap:6px">
            <?php if($lead['call_count'] > 0): ?>
            <form method="POST" style="margin:0">
               <input type="hidden" name="action" value="decrement_call">
               <button type="submit" style="background:rgba(239,68,68,0.1);color:#ef4444;border:1px solid rgba(239,68,68,0.3);border-radius:6px;width:30px;height:30px;font-size:18px;font-weight:bold;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background 0.2s" onmouseover="this.style.background='rgba(239,68,68,0.2)'" onmouseout="this.style.background='rgba(239,68,68,0.1)'" title="Decrease Call Count">-</button>
            </form>
            <?php endif; ?>
            <form method="POST" style="margin:0">
               <input type="hidden" name="action" value="increment_call">
               <button type="submit" style="background:rgba(16,185,129,0.1);color:#10b981;border:1px solid rgba(16,185,129,0.3);border-radius:6px;width:30px;height:30px;font-size:18px;font-weight:bold;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background 0.2s" onmouseover="this.style.background='rgba(16,185,129,0.2)'" onmouseout="this.style.background='rgba(16,185,129,0.1)'" title="Increase Call Count">+</button>
            </form>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if ($lead['source'] === 'meta'): ?>
    <!-- Meta Lead Details Card -->
    <div class="card" style="border-color:rgba(59,130,246,0.25)">
      <div style="font-size:13px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#60a5fa;margin-bottom:14px">📱 Meta Lead Details</div>
      
      <?php if (!empty($lead['campaign_name']) || !empty($lead['ad_name']) || !empty($lead['form_name'])): ?>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px">
        <?php if (!empty($lead['campaign_name'])): ?>
        <div style="background:var(--bg);border-radius:10px;padding:12px 16px">
          <div style="font-size:10px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--text2);margin-bottom:4px">📢 Campaign</div>
          <div style="font-size:14px;color:var(--accent)"><?= htmlspecialchars($lead['campaign_name']) ?></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($lead['ad_name'])): ?>
        <div style="background:var(--bg);border-radius:10px;padding:12px 16px">
          <div style="font-size:10px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--text2);margin-bottom:4px">🎯 Ad Name</div>
          <div style="font-size:14px;color:var(--text)"><?= htmlspecialchars($lead['ad_name']) ?></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($lead['form_name'])): ?>
        <div style="background:var(--bg);border-radius:10px;padding:12px 16px">
          <div style="font-size:10px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--text2);margin-bottom:4px">📝 Form Name</div>
          <div style="font-size:14px;color:var(--text)"><?= htmlspecialchars($lead['form_name']) ?></div>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php
      // Parse notes for form Q&A
      $notesText = $lead['notes'] ?? $lead['comments'] ?? '';
      $qaLines = [];
      if ($notesText) {
          // Extract "All Answers:" section
          if (preg_match('/All Answers:\s*(.+)$/s', $notesText, $m)) {
              $pairs = explode(' | ', trim($m[1]));
              foreach ($pairs as $pair) {
                  $parts = explode(': ', $pair, 2);
                  if (count($parts) === 2) {
                      $qaLines[] = ['q' => trim($parts[0]), 'a' => trim($parts[1])];
                  }
              }
          }
          // Also extract individual lines like "Preference: 4 BHK"
          if (empty($qaLines)) {
              foreach (explode("\n", $notesText) as $line) {
                  $line = trim($line);
                  if ($line && $line !== '---' && strpos($line, ':') !== false) {
                      $parts = explode(': ', $line, 2);
                      if (count($parts) === 2 && !in_array($parts[0], ['All Answers'])) {
                          $qaLines[] = ['q' => trim($parts[0]), 'a' => trim($parts[1])];
                      }
                  }
              }
          }
      }
      ?>

      <?php if (!empty($qaLines)): ?>
      <div style="font-size:12px;font-weight:600;color:var(--text2);margin-bottom:10px;text-transform:uppercase;letter-spacing:1px">Form Answers</div>
      <div style="display:flex;flex-direction:column;gap:8px">
        <?php foreach ($qaLines as $qa): ?>
        <div style="background:var(--bg);border-radius:10px;padding:12px 16px;border-left:3px solid rgba(59,130,246,0.4)">
          <div style="font-size:11px;font-weight:600;color:#60a5fa;margin-bottom:3px"><?= htmlspecialchars($qa['q']) ?></div>
          <div style="font-size:14px;color:var(--text)"><?= htmlspecialchars($qa['a']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php elseif ($notesText): ?>
      <div style="font-size:12px;font-weight:600;color:var(--text2);margin-bottom:10px;text-transform:uppercase;letter-spacing:1px">Raw Notes</div>
      <div style="background:var(--bg);border-radius:10px;padding:14px;font-size:13px;color:var(--text);line-height:1.6;white-space:pre-wrap"><?= htmlspecialchars($notesText) ?></div>
      <?php else: ?>
      <div style="color:var(--text2);font-size:13px">No form data available. Sync leads to fetch form answers.</div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

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
        <div style="display:flex;gap:10px;align-items:center;">
          <button type="submit" class="btn btn-primary btn-sm">📞 Log Call</button>
          <button type="button" class="btn btn-outline btn-sm" onclick="openReminderModal()">📅 Add to Calendar</button>
        </div>
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
          <select name="lead_status" class="form-control" id="leadStatusSelect">
            <option value="sv_pending" <?= $lead['lead_status']==='sv_pending'?'selected':'' ?>>SV Pending</option>
            <option value="sv_done"    <?= $lead['lead_status']==='sv_done'?'selected':'' ?>>SV Done</option>
            <option value="closed"     <?= $lead['lead_status']==='closed'?'selected':'' ?>>Closed</option>
            <option value="spam"       <?= $lead['lead_status']==='spam'?'selected':'' ?>>Spam (Junk/Fake)</option>
          </select>
        </div>
        
        <div id="spamNoteWrap" style="display:none;margin-bottom:16px;">
            <label style="display:block;font-size:11px;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:#ef4444;margin-bottom:6px">Spam Details/Note</label>
            <textarea name="spam_note" class="form-control" rows="2" placeholder="Why is this spam? (Both Admin and SM will see this)"></textarea>
        </div>
        <script>
        document.getElementById('leadStatusSelect').addEventListener('change', function(){
            document.getElementById('spamNoteWrap').style.display = (this.value === 'spam') ? 'block' : 'none';
        });
        if(document.getElementById('leadStatusSelect').value === 'spam') {
            document.getElementById('spamNoteWrap').style.display = 'block';
        }
        </script>

        <?php if ($user['role']==='admin'): ?>
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
        <?php endif; ?>

        <button type="submit" class="btn btn-primary" style="width:100%">Update Lead</button>
      </form>
    </div>

    <!-- Activity Timeline -->
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
        <div style="font-size:13px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--text2)">📊 Activity Timeline</div>
        <span style="background:rgba(var(--accentRGB),0.15);color:var(--accent);border-radius:20px;padding:2px 10px;font-size:11px;font-weight:700">
          <?= count($followups) ?> action<?= count($followups)!=1?'s':'' ?>
        </span>
      </div>

      <?php if (empty($followups)): ?>
        <div style="color:var(--text2);font-size:13px;text-align:center;padding:20px 0">No activity logged yet.</div>
      <?php endif; ?>

      <div style="display:flex;flex-direction:column;gap:10px">
        <?php foreach ($followups as $i => $f): 
          $text = $f['call_response'];
          $htmlContent = '';
          if (strpos($text, '[💬 COMMENT] ') === 0) {
              $bgColor = 'rgba(245,158,11,0.08)'; $bColor = '#f59e0b'; $tLeft = '💬 Comment Logged'; $tColor = '#fbbf24';
              $text = substr($text, 13);
              $htmlContent = nl2br(htmlspecialchars($text));
          } elseif (strpos($text, '[🎙️ RECORDING] ') === 0) {
              $bgColor = 'rgba(168,85,247,0.08)'; $bColor = '#c084fc'; $tLeft = '🎙️ Audio Uploaded'; $tColor = '#c084fc';
              $text = substr($text, 16);
              $fileUrl = BASE_URL . '/assets/uploads/audio/' . htmlspecialchars($text);
              $htmlContent = '<audio controls style="width:100%;height:32px;outline:none;margin-top:4px;border-radius:6px"><source src="'.$fileUrl.'"></audio><div style="font-size:10px;margin-top:4px;color:var(--text2)">'.htmlspecialchars($text).'</div>';
          } elseif (strpos($text, '[⚙️ UPDATE] ') === 0) {
              $bgColor = 'rgba(59,130,246,0.08)'; $bColor = '#60a5fa'; $tLeft = '⚙️ Lead Updated'; $tColor = '#60a5fa';
              $text = substr($text, 12);
              $htmlContent = nl2br(htmlspecialchars($text));
          } elseif (strpos($text, '[📞 INCREMENT] ') === 0) {
              $bgColor = 'rgba(16,185,129,0.08)'; $bColor = '#10b981'; $tLeft = '📞 Called (Count Incremented)'; $tColor = '#10b981';
              $text = substr($text, 15);
              $htmlContent = nl2br(htmlspecialchars($text));
          } elseif (strpos($text, '[📉 DECREMENT] ') === 0) {
              $bgColor = 'rgba(239,68,68,0.08)'; $bColor = '#ef4444'; $tLeft = '📉 Call Decreased'; $tColor = '#ef4444';
              $text = substr($text, 15);
              $htmlContent = nl2br(htmlspecialchars($text));
          } else {
              $bgColor = 'var(--bg)'; $bColor = 'var(--accent)'; $tLeft = '📞 Call Logged'; $tColor = 'var(--accent)';
              $htmlContent = nl2br(htmlspecialchars($text));
          }
        ?>
        <div style="background:<?= $bgColor ?>;border-radius:10px;padding:12px 14px;border-left:3px solid <?= $bColor ?>">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
            <span style="font-size:11px;font-weight:700;color:<?= $tColor ?>"><?= $tLeft ?></span>
            <span style="font-size:11px;color:var(--text2)"><?= date('d M Y, H:i', strtotime($f['created_at'])) ?></span>
          </div>
          <div style="font-size:13px;color:var(--text);line-height:1.5"><?= $htmlContent ?></div>
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

<!-- Quick Add Reminder Modal -->
<div class="modal-bg" id="addReminderModal">
    <div class="modal" style="max-width:500px">
        <div class="modal-header">
            <div class="modal-title">📅 Add to Calendar</div>
            <button class="modal-close" onclick="closeModal('addReminderModal')">&times;</button>
        </div>
        <form id="reminderForm" onsubmit="saveReminderAction(event)">
            <input type="hidden" id="remLeadId" value="<?= $id ?>">
            <div class="form-group">
                <label>Reminder Title / Task</label>
                <input type="text" class="form-control" id="remTitle" required placeholder="e.g. Call back regarding pricing">
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label>Date</label>
                    <input type="date" class="form-control" id="remDate" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label>Time</label>
                    <input type="time" class="form-control" id="remTime">
                </div>
            </div>
            <div class="form-group">
                <label>Notes / Description</label>
                <textarea class="form-control" id="remDesc" placeholder="Any extra details..." rows="3"></textarea>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:12px;margin-top:20px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('addReminderModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSaveRem">Save to Calendar</button>
            </div>
        </form>
    </div>
</div>
<script>
function openReminderModal() {
    openModal('addReminderModal');
}
function saveReminderAction(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSaveRem');
    const og = btn.innerHTML;
    btn.innerHTML = 'Saving...';
    btn.disabled = true;

    const fd = new FormData();
    fd.append('title', document.getElementById('remTitle').value);
    fd.append('description', document.getElementById('remDesc').value);
    fd.append('event_date', document.getElementById('remDate').value);
    fd.append('event_time', document.getElementById('remTime').value);
    fd.append('lead_id', document.getElementById('remLeadId').value);

    fetch('ajax_calendar_events.php?action=add', {
        method: 'POST', body: fd
    }).then(r => r.json()).then(d => {
        closeModal('addReminderModal');
        btn.innerHTML = og;
        btn.disabled = false;
        
        // Custom animated success flash
        const flash = document.createElement('div');
        flash.innerHTML = '✅ Reminder added to Calendar!';
        flash.style.cssText = 'position:fixed;bottom:20px;right:20px;background:#10b981;color:#fff;padding:12px 20px;border-radius:8px;font-weight:600;box-shadow:0 4px 12px rgba(0,0,0,0.3);z-index:999;animation:fadeUp 0.3s ease;';
        document.body.appendChild(flash);
        setTimeout(() => {
            flash.style.opacity = '0';
            flash.style.transition = 'opacity 0.5s ease';
            setTimeout(() => flash.remove(), 500);
        }, 3000);
        
        // Reset form
        document.getElementById('reminderForm').reset();
        document.getElementById('remDate').value = '<?= date('Y-m-d') ?>';
    }).catch(() => {
        btn.innerHTML = og;
        btn.disabled = false;
        alert('Could not save reminder. Please try again.');
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
