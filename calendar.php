<?php
require_once __DIR__ . '/config.php';
requireAuth();

$pageTitle = 'Calendar';
include __DIR__ . '/includes/header.php';

// Prepare a few things
$today = date('Y-m-d');
?>
<style>
/* ── Apple-style Dark Calendar ────────────────────────────── */
.cal-wrapper {
    display: flex;
    gap: 20px;
    height: calc(100vh - 120px);
}

.cal-main {
    flex: 1;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.cal-sidebar {
    width: 320px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/* Header */
.cal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 24px;
    border-bottom: 1px solid var(--border);
}

.cal-header h2 {
    font-size: 20px;
    font-weight: 600;
    margin: 0;
}

.cal-nav {
    display: flex;
    align-items: center;
    gap: 12px;
}

.cal-nav button {
    background: transparent;
    border: 1px solid var(--border);
    border-radius: 8px;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text);
    cursor: pointer;
    transition: all 0.2s;
}

.cal-nav button:hover {
    background: rgba(255,255,255,0.05);
}

/* Grid */
.cal-grid {
    flex: 1;
    display: flex;
    flex-direction: column;
}

.cal-days-header {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    border-bottom: 1px solid var(--border);
    background: var(--surface2);
}

.cal-day-label {
    text-align: right;
    padding: 10px;
    font-size: 11px;
    font-weight: 700;
    color: var(--text2);
    text-transform: uppercase;
}

.cal-body {
    flex: 1;
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    grid-template-rows: repeat(5, 1fr); /* dynamic via JS */
    border-bottom: 1px solid var(--border);
}

.cal-cell {
    border-right: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
    padding: 8px;
    position: relative;
    cursor: pointer;
    transition: background 0.2s;
    overflow-y: auto;
}

.cal-cell:hover {
    background: rgba(255,255,255,0.02);
}

.cal-cell:nth-child(7n) { border-right: none; }
.cal-cell.other-month { opacity: 0.3; }

.cal-date-num {
    text-align: right;
    font-size: 14px;
    font-weight: 500;
    margin-bottom: 8px;
    color: var(--text2);
}

.cal-cell.today .cal-date-num span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: var(--danger);
    color: #fff;
    border-radius: 50%;
    width: 26px;
    height: 26px;
}

/* Event Chips inside calendar */
.cal-evt {
    font-size: 10px;
    background: rgba(var(--accentRGB), 0.15);
    color: var(--accent);
    padding: 4px 6px;
    border-radius: 4px;
    margin-bottom: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    border-left: 3px solid var(--accent);
}
.cal-evt.done {
    opacity: 0.5;
    text-decoration: line-through;
    border-color: var(--success);
    color: var(--success);
    background: rgba(34, 197, 94, 0.1);
}

/* Sidebar tasks list */
.sb-header {
    padding: 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.sb-header h3 { font-size: 16px; font-weight: 500; margin: 0; }

.sb-list {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
}

.sb-task {
    background: var(--surface2);
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 12px;
    position: relative;
    border-left: 3px solid var(--accent);
}
.sb-task.done { border-color: var(--success); opacity: 0.6; }

.sb-task-head { display: flex; justify-content: space-between; margin-bottom: 6px; }
.sb-task-time { font-size: 11px; color: var(--text2); font-weight: 600; }
.sb-task-del { font-size: 14px; cursor: pointer; color: var(--danger); opacity: 0.5; }
.sb-task-del:hover { opacity: 1; }
.sb-task-title { font-size: 14px; font-weight: 500; margin-bottom: 4px; }
.sb-task-desc { font-size: 12px; color: var(--text2); }

.sb-task-actions {
    margin-top: 10px;
    display: flex;
    gap: 10px;
    align-items: center;
}

/* Add event FAB */
.fab {
    position: fixed;
    bottom: 30px;
    right: 30px;
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: linear-gradient(135deg,var(--accent),var(--accent2));
    color: #000;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    box-shadow: 0 10px 20px rgba(0,0,0,0.4);
    cursor: pointer;
    transition: transform 0.2s;
    border: none;
    z-index: 100;
}
.fab:hover { transform: scale(1.1); }
</style>

<div class="cal-wrapper">
    <!-- Main Calendar Area -->
    <div class="cal-main">
        <div class="cal-header">
            <h2 id="monthYearLabel">April 2026</h2>
            <div class="cal-nav">
                <button onclick="changeMonth(-1)">&#8592;</button>
                <button onclick="resetToToday()" style="width: auto; padding: 0 12px; font-size: 12px;">Today</button>
                <button onclick="changeMonth(1)">&#8594;</button>
            </div>
        </div>
        <div class="cal-grid">
            <div class="cal-days-header">
                <div class="cal-day-label">Sun</div>
                <div class="cal-day-label">Mon</div>
                <div class="cal-day-label">Tue</div>
                <div class="cal-day-label">Wed</div>
                <div class="cal-day-label">Thu</div>
                <div class="cal-day-label">Fri</div>
                <div class="cal-day-label">Sat</div>
            </div>
            <div class="cal-body" id="calendarBody">
                <!-- Days will be generated by JS -->
            </div>
        </div>
    </div>

    <!-- Sidebar: Events for selected day -->
    <div class="cal-sidebar">
        <div class="sb-header">
            <h3 id="selectedDateLabel">Select a Date</h3>
        </div>
        <div class="sb-list" id="sidebarList">
            <div style="color:var(--text2); font-size:13px; text-align:center; margin-top: 40px;">No events on this day.</div>
        </div>
    </div>
</div>

<button class="fab" onclick="openEventAddModal()">+</button>

<!-- Add Event Modal -->
<div class="modal-bg" id="addEventModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title">New Reminder</div>
            <button class="modal-close" onclick="closeModal('addEventModal')">&times;</button>
        </div>
        <form onsubmit="saveEvent(event)">
            <div class="form-group">
                <label>Title / Task</label>
                <input type="text" class="form-control" id="evTitle" required placeholder="e.g. Call Client">
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label>Date</label>
                    <input type="date" class="form-control" id="evDate" required>
                </div>
                <div class="form-group">
                    <label>Time (Optional)</label>
                    <input type="time" class="form-control" id="evTime">
                </div>
            </div>
            <div class="form-group">
                <label>Related Lead ID (Optional)</label>
                <input type="number" class="form-control" id="evLeadId" placeholder="Lead ID digits">
            </div>
            <div class="form-group">
                <label>Notes / Description</label>
                <textarea class="form-control" id="evDesc" placeholder="Any extra details..."></textarea>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:12px;margin-top:20px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('addEventModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSaveEvt">Save Reminder</button>
            </div>
        </form>
    </div>
</div>

<script>
let currentYear = new Date().getFullYear();
let currentMonth = new Date().getMonth(); // 0-11
let eventsData = [];
let selectedDateStr = '<?= $today ?>';

const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];

function renderCalendar() {
    document.getElementById('monthYearLabel').textContent = `${monthNames[currentMonth]} ${currentYear}`;
    
    // Find first day of the month
    const firstDay = new Date(currentYear, currentMonth, 1).getDay();
    // Days in current month
    const daysInMonth = new Date(currentYear, currentMonth + 1, 0).getDate();
    // Days in previous month
    const prevDays = new Date(currentYear, currentMonth, 0).getDate();
    
    let html = '';
    
    // Previous month cells
    for(let i = firstDay; i > 0; i--) {
        const pd = prevDays - i + 1;
        const fm = (currentMonth === 0) ? 12 : currentMonth;
        const fy = (currentMonth === 0) ? currentYear - 1 : currentYear;
        const dateStr = `${fy}-${String(fm).padStart(2,'0')}-${String(pd).padStart(2,'0')}`;
        html += renderCell(dateStr, pd, true);
    }
    
    // Current month cells
    for(let i = 1; i <= daysInMonth; i++) {
        const dateStr = `${currentYear}-${String(currentMonth+1).padStart(2,'0')}-${String(i).padStart(2,'0')}`;
        html += renderCell(dateStr, i, false);
    }
    
    // Next month cells
    const totalCellsFilled = firstDay + daysInMonth;
    const nextDaysNeeded = Math.ceil(totalCellsFilled / 7) * 7 - totalCellsFilled;
    for(let i = 1; i <= nextDaysNeeded; i++) {
        const nm = (currentMonth === 11) ? 1 : currentMonth + 2;
        const ny = (currentMonth === 11) ? currentYear + 1 : currentYear;
        const dateStr = `${ny}-${String(nm).padStart(2,'0')}-${String(i).padStart(2,'0')}`;
        html += renderCell(dateStr, i, true);
    }
    
    document.getElementById('calendarBody').innerHTML = html;
    
    // Apply grid rows based on generated
    const rows = Math.ceil((firstDay + daysInMonth) / 7);
    document.getElementById('calendarBody').style.gridTemplateRows = `repeat(${rows}, 1fr)`;

    // Render events on calendar
    populateEventsOnGrid();

    // Select the currently selected date or today
    selectDate(selectedDateStr);
}

function renderCell(dateStr, dayNum, isOther) {
    let classes = 'cal-cell';
    if(isOther) classes += ' other-month';
    
    const isToday = (dateStr === '<?= $today ?>') ? 'today' : '';
    const isSel = (dateStr === selectedDateStr) ? 'selected' : '';
    
    return `
        <div class="${classes} ${isToday}" data-date="${dateStr}" onclick="selectDate('${dateStr}')">
            <div class="cal-date-num"><span>${dayNum}</span></div>
            <div class="cal-evt-container" id="evc-${dateStr}"></div>
        </div>
    `;
}

function changeMonth(dir) {
    currentMonth += dir;
    if(currentMonth > 11) { currentMonth = 0; currentYear++; }
    else if(currentMonth < 0) { currentMonth = 11; currentYear--; }
    fetchEvents();
}

function resetToToday() {
    const d = new Date();
    currentYear = d.getFullYear();
    currentMonth = d.getMonth();
    selectedDateStr = `${currentYear}-${String(currentMonth+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
    fetchEvents();
}

function selectDate(dateStr) {
    document.querySelectorAll('.cal-cell').forEach(c => c.style.background = '');
    const cell = document.querySelector(`.cal-cell[data-date="${dateStr}"]`);
    if(cell) cell.style.background = 'rgba(201,169,110, 0.1)';
    
    selectedDateStr = dateStr;
    const dObj = new Date(dateStr);
    document.getElementById('selectedDateLabel').textContent = dateStr === '<?= $today ?>' 
        ? 'Today' 
        : dObj.toLocaleDateString('en-US', {weekday:'short', month:'short', day:'numeric'});
        
    renderSidebarTasks(dateStr);
}

function fetchEvents() {
    const monthStr = `${currentYear}-${String(currentMonth+1).padStart(2,'0')}`;
    fetch(`ajax_calendar_events.php?action=list&month=${monthStr}`)
        .then(r => r.json())
        .then(d => {
            if(d.status==='success') {
                eventsData = d.events;
                renderCalendar();
            }
        });
}

function populateEventsOnGrid() {
    eventsData.forEach(ev => {
        const c = document.getElementById(`evc-${ev.event_date}`);
        if(c) {
            const dn = ev.is_completed==1 ? 'done' : '';
            c.innerHTML += `<div class="cal-evt ${dn}">• ${ev.title}</div>`;
        }
    });
}

function renderSidebarTasks(dateStr) {
    const list = document.getElementById('sidebarList');
    const tdEvts = eventsData.filter(e => e.event_date === dateStr);
    
    if(tdEvts.length === 0) {
        list.innerHTML = `<div style="color:var(--text2); font-size:13px; text-align:center; margin-top:40px;">No events scheduled.</div>`;
        return;
    }
    
    let html = '';
    tdEvts.forEach(ev => {
        const timeStr = ev.event_time ? ev.event_time.substring(0,5) : 'All Day';
        const dn = ev.is_completed==1 ? 'done' : '';
        const chk = ev.is_completed==1 ? 'checked' : '';
        const url = ev.lead_id ? `<a href="lead_detail.php?id=${ev.lead_id}" style="font-size:11px;color:var(--accent);">Lead #${ev.lead_id}</a>` : '';
        
        html += `
            <div class="sb-task ${dn}">
                <div class="sb-task-head">
                    <span class="sb-task-time">${timeStr}</span>
                    <span class="sb-task-del" title="Delete" onclick="deleteEvent(${ev.id})">&times;</span>
                </div>
                <div class="sb-task-title">${ev.title}</div>
                <div class="sb-task-desc">${ev.description || ''}</div>
                <div class="sb-task-desc" style="margin-top:4px;">${url}</div>
                <div class="sb-task-actions">
                    <input type="checkbox" ${chk} onchange="toggleEvent(${ev.id}, this.checked)">
                    <span style="font-size:11px;color:var(--text2)">Mark complete</span>
                </div>
            </div>
        `;
    });
    list.innerHTML = html;
}

// ── CRUD Functions ──────────────────────

function openEventAddModal() {
    document.getElementById('evDate').value = selectedDateStr;
    document.getElementById('evTitle').value = '';
    document.getElementById('evDesc').value = '';
    document.getElementById('evTime').value = '';
    document.getElementById('evLeadId').value = '';
    openModal('addEventModal');
}

function saveEvent(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSaveEvt');
    btn.textContent = 'Saving...';
    btn.disabled = true;
    
    const fd = new FormData();
    fd.append('title', document.getElementById('evTitle').value);
    fd.append('description', document.getElementById('evDesc').value);
    fd.append('event_date', document.getElementById('evDate').value);
    fd.append('event_time', document.getElementById('evTime').value);
    fd.append('lead_id', document.getElementById('evLeadId').value);

    fetch('ajax_calendar_events.php?action=add', {
        method: 'POST', body: fd
    }).then(r=>r.json()).then(d => {
        closeModal('addEventModal');
        btn.textContent = 'Save Reminder';
        btn.disabled = false;
        fetchEvents();
    });
}

function toggleEvent(id, isChecked) {
    const fd = new FormData();
    fd.append('id', id);
    fd.append('status', isChecked ? 1 : 0);
    fetch('ajax_calendar_events.php?action=toggle', { method: 'POST', body: fd })
      .then(()=>fetchEvents());
}

function deleteEvent(id) {
    if(!confirm("Delete this reminder?")) return;
    const fd = new FormData(); fd.append('id', id);
    fetch('ajax_calendar_events.php?action=delete', { method: 'POST', body: fd })
      .then(()=>fetchEvents());
}

// Init
fetchEvents();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
