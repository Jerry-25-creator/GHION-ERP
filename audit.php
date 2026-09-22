<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$pdo = db();

if (($_GET['export'] ?? '') === 'audit') {
    require_role('director', 'consultant');
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="ghion_audit_' . date('Ymd') . '.xls"');
    echo "Date\tUser\tAction\tModule\tRecord\tOld Value\tNew Value\tReason\tIP\n";
    foreach ($pdo->query("SELECT * FROM audit_log ORDER BY id DESC LIMIT 5000") as $r) {
        echo implode("\t", [$r['created_at'], $r['user_name'], $r['action'], $r['module'], $r['record_id'], $r['old_value'], $r['new_value'], $r['reason'], $r['ip_address']]) . "\n";
    }
    exit;
}

  require_once __DIR__ . '/../includes/header.php';

$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $stmt = $pdo->prepare("SELECT * FROM audit_log WHERE user_name LIKE ? OR action LIKE ? OR module LIKE ? OR record_id LIKE ? ORDER BY id DESC LIMIT 200");
    $like = "%$q%";
    $stmt->execute([$like, $like, $like, $like]);
    $logs = $stmt->fetchAll();
} else {
    $logs = $pdo->query("SELECT * FROM audit_log ORDER BY id DESC LIMIT 200")->fetchAll();
}
?>

<div class="page-head">
  <div>
    <h1>System Audit Trail &amp; Activity Log</h1>
    <span class="muted">Immutable log of system actions, record updates, and user activity</span>
  </div>
  <?php if (is_financial()): ?>
    <a class="btn btn-ghost btn-sm" href="?export=audit">
      <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
      <span>Export Audit Log (Excel)</span>
    </a>
  <?php endif; ?>
</div>

<div class="panel">
  <form method="get" class="filters" style="margin-bottom:0;">
    <input name="q" value="<?= h($q) ?>" placeholder="Search user, action, module, or record ID…" style="flex:1; max-width: 400px;">
    <button class="btn btn-primary" type="submit">Search Audit Trail</button>
    <?php if ($q): ?><a href="<?= url('modules/audit.php') ?>" class="btn btn-ghost">Clear Filter</a><?php endif; ?>
  </form>
</div>

<div class="table-responsive">
  <table class="table">
    <thead>
      <tr>
        <th>Date &amp; Time</th>
        <th>User Name</th>
        <th>Action</th>
        <th>Module</th>
        <th>Record Ref</th>
        <th>IP Address</th>
        <th>Payload Detail</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($logs as $l): ?>
      <tr>
        <td style="font-size: 0.82rem; color: var(--muted);"><?= date('d M Y, H:i:s', strtotime($l['created_at'])) ?></td>
        <td><strong><?= h($l['user_name'] ?? 'System') ?></strong></td>
        <td><span class="badge <?= h($l['action']) ?>"><?= h(ucfirst($l['action'])) ?></span></td>
        <td><span class="badge" style="background:#f1f5f9; color:var(--ink);"><?= h($l['module']) ?></span></td>
        <td><?= h($l['record_id'] ?? '—') ?></td>
        <td style="font-size:0.8rem; color:var(--muted);"><?= h($l['ip_address'] ?? '127.0.0.1') ?></td>
        <td>
          <?php if ($l['old_value'] || $l['new_value']): ?>
            <button class="btn btn-ghost btn-sm" onclick='showAuditModal(<?= json_encode($l) ?>)'>View Changes</button>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$logs): ?>
      <tr><td colspan="7" class="muted" style="text-align: center; padding: 2rem;">No audit logs matching search query.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<script>
function showAuditModal(log) {
  const html = `
    <div style="font-family: var(--font-body); font-size: 0.9rem;">
      <div style="margin-bottom: 1rem;">
        <strong>Action:</strong> <span class="badge ${log.action}">${log.action}</span> on module <strong>${log.module}</strong> (Record: ${log.record_id || 'N/A'})<br>
        <span class="muted">By ${log.user_name} on ${log.created_at} (IP: ${log.ip_address})</span>
      </div>
      ${log.reason ? `<div style="margin-bottom: 1rem; padding: 0.5rem; background: var(--bg); border-radius: var(--radius-sm);"><strong>Reason:</strong> ${log.reason}</div>` : ''}
      <div style="margin-bottom: 0.75rem;">
        <strong>Previous Value:</strong>
        <pre style="background: #f8fafc; padding: 0.75rem; border-radius: var(--radius); border: 1px solid var(--border); overflow-x: auto; font-size: 0.8rem;">${log.old_value ? JSON.stringify(JSON.parse(log.old_value), null, 2) : 'None'}</pre>
      </div>
      <div>
        <strong>New Value:</strong>
        <pre style="background: #ecfdf5; padding: 0.75rem; border-radius: var(--radius); border: 1px solid #a7f3d0; overflow-x: auto; font-size: 0.8rem; color: #065f46;">${log.new_value ? JSON.stringify(JSON.parse(log.new_value), null, 2) : 'None'}</pre>
      </div>
    </div>
  `;
  openModal('Audit Change Log #' + log.id, html);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
