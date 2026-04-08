<?php
require_once __DIR__ . '/config.php';
$user = requireAuth('admin');
$pageTitle = 'Sync All Sources';

$pdo     = db();
$results = [];
$error   = '';
$metaResults = [];
$metaSynced = 0;
$metaSkipped = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $syncType = $_POST['sync_type'] ?? 'sheets';

    // ── Google Sheets Sync ──
    if ($syncType === 'sheets') {
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

    // ── CNP Greenfield Sheet Sync ──
    if ($syncType === 'cnp_greenfield') {
        try {
            require_once __DIR__ . '/includes/sheets.php';
            $api = new SheetsAPI();
            $n = $api->syncCNPGreenfield();
            $results[] = ['source' => 'CNP Greenfield (All Projects)', 'count' => $n, 'ok' => true];
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }

    // ── Meta API Sync (from Facebook directly) ──
    if ($syncType === 'meta_api') {
        $PAGE_ID = '387375521120468';
        $TOKEN   = defined('META_PAGE_ACCESS_TOKEN') ? META_PAGE_ACCESS_TOKEN : '';

        // Date range for filtering (Facebook defaults to 90 days; we override)
        $syncFrom = $_POST['meta_sync_from'] ?? '2020-01-01';
        $syncTo   = $_POST['meta_sync_to']   ?? date('Y-m-d');
        $tsFrom   = strtotime($syncFrom . ' 00:00:00');
        $tsTo     = strtotime($syncTo   . ' 23:59:59');

        if (!$TOKEN) {
            $error = 'META_PAGE_ACCESS_TOKEN not configured.';
        } else {
            $forms = metaCurl("https://graph.facebook.com/v21.0/{$PAGE_ID}/leadgen_forms?fields=id,name,status&limit=50&access_token={$TOKEN}");
            if (!$forms || empty($forms['data'])) {
                $error = 'Could not fetch lead forms. Check Page Access Token.';
            } else {
                foreach ($forms['data'] as $form) {
                    $formId   = $form['id'];
                    $formName = $form['name'] ?? 'Unknown Form';
                    // Apply date filtering to get ALL leads, not just last 90 days
                    $filtering = urlencode(json_encode([
                        ['field' => 'time_created', 'operator' => 'GREATER_THAN', 'value' => $tsFrom],
                        ['field' => 'time_created', 'operator' => 'LESS_THAN',    'value' => $tsTo],
                    ]));
                    $leadsUrl = "https://graph.facebook.com/v21.0/{$formId}/leads?fields=id,created_time,field_data&filtering={$filtering}&limit=500&access_token={$TOKEN}";

                    while ($leadsUrl) {
                        $leadsResp = metaCurl($leadsUrl);
                        if (!$leadsResp || empty($leadsResp['data'])) break;

                        foreach ($leadsResp['data'] as $lead) {
                            $fields = [];
                            $allQA = [];
                            foreach ($lead['field_data'] ?? [] as $f) {
                                $key = strtolower(str_replace([' ', '-'], '_', $f['name']));
                                $val = $f['values'][0] ?? '';
                                $fields[$key] = $val;
                                $allQA[] = $f['name'] . ': ' . $val;
                            }

                            $firstName = $fields['first_name'] ?? $fields['full_name'] ?? '';
                            $lastName  = $fields['last_name']  ?? '';
                            $mobile    = $fields['phone_number'] ?? $fields['phone'] ?? $fields['mobile'] ?? '';
                            $email     = $fields['email'] ?? '';
                            $pref      = $fields['preference'] ?? $fields['select_your_preference'] ?? $fields['interested_in'] ?? $fields['configuration'] ?? '';
                            $mobile    = preg_replace('/[^0-9+]/', '', $mobile);
                            $createdAt = $lead['created_time'] ?? date('Y-m-d H:i:s');

                            $leadId = $lead['id'] ?? '';
                            if ($leadId) {
                                $dup = $pdo->prepare('SELECT id FROM leads WHERE source_row_id=? AND source="meta"');
                                $dup->execute([$leadId]);
                                if ($dup->fetch()) { $metaSkipped++; continue; }
                            }

                            $notes = "Form: {$formName}\n";
                            if ($pref) $notes .= "Preference: {$pref}\n";
                            $notes .= "---\nAll Answers: " . implode(' | ', $allQA);

                            try {
                                $pdo->prepare('INSERT INTO leads (source, source_row_id, first_name, last_name, mobile, email, preference, project_name, form_name, notes, lead_type, lead_status, created_at) VALUES ("meta", ?, ?, ?, ?, ?, ?, ?, ?, ?, "warm", "sv_pending", ?)')
                                    ->execute([$leadId, $firstName, $lastName, $mobile, $email, $pref, $formName, $formName, $notes, $createdAt]);
                            } catch (\PDOException $e) {
                                $pdo->prepare('INSERT INTO leads (source, source_row_id, first_name, last_name, mobile, email, preference, comments, lead_type, lead_status, created_at) VALUES ("meta", ?, ?, ?, ?, ?, ?, ?, "warm", "sv_pending", ?)')
                                    ->execute([$leadId, $firstName, $lastName, $mobile, $email, $pref, $notes, $createdAt]);
                            }
                            $metaSynced++;
                            $metaResults[] = ['name' => trim("$firstName $lastName"), 'mobile' => $mobile, 'form' => $formName];
                        }
                        $leadsUrl = $leadsResp['paging']['next'] ?? null;
                    }
                }
                $pdo->prepare('INSERT INTO sync_log (source, rows_synced) VALUES ("meta_api", ?)')->execute([$metaSynced]);
            }
        }
    }
}

function metaCurl(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => true]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$resp) return null;
    return json_decode($resp, true);
}

$logs = $pdo->query('SELECT * FROM sync_log ORDER BY id DESC LIMIT 20')->fetchAll();
include __DIR__ . '/includes/header.php';
?>

<div style="max-width:700px">

  <h2 style="font-family:'DM Serif Display',serif;font-size:22px;margin-bottom:8px">🔄 Sync All Sources</h2>
  <p style="color:var(--text2);font-size:13px;margin-bottom:24px;line-height:1.6">
    Pull leads from Google Sheets or directly from Facebook Lead Forms into the CRM.
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

  <?php if ($metaSynced > 0 || $metaSkipped > 0): ?>
    <div class="grid-3" style="margin-bottom:20px">
      <div class="stat-card" style="border-color:rgba(74,222,128,0.3)">
        <div class="stat-label" style="color:#4ade80">✅ Synced</div>
        <div class="stat-value" style="color:#4ade80"><?= $metaSynced ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label" style="color:#fbbf24">⏭ Skipped</div>
        <div class="stat-value" style="color:#fbbf24"><?= $metaSkipped ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">📊 Total</div>
        <div class="stat-value"><?= $metaSynced + $metaSkipped ?></div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Google Sheets Sync -->
  <div class="card">
    <form method="POST">
      <div style="font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--text2);margin-bottom:16px">Google Sheets Sync</div>

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

      <button type="submit" name="sync_type" value="sheets" class="btn btn-primary" style="width:100%">🔄 Sync Google Sheets</button>
    </form>
  </div>

  <!-- CNP Greenfield Sheet Sync -->
  <div class="card" style="margin-top:20px;border-color:rgba(74,222,128,0.25)">
    <form method="POST">
      <div style="font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#4ade80;margin-bottom:12px">📋 CNP Greenfield Sheet Sync</div>
      <p style="color:var(--text2);font-size:13px;margin-bottom:12px">Import ALL historical leads from the CNP Greenfield Google Sheet. This sheet has 7 projects arranged side-by-side:</p>
      <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:16px">
        <span style="background:rgba(74,222,128,0.1);color:#4ade80;border:1px solid rgba(74,222,128,0.2);padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;">Elevate Shop</span>
        <span style="background:rgba(74,222,128,0.1);color:#4ade80;border:1px solid rgba(74,222,128,0.2);padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;">Athena</span>
        <span style="background:rgba(74,222,128,0.1);color:#4ade80;border:1px solid rgba(74,222,128,0.2);padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;">Studio Apartment</span>
        <span style="background:rgba(74,222,128,0.1);color:#4ade80;border:1px solid rgba(74,222,128,0.2);padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;">One Holding Kharadi</span>
        <span style="background:rgba(74,222,128,0.1);color:#4ade80;border:1px solid rgba(74,222,128,0.2);padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;">Jhamtani Ace Abundance</span>
        <span style="background:rgba(74,222,128,0.1);color:#4ade80;border:1px solid rgba(74,222,128,0.2);padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;">Jhamtani Spacebiz Baner</span>
        <span style="background:rgba(74,222,128,0.1);color:#4ade80;border:1px solid rgba(74,222,128,0.2);padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;">Azalea/Athena</span>
      </div>
      <p style="color:#4ade80;font-size:11px;margin-bottom:14px;opacity:0.8;">💡 Duplicates are automatically skipped by phone number. Only NEW leads will be imported.</p>
      <button type="submit" name="sync_type" value="cnp_greenfield" class="btn btn-primary" style="width:100%;background:linear-gradient(135deg,#22c55e,#4ade80);color:#0a0e17;font-weight:700;">📥 Import All CNP Greenfield Leads</button>
    </form>
  </div>

  <!-- Meta API Sync -->
  <div class="card" style="margin-top:20px;border-color:rgba(59,130,246,0.25)">
    <form method="POST">
      <div style="font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#60a5fa;margin-bottom:12px">📱 Facebook Meta API Sync</div>
      <p style="color:var(--text2);font-size:13px;margin-bottom:16px">Pull leads directly from Facebook Lead Forms API — from any date range. Fetches all form answers, campaign names. Duplicates auto-skipped.</p>
      
      <div style="display:flex;gap:14px;margin-bottom:18px;flex-wrap:wrap;align-items:flex-end;">
        <div style="flex:1;min-width:150px;">
          <label style="display:block;font-size:11px;font-weight:600;letter-spacing:0.5px;text-transform:uppercase;color:#8a9ab8;margin-bottom:5px;font-family:'Inter',sans-serif;">From Date</label>
          <input type="date" name="meta_sync_from" value="2020-01-01" class="form-control" style="margin:0;">
        </div>
        <div style="flex:1;min-width:150px;">
          <label style="display:block;font-size:11px;font-weight:600;letter-spacing:0.5px;text-transform:uppercase;color:#8a9ab8;margin-bottom:5px;font-family:'Inter',sans-serif;">To Date</label>
          <input type="date" name="meta_sync_to" value="<?= date('Y-m-d') ?>" class="form-control" style="margin:0;">
        </div>
      </div>
      <p style="color:#60a5fa;font-size:11px;margin-bottom:14px;opacity:0.8;">💡 Facebook only returns last 90 days by default. Set an earlier "From Date" (e.g. 2020-01-01) to fetch ALL historical leads.</p>
      
      <button type="submit" name="sync_type" value="meta_api" class="btn btn-primary" style="width:100%;background:linear-gradient(135deg,#3b82f6,#60a5fa);color:#fff">📥 Sync All Meta Leads from Facebook</button>
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
