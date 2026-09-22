<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('director');
require_once __DIR__ . '/../includes/header.php';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['form'] ?? '') === 'user') {
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $pass = $_POST['password'] ?? '';
        $role = $_POST['role'];
        $active = (int)($_POST['active'] ?? 1);

        if (empty($name) || empty($email)) {
            $err = 'Name and email are required.';
        } else {
            // Check if existing user
            $check = $pdo->prepare("SELECT id FROM users WHERE email=?");
            $check->execute([$email]);
            $existing = $check->fetch();

            if ($existing) {
                if ($pass !== '') {
                    $pdo->prepare("UPDATE users SET name=?, role=?, active=?, password_hash=? WHERE id=?")
                        ->execute([$name, $role, $active, password_hash($pass, PASSWORD_DEFAULT), $existing['id']]);
                } else {
                    $pdo->prepare("UPDATE users SET name=?, role=?, active=? WHERE id=?")
                        ->execute([$name, $role, $active, $existing['id']]);
                }
                audit('update', 'users', $email, null, ['role'=>$role, 'active'=>$active]);
                $ok = "User <strong>" . h($name) . "</strong> updated successfully.";
            } else {
                if (strlen($pass) < 8) {
                    $err = 'Password must be at least 8 characters long.';
                } else {
                    $pdo->prepare("INSERT INTO users (name, email, password_hash, role, active) VALUES (?,?,?,?,?)")
                        ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role, $active]);
                    audit('create', 'users', $email, null, ['role'=>$role]);
                    $ok = "New user <strong>" . h($name) . "</strong> created successfully.";
                }
            }
        }
    }

    if (($_POST['form'] ?? '') === 'toggle') {
        $uid = (int)$_POST['uid'];
        if ($uid !== $u['id']) {
            $pdo->prepare("UPDATE users SET active = 1 - active WHERE id = ?")->execute([$uid]);
            audit('update', 'users', $uid, null, ['status_toggled']);
            $ok = 'User status updated successfully.';
        }
    }
}

$users = $pdo->query("SELECT id, name, email, role, active, last_login, created_at FROM users ORDER BY id")->fetchAll();
?>

<div class="page-head">
  <div>
    <h1>System Settings &amp; User Management</h1>
    <span class="muted">Director Controls · User Access Roles &amp; Security Policy</span>
  </div>
</div>

<?php if (!empty($ok)): ?><div class="alert alert-ok"><?= $ok ?></div><?php endif; ?>
<?php if (!empty($err)): ?><div class="alert alert-error"><?= h($err) ?></div><?php endif; ?>

<div class="panel">
  <h2 style="margin-top:0;">Add or Edit System User</h2>
  <form method="post" class="form-grid">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="form" value="user">

    <label>Full Name *
      <input name="name" placeholder="John Kayiwa" required>
    </label>

    <label>Work Email Address *
      <input type="email" name="email" placeholder="john@ghion.com" required>
    </label>

    <label>System Role *
      <select name="role" required>
        <option value="accountant">Accountant (Operational &amp; Sales Entry)</option>
        <option value="director">Director (Full Financial &amp; Administrative Control)</option>
        <option value="consultant">Consultant / Auditor (Financial Statements &amp; Reports)</option>
      </select>
    </label>

    <label>Password (Min 8 characters; leave blank when editing existing user)
      <input type="password" name="password" minlength="8" placeholder="••••••••">
    </label>

    <label class="check">
      <input type="checkbox" name="active" checked value="1"> Account Active &amp; Allowed Login
    </label>

    <div class="span2" style="margin-top: 0.5rem;">
      <button class="btn btn-primary" type="submit">
        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
        <span>Save User Account</span>
      </button>
    </div>
  </form>
</div>

<h2>System Users Directory</h2>
<div class="table-responsive">
  <table class="table">
    <thead>
      <tr>
        <th>Full Name</th>
        <th>Email Address</th>
        <th>System Role</th>
        <th>Status</th>
        <th>Last Login</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $usr): ?>
      <tr>
        <td><strong><?= h($usr['name']) ?></strong></td>
        <td><?= h($usr['email']) ?></td>
        <td><span class="badge" style="background:#f1f5f9; color:var(--ink); font-weight:700;"><?= h(ucfirst($usr['role'])) ?></span></td>
        <td>
          <span class="badge <?= $usr['active'] ? 'active' : 'exited' ?>">
            <?= $usr['active'] ? 'Active' : 'Deactivated' ?>
          </span>
        </td>
        <td style="font-size: 0.82rem; color: var(--muted);">
          <?= $usr['last_login'] ? date('d M Y, H:i', strtotime($usr['last_login'])) : 'Never logged in' ?>
        </td>
        <td>
          <?php if ($usr['id'] != $u['id']): ?>
            <form method="post" style="display:inline;">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="form" value="toggle">
              <input type="hidden" name="uid" value="<?= $usr['id'] ?>">
              <button class="btn btn-ghost btn-sm" type="submit" style="<?= $usr['active'] ? 'color:var(--danger);' : 'color:var(--success);' ?>">
                <?= $usr['active'] ? 'Deactivate' : 'Reactivate' ?>
              </button>
            </form>
          <?php else: ?>
            <span class="badge" style="background:#eff6ff; color:var(--primary);">Current User</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
