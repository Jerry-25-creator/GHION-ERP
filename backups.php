<?php
require_once __DIR__ . '/../includes/auth.php';
require_permission('manage_backups');
$pdo = db();
try {
    $pdo->query('SELECT 1 FROM backups LIMIT 1');
} catch (Throwable $e) {
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="alert alert-error"><strong>Backup migration required.</strong> Apply <code>sql/erp_expansion_schema.sql</code> first.</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

if (($_GET['download'] ?? '') === 'json') {
    $tables = ['users','products','raw_materials','batches','production_runs','customers','sales','sale_lines','payments','suppliers','purchases','employees','payroll_runs','payroll_lines','accounts','journal_entries','journal_lines','cash_accounts','audit_log'];
    $dump = ['generated_at'=>date('c'),'company'=>COMPANY_NAME,'tables'=>[]];
    foreach ($tables as $table) $dump['tables'][$table] = $pdo->query("SELECT * FROM `$table`")->fetchAll();
    $filename='ghion_backup_' . date('Ymd_His') . '.json';
    $json=json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $stmt=$pdo->prepare("INSERT INTO backups (filename,status,size_bytes,initiated_by) VALUES (?, 'completed', ?, ?)");
    $stmt->execute([$filename, strlen($json), current_user()['id']]);
    audit('export','backups',$filename,null,['tables'=>count($tables),'size'=>strlen($json)]);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $json;
    exit;
}

require_once __DIR__ . '/../includes/header.php';
$backups=$pdo->query('SELECT b.*,u.name initiated_by_name FROM backups b LEFT JOIN users u ON u.id=b.initiated_by ORDER BY b.id DESC LIMIT 50')->fetchAll();
?>
<div class="page-head"><div><h1>Backup &amp; Recovery</h1><span class="muted">Authorized database snapshots and backup history</span></div><a class="btn btn-primary" href="?download=json">Download JSON Backup</a></div>
<div class="alert alert-warning">Backups contain confidential data. Store downloaded files securely. Restore operations must be performed by an administrator after verification.</div>
<div class="table-responsive"><table class="table"><thead><tr><th>Filename</th><th>Status</th><th>Size</th><th>Initiated by</th><th>Date</th></tr></thead><tbody><?php foreach($backups as $b):?><tr><td><?=h($b['filename'])?></td><td><?=h($b['status'])?></td><td><?=money($b['size_bytes']/1024,2)?> KB</td><td><?=h($b['initiated_by_name']??'System')?></td><td><?=h($b['created_at'])?></td></tr><?php endforeach;?><?php if(!$backups):?><tr><td colspan="5" class="muted">No backups recorded.</td></tr><?php endif;?></tbody></table></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
