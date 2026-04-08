<?php
require_once __DIR__ . '/config.php';
$user = requireAuth();
$pageTitle = 'All Leads';

$pdo = db();

// ── Filters & Query Building ──────────────────────────────────────────────────
$where  = ['l.is_deleted = 0'];
$params = [];

$source = $_GET['source'] ?? '';
if ($source) { $where[] = 'l.source=?'; $params[] = $source; }

$type = $_GET['type'] ?? '';
if ($type) { $where[] = 'l.lead_type=?'; $params[] = $type; }

$status = $_GET['status'] ?? '';
if ($status) { $where[] = 'l.lead_status=?'; $params[] = $status; }

$projectId = (int)($_GET['project'] ?? 0);
if ($projectId) { $where[] = 'l.project_id=?'; $params[] = $projectId; }

$assignedTo = (int)($_GET['assigned'] ?? 0);
if ($assignedTo === -1) {
    $where[] = '(l.assigned_to = 0 OR l.assigned_to IS NULL)';
} elseif ($assignedTo === -2) {
    $where[] = '(l.assigned_to IS NOT NULL AND l.assigned_to > 0)';
} elseif ($assignedTo > 0) {
    $where[] = 'l.assigned_to=?'; 
    $params[] = $assignedTo;
}

$dateFilter = $_GET['date_filter'] ?? '';
$dateFrom   = $_GET['date_from'] ?? '';
$dateTo     = $_GET['date_to'] ?? '';

if ($dateFilter === 'today') {
    $where[] = '(DATE(l.created_at) = CURRENT_DATE())';
} elseif ($dateFilter === 'yesterday') {
    $where[] = '(DATE(l.created_at) = DATE_SUB(CURRENT_DATE(), INTERVAL 1 DAY))';
} elseif ($dateFilter === 'last_7') {
    $where[] = '(l.created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 7 DAY))';
} elseif ($dateFilter === 'last_14') {
    $where[] = '(l.created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 14 DAY))';
} elseif ($dateFilter === 'last_30') {
    $where[] = '(l.created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY))';
} elseif ($dateFilter === 'this_week') {
    $where[] = '(YEARWEEK(l.created_at, 1) = YEARWEEK(CURRENT_DATE(), 1))';
} elseif ($dateFilter === 'this_month') {
    $where[] = '(MONTH(l.created_at) = MONTH(CURRENT_DATE()) AND YEAR(l.created_at) = YEAR(CURRENT_DATE()))';
} elseif ($dateFilter === '3_months') {
    $where[] = '(l.created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 3 MONTH))';
} elseif ($dateFilter === 'custom' && $dateFrom && $dateTo) {
    $where[] = '(DATE(l.created_at) >= ? AND DATE(l.created_at) <= ?)';
    $params[] = $dateFrom;
    $params[] = $dateTo;
} elseif ($dateFilter === 'custom' && $dateFrom && !$dateTo) {
    $where[] = '(DATE(l.created_at) >= ?)';
    $params[] = $dateFrom;
} elseif ($dateFilter === 'custom' && !$dateFrom && $dateTo) {
    $where[] = '(DATE(l.created_at) <= ?)';
    $params[] = $dateTo;
}

if ($user['role'] === 'sales_manager') {
    $where[] = 'l.assigned_to=?';
    $params[] = $user['id'];
}

$search = trim($_GET['q'] ?? '');
if ($search) {
    $where[]  = '(l.first_name LIKE ? OR l.last_name LIKE ? OR l.mobile LIKE ? OR l.email LIKE ?)';
    $like = "%{$search}%";
    array_push($params, $like, $like, $like, $like);
}

$whereStr = implode(' AND ', $where);

// ── Export CSV Logic ─────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=CRM_Report_'.date('Ymd_His').'.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Name', 'Mobile', 'Email', 'Project', 'Source', 'Type', 'Status', 'Assigned To', 'Added On']);
    
    $sqlExport = "SELECT l.*, COALESCE(p.name, l.project_name) AS project_name, u.name as assigned_name 
                  FROM leads l LEFT JOIN projects p ON l.project_id=p.id LEFT JOIN users u ON l.assigned_to=u.id 
                  WHERE {$whereStr} ORDER BY l.created_at DESC";
    $stExport = $pdo->prepare($sqlExport);
    $stExport->execute($params);
    
    while ($row = $stExport->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['id'],
            $row['first_name'] . ' ' . $row['last_name'],
            $row['mobile'],
            $row['email'],
            $row['project_name'],
            urlencode(ucfirst($row['source'])),
            ucfirst($row['lead_type']),
            $row['lead_status'],
            $row['assigned_name'] ?: 'Unassigned',
            $row['created_at']
        ]);
    }
    fclose($output);
    exit;
}

// ── Handle Bulk Assignment ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_assign' && $user['role'] === 'admin') {
    $assignToId = (int)($_POST['bulk_assigned_to'] ?? 0);
    $leadIds = $_POST['lead_ids'] ?? [];
    
    // Check if Global Select All is used
    if (isset($_POST['global_select']) && $_POST['global_select'] === '1') {
        $stGlobal = $pdo->prepare("SELECT id FROM leads l WHERE {$whereStr}");
        $stGlobal->execute($params);
        $leadIds = $stGlobal->fetchAll(PDO::FETCH_COLUMN); // Override with ALL matching IDs
    }
    
    if (($assignToId > 0 || $assignToId === -1) && is_array($leadIds) && count($leadIds) > 0) {
        try {
            $assignerName = $user['name'];
            $assignedCount = 0;

            // ── UNASSIGN MODE (value = -1) ──────────────────────────────
            if ($assignToId === -1) {
                foreach ($leadIds as $lid) {
                    $lid = (int)$lid;
                    $pdo->prepare('UPDATE leads SET assigned_to=NULL WHERE id=?')->execute([$lid]);
                    $pdo->prepare('INSERT INTO followups (lead_id,user_id,call_response) VALUES (?,?,?)')
                        ->execute([$lid, $user['id'], "Lead unassigned by {$assignerName}."]);
                    $assignedCount++;
                }
                $msg = urlencode("{$assignedCount} leads unassigned successfully.");
                header("Location: " . BASE_URL . "/index.php?msg={$msg}");
                exit;
            }

            // ── ASSIGN MODE ─────────────────────────────────────────────
            $smData = $pdo->prepare('SELECT name, email FROM users WHERE id=?');
            $smData->execute([$assignToId]);
            $smInfo = $smData->fetch();
            $smMobile = '';
            try {
                $smMob = $pdo->prepare('SELECT mobile FROM users WHERE id=?');
                $smMob->execute([$assignToId]);
                $smMobile = (string)($smMob->fetchColumn() ?? '');
            } catch (\Exception $ignored) {}

            foreach ($leadIds as $lid) {
                $lid = (int)$lid;
                $stLead = $pdo->prepare('SELECT l.id, l.first_name, l.last_name, l.mobile, l.assigned_to, l.project_id, COALESCE(p.name, \'\') AS project_name FROM leads l LEFT JOIN projects p ON l.project_id=p.id WHERE l.id=?');
                $stLead->execute([$lid]);
                $lead = $stLead->fetch();
                
                if ($lead && $lead['assigned_to'] != $assignToId) {
                    $pdo->prepare('UPDATE leads SET assigned_to=? WHERE id=?')->execute([$assignToId, $lid]);
                    $pdo->prepare('INSERT INTO followups (lead_id,user_id,call_response) VALUES (?,?,?)')
                        ->execute([$lid, $user['id'], "Lead assigned to " . ($smInfo['name'] ?? 'Sales Manager') . " by {$assignerName}."]);
                        
                    $leadName = trim($lead['first_name'] . ' ' . $lead['last_name']);
                    $projectName = $lead['project_name'] ?: 'N/A';
                    $leadMobile  = $lead['mobile'] ?? '';
                    
                    // In-app notification
                    createNotification($assignToId, 'New Lead Assigned', "Lead \"{$leadName}\" (#{$lid}) assigned to you by {$assignerName}.", $lid, 'lead_assigned');
                    
                    // Individual Email per lead
                    if ($smInfo && !empty($smInfo['email'])) {
                        $emailBody = '<!DOCTYPE html><html><body style="margin:0;padding:20px;background:#080d16;">
                        <div style="max-width:600px;margin:0 auto;font-family:Segoe UI,Roboto,Arial,sans-serif;border:1px solid #1a2640;border-radius:12px;overflow:hidden;background:#0f1623;color:#dde3f0;">
                            <div style="background:linear-gradient(135deg,#c9a96e,#e8c98a);padding:22px;text-align:center;color:#0a0e17">
                                <h2 style="margin:0;font-size:20px;">New Lead Assigned</h2>
                            </div>
                            <div style="padding:28px;">
                                <p style="font-size:15px;line-height:1.6;">Hi <strong>'.htmlspecialchars($smInfo['name']).'</strong>,</p>
                                <p style="font-size:14px;line-height:1.6;color:#8a9ab8;">A new lead has been assigned to you by <strong style="color:#dde3f0;">'.htmlspecialchars($assignerName).'</strong>.</p>
                                <div style="background:#141d2e;padding:18px;border-radius:8px;margin:20px 0;border:1px solid #1a2640;">
                                    <p style="margin:0 0 8px 0;font-size:14px;"><b style="color:#8a9ab8;">Name:</b> <span style="color:#dde3f0;">'.htmlspecialchars($leadName).'</span></p>
                                    <p style="margin:0 0 8px 0;font-size:14px;"><b style="color:#8a9ab8;">Mobile:</b> <a href="tel:'.htmlspecialchars($leadMobile).'" style="color:#c9a96e;text-decoration:none;font-family:monospace;">'.htmlspecialchars($leadMobile).'</a></p>
                                    <p style="margin:0;font-size:14px;"><b style="color:#8a9ab8;">Project:</b> <span style="color:#e8c98a;">'.htmlspecialchars($projectName).'</span></p>
                                </div>
                                <div style="text-align:center;margin-top:24px;">
                                    <a href="'.BASE_URL.'/lead_detail.php?id='.$lid.'" style="display:inline-block;padding:12px 28px;background:linear-gradient(135deg,#c9a96e,#e8c98a);color:#0a0e17;text-decoration:none;border-radius:8px;font-weight:bold;font-size:14px;">View Lead Details</a>
                                </div>
                            </div>
                        </div>
                        </body></html>';
                        sendMail($smInfo['email'], "New Lead Assigned: {$leadName}", $emailBody);
                    }
                    
                    // SMS/WhatsApp
                    if (!empty($smMobile)) {
                        $msgText = "LSR CRM: New lead - {$leadName}, Ph: {$leadMobile}, Project: {$projectName}. Login: crm.propnmore.com";
                        sendAlert($smMobile, $msgText);
                    }
                    
                    $assignedCount++;
                }
            }
            
            $msg = urlencode("{$assignedCount} leads assigned to " . ($smInfo['name'] ?? 'Sales Manager') . ".");
            header("Location: " . BASE_URL . "/index.php?msg={$msg}");
            exit;
        } catch (Exception $e) {
            $err = urlencode("Error: " . $e->getMessage());
            header("Location: " . BASE_URL . "/index.php?err={$err}");
            exit;
        }
    } else {
        $err = urlencode("Please select leads and choose an action.");
        header("Location: " . BASE_URL . "/index.php?err={$err}");
        exit;
    }
}

// Code moved to top

// ── Pagination ────────────────────────────────────────────────────────────────
$perPage = 25;
$page    = max(1, (int)($_GET['pg'] ?? 1));
$offset  = ($page - 1) * $perPage;

$total = $pdo->prepare("SELECT COUNT(*) FROM leads l WHERE {$whereStr}");
$total->execute($params);
$totalRows = (int)$total->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));

// ── Leads query ───────────────────────────────────────────────────────────────
$sql = "SELECT l.*, 
               COALESCE(p.name, l.project_name) AS project_name,
               l.campaign_name, l.ad_name, l.form_name, l.notes,
               CONCAT(u.name) AS assigned_name,
               (SELECT COUNT(*) FROM followups f WHERE f.lead_id=l.id) AS followup_count
        FROM leads l
        LEFT JOIN projects p ON l.project_id = p.id
        LEFT JOIN users    u ON l.assigned_to = u.id
        WHERE {$whereStr}
        ORDER BY l.created_at DESC
        LIMIT {$perPage} OFFSET {$offset}";
$st = $pdo->prepare($sql);
$st->execute($params);
$leads = $st->fetchAll();

// ── Stats (dynamic — reflects active filters) ────────────────────────────────
$statsSql = "SELECT
    COUNT(*) AS total,
    SUM(MONTH(l.created_at) = MONTH(CURRENT_DATE()) AND YEAR(l.created_at) = YEAR(CURRENT_DATE())) AS total_tm,
    SUM(l.lead_type='hot') AS hot,
    SUM(l.lead_type='hot' AND MONTH(l.created_at) = MONTH(CURRENT_DATE()) AND YEAR(l.created_at) = YEAR(CURRENT_DATE())) AS hot_tm,
    SUM(l.lead_type='warm') AS warm,
    SUM(l.lead_type='warm' AND MONTH(l.created_at) = MONTH(CURRENT_DATE()) AND YEAR(l.created_at) = YEAR(CURRENT_DATE())) AS warm_tm,
    SUM(l.lead_type='cold') AS cold,
    SUM(l.lead_type='cold' AND MONTH(l.created_at) = MONTH(CURRENT_DATE()) AND YEAR(l.created_at) = YEAR(CURRENT_DATE())) AS cold_tm,
    SUM(l.lead_status='sv_pending') AS svp,
    SUM(l.lead_status='sv_pending' AND MONTH(l.created_at) = MONTH(CURRENT_DATE()) AND YEAR(l.created_at) = YEAR(CURRENT_DATE())) AS svp_tm,
    SUM(l.lead_status='sv_done') AS svd,
    SUM(l.lead_status='sv_done' AND MONTH(l.created_at) = MONTH(CURRENT_DATE()) AND YEAR(l.created_at) = YEAR(CURRENT_DATE())) AS svd_tm,
    SUM(l.lead_status='closed') AS closed,
    SUM(l.lead_status='closed' AND MONTH(l.created_at) = MONTH(CURRENT_DATE()) AND YEAR(l.created_at) = YEAR(CURRENT_DATE())) AS closed_tm,
    SUM(l.lead_status='spam') AS spam,
    SUM(l.lead_status='spam' AND MONTH(l.created_at) = MONTH(CURRENT_DATE()) AND YEAR(l.created_at) = YEAR(CURRENT_DATE())) AS spam_tm
    FROM leads l WHERE {$whereStr}";
$statsStmt = $pdo->prepare($statsSql);
$statsStmt->execute($params);
$stats = $statsStmt->fetch();

// ── Dropdown data ─────────────────────────────────────────────────────────────
$projects     = $pdo->query('SELECT id,name FROM projects ORDER BY name')->fetchAll();
$salesManagers = $pdo->query("SELECT id,name FROM users WHERE role='sales_manager' AND is_active=1 ORDER BY name")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<!-- Stats row -->
<?php if ($user['role'] === 'admin'): ?>
<!-- ADMIN VIEW -->
<style>
  .si{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px;vertical-align:middle;}
  .si-total{background:#c9a96e;} .si-hot{background:#f87171;} .si-warm{background:#fbbf24;}
  .si-cold{background:#60a5fa;} .si-svp{background:#c084fc;} .si-svd{background:#4ade80;}
  .si-closed{background:#94a3b8;} .si-spam{background:#ef4444;}
  .stat-label{font-family:'Inter',sans-serif;font-size:12px;font-weight:600;letter-spacing:0.5px;text-transform:uppercase;}
  .stat-value{font-family:'Inter',sans-serif;font-weight:700;}
  .tm-badge{font-size:11px;color:rgba(200,212,230,0.7);margin-bottom:6px;font-family:'Inter',sans-serif;font-weight:500;}
  .tm-badge strong{font-weight:700;}
</style>
<div class="grid-4" style="margin-bottom:24px">
  <div class="stat-card">
    <div class="stat-label"><span class="si si-total"></span>Total Leads</div>
    <div style="display:flex;justify-content:space-between;align-items:flex-end;">
      <div class="stat-value"><?= number_format($stats['total'] ?: 0) ?></div>
      <div class="tm-badge">This Month: <strong style="color:#e8d5b0"><?= number_format($stats['total_tm'] ?: 0) ?></strong></div>
    </div>
    <div class="stat-sub">All sources</div>
  </div>
  <div class="stat-card" style="border-color:rgba(239,68,68,0.3)">
    <div class="stat-label" style="color:#f87171"><span class="si si-hot"></span>Hot</div>
    <div style="display:flex;justify-content:space-between;align-items:flex-end;">
      <div class="stat-value" style="color:#f87171"><?= number_format($stats['hot'] ?: 0) ?></div>
      <div class="tm-badge">This Month: <strong style="color:#f87171"><?= number_format($stats['hot_tm'] ?: 0) ?></strong></div>
    </div>
    <div class="stat-sub">High priority</div>
  </div>
  <div class="stat-card" style="border-color:rgba(245,158,11,0.3)">
    <div class="stat-label" style="color:#fbbf24"><span class="si si-warm"></span>Warm</div>
    <div style="display:flex;justify-content:space-between;align-items:flex-end;">
      <div class="stat-value" style="color:#fbbf24"><?= number_format($stats['warm'] ?: 0) ?></div>
      <div class="tm-badge">This Month: <strong style="color:#fbbf24"><?= number_format($stats['warm_tm'] ?: 0) ?></strong></div>
    </div>
    <div class="stat-sub">In progress</div>
  </div>
  <div class="stat-card" style="border-color:rgba(59,130,246,0.3)">
    <div class="stat-label" style="color:#60a5fa"><span class="si si-cold"></span>Cold</div>
    <div style="display:flex;justify-content:space-between;align-items:flex-end;">
      <div class="stat-value" style="color:#60a5fa"><?= number_format($stats['cold'] ?: 0) ?></div>
      <div class="tm-badge">This Month: <strong style="color:#60a5fa"><?= number_format($stats['cold_tm'] ?: 0) ?></strong></div>
    </div>
    <div class="stat-sub">Needs nurturing</div>
  </div>
</div>

<div class="grid-4" style="margin-bottom:24px">
  <div class="stat-card">
    <div class="stat-label" style="color:#c084fc"><span class="si si-svp"></span>SV Pending</div>
    <div style="display:flex;justify-content:space-between;align-items:flex-end;">
      <div class="stat-value"><?= number_format($stats['svp'] ?: 0) ?></div>
      <div class="tm-badge">This Month: <strong style="color:#c084fc"><?= number_format($stats['svp_tm'] ?: 0) ?></strong></div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-label" style="color:#4ade80"><span class="si si-svd"></span>SV Done</div>
    <div style="display:flex;justify-content:space-between;align-items:flex-end;">
      <div class="stat-value"><?= number_format($stats['svd'] ?: 0) ?></div>
      <div class="tm-badge">This Month: <strong style="color:#4ade80"><?= number_format($stats['svd_tm'] ?: 0) ?></strong></div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-label"><span class="si si-closed"></span>Closed</div>
    <div style="display:flex;justify-content:space-between;align-items:flex-end;">
      <div class="stat-value"><?= number_format($stats['closed'] ?: 0) ?></div>
      <div class="tm-badge">This Month: <strong style="color:#e0e6ef"><?= number_format($stats['closed_tm'] ?: 0) ?></strong></div>
    </div>
  </div>
  <div class="stat-card" style="border-color:rgba(239,68,68,0.3)">
    <div class="stat-label" style="color:#ef4444"><span class="si si-spam"></span>Spam</div>
    <div style="display:flex;justify-content:space-between;align-items:flex-end;">
      <div class="stat-value" style="color:#ef4444"><?= number_format($stats['spam'] ?: 0) ?></div>
      <div class="tm-badge">This Month: <strong style="color:#ef4444"><?= number_format($stats['spam_tm'] ?: 0) ?></strong></div>
    </div>
  </div>
</div>
<?php else: ?>
<!-- SALES MANAGER VIEW -->
<div class="grid-4" style="margin-bottom:24px">
  <div class="stat-card">
    <div class="stat-label" style="color:#c9a96e"><span class="si si-total"></span>My Assigned Leads</div>
    <div style="display:flex;justify-content:space-between;align-items:flex-end;">
      <div class="stat-value" style="color:#c9a96e"><?= number_format($stats['total'] ?: 0) ?></div>
      <div class="tm-badge">This Month: <strong style="color:#e8c98a"><?= number_format($stats['total_tm'] ?: 0) ?></strong></div>
    </div>
  </div>
  <div class="stat-card" style="border-color:rgba(245,158,11,0.3)">
    <div class="stat-label" style="color:#fbbf24"><span class="si si-warm"></span>Active (Warm/Hot)</div>
    <div style="display:flex;justify-content:space-between;align-items:flex-end;">
      <div class="stat-value" style="color:#fbbf24"><?= number_format(($stats['warm'] ?: 0) + ($stats['hot'] ?: 0)) ?></div>
      <div class="tm-badge">This Month: <strong style="color:#fbbf24"><?= number_format(($stats['warm_tm'] ?: 0) + ($stats['hot_tm'] ?: 0)) ?></strong></div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-label" style="color:#4ade80"><span class="si si-svd"></span>SV Done</div>
    <div style="display:flex;justify-content:space-between;align-items:flex-end;">
      <div class="stat-value"><?= number_format($stats['svd'] ?: 0) ?></div>
      <div class="tm-badge">This Month: <strong style="color:#4ade80"><?= number_format($stats['svd_tm'] ?: 0) ?></strong></div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-label"><span class="si si-closed"></span>Closed By Me</div>
    <div style="display:flex;justify-content:space-between;align-items:flex-end;">
      <div class="stat-value"><?= number_format($stats['closed'] ?: 0) ?></div>
      <div class="tm-badge">This Month: <strong style="color:#e0e6ef"><?= number_format($stats['closed_tm'] ?: 0) ?></strong></div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Filters bar -->
<style>
  .dp-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:999;justify-content:center;align-items:center;}
  .dp-overlay.active{display:flex;}
  .dp-panel{background:#111827;border:1px solid #1e2d45;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,0.6);display:flex;max-width:680px;width:95%;overflow:hidden;font-family:'Inter',sans-serif;}
  .dp-presets{width:160px;background:#0d1420;padding:16px 0;border-right:1px solid #1e2d45;}
  .dp-presets label{display:flex;align-items:center;gap:8px;padding:9px 16px;cursor:pointer;font-size:13px;color:#8a9ab8;transition:all 0.15s;font-weight:500;}
  .dp-presets label:hover{background:rgba(201,169,110,0.08);color:#e0e6ef;}
  .dp-presets input[type=radio]{accent-color:#c9a96e;width:14px;height:14px;}
  .dp-presets label.active-preset{background:rgba(201,169,110,0.12);color:#c9a96e;font-weight:600;}
  .dp-calendar{flex:1;padding:20px;}
  .dp-cal-row{display:flex;gap:16px;margin-bottom:16px;}
  .dp-cal-row > div{flex:1;}
  .dp-cal-row label{display:block;font-size:11px;font-weight:600;color:#8a9ab8;letter-spacing:0.5px;text-transform:uppercase;margin-bottom:6px;}
  .dp-cal-row input[type=date]{width:100%;padding:10px 12px;background:#0d1420;border:1px solid #1e2d45;border-radius:8px;color:#e0e6ef;font-size:14px;font-family:'Inter',sans-serif;}
  .dp-cal-row input[type=date]:focus{border-color:#c9a96e;outline:none;}
  .dp-actions{display:flex;justify-content:space-between;align-items:center;padding-top:16px;border-top:1px solid #1e2d45;}
  .dp-actions .dp-tz{font-size:11px;color:#5c6b84;}
  .dp-actions .dp-btns{display:flex;gap:8px;}
  .dp-btn-cancel{padding:8px 20px;background:transparent;border:1px solid #2a3a54;color:#8a9ab8;border-radius:8px;cursor:pointer;font-size:13px;font-weight:500;font-family:'Inter',sans-serif;}
  .dp-btn-cancel:hover{border-color:#c9a96e;color:#e0e6ef;}
  .dp-btn-apply{padding:8px 20px;background:#c9a96e;border:none;color:#0a0e17;border-radius:8px;cursor:pointer;font-size:13px;font-weight:700;font-family:'Inter',sans-serif;}
  .dp-btn-apply:hover{background:#e8c98a;}
  .flbl{display:block;font-size:11px;font-weight:600;letter-spacing:0.5px;text-transform:uppercase;color:#8a9ab8;margin-bottom:5px;font-family:'Inter',sans-serif;}
</style>
<div class="card card-sm" style="margin-bottom:20px">
  <form method="GET" id="filterForm" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
    <input type="hidden" name="source" value="<?= htmlspecialchars($source) ?>">
    <input type="hidden" name="date_filter" id="hiddenDateFilter" value="<?= htmlspecialchars($dateFilter) ?>">
    <input type="hidden" name="date_from" id="hiddenDateFrom" value="<?= htmlspecialchars($dateFrom) ?>">
    <input type="hidden" name="date_to" id="hiddenDateTo" value="<?= htmlspecialchars($dateTo) ?>">

    <div style="flex:1;min-width:180px">
      <label class="flbl">Search</label>
      <input type="text" name="q" id="searchInput" value="<?= htmlspecialchars($search) ?>"
             placeholder="Name, mobile, email…" class="form-control" style="margin:0" autocomplete="off">
    </div>

    <div style="min-width:120px">
      <label class="flbl">Type</label>
      <select name="type" class="form-control" style="margin:0" onchange="this.form.submit()">
        <option value="">All Types</option>
        <option value="hot"  <?= $type==='hot'?'selected':'' ?>>Hot</option>
        <option value="warm" <?= $type==='warm'?'selected':'' ?>>Warm</option>
        <option value="cold" <?= $type==='cold'?'selected':'' ?>>Cold</option>
      </select>
    </div>

    <div style="min-width:130px">
      <label class="flbl">Status</label>
      <select name="status" class="form-control" style="margin:0" onchange="this.form.submit()">
        <option value="">All Statuses</option>
        <option value="sv_pending" <?= $status==='sv_pending'?'selected':'' ?>>SV Pending</option>
        <option value="sv_done"    <?= $status==='sv_done'?'selected':'' ?>>SV Done</option>
        <option value="closed"     <?= $status==='closed'?'selected':'' ?>>Closed</option>
        <option value="spam"       <?= $status==='spam'?'selected':'' ?>>Spam / Junk</option>
      </select>
    </div>

    <div style="min-width:140px;position:relative;">
      <label class="flbl">Date</label>
      <button type="button" id="datePickerBtn" class="form-control" style="margin:0;cursor:pointer;text-align:left;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
        <?php
          $dateLabel = 'All Time';
          if ($dateFilter === 'today') $dateLabel = 'Today';
          elseif ($dateFilter === 'yesterday') $dateLabel = 'Yesterday';
          elseif ($dateFilter === 'last_7') $dateLabel = 'Last 7 Days';
          elseif ($dateFilter === 'last_14') $dateLabel = 'Last 14 Days';
          elseif ($dateFilter === 'this_month') $dateLabel = 'This Month';
          elseif ($dateFilter === 'last_30') $dateLabel = 'Last 30 Days';
          elseif ($dateFilter === '3_months') $dateLabel = 'Last 3 Months';
          elseif ($dateFilter === 'custom' && $dateFrom && $dateTo) $dateLabel = date('d M', strtotime($dateFrom)).' - '.date('d M', strtotime($dateTo));
          elseif ($dateFilter === 'custom') $dateLabel = 'Custom Range';
          echo htmlspecialchars($dateLabel);
        ?>
      </button>
    </div>

    <div style="min-width:140px">
      <label class="flbl">Project</label>
      <select name="project" class="form-control" style="margin:0" onchange="this.form.submit()">
        <option value="">All Projects</option>
        <?php foreach ($projects as $pr): ?>
          <option value="<?= $pr['id'] ?>" <?= $projectId==$pr['id']?'selected':'' ?>><?= htmlspecialchars($pr['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <?php if ($user['role']==='admin'): ?>
    <div style="min-width:150px">
      <label class="flbl">Assigned To</label>
      <select name="assigned" class="form-control" style="margin:0" onchange="this.form.submit()">
        <option value="">Anyone</option>
        <option value="-2" <?= $assignedTo===-2?'selected':'' ?>>All Assigned</option>
        <option value="-1" <?= $assignedTo===-1?'selected':'' ?>>Unassigned</option>
        <?php foreach ($salesManagers as $sm): ?>
          <option value="<?= $sm['id'] ?>" <?= $assignedTo==$sm['id']?'selected':'' ?>><?= htmlspecialchars($sm['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

    <div style="display:flex;gap:8px;align-items:flex-end;padding-bottom:1px">
      <a href="<?= BASE_URL ?>/index.php" class="btn btn-outline btn-sm" title="Clear all filters" style="font-family:'Inter',sans-serif;">Clear</a>
      <button type="button" class="btn btn-outline btn-sm" title="Export CSV" style="font-family:'Inter',sans-serif; color:var(--accent); border-color:var(--accent);" onclick="exportReport()">📥 Export</button>
    </div>
  </form>
</div>

<!-- Date Picker Panel Overlay -->
<div class="dp-overlay" id="dpOverlay">
  <div class="dp-panel">
    <div class="dp-presets">
      <?php
        $presets = [
          '' => 'All Time',
          'today' => 'Today',
          'yesterday' => 'Yesterday',
          'last_7' => 'Last 7 Days',
          'last_14' => 'Last 14 Days',
          'last_30' => 'Last 30 Days',
          'this_month' => 'This Month',
          '3_months' => 'Last 3 Months',
          'custom' => 'Custom',
        ];
        foreach ($presets as $val => $lbl): ?>
        <label class="<?= $dateFilter===$val?'active-preset':'' ?>" onclick="selectPreset('<?= $val ?>')">
          <input type="radio" name="dp_preset" value="<?= $val ?>" <?= $dateFilter===$val?'checked':'' ?>>
          <?= $lbl ?>
        </label>
      <?php endforeach; ?>
    </div>
    <div class="dp-calendar">
      <div class="dp-cal-row">
        <div>
          <label>From Date</label>
          <input type="date" id="dpFrom" value="<?= htmlspecialchars($dateFrom) ?>">
        </div>
        <div>
          <label>To Date</label>
          <input type="date" id="dpTo" value="<?= htmlspecialchars($dateTo) ?>">
        </div>
      </div>
      <div class="dp-actions">
        <span class="dp-tz">Dates are shown in IST (India)</span>
        <div class="dp-btns">
          <button type="button" class="dp-btn-cancel" onclick="closeDatePicker()">Cancel</button>
          <button type="button" class="dp-btn-apply" onclick="applyDatePicker()">Update</button>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if (!empty($_GET['msg'])): ?>
<div style="background:rgba(34, 197, 94, 0.1); border:1px solid rgba(34, 197, 94, 0.3); color:#4ade80; padding:12px 16px; border-radius:8px; margin-bottom:20px; font-size:13.5px; font-weight:500; font-family:'Inter',sans-serif; display:flex; align-items:center; gap:8px;">
  <span>✅</span> <?= htmlspecialchars($_GET['msg']) ?>
</div>
<?php endif; ?>

<?php if (!empty($_GET['err'])): ?>
<div style="background:rgba(239, 68, 68, 0.1); border:1px solid rgba(239, 68, 68, 0.3); color:#f87171; padding:12px 16px; border-radius:8px; margin-bottom:20px; font-size:13.5px; font-weight:500; font-family:'Inter',sans-serif; display:flex; align-items:center; gap:8px;">
  <span>⚠️</span> <?= htmlspecialchars($_GET['err']) ?>
</div>
<?php endif; ?>

<form method="POST" id="bulkAssignForm">
<input type="hidden" name="action" value="bulk_assign">
<input type="hidden" name="global_select" id="globalSelectFlag" value="0">

<!-- Lead count + add button -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
  <div style="display:flex;align-items:center;gap:10px;">
    <span style="color:var(--text2);font-size:13px">
      Showing <strong style="color:var(--text)"><?= number_format($totalRows) ?></strong> lead<?= $totalRows !== 1 ? 's' : '' ?>
      <?= $source ? '· Source: <strong style="color:var(--accent)">'.strtoupper($source).'</strong>' : '' ?>
    </span>
    <span id="selectedCount" style="display:none;background:rgba(201,169,110,0.15);color:#c9a96e;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600;"></span>
    <?php if ($user['role'] === 'admin'): ?>
    <button type="button" id="btnSelectAllGlobal" style="display:none; background:transparent; border:1px solid var(--accent); padding:2px 10px; border-radius:12px; text-decoration:none; color:var(--accent); font-size:12px; font-weight:600; cursor:pointer;" onclick="selectAllGlobal()">Select all <?= number_format($totalRows) ?> leads</button>
    <?php endif; ?>
  </div>
  <?php if ($user['role']==='admin'): ?>
  <div style="display:flex; gap:10px; align-items:center;">
      <div id="bulkActions" style="display:none;align-items:center;gap:10px;">
        <select name="bulk_assigned_to" id="bulk_assigned_to" class="form-control" style="margin:0;min-width:220px;background:#141d2e;color:#dde3f0;border:1px solid #1a2640;padding:6px 12px;height:auto;" required>
          <option value="">Select Action...</option>
          <option value="-1" style="color:#f87171;">✕ Unassign (Remove Assignment)</option>
          <optgroup label="Assign To Sales Manager">
          <?php foreach ($salesManagers as $sm): ?>
            <option value="<?= $sm['id'] ?>"><?= htmlspecialchars($sm['name']) ?></option>
          <?php endforeach; ?>
          </optgroup>
        </select>
        <button type="button" class="btn btn-primary btn-sm" onclick="triggerBulkAssign()">🎯 Assign</button>
      </div>
      <a href="<?= BASE_URL ?>/leads_new.php" class="btn btn-primary btn-sm" id="btnAddLead">➕ Add Lead</a>
  </div>
  <?php endif; ?>
</div>

<!-- Table -->
<div class="tbl-wrap">
  <table>
    <thead>
      <tr>
        <?php if ($user['role']==='admin'): ?>
        <th style="width:40px;text-align:center;"><input type="checkbox" id="selectAllLeads" style="cursor:pointer;width:16px;height:16px;accent-color:var(--accent);"></th>
        <?php endif; ?>
        <th>#</th>
        <th>Name</th>
        <th>Mobile</th>
        <th>Project</th>
        <?php if ($user['role']==='admin'): ?><th>Source</th><?php endif; ?>
        <th>Type</th>
        <th>Status</th>
        <th>Assigned</th>
        <th>Calls</th>
        <th>Added</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($leads)): ?>
      <tr><td colspan="<?= $user['role']==='admin' ? '12' : '10' ?>" style="text-align:center;padding:50px 20px;">
        <div style="color:var(--text2);">
          <div style="font-size:42px;margin-bottom:12px;opacity:0.4;font-family:'Inter',sans-serif;">No Data</div>
          <div style="font-size:15px;font-weight:600;color:var(--text);margin-bottom:6px;font-family:'Inter',sans-serif;">No Results Found</div>
          <div style="font-size:13px;color:var(--text2);max-width:320px;margin:0 auto;font-family:'Inter',sans-serif;">No leads match your current filters. Try adjusting your search criteria or <a href="<?= BASE_URL ?>/index.php" style="color:var(--accent);text-decoration:underline;">clear all filters</a>.</div>
        </div>
      </td></tr>
    <?php endif; ?>
    <?php $srNo = $offset + 1; foreach ($leads as $l): ?>
      <tr>
        <?php if ($user['role']==='admin'): ?>
        <td style="text-align:center;">
          <input type="checkbox" name="lead_ids[]" value="<?= $l['id'] ?>" class="lead-checkbox" onchange="toggleBulkActions()" style="cursor:pointer;width:16px;height:16px;accent-color:var(--accent);">
        </td>
        <?php endif; ?>
        <td style="color:var(--text2);font-size:13px;font-weight:600;"><?= $srNo++ ?>.</td>
        <td>
          <strong><?= htmlspecialchars($l['first_name'] . ' ' . $l['last_name']) ?></strong>
          <?php if ($l['email']): ?>
            <div style="font-size:11px;color:var(--text2);margin-top:2px"><?= htmlspecialchars($l['email']) ?></div>
          <?php endif; ?>
        </td>
        <td>
          <a href="tel:<?= htmlspecialchars($l['mobile']) ?>" style="color:var(--accent);text-decoration:none;font-family:monospace;font-size:13px">
            <?= htmlspecialchars($l['mobile']) ?>
          </a>
        </td>
        <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($l['campaign_name'] ?? '') ?>">
          <?= htmlspecialchars($l['project_name'] ?? '—') ?>
          <?php if (!empty($l['campaign_name'])): ?>
            <div style="font-size:10px;color:var(--text2);margin-top:1px"><?= htmlspecialchars($l['campaign_name']) ?></div>
          <?php endif; ?>
        </td>
        <?php if ($user['role']==='admin'): ?>
        <td>
          <span class="badge badge-<?= $l['source'] ?>">
            <?= ucfirst($l['source']) ?>
          </span>
        </td>
        <?php endif; ?>
        <td>
          <span class="badge badge-<?= $l['lead_type'] ?>">
            <?= ucfirst($l['lead_type']) ?>
          </span>
        </td>
        <td>
          <span class="badge <?= match($l['lead_status']) { 'sv_pending'=>'badge-svp', 'sv_done'=>'badge-svd', 'closed'=>'badge-closed', 'spam'=>'badge-danger', default=>'' } ?>"
                <?= $l['lead_status']==='spam'?'style="background:rgba(239,68,68,0.1);color:#ef4444;border:1px solid rgba(239,68,68,0.2)"':'' ?>>
            <?= match($l['lead_status']) { 'sv_pending'=>'SV Pending', 'sv_done'=>'SV Done', 'closed'=>'Closed', 'spam'=>'Spam', default=>$l['lead_status'] } ?>
          </span>
        </td>
        <td><?= $l['assigned_name'] ? htmlspecialchars($l['assigned_name']) : '<span style="color:var(--text2)">—</span>' ?></td>
        <td>
          <?php if ($l['call_count'] > 0): ?>
            <span style="background:rgba(var(--accentRGB),0.15);color:var(--accent);border-radius:20px;padding:2px 9px;font-size:11px;font-weight:700">
              <?= $l['call_count'] ?> calls
            </span>
          <?php else: ?>
            <span style="color:var(--text2);font-size:12px">0</span>
          <?php endif; ?>
        </td>
        <td style="font-size:12px;color:var(--text2);white-space:nowrap">
          <?= date('d M Y', strtotime($l['created_at'])) ?>
        </td>
        <td>
          <div style="display:flex;gap:6px">
            <a href="<?= BASE_URL ?>/lead_detail.php?id=<?= $l['id'] ?>" class="btn btn-outline btn-sm">View</a>
            <?php if ($user['role']==='admin'): ?>
            <a href="<?= BASE_URL ?>/lead_edit.php?id=<?= $l['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Assign/Unassign Modal -->
<div class="modal-bg" id="assignModal" style="backdrop-filter: blur(4px);">
  <div class="modal" style="max-width:420px; text-align:center; padding:36px 28px;">
    <div id="assignModalIcon" style="font-size:46px; margin-bottom:18px;">🎯</div>
    <div class="modal-title" id="assignModalTitle" style="margin-bottom:14px;font-size:22px;">Confirm Assignment</div>
    <div id="assignModalDesc" style="color:var(--text2); font-size:15px; margin-bottom:28px; line-height:1.6;">
      You are about to assign the selected leads to <strong id="assignModalSmName" style="color:var(--text);font-weight:700;">Sales Manager</strong>.<br><br>They will receive notifications via email and WhatsApp.
    </div>
    <div style="display:flex; gap:14px; justify-content:center;">
      <button type="button" class="btn btn-outline" style="flex:1; justify-content:center; padding:10px 0; font-size:14px;" onclick="closeModal('assignModal')">Cancel</button>
      <button type="button" id="assignModalConfirmBtn" class="btn btn-primary" style="flex:1; justify-content:center; padding:10px 0; font-size:14px;" onclick="confirmBulkAssign()">Yes, Assign Now</button>
    </div>
  </div>
</div>

</form>

<script>
function exportReport() {
    let url = window.location.href;
    url += (url.indexOf('?') !== -1 ? '&' : '?') + 'export=csv';
    window.location.href = url;
}

function selectAllGlobal() {
    const btn = document.getElementById('btnSelectAllGlobal');
    const flag = document.getElementById('globalSelectFlag');
    if (flag.value === '0') {
        flag.value = '1';
        btn.textContent = 'All <?= number_format($totalRows) ?> leads selected';
        btn.style.background = '#4ade80';
        btn.style.borderColor = '#4ade80';
        btn.style.color = '#0a0e17';
    } else {
        flag.value = '0';
        btn.textContent = 'Select all <?= number_format($totalRows) ?> leads';
        btn.style.background = 'transparent';
        btn.style.borderColor = 'var(--accent)';
        btn.style.color = 'var(--accent)';
    }
}

// ── Debounced search auto-submit ───────────────────────────────────────
let searchTimer = null;
const searchInput = document.getElementById('searchInput');
if (searchInput) {
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            document.getElementById('filterForm').submit();
        }, 600);
    });
    // Also submit on Enter key
    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            clearTimeout(searchTimer);
            document.getElementById('filterForm').submit();
        }
    });
}

// ── Date filter handler ──────────────────────────────────────────────
function handleDateFilter() {
    const sel = document.getElementById('dateFilterSelect');
    const box = document.getElementById('customDateRange');
    if (!sel) return;
    if (sel.value === 'custom') {
        if (box) box.style.display = 'flex';
    } else {
        if (box) box.style.display = 'none';
        document.getElementById('filterForm').submit();
    }
}

// Custom Datepicker Presets & Overlay Control
function selectPreset(val) {
    document.getElementById('hiddenDateFilter').value = val;
    if (val !== 'custom') {
        document.getElementById('filterForm').submit();
    } else {
        document.querySelectorAll('.dp-presets label').forEach(l => l.classList.remove('active-preset'));
        event.currentTarget.classList.add('active-preset');
    }
}

function closeDatePicker() {
    document.getElementById('dpOverlay').classList.remove('active');
}

function applyDatePicker() {
    const from = document.getElementById('dpFrom').value;
    const to = document.getElementById('dpTo').value;
    
    document.getElementById('hiddenDateFilter').value = 'custom';
    
    if (from || to) {
        document.getElementById('hiddenDateFrom').value = from || '';
        document.getElementById('hiddenDateTo').value = to || '';
    }
    document.getElementById('filterForm').submit();
}

document.getElementById('datePickerBtn')?.addEventListener('click', function() {
    document.getElementById('dpOverlay').classList.add('active');
});

document.getElementById('dpOverlay')?.addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('active');
});

// ── Custom date range: submit when both dates are filled ───────────────
function submitCustomDate() {
    const from = document.getElementById('dateFrom');
    const to = document.getElementById('dateTo');
    if (from && to && from.value && to.value) {
        document.getElementById('filterForm').submit();
    }
}

// ── Bulk selection actions ─────────────────────────────────────────────
function toggleBulkActions() {
    const checked = document.querySelectorAll('.lead-checkbox:checked');
    const bulkDiv = document.getElementById('bulkActions');
    const addBtn = document.getElementById('btnAddLead');
    const countBadge = document.getElementById('selectedCount');
    const selectAll = document.getElementById('selectAllLeads');
    const allBoxes = document.querySelectorAll('.lead-checkbox');
    const btnGlobal = document.getElementById('btnSelectAllGlobal');

    if (checked.length > 0) {
        if(bulkDiv) bulkDiv.style.display = 'flex';
        if(addBtn) addBtn.style.display = 'none';
        if(countBadge) {
            countBadge.style.display = 'inline-block';
            countBadge.textContent = checked.length + ' selected';
        }
    } else {
        if(bulkDiv) bulkDiv.style.display = 'none';
        if(addBtn) addBtn.style.display = 'inline-flex';
        if(countBadge) countBadge.style.display = 'none';
        // Reset global
        if(btnGlobal) {
             btnGlobal.style.display = 'none';
             document.getElementById('globalSelectFlag').value = '0';
             btnGlobal.textContent = 'Select all <?= number_format($totalRows) ?> leads';
             btnGlobal.style.background = 'transparent';
             btnGlobal.style.borderColor = 'var(--accent)';
             btnGlobal.style.color = 'var(--accent)';
        }
    }
    if (selectAll) {
        selectAll.checked = allBoxes.length > 0 && checked.length === allBoxes.length;
        selectAll.indeterminate = checked.length > 0 && checked.length < allBoxes.length;
        
        // Show global select button if selecting all on current page and there are more pages
        if (btnGlobal) {
            if (selectAll.checked && <?= $totalRows ?> > allBoxes.length) {
                btnGlobal.style.display = 'inline-block';
            } else {
                btnGlobal.style.display = 'none';
                document.getElementById('globalSelectFlag').value = '0';
                btnGlobal.textContent = 'Select all <?= number_format($totalRows) ?> leads';
                btnGlobal.style.background = 'transparent';
                btnGlobal.style.borderColor = 'var(--accent)';
                btnGlobal.style.color = 'var(--accent)';
            }
        }
    }
}

document.getElementById('selectAllLeads')?.addEventListener('change', function(e) {
    const cbs = document.querySelectorAll('.lead-checkbox');
    cbs.forEach(cb => cb.checked = e.target.checked);
    toggleBulkActions();
});

// ── Custom Bulk Assign Modal ───────────────────────────────────────────
function triggerBulkAssign() {
    const assignTo = document.getElementById('bulk_assigned_to').value;
    if (!assignTo) {
        alert('Please select an action first.');
        return;
    }
    const smOption = document.querySelector('#bulk_assigned_to option:checked');
    const checkedCount = document.querySelectorAll('.lead-checkbox:checked').length;
    
    if (assignTo === '-1') {
        // Unassign mode
        document.getElementById('assignModalIcon').textContent = '🔓';
        document.getElementById('assignModalTitle').textContent = 'Confirm Unassignment';
        document.getElementById('assignModalDesc').innerHTML = 'You are about to <strong style="color:#f87171;">remove the assignment</strong> from <strong style="color:var(--text);">' + checkedCount + ' selected lead(s)</strong>.<br><br>They will become unassigned and won\'t appear under any sales manager.';
        document.getElementById('assignModalConfirmBtn').textContent = 'Yes, Unassign Now';
        document.getElementById('assignModalConfirmBtn').style.background = 'rgba(239,68,68,0.15)';
        document.getElementById('assignModalConfirmBtn').style.color = '#f87171';
        document.getElementById('assignModalConfirmBtn').style.border = '1px solid rgba(239,68,68,0.3)';
    } else {
        // Assign mode
        document.getElementById('assignModalIcon').textContent = '🎯';
        document.getElementById('assignModalTitle').textContent = 'Confirm Assignment';
        document.getElementById('assignModalSmName').textContent = smOption ? smOption.textContent.trim() : 'Sales Manager';
        document.getElementById('assignModalDesc').innerHTML = 'You are about to assign <strong style="color:var(--text);">' + checkedCount + ' lead(s)</strong> to <strong id="assignModalSmName" style="color:var(--accent);font-weight:700;">' + (smOption ? smOption.textContent.trim() : 'Sales Manager') + '</strong>.<br><br>They will receive notifications via email and WhatsApp.';
        document.getElementById('assignModalConfirmBtn').textContent = 'Yes, Assign Now';
        document.getElementById('assignModalConfirmBtn').style.background = 'linear-gradient(135deg,var(--accent),var(--accent2))';
        document.getElementById('assignModalConfirmBtn').style.color = '#0a0e17';
        document.getElementById('assignModalConfirmBtn').style.border = 'none';
    }
    openModal('assignModal');
}

function confirmBulkAssign() {
    closeModal('assignModal');
    const isUnassign = document.getElementById('bulk_assigned_to').value === '-1';
    const overlayText = isUnassign ? 'UNASSIGNING LEADS...' : 'ASSIGNING LEADS...';
    const overlayColor = isUnassign ? '#f87171' : '#c9a96e';
    // Create an overlay to prevent duplicate submissions
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(8,13,22,0.85);z-index:9999;display:flex;align-items:center;justify-content:center;font-size:18px;font-family:Inter,sans-serif;backdrop-filter:blur(8px);color:' + overlayColor + ';';
    overlay.innerHTML = '<div style="display:flex;flex-direction:column;align-items:center;gap:18px;"><div class="spinner" style="border-top-color:' + overlayColor + '"></div><div style="font-weight:600;letter-spacing:1px;">' + overlayText + '</div></div>';
    document.body.appendChild(overlay);
    
    if (!document.getElementById('spinner-css')) {
        const style = document.createElement('style');
        style.id = 'spinner-css';
        style.innerHTML = '.spinner { width:50px; height:50px; border:4px solid rgba(201,169,110,0.2); border-top-color:#c9a96e; border-radius:50%; animation:spin 1s linear infinite; } @keyframes spin { to { transform: rotate(360deg); } }';
        document.head.appendChild(style);
    }

    document.getElementById('bulkAssignForm').submit();
}

// ── Auto-sync Meta leads every 5 minutes (background) ──────────────────
<?php if ($user['role'] === 'admin'): ?>
(function autoSyncMeta() {
    const SYNC_INTERVAL = 5 * 60 * 1000; // 5 minutes
    
    async function syncNow() {
        try {
            const formData = new FormData();
            formData.append('sync_type', 'meta_api');
            // Auto-sync: fetch last 30 days of leads for efficiency
            const today = new Date().toISOString().split('T')[0];
            const from  = new Date(Date.now() - 30*24*60*60*1000).toISOString().split('T')[0];
            formData.append('meta_sync_from', from);
            formData.append('meta_sync_to', today);
            const resp = await fetch('<?= BASE_URL ?>/admin_sync.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            if (resp.ok) {
                console.log('[CRM Auto-Sync] Meta leads synced at', new Date().toLocaleTimeString());
            }
        } catch(e) {
            console.log('[CRM Auto-Sync] Background sync skipped:', e.message);
        }
    }

    // First sync after 30 seconds of page load
    setTimeout(syncNow, 30000);
    // Then every 5 minutes
    setInterval(syncNow, SYNC_INTERVAL);
})();
<?php endif; ?>
</script>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="pagination">
  <?php 
  $visiblePages = 5; 
  $startPage = max(1, $page - floor($visiblePages/2));
  $endPage = min($totalPages, $startPage + $visiblePages - 1);
  if ($endPage - $startPage + 1 < $visiblePages) {
      $startPage = max(1, $endPage - $visiblePages + 1);
  }
  
  if ($page > 1) {
      $qp = array_merge($_GET, ['pg' => $page - 1]);
      echo '<a href="?' . http_build_query($qp) . '">&laquo; Prev</a>';
  }
  
  if ($startPage > 1) {
      $qp = array_merge($_GET, ['pg' => 1]);
      echo '<a href="?' . http_build_query($qp) . '">1</a>';
      if ($startPage > 2) echo '<span class="dots">...</span>';
  }

  for ($i = $startPage; $i <= $endPage; $i++) {
      $qp = array_merge($_GET, ['pg' => $i]);
      $qs = http_build_query($qp);
      if ($i === $page) {
          echo '<span class="cur">' . $i . '</span>';
      } else {
          echo '<a href="?' . $qs . '">' . $i . '</a>';
      }
  }

  if ($endPage < $totalPages) {
      if ($endPage < $totalPages - 1) echo '<span class="dots">...</span>';
      $qp = array_merge($_GET, ['pg' => $totalPages]);
      echo '<a href="?' . http_build_query($qp) . '">' . $totalPages . '</a>';
  }

  if ($page < $totalPages) {
      $qp = array_merge($_GET, ['pg' => $page + 1]);
      echo '<a href="?' . http_build_query($qp) . '">Next &raquo;</a>';
  }
  ?>
</div>
<style>
.pagination .dots { padding: 8px 12px; color: var(--text2); display: inline-block; vertical-align: bottom; }
</style>
<?php endif; ?>

<script>
// Save current URL (with all page and filter params) so we can return to it later
sessionStorage.setItem('lastIndexUrl', window.location.href);
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
