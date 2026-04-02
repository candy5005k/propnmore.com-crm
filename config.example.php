<?php
// ============================================================
//  CRM LSR LEADS 2026 — Configuration TEMPLATE
//  Copy this file to config.php and fill in real values
//  config.php is in .gitignore — never commit real credentials!
// ============================================================

// PHPMailer autoload (install via: composer require phpmailer/phpmailer)
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

define('DB_HOST', 'localhost');
define('DB_NAME', 'your_db_name');         // e.g. u389532358_crm_lsr
define('DB_USER', 'your_db_user');         // e.g. u389532358_crm_admin
define('DB_PASS', 'your_db_password');     // ← DB password
define('DB_CHARSET', 'utf8mb4');

// Admin OTP email (all OTPs go here)
define('OTP_EMAIL', 'your@gmail.com');
define('OTP_FROM',  'noreply@yourdomain.com');
define('SITE_NAME', 'CRM LSR LEADS 2026');
define('BASE_URL',  'https://crm.yourdomain.com');   // no trailing slash

// Google Sheets API
define('GOOGLE_CLIENT_ID',     'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'YOUR_GOOGLE_CLIENT_SECRET');
define('GOOGLE_TOKEN_FILE',    __DIR__ . '/token.json');
define('GOOGLE_CREDS_FILE',    __DIR__ . '/credentials.json');

// Google Sheet IDs and tab names
define('SHEET_WEBSITE_ID',  'YOUR_GOOGLE_SHEET_ID');
define('SHEET_WEBSITE_TAB', 'CNP Leads');

define('SHEET_META_ID',  'YOUR_GOOGLE_SHEET_ID');
define('SHEET_META_TAB', 'LSR Leads 2026');

define('SHEET_GOOGLE_ID',  'YOUR_GOOGLE_SHEET_ID');
define('SHEET_GOOGLE_TAB', 'Google Leads');

define('SHEET_SALES_TAB', 'Sales Team');

// File upload
define('UPLOAD_DIR',      __DIR__ . '/assets/uploads/audio/');
define('UPLOAD_MAX_MB',   50);

// Session
define('SESSION_NAME',    'crm_lsr_sess');
define('SESSION_HOURS',   8);

// SMTP — create noreply@yourdomain.com in Hostinger hPanel → Emails
define('SMTP_HOST', 'mail.yourdomain.com');
define('SMTP_USER', 'noreply@yourdomain.com');
define('SMTP_PASS', 'your_email_password');
define('SMTP_PORT', 587);

// ============================================================
//  Bootstrap — DO NOT EDIT BELOW THIS LINE
// ============================================================
session_name(SESSION_NAME);
session_start();

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function auth(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    $st = db()->prepare('SELECT id,name,email,role FROM users WHERE id=? AND is_active=1');
    $st->execute([$_SESSION['user_id']]);
    return $st->fetch() ?: null;
}

function requireAuth(string $role = ''): array {
    $user = auth();
    if (!$user) { header('Location:' . BASE_URL . '/login.php'); exit; }
    if ($role && $user['role'] !== $role && $user['role'] !== 'admin') {
        header('Location:' . BASE_URL . '/index.php'); exit;
    }
    return $user;
}

function isAdmin(): bool {
    $u = auth();
    return $u && $u['role'] === 'admin';
}

function json(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function verifyCsrf(): void {
    $t = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $t)) {
        json(['error' => 'Invalid CSRF token'], 403);
    }
}

function sendMail(string $to, string $subject, string $body): bool {
    if (class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = SMTP_PORT;
            $mail->setFrom(OTP_FROM, SITE_NAME);
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->send();
            return true;
        } catch (\Exception $e) {
            error_log('Mailer Error: ' . $e->getMessage());
            return false;
        }
    }
    $headers  = "From: " . SITE_NAME . " <" . OTP_FROM . ">\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    return mail($to, $subject, $body, $headers);
}

function createNotification(int $userId, string $title, string $message = '', ?int $leadId = null, string $type = 'lead_assigned'): void {
    db()->prepare('INSERT INTO notifications (user_id, type, title, message, lead_id) VALUES (?,?,?,?,?)')
        ->execute([$userId, $type, $title, $message, $leadId]);
}

function getUnreadNotificationCount(int $userId): int {
    $st = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0');
    $st->execute([$userId]);
    return (int)$st->fetchColumn();
}

function getNotifications(int $userId, int $limit = 20): array {
    $st = db()->prepare('SELECT n.*, l.first_name, l.last_name FROM notifications n LEFT JOIN leads l ON n.lead_id=l.id WHERE n.user_id=? ORDER BY n.created_at DESC LIMIT ?');
    $st->execute([$userId, $limit]);
    return $st->fetchAll();
}

function markNotificationRead(int $notifId, int $userId): void {
    db()->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?')->execute([$notifId, $userId]);
}

function markAllNotificationsRead(int $userId): void {
    db()->prepare('UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0')->execute([$userId]);
}
