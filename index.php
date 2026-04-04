<?php
require_once __DIR__ . '/config.php';
$user = requireAuth();
$pageTitle = 'All Leads';

$pdo = db();

// ── Filters ──────────────────────────────────────────────────────────────────
$where  = ['1=1'];
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
if ($assignedTo) { $where[] = 'l.assigned_to=?'; $params[] = $assignedTo; }

// Sales manager can only see own leads
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

// ── Stats ─────────────────────────────────────────────────────────────────────
$stats = $pdo->query("SELECT
    COUNT(*) AS total,
    SUM(lead_type='hot') AS hot,
    SUM(lead_type='warm') AS warm,
    SUM(lead_type='cold') AS cold,
    SUM(lead_status='sv_pending') AS svp,
    SUM(lead_status='sv_done') AS svd,
    SUM(lead_status='closed') AS closed
    FROM leads")->fetch();

// ── Dropdown data ─────────────────────────────────────────────────────────────
$projects     = $pdo->query('SELECT id,name FROM projects ORDER BY name')->fetchAll();
$salesManagers = $pdo->query("SELECT id,name FROM users WHERE role='sales_manager' AND is_active=1 ORDER BY name")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<!-- Stats row -->
<div class="grid-4" style="margin-bottom:24px">
  <div class="stat-card">
    <div class="stat-label">Total Leads</div>
    <div class="stat-value"><?= number_format($stats['total']) ?></div>
    <div class="stat-sub">All sources</div>
  </div>
  <div class="stat-card" style="border-color:rgba(239,68,68,0.3)">
    <div class="stat-label" style="color:#f87171">🔥 Hot</div>
    <div class="stat-value" style="color:#f87171"><?= $stats['hot'] ?></div>
    <div class="stat-sub">High priority</div>
  </div>
  <div class="stat-card" style="border-color:rgba(245,158,11,0.3)">
    <div class="stat-label" style="color:#fbbf24">☀️ Warm</div>
    <div class="stat-value" style="color:#fbbf24"><?= $stats['warm'] ?></div>
    <div class="stat-sub">In progress</div>
  </div>
  <div class="stat-card" style="border-color:rgba(59,130,246,0.3)">
    <div class="stat-label" style="color:#60a5fa">❄️ Cold</div>
    <div class="stat-value" style="color:#60a5fa"><?= $stats['cold'] ?></div>
    <div class="stat-sub">Needs nurturing</div>
  </div>
</div>

<div class="grid-3" style="margin-bottom:24px">
  <div class="stat-card">
    <div class="stat-label" style="color:#c084fc">📍 SV Pending</div>
    <div class="stat-value"><?= $stats['svp'] ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label" style="color:#4ade80">✅ SV Done</div>
    <div class="stat-value"><?= $stats['svd'] ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">🔒 Closed</div>
    <div class="stat-value"><?= $stats['closed'] ?></div>
  </div>
</div>

<!-- Filters bar -->
<div class="card card-sm" style="margin-bottom:20px">
  <form method="GET" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
    <input type="hidden" name="source" value="<?= htmlspecialchars($source) ?>">

    <div style="flex:1;min-width:180px">
      <label style="display:block;font-size:11px;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:var(--text2);margin-bottom:5px">Search</label>
      <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
             placeholder="Name, mobile, email…" class="form-control" style="margin:0">
    </div>

    <div style="min-width:140px">
      <label style="display:block;font-size:11px;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:var(--text2);margin-bottom:5px">Type</label>
      <select name="type" class="form-control" style="margin:0">
        <option value="">All Types</option>
        <option value="hot"  <?= $type==='hot'?'selected':'' ?>>🔥 Hot</option>
        <option value="warm" <?= $type==='warm'?'selected':'' ?>>☀️ Warm</option>
        <option value="cold" <?= $type==='cold'?'selected':'' ?>>❄️ Cold</option>
      </select>
    </div>

    <div style="min-width:160px">
      <label style="display:block;font-size:11px;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:var(--text2);margin-bottom:5px">Status</label>
      <select name="status" class="form-control" style="margin:0">
        <option value="">All Status</option>
        <option value="sv_pending" <?= $status==='sv_pending'?'selected':'' ?>>SV Pending</option>
        <option value="sv_done"    <?= $status==='sv_done'?'selected':'' ?>>SV Done</option>
        <option value="closed"     <?= $status==='closed'?'selected':'' ?>>Closed</option>
      </select>
    </div>

    <div style="min-width:160px">
      <label style="display:block;font-size:11px;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:var(--text2);margin-bottom:5px">Project</label>
      <select name="project" class="form-control" style="margin:0">
        <option value="">All Projects</option>
        <?php foreach ($projects as $pr): ?>
          <option value="<?= $pr['id'] ?>" <?= $projectId==$pr['id']?'selected':'' ?>><?= htmlspecialchars($pr['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <?php if ($user['role']==='admin'): ?>
    <div style="min-width:160px">
      <label style="display:block;font-size:11px;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:var(--text2);margin-bottom:5px">Assigned To</label>
      <select name="assigned" class="form-control" style="margin:0">
        <option value="">Anyone</option>
        <option value="-1" <?= $assignedTo===-1?'selected':'' ?>>Unassigned</option>
        <?php foreach ($salesManagers as $sm): ?>
          <option value="<?= $sm['id'] ?>" <?= $assignedTo==$sm['id']?'selected':'' ?>><?= htmlspecialchars($sm['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

    <div style="display:flex;gap:8px;align-items:flex-end;padding-bottom:1px">
      <button type="submit" class="btn btn-primary btn-sm">🔍 Filter</button>
      <a href="<?= BASE_URL ?>/index.php" class="btn btn-outline btn-sm">✕ Clear</a>
    </div>
  </form>
</div>

<!-- Lead count + add button -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
  <span style="color:var(--text2);font-size:13px">
    Showing <strong style="color:var(--text)"><?= number_format($totalRows) ?></strong> leads
    <?= $source ? '· Source: <strong style="color:var(--accent)">'.strtoupper($source).'</strong>' : '' ?>
  </span>
  <?php if ($user['role']==='admin'): ?>
  <a href="<?= BASE_URL ?>/leads_new.php" class="btn btn-primary btn-sm">➕ Add Lead</a>
  <?php endif; ?>
</div>

<!-- Table -->
<div class="tbl-wrap">
  <table>
    <thead>
      <tr>
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
      <tr><td colspan="<?= $user['role']==='admin' ? '11' : '10' ?>" style="text-align:center;padding:40px;color:var(--text2)">No leads found. Adjust filters or sync sheets.</td></tr>
    <?php endif; ?>
    <?php foreach ($leads as $l): ?>
      <tr>
        <td style="color:var(--text2);font-size:12px">#<?= $l['id'] ?></td>
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
            <div style="font-size:10px;color:var(--text2);margin-top:1px">📢 <?= htmlspecialchars($l['campaign_name']) ?></div>
          <?php endif; ?>
        </td>
        <?php if ($user['role']==='admin'): ?>
        <td>
          <span class="badge badge-<?= $l['source'] ?>">
            <?= match($l['source']) { 'website'=>'🌐', 'meta'=>'📱', 'google'=>'🔍', default=>'✏️' } ?>
            <?= ucfirst($l['source']) ?>
          </span>
        </td>
        <?php endif; ?>
        <td>
          <span class="badge badge-<?= $l['lead_type'] ?>">
            <?= match($l['lead_type']) { 'hot'=>'🔥', 'warm'=>'☀️', 'cold'=>'❄️', default=>'' } ?>
            <?= ucfirst($l['lead_type']) ?>
          </span>
        </td>
        <td>
          <span class="badge <?= match($l['lead_status']) { 'sv_pending'=>'badge-svp', 'sv_done'=>'badge-svd', 'closed'=>'badge-closed', default=>'' } ?>">
            <?= match($l['lead_status']) { 'sv_pending'=>'SV Pending', 'sv_done'=>'SV Done', 'closed'=>'Closed', default=>$l['lead_status'] } ?>
          </span>
        </td>
        <td><?= $l['assigned_name'] ? htmlspecialchars($l['assigned_name']) : '<span style="color:var(--text2)">—</span>' ?></td>
        <td>
          <?php if ($l['followup_count'] > 0): ?>
            <span style="background:rgba(var(--accentRGB),0.15);color:var(--accent);border-radius:20px;padding:2px 9px;font-size:11px;font-weight:700">
              <?= $l['followup_count'] ?> calls
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
            <a href="<?= BASE_URL ?>/lead_detail.php?id=<?= $l['id'] ?>" class="btn btn-outline btn-sm">👁 View</a>
            <?php if ($user['role']==='admin'): ?>
            <a href="<?= BASE_URL ?>/lead_edit.php?id=<?= $l['id'] ?>" class="btn btn-outline btn-sm">✏️</a>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="pagination">
  <?php for ($i=1;$i<=$totalPages;$i++): ?>
    <?php
    $qp = array_merge($_GET, ['pg' => $i]);
    $qs = http_build_query($qp);
    ?>
    <?php if ($i === $page): ?>
      <span class="cur"><?= $i ?></span>
    <?php else: ?>
      <a href="?<?= $qs ?>"><?= $i ?></a>
    <?php endif; ?>
  <?php endfor; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
