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

    // Deduplicate by mobile (prevent spamming reload)
    $check = $pdo->prepare('SELECT id FROM leads WHERE mobile=? AND source="website"');
    $check->execute([$mobile]);
    if ($check->fetch()) {
        http_response_code(200);
        exit(json_encode(['success' => true, 'message' => 'Duplicate lead, skipped']));
    }

    // Insert lead into CRM
    $pdo->prepare('
        INSERT INTO leads
            (source, first_name, last_name, mobile, email, preference, lead_type, lead_status, created_at)
        VALUES
            ("website", ?, "", ?, ?, ?, "warm", "sv_pending", NOW())
    ')->execute([
        $firstName,
        $mobile,
        $email,
        $fullPref
    ]);

    $newId = (int)$pdo->lastInsertId();

    // Log the event
    $pdo->prepare('INSERT INTO sync_log (source, rows_synced) VALUES ("website_api", 1)')->execute();

    error_log("Website API Webhook: Lead #{$newId} saved from {$siteName} — {$firstName} ({$mobile})");

    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Lead successfully added to CRM']);
    exit;
}

// Ignore GET requests
http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
exit;
