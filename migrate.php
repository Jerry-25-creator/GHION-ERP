<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('director');
$pdo = db();
$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $sql = file_get_contents(__DIR__ . '/../sql/erp_expansion_schema.sql');
        $sql = preg_replace('/^USE\s+ghion_erp;\s*/mi', '', $sql);
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql);
        $count = 0;
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '' || str_starts_with($statement, '--')) continue;
            $pdo->exec($statement);
            $count++;
        }
        audit('update', 'system', 'erp_expansion_schema', null, ['statements'=>$count], 'Applied additive ERP expansion migration');
        $message = "Migration applied successfully ($count statements).";
    } catch (Throwable $e) {
        $error = 'Migration failed: ' . $e->getMessage();
    }
}
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-head"><div><h1>ERP Expansion Migration</h1><span class="muted">Director-only database upgrade for Operations, permissions, costing, backups, and reconciliation</span></div></div>
<?php if($message):?><div class="alert alert-ok"><?=h($message)?></div><?php endif;?><?php if($error):?><div class="alert alert-error"><?=h($error)?></div><?php endif;?>
<div class="panel"><p>This applies the additive migration in <code>sql/erp_expansion_schema.sql</code>. Existing tables and transactions are preserved. The migration is safe to run again.</p><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><button class="btn btn-primary">Apply ERP Expansion Migration</button></form></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
