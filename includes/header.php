<?php
// includes/header.php — shared top bar + sidebar
// Usage: include this file AFTER requiring config.php and calling requireAuth()
// Expects $user array and $pageTitle string to be set before inclusion.
$pageTitle = $pageTitle ?? 'CRM LSR LEADS 2026';
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

// ── Handle notification AJAX actions ────────────────────────────────
if (isset($_GET['_notif_action'])) {
    header('Content-Type: application/json');
    if ($_GET['_notif_action'] === 'mark_read' && isset($_POST['notif_id'])) {
        markNotificationRead((int)$_POST['notif_id'], $user['id']);
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($_GET['_notif_action'] === 'mark_all_read') {
        markAllNotificationsRead($user['id']);
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($_GET['_notif_action'] === 'get') {
        $notifs = getNotifications($user['id']);
        $count  = getUnreadNotificationCount($user['id']);
        echo json_encode(['count' => $count, 'notifications' => $notifs]);
        exit;
    }
    exit;
}

// Get unread count for badge
$unreadCount = getUnreadNotificationCount($user['id']);
$notifications = getNotifications($user['id']);

// Google Sheet URL (the main CRM sheet)
$googleSheetUrl = 'https://docs.google.com/spreadsheets/d/' . SHEET_META_ID;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($pageTitle) ?> — CRM LSR</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ── Reset ──────────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --bg:        #080d16;
  --surface:   #0f1623;
  --surface2:  #141d2e;
  --border:    #1a2640;
  --accent:    #c9a96e;
  --accent2:   #e8c98a;
  --accentRGB: 201,169,110;
  --text:      #dde3f0;
  --text2:     #8a9ab8;
  --danger:    #ef4444;
  --warn:      #f59e0b;
  --success:   #22c55e;
  --hot:       #ef4444;
  --warm:      #f59e0b;
  --cold:      #3b82f6;
  --sidebar-w: 240px;
  --topbar-h:  60px;
}

body {
  background: var(--bg);
  color: var(--text);
  font-family: 'DM Sans', sans-serif;
  display: flex;
  min-height: 100vh;
  font-size: 14px;
}

/* ── Sidebar ─────────────────────────────────────────────────────── */
.sidebar {
  position: fixed;
  top: 0; left: 0;
  width: var(--sidebar-w);
  height: 100vh;
  background: var(--surface);
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  z-index: 100;
  overflow-y: auto;
}

.sidebar-logo {
  padding: 20px 20px 16px;
  border-bottom: 1px solid var(--border);
}
.sidebar-logo .name {
  font-family: 'DM Serif Display', serif;
  font-size: 16px;
  color: var(--accent);
  line-height: 1.2;
}
.sidebar-logo .sub {
  font-size: 10px;
  color: var(--text2);
  letter-spacing: 2px;
  text-transform: uppercase;
  margin-top: 2px;
}

.nav-section {
  padding: 16px 12px 4px;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 1.8px;
  text-transform: uppercase;
  color: var(--text2);
}

.nav-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 16px;
  border-radius: 10px;
  margin: 2px 8px;
  text-decoration: none;
  color: var(--text2);
  font-size: 13.5px;
  font-weight: 500;
  transition: all 0.18s;
}
.nav-item:hover, .nav-item.active {
  background: rgba(var(--accentRGB), 0.1);
  color: var(--accent);
}
.nav-item .icon { font-size: 16px; flex-shrink: 0; }

/* Google Sheet quick-open button */
.nav-item-sheet {
  background: linear-gradient(135deg, rgba(52,168,83,0.12), rgba(66,133,244,0.12));
  border: 1px solid rgba(52,168,83,0.25);
  color: #4ade80;
  position: relative;
  overflow: hidden;
}
.nav-item-sheet:hover {
  background: linear-gradient(135deg, rgba(52,168,83,0.22), rgba(66,133,244,0.22));
  border-color: rgba(52,168,83,0.45);
  color: #86efac;
}
.nav-item-sheet::after {
  content: '↗';
  position: absolute;
  right: 14px;
  font-size: 12px;
  opacity: 0;
  transform: translate(-4px, 4px);
  transition: all 0.2s;
}
.nav-item-sheet:hover::after {
  opacity: 0.7;
  transform: translate(0, 0);
}

.sidebar-footer {
  margin-top: auto;
  padding: 16px 12px;
  border-top: 1px solid var(--border);
}
.user-chip {
  display: flex;
  align-items: center;
  gap: 10px;
}
.user-avatar {
  width: 34px; height: 34px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--accent), var(--accent2));
  display: flex; align-items: center; justify-content: center;
  font-size: 13px;
  font-weight: 700;
  color: #0a0e17;
  flex-shrink: 0;
}
.user-info .uname { font-size: 13px; font-weight: 600; color: var(--text); }
.user-info .urole { font-size: 11px; color: var(--text2); }
.logout-btn {
  display: block;
  margin-top: 10px;
  padding: 8px 14px;
  background: rgba(239,68,68,0.1);
  border: 1px solid rgba(239,68,68,0.25);
  border-radius: 8px;
  color: #fca5a5;
  text-decoration: none;
  font-size: 12px;
  text-align: center;
  transition: all 0.18s;
}
.logout-btn:hover { background: rgba(239,68,68,0.2); }

/* ── Main layout ─────────────────────────────────────────────────── */
.main-wrap {
  margin-left: var(--sidebar-w);
  flex: 1;
  display: flex;
  flex-direction: column;
  min-height: 100vh;
}

.topbar {
  height: var(--topbar-h);
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 28px;
  position: sticky;
  top: 0;
  z-index: 90;
}
.topbar-title {
  font-family: 'DM Serif Display', serif;
  font-size: 18px;
  color: var(--text);
}
.topbar-right { display: flex; align-items: center; gap: 14px; }
.badge-pill {
  background: rgba(var(--accentRGB), 0.15);
  color: var(--accent);
  border-radius: 20px;
  padding: 4px 12px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.5px;
}

.page-content {
  flex: 1;
  padding: 28px;
}

/* ── Notification Bell ─────────────────────────────────────────────── */
.notif-bell {
  position: relative;
  cursor: pointer;
  width: 38px;
  height: 38px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 10px;
  background: rgba(var(--accentRGB), 0.08);
  border: 1px solid var(--border);
  transition: all 0.2s;
  user-select: none;
}
.notif-bell:hover {
  background: rgba(var(--accentRGB), 0.15);
  border-color: rgba(var(--accentRGB), 0.3);
}
.notif-bell .bell-icon { font-size: 18px; }
.notif-bell .notif-count {
  position: absolute;
  top: -4px;
  right: -4px;
  background: var(--danger);
  color: #fff;
  font-size: 10px;
  font-weight: 800;
  border-radius: 50%;
  min-width: 18px;
  height: 18px;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0 4px;
  line-height: 1;
  animation: notifPulse 2s ease infinite;
  box-shadow: 0 0 8px rgba(239,68,68,0.4);
}
@keyframes notifPulse {
  0%, 100% { transform: scale(1); }
  50% { transform: scale(1.1); }
}
.notif-count.hidden { display: none; }

/* ── Notification Dropdown Panel ─────────────────────────────────── */
.notif-panel {
  position: absolute;
  top: calc(100% + 10px);
  right: 0;
  width: 380px;
  max-height: 480px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 16px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.5), 0 0 1px rgba(var(--accentRGB),0.2);
  z-index: 200;
  display: none;
  flex-direction: column;
  overflow: hidden;
  animation: notifSlide 0.25s ease both;
}
@keyframes notifSlide {
  from { opacity: 0; transform: translateY(-8px); }
  to   { opacity: 1; transform: translateY(0); }
}
.notif-panel.open { display: flex; }

.notif-panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px;
  border-bottom: 1px solid var(--border);
}
.notif-panel-header h3 {
  font-family: 'DM Serif Display', serif;
  font-size: 16px;
  color: var(--text);
}
.notif-mark-all {
  background: none;
  border: none;
  color: var(--accent);
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  font-family: 'DM Sans', sans-serif;
  transition: opacity 0.2s;
}
.notif-mark-all:hover { opacity: 0.75; }

.notif-list {
  overflow-y: auto;
  max-height: 380px;
  padding: 8px;
}
.notif-empty {
  padding: 40px 20px;
  text-align: center;
  color: var(--text2);
  font-size: 13px;
}
.notif-empty .empty-icon {
  font-size: 32px;
  display: block;
  margin-bottom: 10px;
  opacity: 0.5;
}

.notif-item {
  display: flex;
  gap: 12px;
  padding: 12px 14px;
  border-radius: 10px;
  cursor: pointer;
  transition: all 0.15s;
  text-decoration: none;
  color: inherit;
  position: relative;
}
.notif-item:hover { background: var(--surface2); }
.notif-item.unread { background: rgba(var(--accentRGB), 0.06); }
.notif-item.unread::before {
  content: '';
  position: absolute;
  left: 6px;
  top: 50%;
  transform: translateY(-50%);
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--accent);
  box-shadow: 0 0 6px rgba(var(--accentRGB), 0.5);
}

.notif-icon-wrap {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  flex-shrink: 0;
  background: rgba(var(--accentRGB), 0.12);
}
.notif-icon-wrap.assign { background: rgba(34,197,94,0.12); }
.notif-icon-wrap.info   { background: rgba(59,130,246,0.12); }

.notif-content { flex: 1; min-width: 0; }
.notif-title {
  font-size: 13px;
  font-weight: 600;
  color: var(--text);
  line-height: 1.3;
  margin-bottom: 2px;
}
.notif-msg {
  font-size: 12px;
  color: var(--text2);
  line-height: 1.4;
  overflow: hidden;
  text-overflow: ellipsis;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  -webkit-box-orient: vertical;
}
.notif-time {
  font-size: 10px;
  color: var(--text2);
  margin-top: 4px;
  opacity: 0.7;
}

/* ── Utility components ─────────────────────────────────────────── */
.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 16px;
  padding: 24px;
}
.card-sm { padding: 16px 20px; }

.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.grid-3 { display: grid; grid-template-columns: repeat(3,1fr); gap: 16px; }
.grid-4 { display: grid; grid-template-columns: repeat(4,1fr); gap: 16px; }

.stat-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 20px 22px;
}
.stat-label { font-size: 11px; font-weight: 600; letter-spacing: 1.5px; text-transform: uppercase; color: var(--text2); margin-bottom: 8px; }
.stat-value { font-family: 'DM Serif Display', serif; font-size: 36px; color: var(--text); line-height: 1; }
.stat-sub   { font-size: 12px; color: var(--text2); margin-top: 6px; }

/* Tables */
.tbl-wrap { overflow-x: auto; border-radius: 12px; border: 1px solid var(--border); box-shadow: 0 4px 20px rgba(0,0,0,0.15); }
table { width: 100%; border-collapse: collapse; font-family: 'Inter', 'DM Sans', sans-serif; }
thead th {
  background: var(--surface2);
  padding: 14px 16px;
  font-size: 11.5px;
  font-weight: 700;
  letter-spacing: 0.8px;
  text-transform: uppercase;
  color: var(--text2);
  text-align: left;
  border-bottom: 1px solid var(--border);
  white-space: nowrap;
}
tbody tr { border-bottom: 1px solid var(--border); transition: all 0.2s ease-in-out; }
tbody tr:last-child { border-bottom: none; }
tbody tr:hover { background: rgba(20, 29, 46, 0.7); transform: translateY(-1px); box-shadow: inset 0 0 0 1px rgba(201,169,110,0.1); }
tbody td { padding: 14px 16px; font-size: 14px; vertical-align: middle; line-height: 1.5; color: var(--text); }

/* Badges */
.badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  border-radius: 6px;
  padding: 3px 9px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.3px;
  text-transform: uppercase;
}
.badge-hot     { background: rgba(239,68,68,0.15);  color: #f87171; }
.badge-warm    { background: rgba(245,158,11,0.15); color: #fbbf24; }
.badge-cold    { background: rgba(59,130,246,0.15); color: #60a5fa; }
.badge-svp     { background: rgba(168,85,247,0.15); color: #c084fc; }
.badge-svd     { background: rgba(34,197,94,0.15);  color: #4ade80; }
.badge-closed  { background: rgba(107,114,128,0.15); color: #9ca3af; }
.badge-web     { background: rgba(6,182,212,0.15);  color: #22d3ee; }
.badge-meta    { background: rgba(59,130,246,0.15); color: #60a5fa; }
.badge-google  { background: rgba(239,68,68,0.15);  color: #f87171; }
.badge-manual  { background: rgba(245,158,11,0.15); color: #fbbf24; }

/* Buttons */
.btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 9px 18px;
  border-radius: 9px;
  font-size: 13px;
  font-weight: 600;
  font-family: 'DM Sans', sans-serif;
  cursor: pointer;
  border: none;
  transition: all 0.18s;
  text-decoration: none;
}
.btn-primary  { background: linear-gradient(135deg,var(--accent),var(--accent2)); color: #0a0e17; }
.btn-primary:hover { opacity: 0.88; }
.btn-outline  { background: transparent; border: 1px solid var(--border); color: var(--text2); }
.btn-outline:hover { border-color: var(--accent); color: var(--accent); }
.btn-danger   { background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #f87171; }
.btn-sm       { padding: 6px 12px; font-size: 12px; }

/* Forms */
.form-group { margin-bottom: 18px; }
.form-group label { display: block; font-size: 11px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text2); margin-bottom: 7px; }
.form-control {
  width: 100%;
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: 9px;
  padding: 10px 14px;
  font-size: 14px;
  color: var(--text);
  font-family: 'DM Sans', sans-serif;
  outline: none;
  transition: border-color 0.18s;
}
.form-control:focus { border-color: var(--accent); }
select.form-control { cursor: pointer; }
textarea.form-control { resize: vertical; min-height: 90px; }

/* Alerts */
.alert { padding: 12px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 18px; }
.alert-error   { background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.25); color:#fca5a5; }
.alert-success { background:rgba(34,197,94,0.1); border:1px solid rgba(34,197,94,0.25); color:#86efac; }
.alert-info    { background:rgba(59,130,246,0.1); border:1px solid rgba(59,130,246,0.25); color:#93c5fd; }

/* Pagination */
.pagination { display: flex; gap: 6px; align-items: center; justify-content: center; margin-top: 20px; }
.pagination a, .pagination span {
  display: inline-flex; align-items: center; justify-content: center;
  width: 34px; height: 34px;
  border-radius: 8px; font-size: 13px; font-weight: 600;
  text-decoration: none; color: var(--text2);
  border: 1px solid var(--border);
  transition: all 0.18s;
}
.pagination a:hover   { border-color: var(--accent); color: var(--accent); }
.pagination span.cur  { background: var(--accent); color: #0a0e17; border-color: var(--accent); }

/* Modal */
.modal-bg {
  display: none;
  position: fixed; inset: 0;
  background: rgba(0,0,0,0.65);
  z-index: 200;
  align-items: center;
  justify-content: center;
}
.modal-bg.open { display: flex; }
.modal {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 18px;
  width: 90%;
  max-width: 600px;
  max-height: 90vh;
  overflow-y: auto;
  padding: 32px;
  animation: fadeUp 0.25s ease both;
}
@keyframes fadeUp { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }
.modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
.modal-title { font-family:'DM Serif Display',serif; font-size:20px; }
.modal-close { background:none; border:none; color:var(--text2); font-size:22px; cursor:pointer; }
.modal-close:hover { color:var(--text); }

/* Responsive */
@media (max-width: 768px) {
  .sidebar { transform: translateX(-100%); transition: transform 0.3s; }
  .sidebar.open { transform: translateX(0); }
  .main-wrap { margin-left: 0; }
  .grid-4, .grid-3, .grid-2 { grid-template-columns: 1fr; }
  .notif-panel { width: calc(100vw - 24px); right: -60px; }
}
</style>
</head>
<body>

<nav class="sidebar">
  <div class="sidebar-logo">
    <div class="name" style="font-size:16px;line-height:1.2;margin-bottom:2px">LSR Lakshsiddh<br>Realty LLP</div>
    <div class="sub" style="letter-spacing:1px;font-size:10px">By PROPNMORE</div>
  </div>

  <div class="nav-section">Leads</div>
  <a href="<?= BASE_URL ?>/index.php" class="nav-item <?= $currentPage==='index'?'active':'' ?>">
    <span class="icon">📋</span> All Leads
  </a>
  <?php if ($user['role']==='admin'): ?>
  <a href="<?= BASE_URL ?>/leads_new.php" class="nav-item <?= $currentPage==='leads_new'?'active':'' ?>">
    <span class="icon">➕</span> Add Lead
  </a>
  <a href="<?= BASE_URL ?>/daily_activity.php" class="nav-item <?= $currentPage==='daily_activity'?'active':'' ?>">
    <span class="icon">🌍</span> Live Activity
  </a>
  <a href="<?= BASE_URL ?>/import_leads.php" class="nav-item <?= $currentPage==='import_leads'?'active':'' ?>">
    <span class="icon">📥</span> Offline Leads
  </a>
  <a href="<?= BASE_URL ?>/recycle_bin.php" class="nav-item <?= $currentPage==='recycle_bin'?'active':'' ?>">
    <span class="icon">🗑️</span> Recycle Bin
  </a>
  <?php endif; ?>

  <?php if ($user['role']==='admin'): ?>
  <div class="nav-section">Sources</div>
  <a href="<?= BASE_URL ?>/index.php?source=website" class="nav-item">
    <span class="icon">🌐</span> Website
  </a>
  <a href="<?= BASE_URL ?>/index.php?source=meta" class="nav-item">
    <span class="icon">📱</span> Meta
  </a>
  <a href="<?= BASE_URL ?>/index.php?source=google" class="nav-item">
    <span class="icon">🔍</span> Google
  </a>
  <?php endif; ?>



  <?php if ($user['role']==='admin'): ?>
  <div class="nav-section">Admin</div>
  <a href="<?= BASE_URL ?>/admin_users.php" class="nav-item <?= $currentPage==='admin_users'?'active':'' ?>">
    <span class="icon">👥</span> Users
  </a>
  <a href="<?= BASE_URL ?>/admin_sync.php" class="nav-item <?= $currentPage==='admin_sync'?'active':'' ?>">
    <span class="icon">🔄</span> Sync
  </a>
  <a href="<?= BASE_URL ?>/admin_projects.php" class="nav-item <?= $currentPage==='admin_projects'?'active':'' ?>">
    <span class="icon">🏗️</span> Projects
  </a>
  <?php endif; ?>

  <div class="sidebar-footer">
    <div class="user-chip">
      <div class="user-avatar"><?= strtoupper(substr($user['name'],0,1)) ?></div>
      <div class="user-info">
        <div class="uname"><?= htmlspecialchars($user['name']) ?></div>
        <div class="urole"><?= $user['role']==='admin'?'Administrator':'Sales Manager' ?></div>
      </div>
    </div>
    <a href="<?= BASE_URL ?>/logout.php" class="logout-btn">Sign Out</a>
  </div>
</nav>

<div class="main-wrap">
  <div class="topbar">
    <span class="topbar-title"><?= htmlspecialchars($pageTitle) ?></span>
    <div class="topbar-right">

      <?php if ($user['role']==='admin'): ?>
      <!-- Google Sheet button (admin only) -->
      <a href="<?= htmlspecialchars($googleSheetUrl) ?>" target="_blank" rel="noopener"
         class="btn btn-outline btn-sm" style="gap:6px;border-color:rgba(52,168,83,0.35);color:#4ade80;"
         title="Open Google Sheet in new tab">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
          <polyline points="14 2 14 8 20 8"></polyline>
          <line x1="16" y1="13" x2="8" y2="13"></line>
          <line x1="16" y1="17" x2="8" y2="17"></line>
          <polyline points="10 9 9 9 8 9"></polyline>
        </svg>
        Sheet
      </a>
      <?php endif; ?>

      <!-- ★ Point 11: Notification Bell -->
      <div class="notif-bell" id="notif-bell" onclick="toggleNotifPanel()">
        <span class="bell-icon">🔔</span>
        <span class="notif-count <?= $unreadCount === 0 ? 'hidden' : '' ?>" id="notif-count"><?= $unreadCount ?></span>

        <!-- Notification dropdown panel -->
        <div class="notif-panel" id="notif-panel">
          <div class="notif-panel-header">
            <h3>Notifications</h3>
            <button class="notif-mark-all" onclick="markAllRead(event)">Mark all read</button>
          </div>
          <div class="notif-list" id="notif-list">
            <?php if (empty($notifications)): ?>
              <div class="notif-empty">
                <span class="empty-icon">🔕</span>
                No notifications yet
              </div>
            <?php else: ?>
              <?php foreach ($notifications as $n): ?>
              <a href="<?= $n['lead_id'] ? BASE_URL . '/lead_detail.php?id=' . $n['lead_id'] : '#' ?>"
                 class="notif-item <?= $n['is_read'] ? '' : 'unread' ?>"
                 data-id="<?= $n['id'] ?>"
                 onclick="markRead(event, <?= $n['id'] ?>)">
                <div class="notif-icon-wrap assign">
                  <?= $n['type'] === 'lead_assigned' ? '📌' : '📣' ?>
                </div>
                <div class="notif-content">
                  <div class="notif-title"><?= htmlspecialchars($n['title']) ?></div>
                  <div class="notif-msg"><?= htmlspecialchars($n['message']) ?></div>
                  <div class="notif-time"><?= timeAgo($n['created_at']) ?></div>
                </div>
              </a>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <?php if ($user['role']==='admin'): ?>
        <a href="<?= BASE_URL ?>/admin_users.php" style="text-decoration:none"><span class="badge-pill" style="cursor:pointer" title="Edit Profile">🛡 <?= htmlspecialchars($user['name']) ?></span></a>
      <?php else: ?>
        <span class="badge-pill">👤 <?= htmlspecialchars($user['name']) ?></span>
      <?php endif; ?>
    </div>
  </div>
  <div class="page-content">

<?php
// Helper: human-readable time-ago
function timeAgo(string $datetime): string {
    $now  = new DateTime();
    $then = new DateTime($datetime);
    $diff = $now->diff($then);
    if ($diff->y > 0) return $diff->y . 'y ago';
    if ($diff->m > 0) return $diff->m . 'mo ago';
    if ($diff->d > 0) return $diff->d . 'd ago';
    if ($diff->h > 0) return $diff->h . 'h ago';
    if ($diff->i > 0) return $diff->i . 'm ago';
    return 'Just now';
}
?>
