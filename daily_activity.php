<?php
require_once __DIR__ . '/config.php';
$user = requireAuth('admin');
$pageTitle = 'LSR Daily Activity';

$pdo = db();

if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    // Return only the timeline items
    $activities = $pdo->query('
        SELECT f.*, u.name AS by_name, l.first_name, l.last_name 
        FROM followups f 
        LEFT JOIN users u ON f.user_id = u.id 
        LEFT JOIN leads l ON f.lead_id = l.id 
        ORDER BY f.created_at DESC 
        LIMIT 50
    ')->fetchAll();

    if (empty($activities)) {
        echo '<div style="color:var(--text2);font-size:13px;text-align:center;padding:20px 0">No activity logged yet.</div>';
        exit;
    }

    foreach ($activities as $f) {
        $text = $f['call_response'];
        $htmlContent = '';
        if (strpos($text, '[💬 COMMENT] ') === 0) {
            $bgColor = 'rgba(245,158,11,0.08)'; $bColor = '#f59e0b'; $tLeft = '💬 Comment'; $tColor = '#fbbf24';
            $text = substr($text, 13);
            $htmlContent = nl2br(htmlspecialchars($text));
        } elseif (strpos($text, '[🎙️ RECORDING] ') === 0) {
            $bgColor = 'rgba(168,85,247,0.08)'; $bColor = '#c084fc'; $tLeft = '🎙️ Audio'; $tColor = '#c084fc';
            $text = substr($text, 16);
            $fileUrl = BASE_URL . '/assets/uploads/audio/' . htmlspecialchars($text);
            $htmlContent = '<audio controls style="width:100%;max-width:300px;height:32px;outline:none;margin-top:4px;border-radius:6px"><source src="'.$fileUrl.'"></audio>';
        } elseif (strpos($text, '[⚙️ UPDATE] ') === 0) {
            $bgColor = 'rgba(59,130,246,0.08)'; $bColor = '#60a5fa'; $tLeft = '⚙️ Updated'; $tColor = '#60a5fa';
            $text = substr($text, 12);
            $htmlContent = nl2br(htmlspecialchars($text));
        } elseif (strpos($text, '[📞 INCREMENT] ') === 0) {
            $bgColor = 'rgba(16,185,129,0.08)'; $bColor = '#10b981'; $tLeft = '📞 Quick Call Logged'; $tColor = '#10b981';
            $text = substr($text, 15);
            $htmlContent = nl2br(htmlspecialchars($text));
        } elseif (strpos($text, '[📉 DECREMENT] ') === 0) {
            $bgColor = 'rgba(239,68,68,0.08)'; $bColor = '#ef4444'; $tLeft = '📉 Call Decreased'; $tColor = '#ef4444';
            $text = substr($text, 15);
            $htmlContent = nl2br(htmlspecialchars($text));
        } else {
            $bgColor = 'var(--bg)'; $bColor = 'var(--accent)'; $tLeft = '📝 Call Notes'; $tColor = 'var(--accent)';
            $htmlContent = nl2br(htmlspecialchars($text));
        }
        
        $leadName = trim($f['first_name'] . ' ' . $f['last_name']) ?: 'Unknown Lead';
        $timeStr = date('d M Y, H:i:s', strtotime($f['created_at']));
        ?>
        <a href="<?= BASE_URL ?>/lead_detail.php?id=<?= $f['lead_id'] ?>" style="display:block; text-decoration:none; color:inherit; transition:transform 0.15s" onmouseover="this.style.transform='scale(1.01)'" onmouseout="this.style.transform='scale(1)'">
            <div style="background:<?= $bgColor ?>;border-radius:10px;padding:12px 14px;border-left:3px solid <?= $bColor ?>;margin-bottom:10px;box-shadow:0 2px 8px rgba(0,0,0,0.2)">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
                    <div style="display:flex;align-items:center;gap:10px">
                        <span style="font-size:11px;font-weight:700;color:<?= $tColor ?>;background:rgba(0,0,0,0.2);padding:3px 8px;border-radius:4px"><?= $tLeft ?></span>
                        <span style="font-size:13px;font-weight:600;color:var(--text)">Lead: <?= htmlspecialchars($leadName) ?></span>
                    </div>
                    <span style="font-size:11px;color:var(--text2);font-family:monospace"><?= $timeStr ?></span>
                </div>
                <div style="font-size:13px;color:var(--text);line-height:1.5;margin-left:2px"><?= $htmlContent ?></div>
                <div style="margin-top:8px;font-size:11px;color:var(--text2);display:flex;justify-content:space-between">
                    <span>By <?= htmlspecialchars($f['by_name']) ?></span>
                    <?php if ($f['next_followup']): ?>
                    <span style="color:var(--warn);font-weight:600">Next: <?= date('d M Y', strtotime($f['next_followup'])) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </a>
        <?php
    }
    exit;
}

include __DIR__ . '/includes/header.php';
?>

<div style="max-width:900px; margin: 0 auto;">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;">
    <div>
        <h2 style="font-family:'DM Serif Display',serif;font-size:24px;">🌍 LSR Daily Activity (Live)</h2>
        <p style="color:var(--text2);font-size:13px;margin-top:4px;display:flex;align-items:center;gap:6px">
            <span style="display:inline-block;width:8px;height:8px;background:var(--success);border-radius:50%;box-shadow:0 0 8px var(--success);animation:pulse 1.5s infinite"></span>
            Syncing in real-time...
        </p>
    </div>
  </div>

  <div class="card" style="min-height:500px">
      <div id="activity-feed">
          <div style="text-align:center;padding:40px;color:var(--text2)">Loading live activities...</div>
      </div>
  </div>
</div>

<style>
@keyframes pulse {
    0% { transform: scale(0.95); opacity: 0.8; }
    50% { transform: scale(1.1); opacity: 1; }
    100% { transform: scale(0.95); opacity: 0.8; }
}
</style>

<script>
function fetchActivities() {
    fetch('?ajax=1')
        .then(res => res.text())
        .then(html => {
            document.getElementById('activity-feed').innerHTML = html;
        })
        .catch(err => console.error('Sync error:', err));
}

// Initial fetch
fetchActivities();

// Sync every 1 second (1000ms)
setInterval(fetchActivities, 1000);
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
