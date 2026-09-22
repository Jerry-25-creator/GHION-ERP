<?php require_once __DIR__ . '/auth.php'; require_login(); $u = current_user(); $fin = is_financial(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h(APP_NAME) ?> — <?= h(COMPANY_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
</head>
<body>
<div class="app">
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="main">
<header class="topbar">
  <div class="topbar-left">
    <button class="mobile-toggle" id="sidebarToggle" aria-label="Toggle Navigation">
      <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>
    <button class="qb-new-btn" onclick="openCompanyNewModal()" title="GHION INVESTMENTS AND ENTERPRISE LTD Quick Create Center">
      <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
      <span>+ New</span>
    </button>
    <span class="sync-badge" id="syncBadge">
      <span class="dot"></span><span id="syncText">Online — Synced</span>
    </span>
    <?php if ($fin): ?>
      <span class="fin-mode-pill" title="You have financial access permissions">
        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
        Financial Mode
      </span>
    <?php endif; ?>
  </div>
  <div class="topbar-right">
    <div class="user-pill">
      <div class="avatar"><?= strtoupper(substr($u['name'], 0, 1)) ?></div>
      <div class="user-meta">
        <span class="user-name"><?= h($u['name']) ?></span>
        <span class="user-role"><?= h(ucfirst($u['role'])) ?></span>
      </div>
    </div>
    <a class="btn btn-ghost btn-sm btn-logout" href="<?= url('logout.php') ?>" title="Sign out of system">
      <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
      <span>Sign Out</span>
    </a>
  </div>
</header>
<div class="content">

