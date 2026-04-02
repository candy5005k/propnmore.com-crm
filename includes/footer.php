  </div><!-- /page-content -->
</div><!-- /main-wrap -->

<script>
// ── Global helpers ───────────────────────────────────────────────
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

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

// ── Notification Panel ───────────────────────────────────────────
const notifPanel = document.getElementById('notif-panel');
const notifBell  = document.getElementById('notif-bell');
const notifCount = document.getElementById('notif-count');

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
  // Don't prevent navigation — but fire AJAX
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
    return;
  }
  // Count remaining unread items
  const unread = document.querySelectorAll('.notif-item.unread').length;
  if (unread === 0) {
    notifCount.classList.add('hidden');
  } else {
    notifCount.textContent = unread;
    notifCount.classList.remove('hidden');
  }
}

// Poll for new notifications every 30 seconds
setInterval(() => {
  fetch(window.location.pathname + '?_notif_action=get')
    .then(r => r.json())
    .then(data => {
      if (data.count > 0) {
        notifCount.textContent = data.count;
        notifCount.classList.remove('hidden');
      } else {
        notifCount.classList.add('hidden');
      }
    })
    .catch(() => {});
}, 30000);
</script>
</body>
</html>
