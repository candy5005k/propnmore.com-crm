<?php
// ============================================================
//  api_website_lead.php — Receives POST data from landing pages
//  Endpoint: https://crm.propnmore.com/api_website_lead.php
//
//  This script accepts leads from Azalea or any other landing
//  page and inserts them directly into the CRM database.
// ============================================================


require_once __DIR__ . '/config.php';

// Allow any of your landing pages to send data here
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody, true);

    // Fallback if sent as form-data instead of JSON
    if (!$payload) {
        $payload = $_POST;
    }

    if (empty($payload['mobile']) || empty($payload['first_name'])) {
        http_response_code(400);
        exit(json_encode(['success' => false, 'message' => 'Invalid payload, missing mobile or name']));
    }

    // Map common fields from website format to CRM format
    $firstName = trim($payload['first_name'] ?? '');
    $mobile    = preg_replace('/[^0-9+]/', '', $payload['mobile'] ?? '');
    $email     = trim($payload['email'] ?? '');
    $pref      = trim($payload['preference'] ?? '');
    $siteName  = trim($payload['site_name'] ?? 'Website');
    $source    = 'website';

    // Enhance preference field with website identity and form type if provided
    $formType = trim($payload['form_type'] ?? 'enquiry');
    $message  = trim($payload['message'] ?? '');
    
    $fullPref = "Site: " . strtoupper($siteName) . " | Form: " . $formType;
    if ($pref) {
        $fullPref .= " | Int: " . $pref;
    }
    if ($message) {
        $fullPref .= " | Msg: " . substr($message, 0, 100);
    }

    $pdo = db();

    // Deduplicate by mobile — only block if same number submitted within last 10 minutes
    // This prevents spam on reload but allows genuine re-enquiries after the window
    $check = $pdo->prepare('SELECT id FROM leads WHERE mobile=? AND source="website" AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)');
    $check->execute([$mobile]);
    if ($check->fetch()) {
        http_response_code(200);
        exit(json_encode(['success' => true, 'message' => 'Duplicate lead (within 10min window), skipped']));
    }

    // Ensure Project ID exists based on Site Name (case-insensitive lookup)
    // For Azalea, siteName = 'azalea' — matches 'Azalea', 'AZALEA', etc.
    $projCheck = $pdo->prepare('SELECT id FROM projects WHERE LOWER(name) = LOWER(?)');
    $projCheck->execute([$siteName]);
    $project = $projCheck->fetch();
    
    if ($project) {
        $projectId = $project['id'];
    } else {
        // Create project if it doesn't exist yet
        $pdo->prepare('INSERT INTO projects (name) VALUES (?)')->execute([ucfirst($siteName)]);
        $projectId = (int)$pdo->lastInsertId();
        error_log("Website API: Auto-created project '{$siteName}' with ID {$projectId}");
    }

    // Insert lead into CRM
    $pdo->prepare('
        INSERT INTO leads
            (source, project_id, first_name, last_name, mobile, email, preference, lead_type, lead_status, created_at)
        VALUES
            ("website", ?, ?, "", ?, ?, ?, "warm", "sv_pending", NOW())
    ')->execute([
        $projectId,
        $firstName,
        $mobile,
        $email,
        $fullPref
    ]);

    $newId = (int)$pdo->lastInsertId();

    if ($newId === 0) {
        // Insert failed silently — log and return error
        error_log("Website API ERROR: INSERT failed for {$firstName} ({$mobile}) from {$siteName}");
        http_response_code(500);
        exit(json_encode(['success' => false, 'message' => 'DB insert failed']));
    }

    // Log the event
    try {
        $pdo->prepare('INSERT INTO sync_log (source, rows_synced) VALUES ("website_api", 1)')->execute();
    } catch (\Exception $e) {
        error_log('Website API: sync_log insert failed (table may not exist): ' . $e->getMessage());
    }

    error_log("Website API Webhook: Lead #{$newId} saved from {$siteName} — {$firstName} ({$mobile})");

    // Send Instant Lead Notification to Admin
    $notifyEmail = 'cnpgreenfield@gmail.com';
    $subject = "🔥 CRM Alert: New Lead from " . strtoupper($siteName);
    $htmlBody = "
    <div style='font-family:sans-serif;max-width:500px;margin:20px auto;border:1px solid #e5e7eb;border-radius:8px;padding:20px;'>
        <h2 style='margin-top:0'>New Lead via Website</h2>
        <p>A new lead has just entered the CRM.</p>
        <div style='background:#f1f5f9;padding:15px;border-radius:6px;'>
            <strong>Name:</strong> {$firstName}<br>
            <strong>Mobile:</strong> {$mobile}<br>
            <strong>Email:</strong> " . ($email ?: 'N/A') . "<br>
            <strong>Source:</strong> " . strtoupper($siteName) . "<br>
            <strong>Preferences:</strong> {$fullPref}
        </div>
        <p style='margin-top:20px;'><a href='https://crm.propnmore.com' style='background:#000;color:#fff;padding:10px 15px;text-decoration:none;border-radius:4px;'>Open CRM</a></p>
    </div>";
    
    sendMail($notifyEmail, $subject, $htmlBody);

    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Lead successfully added to CRM']);
    exit;
}

// Ignore GET requests
http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
exit;
