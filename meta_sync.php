<?php
// ============================================================
//  meta_sync.php — Fetch ALL existing Meta leads into CRM
//  URL: https://crm.propnmore.com/meta_sync.php
//  Pulls leads from ALL lead forms on your Facebook Page
// ============================================================

require_once __DIR__ . '/config.php';
$user = requireAuth('admin');

$pageTitle = 'Meta Lead Sync';
$PAGE_ID = '387375521120468'; // Lakshsiddh Ventures
$TOKEN   = META_PAGE_ACCESS_TOKEN;
$results = [];
$error   = '';
$synced  = 0;
$skipped = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $TOKEN) {
    // Date range for filtering (Facebook defaults to 90 days; we override)
    $syncFrom = $_POST['sync_from'] ?? '2020-01-01';
    $syncTo   = $_POST['sync_to']   ?? date('Y-m-d');
    $tsFrom   = strtotime($syncFrom . ' 00:00:00');
    $tsTo     = strtotime($syncTo   . ' 23:59:59');
    // Step 1: Get all lead forms from Page
    $forms = curlGet("https://graph.facebook.com/v21.0/{$PAGE_ID}/leadgen_forms?fields=id,name,status&limit=50&access_token={$TOKEN}");

    if (!$forms || empty($forms['data'])) {
        $error = 'Could not fetch lead forms. Check Page Access Token.';
    } else {
        $pdo = db();

        foreach ($forms['data'] as $form) {
            $formId   = $form['id'];
            $formName = $form['name'] ?? 'Unknown Form';

            // Step 2: Fetch all leads from each form
            // Apply date filtering to get ALL leads, not just last 90 days
            $filtering = urlencode(json_encode([
                ['field' => 'time_created', 'operator' => 'GREATER_THAN', 'value' => $tsFrom],
                ['field' => 'time_created', 'operator' => 'LESS_THAN',    'value' => $tsTo],
            ]));
            $leadsUrl = "https://graph.facebook.com/v21.0/{$formId}/leads?fields=id,created_time,field_data,ad_id,campaign_id&filtering={$filtering}&limit=500&access_token={$TOKEN}";

            while ($leadsUrl) {
                $leadsResp = curlGet($leadsUrl);
                if (!$leadsResp || empty($leadsResp['data'])) break;

                foreach ($leadsResp['data'] as $lead) {
                    // Parse fields
                    $fields = [];
                    $allQA = [];
                    foreach ($lead['field_data'] ?? [] as $f) {
                        $key = strtolower(str_replace([' ', '-'], '_', $f['name']));
                        $val = $f['values'][0] ?? '';
                        $fields[$key] = $val;
                        $allQA[] = $f['name'] . ': ' . $val;
                    }

                    $firstName = $fields['first_name']  ?? $fields['full_name'] ?? '';
                    $lastName  = $fields['last_name']   ?? '';
                    $mobile    = $fields['phone_number'] ?? $fields['phone'] ?? $fields['mobile'] ?? '';
                    $email     = $fields['email']       ?? '';
                    $pref      = $fields['preference']  ?? $fields['select_your_preference'] ?? $fields['interested_in'] ?? $fields['configuration'] ?? '';
                    $siteVisit = $fields['when_can_you_come_for_the_site_visit_?'] ?? $fields['site_visit'] ?? '';

                    $mobile = preg_replace('/[^0-9+]/', '', $mobile);
                    $createdAt = $lead['created_time'] ?? date('Y-m-d H:i:s');

                    // Dedup by source_row_id
                    $leadId = $lead['id'] ?? '';
                    if ($leadId) {
                        $dup = $pdo->prepare('SELECT id FROM leads WHERE source_row_id=? AND source="meta"');
                        $dup->execute([$leadId]);
                        if ($dup->fetch()) {
                            $skipped++;
                            continue;
                        }
                    }

                    // Build notes
                    $notes = "Form: {$formName}\n";
                    if ($pref) $notes .= "Preference: {$pref}\n";
                    if ($siteVisit) $notes .= "Site Visit: {$siteVisit}\n";
                    $notes .= "---\nAll Answers: " . implode(' | ', $allQA);

                    // Insert
                    try {
                        $pdo->prepare('
                            INSERT INTO leads
                                (source, source_row_id, first_name, last_name, mobile, email,
                                 preference, project_name, form_name, notes,
                                 lead_type, lead_status, created_at)
                            VALUES ("meta", ?, ?, ?, ?, ?, ?, ?, ?, ?, "warm", "sv_pending", ?)
                        ')->execute([
                            $leadId, $firstName, $lastName, $mobile, $email,
                            $pref, $formName, $formName, $notes, $createdAt
                        ]);
                        $synced++;
                    } catch (\PDOException $e) {
                        // Fallback without new columns
                        $pdo->prepare('
                            INSERT INTO leads
                                (source, source_row_id, first_name, last_name, mobile, email,
                                 preference, comments, lead_type, lead_status, created_at)
                            VALUES ("meta", ?, ?, ?, ?, ?, ?, ?, "warm", "sv_pending", ?)
                        ')->execute([
                            $leadId, $firstName, $lastName, $mobile, $email, $pref, $notes, $createdAt
                        ]);
                        $synced++;
                    }

                    $results[] = [
                        'name' => trim("$firstName $lastName"),
                        'mobile' => $mobile,
                        'form' => $formName,
                        'pref' => $pref,
                        'date' => $createdAt,
                    ];
                }

                // Pagination — get next page
                $leadsUrl = $leadsResp['paging']['next'] ?? null;
            }
        }

        // Log sync
        $pdo->prepare('INSERT INTO sync_log (source, rows_synced) VALUES ("meta_sync", ?)')->execute([$synced]);
    }
}

function curlGet(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$resp) return null;
    return json_decode($resp, true);
}

include __DIR__ . '/includes/header.php';
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px">
    <div>
        <h2 style="margin:0;font-size:20px">📱 Meta Lead Sync</h2>
        <p style="color:var(--text2);font-size:13px;margin-top:4px">Pull all existing leads from Facebook Lead Forms into CRM</p>
    </div>
    <a href="<?= BASE_URL ?>/index.php?source=meta" class="btn btn-outline btn-sm">View Meta Leads →</a>
</div>

<?php if (!$TOKEN): ?>
<div class="card" style="border-color:rgba(239,68,68,0.3);padding:24px">
    <p style="color:#f87171;margin:0">⚠️ META_PAGE_ACCESS_TOKEN is not configured in config.php</p>
</div>
<?php else: ?>

<?php if ($_SERVER['REQUEST_METHOD'] === 'GET'): ?>
<div class="card" style="padding:32px;text-align:center">
    <div style="font-size:48px;margin-bottom:16px">📥</div>
    <h3 style="margin-bottom:8px">Fetch All Meta Leads</h3>
    <p style="color:var(--text2);margin-bottom:24px">Pull ALL leads from your Facebook Page's lead forms — from the very beginning to today.<br>Duplicates are automatically skipped.</p>
    <form method="POST">
        <input type="hidden" name="_csrf" value="<?= csrf() ?>">
        
        <div style="display:flex;gap:16px;justify-content:center;align-items:flex-end;margin-bottom:24px;flex-wrap:wrap;">
            <div style="text-align:left;">
                <label class="flbl" style="display:block;font-size:11px;font-weight:600;letter-spacing:0.5px;text-transform:uppercase;color:#8a9ab8;margin-bottom:5px;font-family:'Inter',sans-serif;">From Date</label>
                <input type="date" name="sync_from" value="2020-01-01" class="form-control" style="margin:0;min-width:170px;">
            </div>
            <div style="text-align:left;">
                <label class="flbl" style="display:block;font-size:11px;font-weight:600;letter-spacing:0.5px;text-transform:uppercase;color:#8a9ab8;margin-bottom:5px;font-family:'Inter',sans-serif;">To Date</label>
                <input type="date" name="sync_to" value="<?= date('Y-m-d') ?>" class="form-control" style="margin:0;min-width:170px;">
            </div>
        </div>
        
        <button type="submit" class="btn btn-primary" style="padding:14px 40px;font-size:16px">
            🔄 Sync All Meta Leads Now
        </button>
        
        <p style="color:var(--text2);font-size:12px;margin-top:12px">
            <strong>Tip:</strong> Set "From Date" to an earlier date (e.g., 2020-01-01) to fetch historical leads that Facebook's 90-day default window may have missed.
        </p>
    </form>
    <p style="color:var(--text2);font-size:12px;margin-top:16px">Page: Lakshsiddh Ventures (ID: <?= $PAGE_ID ?>)</p>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="card" style="border-color:rgba(239,68,68,0.3);padding:20px;margin-top:16px">
    <p style="color:#f87171;margin:0">❌ <?= htmlspecialchars($error) ?></p>
</div>
<?php endif; ?>

<?php if ($synced > 0 || $skipped > 0): ?>
<div class="grid-3" style="margin-top:20px;margin-bottom:20px">
    <div class="stat-card" style="border-color:rgba(74,222,128,0.3)">
        <div class="stat-label" style="color:#4ade80">✅ Synced</div>
        <div class="stat-value" style="color:#4ade80"><?= $synced ?></div>
        <div class="stat-sub">New leads imported</div>
    </div>
    <div class="stat-card">
        <div class="stat-label" style="color:#fbbf24">⏭ Skipped</div>
        <div class="stat-value" style="color:#fbbf24"><?= $skipped ?></div>
        <div class="stat-sub">Already in CRM</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">📊 Total</div>
        <div class="stat-value"><?= $synced + $skipped ?></div>
        <div class="stat-sub">Leads processed</div>
    </div>
</div>

<?php if (!empty($results)): ?>
<div class="card card-sm">
    <h4 style="margin-bottom:12px">Imported Leads</h4>
    <div class="tbl-wrap">
        <table>
            <thead><tr><th>Name</th><th>Mobile</th><th>Form/Project</th><th>Preference</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($results as $r): ?>
            <tr>
                <td><strong><?= htmlspecialchars($r['name']) ?></strong></td>
                <td style="font-family:monospace"><?= htmlspecialchars($r['mobile']) ?></td>
                <td><?= htmlspecialchars($r['form']) ?></td>
                <td><?= htmlspecialchars($r['pref']) ?></td>
                <td style="font-size:12px;color:var(--text2)"><?= date('d M Y H:i', strtotime($r['date'])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
