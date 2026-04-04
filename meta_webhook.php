<?php
// ============================================================
//  meta_webhook.php — Meta Lead Ads Webhook Receiver (v2)
//  URL: https://crm.propnmore.com/meta_webhook.php
//  Captures: Lead details + Campaign + Ad + Form + All Q&A
// ============================================================

require_once __DIR__ . '/config.php';

$VERIFY_TOKEN = defined('META_VERIFY_TOKEN') ? META_VERIFY_TOKEN : 'crm_lsr_meta_verify_2026';
$APP_SECRET   = defined('META_APP_SECRET') ? META_APP_SECRET : '';
$PAGE_TOKEN   = defined('META_PAGE_ACCESS_TOKEN') ? META_PAGE_ACCESS_TOKEN : '';

// ── GET: Webhook Verification ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode      = $_GET['hub_mode']         ?? '';
    $token     = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge']    ?? '';

    if ($mode === 'subscribe' && $token === $VERIFY_TOKEN) {
        http_response_code(200);
        echo $challenge;
        exit;
    }
    http_response_code(403);
    echo 'Verification failed';
    exit;
}

// ── POST: Receive Lead Events ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawBody = file_get_contents('php://input');

    // Log raw payload for debugging
    error_log("Meta Webhook RAW: " . substr($rawBody, 0, 2000));

    // Verify signature
    if ($APP_SECRET) {
        $sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $APP_SECRET);
        if (!hash_equals($expected, $sig)) {
            http_response_code(403);
            error_log('Meta Webhook: Invalid signature');
            exit('Invalid signature');
        }
    }

    $payload = json_decode($rawBody, true);
    if (!$payload || ($payload['object'] ?? '') !== 'page') {
        http_response_code(200);
        exit;
    }

    foreach ($payload['entry'] ?? [] as $entry) {
        foreach ($entry['changes'] ?? [] as $change) {
            if (($change['field'] ?? '') !== 'leadgen') continue;

            $leadgenId = $change['value']['leadgen_id'] ?? null;
            $formId    = $change['value']['form_id']    ?? null;
            $pageId    = $change['value']['page_id']    ?? null;
            $adId      = $change['value']['ad_id']      ?? null;
            $adgroupId = $change['value']['adgroup_id'] ?? null;
            $createdAt = $change['value']['created_time'] ?? null;

            if (!$leadgenId) continue;

            // Fetch full lead data from Graph API
            $leadData = fetchMetaLead($leadgenId, $PAGE_TOKEN);

            // Fetch form name (project/campaign context)
            $formName = fetchFormName($formId, $PAGE_TOKEN);

            // Fetch ad & campaign names
            $adInfo = fetchAdInfo($adId, $PAGE_TOKEN);

            if ($leadData) {
                saveLead($leadData, $pageId, $formId, $formName, $adInfo, $createdAt);
            }
        }
    }

    http_response_code(200);
    echo 'ok';
    exit;
}

// ── Fetch lead details from Graph API ────────────────────────────────
function fetchMetaLead(string $leadgenId, string $token): ?array {
    if (!$token) {
        error_log('Meta Webhook: No PAGE_ACCESS_TOKEN configured');
        return null;
    }
    $url = "https://graph.facebook.com/v21.0/{$leadgenId}?access_token={$token}";
    $data = curlGet($url);
    if (!$data) error_log("Meta Webhook: Failed to fetch lead {$leadgenId}");
    return $data;
}

// ── Fetch form name (tells us which project/campaign) ────────────────
function fetchFormName(?string $formId, string $token): string {
    if (!$formId || !$token) return '';
    $data = curlGet("https://graph.facebook.com/v21.0/{$formId}?fields=name&access_token={$token}");
    return $data['name'] ?? '';
}

// ── Fetch ad + campaign info ─────────────────────────────────────────
function fetchAdInfo(?string $adId, string $token): array {
    $info = ['ad_name' => '', 'campaign_name' => '', 'adset_name' => ''];
    if (!$adId || !$token) return $info;

    $ad = curlGet("https://graph.facebook.com/v21.0/{$adId}?fields=name,campaign{name},adset{name}&access_token={$token}");
    if ($ad) {
        $info['ad_name']       = $ad['name'] ?? '';
        $info['campaign_name'] = $ad['campaign']['name'] ?? '';
        $info['adset_name']    = $ad['adset']['name'] ?? '';
    }
    return $info;
}

// ── cURL helper ──────────────────────────────────────────────────────
function curlGet(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$resp) return null;
    return json_decode($resp, true);
}

// ── Save lead to CRM database ────────────────────────────────────────
function saveLead(array $data, ?string $pageId, ?string $formId, string $formName, array $adInfo, ?string $createdAt): void {
    // Parse field_data into key => value
    $fields = [];
    $allQA = [];
    foreach ($data['field_data'] ?? [] as $field) {
        $key = strtolower(str_replace([' ', '-'], '_', $field['name']));
        $val = $field['values'][0] ?? '';
        $fields[$key] = $val;
        $allQA[] = $field['name'] . ': ' . $val;
    }

    // Map fields
    $firstName = $fields['first_name']  ?? $fields['full_name']  ?? '';
    $lastName  = $fields['last_name']   ?? '';
    $mobile    = $fields['phone_number'] ?? $fields['phone']     ?? $fields['mobile'] ?? '';
    $email     = $fields['email']       ?? '';
    $pref      = $fields['preference']  ?? $fields['select_your_preference'] ?? $fields['interested_in'] ?? $fields['configuration'] ?? '';
    $siteVisit = $fields['when_can_you_come_for_the_site_visit_?'] ?? $fields['site_visit'] ?? $fields['when_can_you_come_for_site_visit'] ?? '';

    // Clean phone
    $mobile = preg_replace('/[^0-9+]/', '', $mobile);

    // Build notes with ALL captured info
    $notes = [];
    if ($adInfo['campaign_name']) $notes[] = "Campaign: " . $adInfo['campaign_name'];
    if ($adInfo['adset_name'])    $notes[] = "Adset: " . $adInfo['adset_name'];
    if ($adInfo['ad_name'])       $notes[] = "Ad: " . $adInfo['ad_name'];
    if ($formName)                $notes[] = "Form: " . $formName;
    if ($pref)                    $notes[] = "Preference: " . $pref;
    if ($siteVisit)               $notes[] = "Site Visit: " . $siteVisit;
    $notes[] = "---";
    $notes[] = "All Answers: " . implode(' | ', $allQA);
    $notesStr = implode("\n", $notes);

    // Derive project from form name
    $projectName = $formName ?: ($adInfo['campaign_name'] ?: 'Meta Lead');

    $pdo = db();

    // Dedup by phone (within 10 min window)
    if ($mobile) {
        $check = $pdo->prepare('SELECT id FROM leads WHERE mobile=? AND source="meta" AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)');
        $check->execute([$mobile]);
        if ($check->fetch()) {
            error_log("Meta Webhook: Duplicate skipped (mobile: {$mobile})");
            return;
        }
    }

    // Insert lead with all info
    $stmt = $pdo->prepare('
        INSERT INTO leads
            (source, source_row_id, first_name, last_name, mobile, email,
             preference, project_name, campaign_name, ad_name, form_name,
             notes, lead_type, lead_status, created_at)
        VALUES
            ("meta", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "warm", "sv_pending", NOW())
    ');

    try {
        $stmt->execute([
            $data['id'] ?? null,
            $firstName,
            $lastName,
            $mobile,
            $email,
            $pref,
            $projectName,
            $adInfo['campaign_name'],
            $adInfo['ad_name'],
            $formName,
            $notesStr,
        ]);
        $newId = (int)$pdo->lastInsertId();

        // Log
        $pdo->prepare('INSERT INTO sync_log (source, rows_synced) VALUES ("meta_webhook", 1)')->execute();
        error_log("Meta Webhook: ✅ Lead #{$newId} saved — {$firstName} {$lastName} ({$mobile}) | Campaign: {$adInfo['campaign_name']}");

    } catch (\PDOException $e) {
        // If columns don't exist yet, fallback to basic insert
        error_log("Meta Webhook: Column error, using fallback — " . $e->getMessage());
        $pdo->prepare('
            INSERT INTO leads
                (source, source_row_id, first_name, last_name, mobile, email, preference, notes, lead_type, lead_status, created_at)
            VALUES ("meta", ?, ?, ?, ?, ?, ?, ?, "warm", "sv_pending", NOW())
        ')->execute([
            $data['id'] ?? null, $firstName, $lastName, $mobile, $email, $pref, $notesStr
        ]);
        $newId = (int)$pdo->lastInsertId();
        error_log("Meta Webhook: ✅ Lead #{$newId} saved (fallback) — {$firstName} ({$mobile})");
    }
}
