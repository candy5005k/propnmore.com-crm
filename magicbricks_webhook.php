<?php
// ============================================================
//  magicbricks_webhook.php — MagicBricks Lead Receiver
//  URL: https://crm.propnmore.com/magicbricks_webhook.php
// ============================================================

require_once __DIR__ . '/config.php';

// 1. Log Raw Payload for Troubleshooting
$rawBody = file_get_contents('php://input');
$rawPost = $_POST;
error_log("MagicBricks Webhook RAW: " . $rawBody);
if (!empty($rawPost)) {
    error_log("MagicBricks Webhook POST: " . print_r($rawPost, true));
}

// 2. Parse Data
// MagicBricks typically sends form-data via POST or JSON.
$leadData = json_decode($rawBody, true);
if (!$leadData) {
    $leadData = $rawPost;
}

if (empty($leadData)) {
    http_response_code(400);
    exit('Empty Payload');
}

// 3. Map Fields (Adjust based on MagicBricks keys)
$firstName = $leadData['LeadName']   ?? $leadData['name']    ?? $leadData['full_name'] ?? 'Unknown MB';
$mobile    = $leadData['LeadMobile'] ?? $leadData['mobile']  ?? $leadData['phone']     ?? '';
$email     = $leadData['LeadEmail']  ?? $leadData['email']   ?? '';
$project   = $leadData['ProjectName'] ?? $leadData['project'] ?? 'MagicBricks';
$message   = $leadData['LeadMsg']    ?? $leadData['message'] ?? $leadData['LeadReq']   ?? '';

// Clean mobile
$mobile = preg_replace('/[^0-9+]/', '', $mobile);

if (!$mobile) {
    error_log("MagicBricks Webhook: Missing mobile. Skipped.");
    http_response_code(200);
    exit('Missing Mobile');
}

$pdo = db();

// 4. Dedup (within 5 minutes)
$check = $pdo->prepare('SELECT id FROM leads WHERE mobile=? AND source="manual" AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)');
$check->execute([$mobile]);
if ($check->fetch()) {
    error_log("MagicBricks Webhook: Duplicate skipped for {$mobile}");
    http_response_code(200);
    exit('Duplicate');
}

// 5. Insert Lead
try {
    // Note: We'll set source to 'manual' or a new 'magicbricks' if you update ENUM.
    // Looking at schema_v2.sql, source is ENUM('website','meta','google','manual').
    // I will use 'manual' for now or handle it via a new enum if column allows it.
    // For now, I will use 'manual' with a specific prefix in preference/notes.

    $notes = "Source: MagicBricks\nProject: {$project}\nMessage: {$message}";

    $stmt = $pdo->prepare("
        INSERT INTO leads
            (source, first_name, mobile, email, project_name, notes, lead_type, lead_status, created_at)
        VALUES
            ('manual', ?, ?, ?, ?, ?, 'warm', 'sv_pending', NOW())
    ");
    $stmt->execute([
        $firstName,
        $mobile,
        $email,
        $project,
        $notes
    ]);

    $newId = $pdo->lastInsertId();
    error_log("MagicBricks Webhook: ✅ Lead #{$newId} saved — {$firstName} ({$mobile})");

    // 6. Send Alert Notification (WhatsApp/SMS)
    // We send this to the Admin who manages lead distributions.
    if (defined('OTP_EMAIL')) {
        $alertMsg = "🎯 New MagicBricks Lead!\nName: {$firstName}\nMobile: {$mobile}\nProject: {$project}\nCheck CRM: https://crm.propnmore.com/lead_detail.php?id={$newId}";

        // Send to OTP_EMAIL (as info) and try to send to a mobile if defined.
        if (defined('NOTIFICATION_MOBILE') && NOTIFICATION_MOBILE) {
            sendAlert(NOTIFICATION_MOBILE, $alertMsg);
        }

        // Also notify via In-App Notification to all Admins
        $admins = $pdo->query("SELECT id FROM users WHERE role='admin'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $adminId) {
            createNotification($adminId, "New MagicBricks Lead", "Lead from {$firstName} for {$project}", $newId);
        }
    }

    http_response_code(200);
    echo "OK - ID: $newId";

} catch (Exception $e) {
    error_log("MagicBricks Webhook ERROR: " . $e->getMessage());
    http_response_code(500);
    echo "Error";
}
