<?php
/**
 * Google OAuth2 Authorization Flow (PHP-based)
 * 
 * This page handles the complete OAuth2 flow for Google Sheets API access.
 * No Python needed — works entirely through the browser.
 * 
 * Flow:
 *   1. Admin clicks "Authorize Google Account"
 *   2. Redirects to Google consent screen
 *   3. Google redirects back with auth code
 *   4. This page exchanges code for access_token + refresh_token
 *   5. Saves token.json to the server
 */

require_once __DIR__ . '/config.php';
$user = requireAuth('admin');
$pageTitle = 'Google Authorization';

$clientId     = GOOGLE_CLIENT_ID;
$clientSecret = GOOGLE_CLIENT_SECRET;
$tokenFile    = GOOGLE_TOKEN_FILE;
// Must match EXACTLY what's in Google Cloud Console → Authorized redirect URIs
$redirectUri   = BASE_URL . '/google_auth.php';

// Scopes needed for Google Sheets read access
$scopes = 'https://www.googleapis.com/auth/spreadsheets.readonly';

$message = '';
$messageType = ''; // 'success' or 'error'
$tokenExists = file_exists($tokenFile);
$tokenData = null;

if ($tokenExists) {
    $tokenData = json_decode(file_get_contents($tokenFile), true);
}

// ── Step 1: Handle the callback from Google ──────────────────────────────
if (isset($_GET['code'])) {
    $code = $_GET['code'];
    
    // Exchange authorization code for tokens
    $postData = [
        'code'          => $code,
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri'  => $redirectUri,
        'grant_type'    => 'authorization_code',
    ];
    
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($postData),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if ($httpCode === 200 && isset($data['access_token'])) {
        // Build token.json structure (compatible with existing SheetsAPI class)
        $tokenPayload = [
            'token'         => $data['access_token'],
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? '',
            'token_uri'     => 'https://oauth2.googleapis.com/token',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'scopes'        => [$scopes],
            'expiry'        => date('c', time() + ($data['expires_in'] ?? 3600)),
        ];
        
        if (file_put_contents($tokenFile, json_encode($tokenPayload, JSON_PRETTY_PRINT))) {
            $message = '✅ Google account authorized successfully! token.json has been saved. You can now sync Google Sheets.';
            $messageType = 'success';
            $tokenExists = true;
            $tokenData = $tokenPayload;
        } else {
            $message = '❌ Authorization succeeded but failed to write token.json. Check file permissions on the server.';
            $messageType = 'error';
        }
    } else {
        $errorMsg = $data['error_description'] ?? $data['error'] ?? 'Unknown error';
        $message = "❌ Failed to exchange auth code: {$errorMsg}";
        $messageType = 'error';
    }
}

// ── Handle Google errors ─────────────────────────────────────────────────
if (isset($_GET['error'])) {
    $message = '❌ Google authorization denied: ' . htmlspecialchars($_GET['error']);
    $messageType = 'error';
}

// ── Handle token revocation / disconnect ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'revoke' && $tokenExists) {
        if (unlink($tokenFile)) {
            $message = '🔓 Google account disconnected. token.json has been deleted.';
            $messageType = 'success';
            $tokenExists = false;
            $tokenData = null;
        } else {
            $message = '❌ Failed to delete token.json. Check file permissions.';
            $messageType = 'error';
        }
    }
    
    if ($_POST['action'] === 'test' && $tokenExists) {
        // Quick test: try to read a sheet
        try {
            require_once __DIR__ . '/includes/sheets.php';
            $api = new SheetsAPI();
            $testRows = $api->getRange(SHEET_WEBSITE_ID, SHEET_WEBSITE_TAB . '!A1:A2');
            $message = '✅ Connection test successful! Google Sheets API is working. Got ' . count($testRows) . ' rows from test range.';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = '❌ Connection test failed: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// ── Build the Google OAuth URL ───────────────────────────────────────────
$authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id'     => $clientId,
    'redirect_uri'  => $redirectUri,
    'response_type' => 'code',
    'scope'         => $scopes,
    'access_type'   => 'offline',      // needed for refresh_token
    'prompt'        => 'consent',       // force consent to get refresh_token
    'state'         => csrf(),          // CSRF protection
]);

include __DIR__ . '/includes/header.php';
?>

<div style="max-width:650px">

  <h2 style="font-family:'DM Serif Display',serif;font-size:22px;margin-bottom:8px">🔐 Google Authorization</h2>
  <p style="color:var(--text2);font-size:13px;margin-bottom:24px;line-height:1.6">
    Connect your Google account to enable Google Sheets sync. This authorizes read-only access to your spreadsheets.
  </p>

  <?php if ($message): ?>
    <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'error' ?>" style="margin-bottom:20px">
      <?= htmlspecialchars($message) ?>
    </div>
  <?php endif; ?>

  <!-- Connection Status Card -->
  <div class="card" style="border-color:<?= $tokenExists ? 'rgba(74,222,128,0.3)' : 'rgba(239,68,68,0.3)' ?>">
    <div style="display:flex;align-items:center;gap:14px;margin-bottom:20px">
      <div style="width:48px;height:48px;border-radius:12px;background:<?= $tokenExists ? 'rgba(74,222,128,0.1)' : 'rgba(239,68,68,0.1)' ?>;display:flex;align-items:center;justify-content:center;font-size:22px">
        <?= $tokenExists ? '✅' : '❌' ?>
      </div>
      <div>
        <div style="font-size:16px;font-weight:700;color:<?= $tokenExists ? '#4ade80' : '#ef4444' ?>">
          <?= $tokenExists ? 'Connected' : 'Not Connected' ?>
        </div>
        <div style="font-size:12px;color:var(--text2);margin-top:2px">
          <?php if ($tokenExists && $tokenData): ?>
            Token expires: <?= date('d M Y, H:i', strtotime($tokenData['expiry'] ?? 'now')) ?>
            <?php if (!empty($tokenData['refresh_token'])): ?>
              <span style="color:#4ade80;margin-left:8px">• Auto-refresh enabled</span>
            <?php endif; ?>
          <?php else: ?>
            No token.json found on server
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if (!$tokenExists): ?>
      <!-- Authorize Button -->
      <a href="<?= htmlspecialchars($authUrl) ?>" 
         style="display:block;text-align:center;background:linear-gradient(135deg,#4285f4,#34a853);color:#fff;padding:14px 24px;border-radius:10px;font-weight:700;font-size:14px;text-decoration:none;transition:opacity 0.2s"
         onmouseover="this.style.opacity='0.9'" onmouseout="this.style.opacity='1'">
        🔗 Authorize Google Account
      </a>
      <p style="color:var(--text2);font-size:11px;margin-top:12px;text-align:center">
        You'll be redirected to Google to sign in and grant read-only Sheets access.
      </p>
    <?php else: ?>
      <!-- Connected: Test & Disconnect options -->
      <div style="display:flex;gap:12px">
        <form method="POST" style="flex:1">
          <button type="submit" name="action" value="test" class="btn btn-primary" style="width:100%">
            🧪 Test Connection
          </button>
        </form>
        <form method="POST" style="flex:1" onsubmit="return confirm('Disconnect Google account? You will need to re-authorize.')">
          <button type="submit" name="action" value="revoke" class="btn" style="width:100%;background:rgba(239,68,68,0.1);color:#ef4444;border:1px solid rgba(239,68,68,0.3)">
            🔓 Disconnect
          </button>
        </form>
      </div>
    <?php endif; ?>
  </div>

  <!-- Setup Instructions -->
  <div class="card" style="margin-top:20px">
    <div style="font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--text2);margin-bottom:16px">Setup Instructions</div>
    
    <div style="display:flex;flex-direction:column;gap:16px;font-size:13px;color:var(--text);line-height:1.7">
      <div style="display:flex;gap:12px">
        <span style="width:24px;height:24px;border-radius:50%;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0">1</span>
        <div>
          Go to <a href="https://console.cloud.google.com/apis/credentials" target="_blank" style="color:var(--accent)">Google Cloud Console → Credentials</a>
        </div>
      </div>
      <div style="display:flex;gap:12px">
        <span style="width:24px;height:24px;border-radius:50%;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0">2</span>
        <div>
          Click on your OAuth Client (e.g. <strong>"cnp lead"</strong>)
        </div>
      </div>
      <div style="display:flex;gap:12px">
        <span style="width:24px;height:24px;border-radius:50%;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0">3</span>
        <div>
          Under <strong>"Authorized redirect URIs"</strong>, add:<br>
          <code style="background:var(--bg);border:1px solid var(--border);padding:4px 10px;border-radius:6px;font-size:12px;display:inline-block;margin-top:4px;user-select:all"><?= htmlspecialchars($redirectUri) ?></code>
        </div>
      </div>
      <div style="display:flex;gap:12px">
        <span style="width:24px;height:24px;border-radius:50%;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0">4</span>
        <div>
          Click <strong>Save</strong>, then come back here and click <strong>"Authorize Google Account"</strong>
        </div>
      </div>
    </div>

    <div style="margin-top:16px;padding:12px 14px;background:rgba(251,191,36,0.08);border:1px solid rgba(251,191,36,0.2);border-radius:8px;font-size:12px;color:#fbbf24;line-height:1.6">
      ⚠️ <strong>Important:</strong> The OAuth Client type must be <strong>"Web application"</strong> (not Desktop). 
      If your current client is Desktop type, create a new one: 
      <strong>+ Create Credentials → OAuth Client ID → Web application</strong>
    </div>
  </div>

  <!-- Current Configuration -->
  <div class="card" style="margin-top:20px">
    <div style="font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--text2);margin-bottom:12px">Current Configuration</div>
    <table style="width:100%;font-size:12px">
      <tr>
        <td style="padding:6px 0;color:var(--text2);width:140px">Client ID</td>
        <td style="padding:6px 0;font-family:monospace;font-size:11px"><?= htmlspecialchars(substr($clientId, 0, 20)) ?>…</td>
      </tr>
      <tr>
        <td style="padding:6px 0;color:var(--text2)">Redirect URI</td>
        <td style="padding:6px 0;font-family:monospace;font-size:11px"><?= htmlspecialchars($redirectUri) ?></td>
      </tr>
      <tr>
        <td style="padding:6px 0;color:var(--text2)">Token File</td>
        <td style="padding:6px 0;font-family:monospace;font-size:11px"><?= htmlspecialchars(GOOGLE_TOKEN_FILE) ?></td>
      </tr>
      <tr>
        <td style="padding:6px 0;color:var(--text2)">Scope</td>
        <td style="padding:6px 0;font-family:monospace;font-size:11px">spreadsheets.readonly</td>
      </tr>
    </table>
  </div>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
