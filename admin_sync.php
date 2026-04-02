<?php
require_once __DIR__ . '/config.php';
$user = requireAuth('admin');
$pageTitle = 'Sync Google Sheets';

$pdo     = db();
$results = [];
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_once __DIR__ . '/includes/sheets.php';
        $api = new SheetsAPI();

        $sources = $_POST['sources'] ?? [];

        if (in_array('website', $sources)) {
            $n = $api->syncWebsite();
            $results[] = ['source' => 'Website (CNP Leads)', 'count' => $n, 'ok' => true];
        }
        if (in_array('meta', $sources)) {
            $n = $api->syncMeta();
            $results[] = ['source' => 'Meta (LSR Leads 2026)', 'count' => $n, 'ok' => true];
        }
        if (in_array('google', $sources)) {
            $n = $api->syncGoogle();
            $results[] = ['source' => 'Google Leads', 'count' => $n, 'ok' => true];
        }
        if (in_array('salesteam', $sources)) {
            $team = $api->getSalesTeam();
            // Auto-create users from sales team sheet (inactive by default)
            $created = 0;
            foreach ($team as $t) {
                if (empty($t['email'])) continue;
                $s = $pdo->prepare('SELECT id FROM users WHERE email=?');
                $s->execute([$t['email']]);
                if (!$s->fetch()) {
                    $tmpPass = password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT);
                    $pdo->prepare('INSERT INTO users (name,email,password,role,is_active) VALUES (?,?,?,?,0)')
                        ->execute([$t['name'], $t['email'], $tmpPass, 'sales_manager']);
                    $created++;
                }
            }
            $results[] = ['source' => 'Sales Team', 'count' => $created, 'ok' => true, 'note' => '(new accounts created inactive)'];
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Sync log
$logs = $pdo->query('SELECT * FROM sync_log ORDER BY id DESC LIMIT 20')->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div style="max-width:700px">

  <h2 style="font-family:'DM Serif Display',serif;font-size:22px;margin-bottom:8px">🔄 Sync Google Sheets</h2>
  <p style="color:var(--text2);font-size:13px;margin-bottom:24px;line-height:1.6">
    Pull latest leads from Google Sheets into the CRM. Existing leads (matched by mobile or row ID) are not duplicated.
    Make sure <code style="background:var(--bg);padding:2px 6px;border-radius:5px">token.json</code> is in the CRM root folder.
  </p>

  <?php if ($error): ?><div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div><?php endif; ?>

  <?php if ($results): ?>
    <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:24px">
      <?php foreach ($results as $r): ?>
        <div class="alert alert-success">
          ✅ <strong><?= htmlspecialchars($r['source']) ?></strong> — <?= $r['count'] ?> new leads imported
          <?= isset($r['note']) ? '<span style="color:var(--text2)"> ' . $r['note'] . '</span>' : '' ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <form method="POST">
      <div style="font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--text2);margin-bottom:16px">Select sheets to sync</div>

      <div style="display:flex;flex-direction:column;gap:14px;margin-bottom:24px">
        <?php $opts = [
          ['website',   '🌐 Website Leads', 'CNP Leads tab'],
          ['meta',      '📱 Meta Leads',    'LSR Leads 2026 tab'],
          ['google',    '🔍 Google Leads',  'Google Leads tab'],
          ['salesteam', '👥 Sales Team',    'Sales Team tab – imports manager names'],
        ]; foreach ($opts as [$val, $label, $sub]): ?>
        <label style="display:flex;align-items:center;gap:14px;cursor:pointer;background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:14px 16px;transition:border-color 0.18s"
               onmouseover="this.style.borderColor='var(--accent)'" onmouseout="this.style.borderColor='var(--border)'">
          <input type="checkbox" name="sources[]" value="<?= $val ?>" checked style="width:16px;height:16px;accent-color:var(--accent)">
          <div>
            <div style="font-size:14px;font-weight:600"><?= $label ?></div>
            <div style="font-size:12px;color:var(--text2);margin-top:2px"><?= $sub ?></div>
          </div>
        </label>
        <?php endforeach; ?>
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%">🔄 Run Sync Now</button>
    </form>
  </div>

  <!-- Sync log -->
  <div style="margin-top:28px">
    <div style="font-size:13px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--text2);margin-bottom:14px">Recent Sync Log</div>
    <?php if (empty($logs)): ?>
      <p style="color:var(--text2);font-size:13px">No syncs yet.</p>
    <?php else: ?>
    <div class="tbl-wrap">
      <table>
        <thead><tr><th>Source</th><th>Rows Imported</th><th>Date &amp; Time</th></tr></thead>
        <tbody>
          <?php foreach ($logs as $l): ?>
          <tr>
            <td><?= htmlspecialchars($l['source']) ?></td>
            <td><span style="color:var(--accent);font-weight:600"><?= $l['rows_synced'] ?></span></td>
            <td style="font-size:12px;color:var(--text2)"><?= date('d M Y, H:i', strtotime($l['synced_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
