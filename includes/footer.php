  </div><!-- /page-content -->
</div><!-- /main-wrap -->

<script>
// ── Global helpers ───────────────────────────────────────────────
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

function toggleSidebar() {
    const sb = document.getElementById('appSidebar');
    const ov = document.getElementById('mobileOverlay');
    if (sb) sb.classList.toggle('open');
    if (ov) ov.style.display = sb.classList.contains('open') ? 'block' : 'none';
}

// Close modal on backdrop click
document.querySelectorAll('.modal-bg').forEach(bg => {
  bg.addEventListener('click', e => { if (e.target === bg) bg.classList.remove('open'); });
});

// Flash auto-dismiss
setTimeout(() => {
  document.querySelectorAll('.alert').forEach(el => {
    el.style.transition = 'opacity 0.5s';
    el.style.opacity = '0';
    setTimeout(() => el.remove(), 500);
  });
}, 4000);

// ── Notification Sound ───────────────────────────────────────────
const notifAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
function playNotifSound() {
  const osc = notifAudioCtx.createOscillator();
  const gain = notifAudioCtx.createGain();
  osc.connect(gain);
  gain.connect(notifAudioCtx.destination);
  osc.type = 'sine';

  // Two-tone chime
  osc.frequency.setValueAtTime(880, notifAudioCtx.currentTime);
  osc.frequency.setValueAtTime(1100, notifAudioCtx.currentTime + 0.12);
  gain.gain.setValueAtTime(0.3, notifAudioCtx.currentTime);
  gain.gain.exponentialRampToValueAtTime(0.01, notifAudioCtx.currentTime + 0.4);

  osc.start(notifAudioCtx.currentTime);
  osc.stop(notifAudioCtx.currentTime + 0.4);
}

// ── Browser Push Notifications ───────────────────────────────────
let pushPermission = Notification.permission;
if (pushPermission === 'default') {
  Notification.requestPermission().then(p => { pushPermission = p; });
}

function showBrowserNotification(title, body, url) {
  if (pushPermission !== 'granted') return;
  const n = new Notification(title, {
    body: body,
    icon: '🎯',
    badge: '🔔',
    tag: 'crm-lead-' + Date.now(),
    requireInteraction: true,
    vibrate: [200, 100, 200],
  });
  n.onclick = function() {
    window.focus();
    if (url) window.location.href = url;
    n.close();
  };
}

// ── Notification Panel ───────────────────────────────────────────
const notifPanel = document.getElementById('notif-panel');
const notifBell  = document.getElementById('notif-bell');
const notifCount = document.getElementById('notif-count');
let lastKnownCount = parseInt(notifCount?.textContent) || 0;

function toggleNotifPanel() {
  notifPanel.classList.toggle('open');
}

// Close notification panel when clicking outside
document.addEventListener('click', function(e) {
  if (notifBell && !notifBell.contains(e.target)) {
    notifPanel.classList.remove('open');
  }
});

// Mark single notification as read
function markRead(e, notifId) {
  const item = e.currentTarget;
  fetch(window.location.pathname + '?_notif_action=mark_read', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'notif_id=' + notifId
  }).then(() => {
    item.classList.remove('unread');
    updateBadgeCount();
  });
}

// Mark all as read
function markAllRead(e) {
  e.stopPropagation();
  e.preventDefault();
  fetch(window.location.pathname + '?_notif_action=mark_all_read', {
    method: 'POST'
  }).then(() => {
    document.querySelectorAll('.notif-item.unread').forEach(el => el.classList.remove('unread'));
    updateBadgeCount(0);
  });
}

// Update badge count
function updateBadgeCount(forcedCount) {
  if (typeof forcedCount === 'number') {
    if (forcedCount === 0) {
      notifCount.classList.add('hidden');
    } else {
      notifCount.textContent = forcedCount;
      notifCount.classList.remove('hidden');
    }
    lastKnownCount = forcedCount;
    return;
  }
  const unread = document.querySelectorAll('.notif-item.unread').length;
  if (unread === 0) {
    notifCount.classList.add('hidden');
  } else {
    notifCount.textContent = unread;
    notifCount.classList.remove('hidden');
  }
  lastKnownCount = unread;
}

// Poll for new notifications every 15 seconds (with sound + push)
setInterval(() => {
  fetch(window.location.pathname + '?_notif_action=get')
    .then(r => r.json())
    .then(data => {
      const newCount = data.count || 0;

      // New notification arrived!
      if (newCount > lastKnownCount) {
        // Play sound
        playNotifSound();

        // Show browser push notification
        if (data.notifications && data.notifications.length > 0) {
          const latest = data.notifications[0];
          const leadUrl = latest.lead_id ? '<?= BASE_URL ?>/lead_detail.php?id=' + latest.lead_id : '';
          showBrowserNotification(
            latest.title || '🎯 New Lead Assigned',
            latest.message || 'You have a new lead assigned to you.',
            leadUrl
          );
        }

        // Pulse the bell
        notifBell.style.animation = 'none';
        notifBell.offsetHeight;
        notifBell.style.animation = 'bellShake 0.6s ease';
      }

      // Update count
      if (newCount > 0) {
        notifCount.textContent = newCount;
        notifCount.classList.remove('hidden');
      } else {
        notifCount.classList.add('hidden');
      }
      lastKnownCount = newCount;
    })
    .catch(() => {});
}, 15000);
</script>

<style>
@keyframes bellShake {
  0%, 100% { transform: rotate(0); }
  20% { transform: rotate(15deg); }
  40% { transform: rotate(-15deg); }
  60% { transform: rotate(10deg); }
  80% { transform: rotate(-5deg); }
}
</style>
</body>
</html>
