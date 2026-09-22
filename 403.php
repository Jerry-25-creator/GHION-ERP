<?php http_response_code(403); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h(APP_NAME) ?> — 403 Forbidden</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
</head>
<body class="login-body">
<div class="login-card" style="text-align: center;">
  <div style="font-size: 4rem; font-weight: 800; color: var(--danger); line-height: 1;">403</div>
  <h1 style="margin-top: 0.5rem;">Access Restricted</h1>
  <p class="muted" style="margin-bottom: 1.5rem;">
    You do not have the required permissions (Director or Consultant role) to view this confidential resource.<br>
    This access attempt has been recorded in the security audit trail.
  </p>
  <a class="btn btn-primary btn-block" href="<?= url('modules/dashboard.php') ?>">
    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
    <span>Return to Dashboard</span>
  </a>
</div>
</body>
</html>
