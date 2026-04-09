<?php
// ============================================================
//  fix_future_dates.php — One-time fix for future-dated leads
//  Run once from browser, then DELETE this file.
//  URL: https://crm.propnmore.com/fix_future_dates.php
// ============================================================

require_once __DIR__ . '/config.php';
$user = requireAuth('admin');

$pdo = db();
$today = '2026-04-09'; // Current date — 9 Apr 2026

// Step 1: Count how many leads have future dates
$countFuture = $pdo->query("SELECT COUNT(*) FROM leads WHERE created_at > NOW()")->fetchColumn();

// Step 2: Preview future-dated leads
$futureleads = $pdo->query("
    SELECT id, first_name, last_name, mobile, source, created_at 
    FROM leads 
    WHERE created_at > NOW()
    ORDER BY id ASC
")->fetchAll();

$updated = 0;
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_fix'])) {
    verifyCsrf();

    // Fix 1: Update all future-dated leads to today's date
    // We preserve the TIME portion (HH:MM:SS) but correct the DATE to today.
    // For leads where created_at is in Jun 2026 → set to Apr 9, 2026 instead.
    // We set them to today's date: 2026-04-09 at the same hour/minute.
    try {
        $stmt = $pdo->prepare("
            UPDATE leads 
            SET created_at = CONCAT('{$today} ', TIME(created_at))
            WHERE created_at > NOW()
        ");
        $stmt->execute();
        $updated = $stmt->rowCount();
    } catch (Exception $e) {
        $errors[] = 'DB Update Error: ' . $e->getMessage();
    }

    // Fix 2: Also fix any manually-added leads from before the launch
    // (leads with created_at that are just wrong/unknown — Jun 2026 was clearly a bug)
    // This is already handled above.
}

include __DIR__ . '/includes/header.php';
?>

<div style="max-width:800px">
  <h2 style="font-family:'DM Serif Display',serif;font-size:22px;margin-bottom:8px;color:#f87171">
    🔧 Fix Future-Dated Leads
  </h2>
  <p style="color:var(--text2);font-size:13px;margin-bottom:24px;line-height:1.6">
    This tool fixes leads that were incorrectly stored with future dates (e.g. 18 Jun 2026).<br>
    It will reset their <code>created_at</code> to today: <strong style="color:#c9a96e"><?= $today ?></strong> (09 Apr 2026).<br>
    <strong style="color:#f87171">Run once, then delete this file.</strong>
  </p>

  <?php if (!empty($errors)): ?>
    <div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:#f87171;padding:16px;border-radius:8px;margin-bottom:20px">
      <?php foreach ($errors as $e): ?>
        <div>❌ <?= htmlspecialchars($e) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($updated > 0): ?>
    <div style="background:rgba(74,222,128,0.1);border:1px solid rgba(74,222,128,0.3);color:#4ade80;padding:16px;border-radius:8px;margin-bottom:20px;font-size:15px;font-weight:600">
      ✅ Fixed! <strong><?= $updated ?></strong> lead(s) updated from future dates → <?= $today ?>.<br>
      <span style="font-size:13px;font-weight:400;opacity:0.85">The dashboard serial numbers and date order are now correct.</span>
    </div>
    <div style="margin-top:12px">
      <a href="<?= BASE_URL ?>/index.php" class="btn btn-primary">View Dashboard →</a>
      <a href="<?= BASE_URL ?>/fix_future_dates.php" class="btn btn-outline" style="margin-left:10px">Re-check</a>
    </div>
  <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
    <div style="background:rgba(96,165,250,0.1);border:1px solid rgba(96,165,250,0.3);color:#60a5fa;padding:16px;border-radius:8px;margin-bottom:20px">
      ℹ️ No future-dated leads found. Nothing to fix.
    </div>
  <?php endif; ?>

  <!-- Preview Table -->
  <div class="card" style="margin-bottom:24px">
    <div style="font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--text2);margin-bottom:14px">
      Future-Dated Leads Found: <span style="color:#f87171"><?= $countFuture ?></span>
    </div>
    <?php if (empty($futureleads)): ?>
      <p style="color:#4ade80;font-weight:600">✅ No future-dated leads! All dates look correct.</p>
    <?php else: ?>
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr>
            <th>DB ID</th>
            <th>Name</th>
            <th>Mobile</th>
            <th>Source</th>
            <th>Current created_at (WRONG)</th>
            <th>Will become</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($futureleads as $r): ?>
          <tr>
            <td style="color:var(--text2);font-size:12px">#<?= $r['id'] ?></td>
            <td><strong><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></strong></td>
            <td style="font-family:monospace;font-size:12px"><?= htmlspecialchars($r['mobile']) ?></td>
            <td><span class="badge badge-<?= $r['source'] ?>"><?= ucfirst($r['source']) ?></span></td>
            <td style="color:#f87171;font-weight:600;font-size:12px"><?= date('d M Y H:i', strtotime($r['created_at'])) ?></td>
            <td style="color:#4ade80;font-size:12px"><?= date('d M Y', strtotime($today)) ?> + same time</td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($countFuture > 0): ?>
    <form method="POST" style="margin-top:20px">
      <input type="hidden" name="_csrf" value="<?= csrf() ?>">
      <button type="submit" name="do_fix" value="1" class="btn btn-primary"
              style="background:linear-gradient(135deg,#f87171,#ef4444);border:none"
              onclick="return confirm('Fix all <?= $countFuture ?> future-dated leads → set to <?= $today ?>?')">
        🔧 Fix <?= $countFuture ?> Lead(s) Now
      </button>
    </form>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <div style="background:rgba(201,169,110,0.08);border:1px solid rgba(201,169,110,0.2);padding:16px;border-radius:8px;font-size:12px;color:var(--text2)">
    <strong style="color:#c9a96e">What this fixes:</strong><br>
    • Leads showing "18 Jun 2026" will be corrected to "09 Apr 2026"<br>
    • Serial number order on the dashboard will follow correct date order (most recent = #1)<br>
    • "This Month" stats on the dashboard will now count correctly<br>
    • <strong>After fixing, delete this file from the server for security.</strong>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
