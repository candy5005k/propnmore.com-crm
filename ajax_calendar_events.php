<?php
// ajax_calendar_events.php
require_once __DIR__ . '/config.php';
requireAuth();

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$pdo = db();

if ($action === 'list') {
    // Get month events
    $month = $_GET['month'] ?? date('Y-m');
    $startDate = $month . '-01';
    $endDate = date('Y-m-t', strtotime($startDate));

    $stmt = $pdo->prepare("
        SELECT id, title, description, event_date, event_time, is_completed, lead_id
        FROM calendar_events
        WHERE user_id = ? AND event_date BETWEEN ? AND ?
        ORDER BY event_date ASC, event_time ASC
    ");
    $stmt->execute([$user['id'], $startDate, $endDate]);
    $events = $stmt->fetchAll();
    
    echo json_encode(['status' => 'success', 'events' => $events]);
    exit;
}

if ($action === 'add') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $eventDate = $_POST['event_date'] ?? date('Y-m-d');
    $eventTime = $_POST['event_time'] ?? null;
    $leadId = !empty($_POST['lead_id']) ? (int)$_POST['lead_id'] : null;

    if (empty($eventTime)) $eventTime = null;

    if (!$title) {
        echo json_encode(['status' => 'error', 'message' => 'Title is required.']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO calendar_events (user_id, lead_id, title, description, event_date, event_time) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$user['id'], $leadId, $title, $description, $eventDate, $eventTime]);
    
    echo json_encode(['status' => 'success', 'id' => $pdo->lastInsertId()]);
    exit;
}

if ($action === 'toggle') {
    $id = (int)($_POST['id'] ?? 0);
    $status = (int)($_POST['status'] ?? 0);

    $stmt = $pdo->prepare("UPDATE calendar_events SET is_completed = ? WHERE id = ? AND user_id = ?");
    $stmt->execute([$status, $id, $user['id']]);
    
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $pdo->prepare("DELETE FROM calendar_events WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $user['id']]);
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action === 'check_reminders') {
    // Look for events today that are due within the next 15 minutes, or overdue but not completed.
    $stmt = $pdo->prepare("
        SELECT id, title, description, event_time, lead_id
        FROM calendar_events 
        WHERE user_id = ? 
          AND is_completed = 0 
          AND event_date = CURDATE()
          AND event_time IS NOT NULL
          AND event_time <= ADDTIME(CURTIME(), '00:15:00')
    ");
    $stmt->execute([$user['id']]);
    $reminders = $stmt->fetchAll();

    echo json_encode(['status' => 'success', 'reminders' => $reminders]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
