<?php
require_once __DIR__ . '/config.php';
if (auth()) { header('Location: ' . BASE_URL . '/index.php'); exit; }

$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['name']  ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pass  = $_POST['password']  ?? '';
    $pass2 = $_POST['password2'] ?? '';

    if (!$name || !$email || !$pass) {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } elseif (strlen($pass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($pass !== $pass2) {
        $error = 'Passwords do not match.';
    } else {
        $s = db()->prepare('SELECT id FROM users WHERE email=?');
        $s->execute([$email]);
        if ($s->fetch()) {
            $error = 'An account with this email already exists.';
        } else {
            $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
            db()->prepare('INSERT INTO users (name,email,password,role,is_active) VALUES (?,?,?,?,0)')
                ->execute([$name, $email, $hash, 'sales_manager']);

            // Notify admins
            $admins = db()->query("SELECT email FROM users WHERE role='admin'")->fetchAll();
            foreach ($admins as $a) {
                $body = "<p>New sales manager registration request:</p>
                         <p><strong>Name:</strong> {$name}<br>
                         <strong>Email:</strong> {$email}</p>
                         <p>Log in to activate this account.</p>";
                sendMail($a['email'], 'New Registration Request – ' . $name, $body);
            }
            $success = 'Registration successful! An admin will activate your account shortly.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Register — CRM LSR LEADS 2026</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root { --bg:#0a0e17; --surface:#111827; --border:#1f2d45; --accent:#c9a96e; --accent2:#e8c98a; --text:#e8eaf0; --muted:#6b7a99; }
  body { background:var(--bg); color:var(--text); font-family:'DM Sans',sans-serif; min-height:100vh; display:flex; align-items:center; justify-content:center; }
  .card { background:var(--surface); border:1px solid var(--border); border-radius:20px; padding:44px; width:100%; max-width:440px; animation:fadeUp 0.5s ease both; }
  @keyframes fadeUp { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }
  .logo-wrap { text-align:center; margin-bottom:32px; }
  .logo-icon { width:52px; height:52px; background:linear-gradient(135deg,var(--accent),var(--accent2)); border-radius:14px; display:inline-flex; align-items:center; justify-content:center; font-size:22px; margin-bottom:10px; }
  .logo-name { font-family:'DM Serif Display',serif; font-size:18px; color:var(--accent); }
  h2 { font-family:'DM Serif Display',serif; font-size:24px; font-weight:400; margin-bottom:6px; }
  .sub { color:var(--muted); font-size:13px; margin-bottom:24px; }
  label { display:block; font-size:11px; font-weight:600; letter-spacing:1.5px; text-transform:uppercase; color:var(--muted); margin-bottom:7px; }
  input { width:100%; background:var(--bg); border:1px solid var(--border); border-radius:10px; padding:12px 16px; font-size:15px; color:var(--text); font-family:'DM Sans',sans-serif; outline:none; transition:border-color 0.2s; margin-bottom:18px; }
  input:focus { border-color:var(--accent); }
  .btn { width:100%; padding:13px; background:linear-gradient(135deg,var(--accent),var(--accent2)); color:#0a0e17; border:none; border-radius:10px; font-size:15px; font-weight:700; cursor:pointer; }
  .btn:hover { opacity:0.9; }
  .alert { padding:12px 16px; border-radius:10px; font-size:13px; margin-bottom:18px; }
  .alert-error   { background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.3); color:#fca5a5; }
  .alert-success { background:rgba(34,197,94,0.1); border:1px solid rgba(34,197,94,0.3); color:#86efac; }
  .back-link { display:inline-block; margin-top:18px; font-size:13px; color:var(--muted); text-decoration:none; }
  .back-link:hover { color:var(--accent); }
</style>
</head>
<body>
<div class="card">
  <div class="logo-wrap">
    <div class="logo-icon">🏢</div>
    <div class="logo-name">LSR LEADS 2026</div>
  </div>

  <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

  <?php if (!$success): ?>
  <h2>Request Access</h2>
  <p class="sub">Admin approval required to activate your account.</p>
  <form method="POST">
    <label>Full Name</label>
    <input type="text" name="name" required placeholder="Your full name">
    <label>Work Email</label>
    <input type="email" name="email" required placeholder="you@propnmore.com">
    <label>Password</label>
    <input type="password" name="password" required placeholder="Minimum 8 characters">
    <label>Confirm Password</label>
    <input type="password" name="password2" required placeholder="Repeat password">
    <button type="submit" class="btn">Submit Request →</button>
  </form>
  <?php endif; ?>

  <a class="back-link" href="<?= BASE_URL ?>/login.php">← Back to Login</a>
</div>
</body>
</html>
