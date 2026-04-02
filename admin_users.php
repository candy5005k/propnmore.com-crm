<?php
require_once __DIR__ . '/config.php';
$user = requireAuth('admin');
$pageTitle = 'User Management';

$pdo   = db();
$error = $success = '';

// Activate / Deactivate
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['toggle_id'])) {
    $tid    = (int)$_POST['toggle_id'];
    $active = (int)$_POST['toggle_val'];
    $pdo->prepare('UPDATE users SET is_active=? WHERE id=?')->execute([$active, $tid]);
    $success = $active ? 'User activated.' : 'User deactivated.';
}

// Delete user
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_id'])) {
    $did = (int)$_POST['delete_id'];
    if ($did !== $user['id']) {
        $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$did]);
        $success = 'User deleted.';
    }
}

// Create user (admin creating sales manager)
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='create') {
    $name  = trim($_POST['name']  ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pass  = $_POST['password'] ?? '';
    $role  = $_POST['role'] === 'admin' ? 'admin' : 'sales_manager';

    if (!$name || !$email || !$pass) {
        $error = 'All fields required.';
    } elseif (strlen($pass) < 8) {
        $error = 'Password must be 8+ characters.';
    } else {
        $s = $pdo->prepare('SELECT id FROM users WHERE email=?');
        $s->execute([$email]);
        if ($s->fetch()) {
            $error = 'Email already exists.';
        } else {
            $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost'=>12]);
            $pdo->prepare('INSERT INTO users (name,email,password,role,is_active) VALUES (?,?,?,?,1)')
                ->execute([$name, $email, $hash, $role]);
            $success = 'User created and activated.';
        }
    }
}

// Edit permissions (reset password)
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='reset_pw') {
    $uid  = (int)$_POST['uid'];
    $pass = $_POST['new_password'] ?? '';
    if (strlen($pass) >= 8) {
        $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost'=>12]);
        $pdo->prepare('UPDATE users SET password=? WHERE id=?')->execute([$hash, $uid]);
        $success = 'Password updated.';
    } else {
        $error = 'Password must be 8+ characters.';
    }
}

$users = $pdo->query('SELECT u.*,
    (SELECT COUNT(*) FROM leads l WHERE l.assigned_to=u.id) AS lead_count
    FROM users u ORDER BY u.role ASC, u.name ASC')->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px">
  <h2 style="font-family:'DM Serif Display',serif;font-size:22px">User Management</h2>
  <button class="btn btn-primary" onclick="openModal('createModal')">➕ Add User</button>
</div>

<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<div class="tbl-wrap">
  <table>
    <thead>
      <tr>
        <th>Name</th>
        <th>Email</th>
        <th>Role</th>
        <th>Status</th>
        <th>Leads</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td>
          <div style="display:flex;align-items:center;gap:10px">
            <div style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#0a0e17;flex-shrink:0">
              <?= strtoupper(substr($u['name'],0,1)) ?>
            </div>
            <strong><?= htmlspecialchars($u['name']) ?></strong>
          </div>
        </td>
        <td style="font-size:13px;color:var(--text2)"><?= htmlspecialchars($u['email']) ?></td>
        <td>
          <span class="badge <?= $u['role']==='admin'?'badge-hot':'badge-cold' ?>">
            <?= $u['role']==='admin'?'🛡 Admin':'👤 Sales Manager' ?>
          </span>
        </td>
        <td>
          <?php if ($u['is_active']): ?>
            <span class="badge badge-svd">● Active</span>
          <?php else: ?>
            <span class="badge badge-closed">○ Inactive</span>
          <?php endif; ?>
        </td>
        <td>
          <span style="background:rgba(var(--accentRGB),0.12);color:var(--accent);border-radius:20px;padding:2px 10px;font-size:12px;font-weight:600">
            <?= $u['lead_count'] ?> leads
          </span>
        </td>
        <td>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <!-- Toggle active -->
            <?php if ($u['id'] !== $user['id']): ?>
            <form method="POST" style="display:inline">
              <input type="hidden" name="toggle_id" value="<?= $u['id'] ?>">
              <input type="hidden" name="toggle_val" value="<?= $u['is_active']?0:1 ?>">
              <button type="submit" class="btn btn-outline btn-sm">
                <?= $u['is_active']?'Deactivate':'Activate' ?>
              </button>
            </form>
            <?php endif; ?>

            <!-- Reset password -->
            <button class="btn btn-outline btn-sm" onclick="openModal('pw<?= $u['id'] ?>')">🔑 Password</button>

            <!-- Delete -->
            <?php if ($u['id'] !== $user['id']): ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this user?')">
              <input type="hidden" name="delete_id" value="<?= $u['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm">🗑</button>
            </form>
            <?php endif; ?>
          </div>

          <!-- Password reset modal per user -->
          <div class="modal-bg" id="pw<?= $u['id'] ?>">
            <div class="modal" style="max-width:380px">
              <div class="modal-header">
                <span class="modal-title">Reset Password</span>
                <button class="modal-close" onclick="closeModal('pw<?= $u['id'] ?>')">×</button>
              </div>
              <p style="color:var(--text2);font-size:13px;margin-bottom:18px">Setting new password for <strong><?= htmlspecialchars($u['name']) ?></strong></p>
              <form method="POST">
                <input type="hidden" name="action" value="reset_pw">
                <input type="hidden" name="uid" value="<?= $u['id'] ?>">
                <div class="form-group">
                  <label>New Password</label>
                  <input type="password" name="new_password" class="form-control" placeholder="Min 8 characters" required>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%">Update Password</button>
              </form>
            </div>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Create user modal -->
<div class="modal-bg" id="createModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">Add New User</span>
      <button class="modal-close" onclick="closeModal('createModal')">×</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="create">
      <div class="grid-2">
        <div class="form-group">
          <label>Full Name</label>
          <input type="text" name="name" class="form-control" required>
        </div>
        <div class="form-group">
          <label>Role</label>
          <select name="role" class="form-control">
            <option value="sales_manager">Sales Manager</option>
            <option value="admin">Admin</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label>Email</label>
        <input type="email" name="email" class="form-control" required>
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" class="form-control" required placeholder="Min 8 characters">
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%">Create User</button>
    </form>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
