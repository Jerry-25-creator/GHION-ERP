<?php
session_start();
require_once __DIR__ . '/config/database.php';

if (!empty($_SESSION['user'])) {
    header('Location: ' . url('modules/dashboard.php'));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass = $_POST['password'] ?? '';
    
    $stmt = db()->prepare("SELECT * FROM users WHERE email = ? AND active = 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($pass, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role']
        ];
        db()->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
        header('Location: ' . url('modules/dashboard.php'));
        exit;
    }
    $error = 'Invalid email, password, or deactivated account.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h(APP_NAME) ?> — Sign In</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
</head>
<body class="login-body">
<div class="login-card">
  <div class="login-brand-icon">G</div>
  <h1>GHION ERP</h1>
  <p class="muted" style="margin-bottom: 1.5rem; font-size: 0.85rem;"><?= h(COMPANY_NAME) ?></p>
  
  <?php if ($error): ?>
    <div class="alert alert-error">
      <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      <span><?= h($error) ?></span>
    </div>
  <?php endif; ?>

  <form method="post" autocomplete="on">
    <label>Work Email Address
      <input type="email" name="email" placeholder="name@ghion.com" required autofocus value="<?= h($_POST['email'] ?? '') ?>">
    </label>
    
    <label>Password
      <input type="password" name="password" placeholder="••••••••" required id="passInput">
    </label>
    
    <button class="btn btn-primary btn-block" type="submit" style="margin-top: 1.5rem; padding: 0.75rem;">
      <span>Sign In to Workspace</span>
      <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
    </button>
  </form>
  
  <div style="margin-top: 1.75rem; padding-top: 1.25rem; border-top: 1px solid var(--border); font-size: 0.78rem; color: var(--muted);">
    Manufacturing ERP · Secure Role-Based Access<br>
    <a href="<?= url('setup.php') ?>" style="color: var(--primary); text-decoration: none; font-weight: 600;">System First-Time Setup →</a>
  </div>
</div>
</body>
</html>
