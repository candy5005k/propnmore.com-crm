<?php
require_once __DIR__ . '/config.php';

// Already logged in → redirect
if (auth()) { header('Location: ' . BASE_URL . '/index.php'); exit; }

$step    = $_GET['step'] ?? 'email';
$error   = '';
$success = '';

// ── Step 1: submit email ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'])) {
    $email = strtolower(trim($_POST['email']));
    $s = db()->prepare('SELECT id, role, is_active FROM users WHERE email=?');
    $s->execute([$email]);
    $user = $s->fetch();

    if (!$user) {
        $error = 'No account found with this email.';
    } elseif (!$user['is_active']) {
        $error = 'Your account is pending activation by an admin.';
    } elseif ($user['role'] === 'admin') {
        // Send OTP
        $otp     = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = date('Y-m-d H:i:s', time() + 600); // 10 min
        db()->prepare('INSERT INTO otp_tokens (email, otp, expires_at) VALUES (?,?,?)')->execute([$email, $otp, $expires]);

        $body = "<h2 style='font-family:sans-serif'>Your OTP</h2>
                 <p style='font-size:32px;letter-spacing:8px;font-weight:bold'>{$otp}</p>
                 <p>Valid for 10 minutes. Do not share.</p>";
        sendMail(OTP_EMAIL, 'CRM Login OTP – ' . $email, $body);

        $_SESSION['otp_email'] = $email;
        header('Location: ' . BASE_URL . '/login.php?step=otp');
        exit;
    } else {
        // Sales manager — password login
        $_SESSION['login_email'] = $email;
        header('Location: ' . BASE_URL . '/login.php?step=password');
        exit;
    }
}

// ── Step 2a: OTP verify ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['otp'])) {
    $email = $_SESSION['otp_email'] ?? '';
    $otp   = trim($_POST['otp']);

    $s = db()->prepare('SELECT id FROM otp_tokens WHERE email=? AND otp=? AND used=0 AND expires_at > NOW() ORDER BY id DESC LIMIT 1');
    $s->execute([$email, $otp]);
    $token = $s->fetch();

    if (!$token) {
        $error = 'Invalid or expired OTP. Please try again.';
        $step  = 'otp';
    } else {
        db()->prepare('UPDATE otp_tokens SET used=1 WHERE id=?')->execute([$token['id']]);
        $s = db()->prepare('SELECT id FROM users WHERE email=?');
        $s->execute([$email]);
        $u = $s->fetch();
        $_SESSION['user_id'] = $u['id'];
        unset($_SESSION['otp_email']);
        header('Location: ' . BASE_URL . '/index.php'); exit;
    }
}

// ── Step 2b: Password verify ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $email = $_SESSION['login_email'] ?? '';
    $pass  = $_POST['password'];

    $s = db()->prepare('SELECT id, password FROM users WHERE email=? AND is_active=1');
    $s->execute([$email]);
    $u = $s->fetch();

    if ($u && password_verify($pass, $u['password'])) {
        $_SESSION['user_id'] = $u['id'];
        unset($_SESSION['login_email']);
        header('Location: ' . BASE_URL . '/index.php'); exit;
    } else {
        $error = 'Incorrect password.';
        $step  = 'password';
    }
}

// Re-read step from session context
if ($step === 'email' && isset($_SESSION['otp_email'])) $step = 'otp';
if ($step === 'email' && isset($_SESSION['login_email'])) $step = 'password';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login — CRM LSR LEADS 2026</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:      #0a0e17;
    --surface: #111827;
    --border:  #1f2d45;
    --accent:  #c9a96e;
    --accent2: #e8c98a;
    --text:    #e8eaf0;
    --muted:   #6b7a99;
    --danger:  #ef4444;
    --success: #22c55e;
  }

  body {
    background: var(--bg);
    color: var(--text);
    font-family: 'DM Sans', sans-serif;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    overflow: hidden;
  }

  /* Ambient glow */
  body::before {
    content: '';
    position: fixed;
    width: 600px; height: 600px;
    background: radial-gradient(circle, rgba(201,169,110,0.07) 0%, transparent 70%);
    top: -100px; right: -100px;
    pointer-events: none;
  }
  body::after {
    content: '';
    position: fixed;
    width: 400px; height: 400px;
    background: radial-gradient(circle, rgba(59,130,246,0.05) 0%, transparent 70%);
    bottom: -50px; left: -50px;
    pointer-events: none;
  }

  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 48px 44px;
    width: 100%;
    max-width: 420px;
    position: relative;
    animation: fadeUp 0.5s ease both;
  }

  @keyframes fadeUp {
    from { opacity:0; transform: translateY(20px); }
    to   { opacity:1; transform: translateY(0); }
  }

  .logo-wrap {
    text-align: center;
    margin-bottom: 36px;
  }
  .logo-icon {
    width: 52px; height: 52px;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    border-radius: 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    margin-bottom: 12px;
  }
  .logo-name {
    font-family: 'DM Serif Display', serif;
    font-size: 20px;
    color: var(--accent);
    letter-spacing: 0.5px;
  }
  .logo-sub {
    font-size: 11px;
    color: var(--muted);
    letter-spacing: 2px;
    text-transform: uppercase;
    margin-top: 2px;
  }

  h2 {
    font-family: 'DM Serif Display', serif;
    font-size: 26px;
    font-weight: 400;
    margin-bottom: 6px;
  }
  .sub {
    color: var(--muted);
    font-size: 13px;
    margin-bottom: 28px;
    line-height: 1.5;
  }

  label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 8px;
  }
  input[type=email], input[type=text], input[type=password] {
    width: 100%;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 13px 16px;
    font-size: 15px;
    color: var(--text);
    font-family: 'DM Sans', sans-serif;
    outline: none;
    transition: border-color 0.2s;
    margin-bottom: 20px;
  }
  input:focus { border-color: var(--accent); }

  .otp-row {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
  }
  .otp-row input {
    width: 48px; height: 56px;
    text-align: center;
    font-size: 22px;
    font-weight: 600;
    padding: 0;
    margin-bottom: 0;
  }

  .btn {
    width: 100%;
    padding: 14px;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    color: #0a0e17;
    border: none;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 700;
    font-family: 'DM Sans', sans-serif;
    cursor: pointer;
    letter-spacing: 0.5px;
    transition: opacity 0.2s, transform 0.1s;
  }
  .btn:hover   { opacity: 0.9; }
  .btn:active  { transform: scale(0.98); }

  .alert {
    padding: 12px 16px;
    border-radius: 10px;
    font-size: 13px;
    margin-bottom: 20px;
  }
  .alert-error   { background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #fca5a5; }
  .alert-success { background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.3); color: #86efac; }

  .back-link {
    display: inline-block;
    margin-top: 18px;
    font-size: 13px;
    color: var(--muted);
    text-decoration: none;
    transition: color 0.2s;
  }
  .back-link:hover { color: var(--accent); }

  .register-link {
    text-align: center;
    margin-top: 24px;
    font-size: 13px;
    color: var(--muted);
  }
  .register-link a { color: var(--accent); text-decoration: none; }
</style>
</head>
<body>
<div class="card">

  <div class="logo-wrap">
    <div class="logo-icon">🏢</div>
    <div class="logo-name">LSR LEADS</div>
    <div class="logo-sub">CRM 2026 · Propnmore</div>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>

  <?php if ($step === 'email'): ?>
    <h2>Welcome back</h2>
    <p class="sub">Enter your work email to continue.</p>
    <form method="POST">
      <label>Email Address</label>
      <input type="email" name="email" placeholder="you@propnmore.com" required autofocus>
      <button type="submit" class="btn">Continue →</button>
    </form>

  <?php elseif ($step === 'otp'): ?>
    <h2>Check your email</h2>
    <p class="sub">A 6-digit OTP was sent to <strong><?= htmlspecialchars(OTP_EMAIL) ?></strong>. Enter it below.</p>
    <form method="POST" id="otpForm">
      <label>One-Time Password</label>
      <div class="otp-row" id="otpBoxes">
        <?php for ($i=0;$i<6;$i++): ?>
          <input type="text" maxlength="1" inputmode="numeric" pattern="\d" class="otp-digit" name="otp_digit_<?=$i?>">
        <?php endfor; ?>
      </div>
      <input type="hidden" name="otp" id="otpHidden">
      <button type="submit" class="btn">Verify OTP</button>
    </form>
    <a class="back-link" href="<?= BASE_URL ?>/login.php">← Use different email</a>

  <?php elseif ($step === 'password'): ?>
    <h2>Enter password</h2>
    <p class="sub">Logging in as <strong><?= htmlspecialchars($_SESSION['login_email'] ?? '') ?></strong>.</p>
    <form method="POST">
      <label>Password</label>
      <input type="password" name="password" required autofocus>
      <button type="submit" class="btn">Sign In →</button>
    </form>
    <a class="back-link" href="<?= BASE_URL ?>/login.php">← Use different email</a>
  <?php endif; ?>

  <div class="register-link">
    New sales manager? <a href="<?= BASE_URL ?>/register.php">Request access</a>
  </div>
</div>

<script>
// OTP box auto-advance
const digits = document.querySelectorAll('.otp-digit');
digits.forEach((el, i) => {
  el.addEventListener('input', () => {
    if (el.value && i < digits.length - 1) digits[i+1].focus();
  });
  el.addEventListener('keydown', e => {
    if (e.key === 'Backspace' && !el.value && i > 0) digits[i-1].focus();
  });
});

const form = document.getElementById('otpForm');
if (form) {
  form.addEventListener('submit', () => {
    document.getElementById('otpHidden').value = [...digits].map(d => d.value).join('');
  });
}
</script>
</body>
</html>
