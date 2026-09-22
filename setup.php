<?php
// GHION ERP — System Initial Setup
require_once __DIR__ . '/config/database.php';
$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $users = [
            ['name' => trim($_POST['director_name']), 'email' => trim($_POST['director_email']), 'pass' => $_POST['director_pass'], 'role' => 'director'],
            ['name' => trim($_POST['accountant_name']), 'email' => trim($_POST['accountant_email']), 'pass' => $_POST['accountant_pass'], 'role' => 'accountant'],
            ['name' => trim($_POST['consultant_name']), 'email' => trim($_POST['consultant_email']), 'pass' => $_POST['consultant_pass'], 'role' => 'consultant'],
        ];
        $stmt = db()->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?,?,?,?)
                               ON DUPLICATE KEY UPDATE name=VALUES(name), password_hash=VALUES(password_hash)");
        foreach ($users as $u) {
            $stmt->execute([$u['name'], $u['email'], password_hash($u['pass'], PASSWORD_DEFAULT), $u['role']]);
        }
        $msg = 'Initial system users created successfully! You can now sign in.';
    } catch (Throwable $e) {
        $error = 'Setup error: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h(APP_NAME) ?> — Initial Setup</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
</head>
<body class="login-body">
<div class="login-card wide">
  <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem;">
    <div>
      <h1>GHION ERP — Initial Setup</h1>
      <p class="muted">Provision initial accounts for Director, Accountant, and Consultant.</p>
    </div>
    <a class="btn btn-ghost btn-sm" href="<?= url('index.php') ?>">Back to Sign In →</a>
  </div>

  <?php if ($msg): ?>
    <div class="alert alert-ok">
      <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
      <span><?= h($msg) ?> <a href="<?= url('index.php') ?>" style="font-weight: 700; color: inherit; text-decoration: underline;">Click here to Login</a></span>
    </div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="alert alert-error">
      <span><?= h($error) ?></span>
    </div>
  <?php endif; ?>

  <form method="post">
    <div class="panel" style="margin-bottom: 1rem;">
      <h3 style="margin-top: 0; color: var(--primary);">1. Executive / Director User</h3>
      <div class="form-grid">
        <label>Full Name<input name="director_name" value="Director" required></label>
        <label>Email Address<input type="email" name="director_email" value="director@ghion.com" required></label>
        <label>Password<input type="password" name="director_pass" value="Director123!" required minlength="8"></label>
      </div>
    </div>

    <div class="panel" style="margin-bottom: 1rem;">
      <h3 style="margin-top: 0; color: var(--primary);">2. Lead Accountant User</h3>
      <div class="form-grid">
        <label>Full Name<input name="accountant_name" value="Accountant" required></label>
        <label>Email Address<input type="email" name="accountant_email" value="accountant@ghion.com" required></label>
        <label>Password<input type="password" name="accountant_pass" value="Accountant123!" required minlength="8"></label>
      </div>
    </div>

    <div class="panel" style="margin-bottom: 1.5rem;">
      <h3 style="margin-top: 0; color: var(--primary);">3. Consultant / Auditor User</h3>
      <div class="form-grid">
        <label>Full Name<input name="consultant_name" value="Consultant" required></label>
        <label>Email Address<input type="email" name="consultant_email" value="consultant@ghion.com" required></label>
        <label>Password<input type="password" name="consultant_pass" value="Consultant123!" required minlength="8"></label>
      </div>
    </div>

    <button class="btn btn-primary btn-block" style="padding: 0.85rem;">
      <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      <span>Initialize System Credentials</span>
    </button>
  </form>
</div>
</body>
</html>
