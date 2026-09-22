<?php
require_once __DIR__ . '/../includes/auth.php';
require_permission('view_operational_reports');
$pdo = db();
$u = current_user();
$fin = is_financial();
$requiredTables = ['expense_categories', 'expenses', 'supplier_payments', 'sales_returns', 'rework_orders', 'accounting_periods', 'packaging_items'];
$missingTables = [];
foreach ($requiredTables as $table) {
    try {
        $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
    } catch (Throwable $e) {
        $missingTables[] = $table;
    }
}
if ($missingTables) {
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="alert alert-error"><strong>Operations migration required.</strong> Apply <code>sql/erp_expansion_schema.sql</code> to enable this workspace. Missing tables: ' . h(implode(', ', $missingTables)) . '.</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $form = $_POST['form'] ?? '';
    try {
        if ($form === 'expense') {
            require_permission('manage_master_data');
            $amount = (float)$_POST['amount'];
            if ($amount <= 0 || trim($_POST['description']) === '') throw new RuntimeException('Amount and description are required.');
            $no = 'EXP-' . date('YmdHis') . '-' . random_int(100, 999);
            $pdo->prepare('INSERT INTO expenses (expense_no, category_id, amount, expense_date, payment_account_id, description, created_by) VALUES (?,?,?,?,?,?,?)')->execute([$no, (int)$_POST['category_id'], $amount, $_POST['expense_date'], (int)$_POST['payment_account_id'], trim($_POST['description']), $u['id']]);
            audit('create', 'expenses', $no, null, $_POST);
            $ok = "Expense $no recorded.";
        } elseif ($form === 'supplier_payment') {
            require_permission('edit_purchase_financials');
            $amount = (float)$_POST['amount'];
            if ($amount <= 0) throw new RuntimeException('Payment amount must be greater than zero.');
            $no = 'SP-' . date('YmdHis') . '-' . random_int(100, 999);
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO supplier_payments (payment_no,supplier_id,amount,method,payment_date,reference,unallocated_amount,created_by) VALUES (?,?,?,?,?,?,?,?)')->execute([$no,(int)$_POST['supplier_id'],$amount,$_POST['method'],$_POST['payment_date'],trim($_POST['reference']),$amount,$u['id']]);
            $paymentId = $pdo->lastInsertId();
            $remaining = $amount;
            $stmt = $pdo->prepare("SELECT id,total_cost FROM purchases WHERE supplier_id=? AND financial_status='complete' ORDER BY id");
            $stmt->execute([(int)$_POST['supplier_id']]);
            foreach ($stmt as $purchase) {
                if ($remaining <= 0) break;
                $already = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM supplier_payment_allocations WHERE purchase_id=?');
                $already->execute([$purchase['id']]);
                $alloc = min($remaining, max(0, (float)$purchase['total_cost'] - (float)$already->fetchColumn()));
                if ($alloc > 0) {
                    $pdo->prepare('INSERT INTO supplier_payment_allocations (supplier_payment_id,purchase_id,amount) VALUES (?,?,?)')->execute([$paymentId,$purchase['id'],$alloc]);
                    $remaining -= $alloc;
                }
            }
            $pdo->prepare('UPDATE supplier_payments SET unallocated_amount=? WHERE id=?')->execute([$remaining,$paymentId]);
            $pdo->commit();
            audit('create', 'supplier_payments', $no, null, ['amount'=>$amount,'unallocated'=>$remaining]);
            $ok = "Supplier payment $no recorded. Unallocated: " . money($remaining) . ' UGX.';
        } elseif ($form === 'sales_return') {
            $saleId = (int)$_POST['sale_id']; $qty = (float)$_POST['qty'];
            if ($qty <= 0) throw new RuntimeException('Return quantity must be greater than zero.');
            $sale = $pdo->prepare('SELECT customer_id, product_id, unit_price FROM sales s JOIN sale_lines sl ON sl.sale_id=s.id WHERE s.id=? LIMIT 1');
            $sale->execute([$saleId]); $line = $sale->fetch();
            if (!$line) throw new RuntimeException('Sale not found.');
            $no = 'RET-' . date('YmdHis') . '-' . random_int(100,999); $total = $qty * (float)$line['unit_price'];
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO sales_returns (return_no,sale_id,customer_id,reason,total,created_by) VALUES (?,?,?,?,?,?)')->execute([$no,$saleId,$line['customer_id'],trim($_POST['reason']),$total,$u['id']]);
            $returnId = $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO sales_return_lines (return_id,product_id,qty,unit_price,line_total) VALUES (?,?,?,?,?)')->execute([$returnId,$line['product_id'],$qty,$line['unit_price'],$total]);
            $pdo->prepare('INSERT INTO finished_stock (product_id,qty) VALUES (?,?) ON DUPLICATE KEY UPDATE qty=qty+VALUES(qty)')->execute([$line['product_id'],$qty]);
            $pdo->prepare("INSERT INTO stock_movements (item_type,item_id,qty,from_state,to_state,movement_type,reference,user_id) VALUES ('finished_good',?,?, 'Customer','Finished Goods','sales_return',?,?)")->execute([$line['product_id'],$qty,$no,$u['id']]);
            $pdo->commit(); audit('create','sales_returns',$no,null,$_POST); $ok = "Sales return $no posted and stock restored.";
        } elseif ($form === 'route_issue') {
            $qty = (float)$_POST['issued']; if ($qty <= 0) throw new RuntimeException('Issued quantity must be greater than zero.');
            $pdo->prepare('INSERT INTO salesman_routes (route_date,salesman,product_id,issued) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE issued=issued+VALUES(issued), status="open"')->execute([$_POST['route_date'],trim($_POST['salesman']),(int)$_POST['product_id'],$qty]);
            audit('create','salesman_routes',$_POST['route_date'],null,$_POST); $ok = 'Salesman stock issue recorded.';
        } elseif ($form === 'route_close') {
            $id=(int)$_POST['route_id']; $sold=(float)$_POST['sold']; $returned=(float)$_POST['returned'];
            $row=$pdo->prepare('SELECT issued FROM salesman_routes WHERE id=?'); $row->execute([$id]); $issued=(float)$row->fetchColumn();
            if (abs($issued-$sold-$returned)>0.001) throw new RuntimeException('Issued must equal sold plus returned before closing the route.');
            $pdo->prepare("UPDATE salesman_routes SET sold=?, returned=?, status='closed' WHERE id=?")->execute([$sold,$returned,$id]); audit('update','salesman_routes',$id,null,$_POST); $ok='Salesman route reconciled and closed.';
        } elseif ($form === 'rework_open') {
            $no='RW-' . date('YmdHis') . '-' . random_int(100,999);
            $pdo->prepare('INSERT INTO rework_orders (rework_no,original_run_id,product_id,damaged_qty,reason,opened_by) VALUES (?,?,?,?,?,?)')->execute([$no,(int)$_POST['run_id'],(int)$_POST['product_id'],(float)$_POST['damaged_qty'],trim($_POST['reason']),$u['id']]); audit('create','rework_orders',$no,null,$_POST); $ok="Rework order $no opened.";
        } elseif ($form === 'rework_complete') {
            $id=(int)$_POST['rework_id']; $re=$pdo->prepare("SELECT * FROM rework_orders WHERE id=? AND status='open'"); $re->execute([$id]); $row=$re->fetch(); if(!$row) throw new RuntimeException('Open rework order not found.');
            $recovered=(float)$_POST['recovered_qty']; $waste=(float)$_POST['final_waste_qty']; if(abs((float)$row['damaged_qty']-$recovered-$waste)>0.001) throw new RuntimeException('Damaged quantity must equal recovered plus final waste.');
            $pdo->beginTransaction(); $pdo->prepare("UPDATE rework_orders SET recovered_qty=?,final_waste_qty=?,status='completed',completed_by=?,completed_at=NOW() WHERE id=?")->execute([$recovered,$waste,$u['id'],$id]); if($recovered>0)$pdo->prepare('INSERT INTO finished_stock(product_id,qty) VALUES(?,?) ON DUPLICATE KEY UPDATE qty=qty+VALUES(qty)')->execute([$row['product_id'],$recovered]); $pdo->commit(); audit('update','rework_orders',$id,null,$_POST); $ok='Rework completed; recovered goods returned to stock.';
        } elseif ($form === 'period') {
            require_permission('manage_accounting_periods'); $id=(int)$_POST['period_id']; $action=$_POST['period_action'];
            if ($action==='close') $pdo->prepare("UPDATE accounting_periods SET status='closed',closed_by=?,closed_at=NOW() WHERE id=? AND status='open'")->execute([$u['id'],$id]); else $pdo->prepare("UPDATE accounting_periods SET status='open',closed_by=NULL,closed_at=NULL,reopen_reason=? WHERE id=?")->execute([trim($_POST['reason']),$id]); audit('update','accounting_periods',$id,null,$_POST); $ok='Accounting period status updated.';
        } elseif ($form === 'new_period') {
            require_permission('manage_accounting_periods');
            $pdo->prepare('INSERT INTO accounting_periods (period_start,period_end,status) VALUES (?,?,\'open\')')->execute([$_POST['period_start'],$_POST['period_end']]);
            audit('create','accounting_periods',$_POST['period_start'],null,$_POST); $ok='Accounting period opened.';
        } elseif ($form === 'packaging') {
            require_permission('manage_master_data');
            $pdo->prepare('INSERT INTO packaging_items (code,name,brand,pack_level,uom,qty_per_pack,avg_cost) VALUES (?,?,?,?,?,?,?)')->execute([trim($_POST['code']),trim($_POST['name']),trim($_POST['brand']),$_POST['pack_level'],$_POST['uom'],(float)$_POST['qty_per_pack'],(float)$_POST['avg_cost']]);
            audit('create','packaging_items',$_POST['code'],null,$_POST); $ok='Packaging item created.';
        } elseif ($form === 'core_paper') {
            $qty=(float)$_POST['quantity_kg']; if($qty<=0) throw new RuntimeException('Core-paper quantity must be greater than zero.');
            $no='CP-' . date('YmdHis') . '-' . random_int(100,999);
            $pdo->prepare('INSERT INTO core_paper_movements (movement_no,movement_type,quantity_kg,production_run_id,waste_kg,reason,recorded_by) VALUES (?,?,?,?,?,?,?)')->execute([$no,$_POST['movement_type'],$qty,($_POST['production_run_id']?:null),(float)$_POST['waste_kg'],trim($_POST['reason']),$u['id']]);
            audit('create','core_paper_movements',$no,null,$_POST); $ok="Core-paper movement $no recorded.";
        }
    } catch (Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); $err=$e->getMessage(); }
}

require_once __DIR__ . '/../includes/header.php';
$categories=$pdo->query('SELECT * FROM expense_categories WHERE active=1 ORDER BY name')->fetchAll();
$cash=$pdo->query('SELECT * FROM cash_accounts ORDER BY name')->fetchAll();
$suppliers=$pdo->query('SELECT id,name FROM suppliers WHERE active=1 ORDER BY name')->fetchAll();
$products=$pdo->query('SELECT id,name FROM products WHERE active=1 ORDER BY name')->fetchAll();
$sales=$pdo->query('SELECT id,invoice_no FROM sales ORDER BY id DESC LIMIT 100')->fetchAll();
$runs=$pdo->query('SELECT id,run_no,product_id FROM production_runs ORDER BY id DESC LIMIT 100')->fetchAll();
$routes=$pdo->query("SELECT sr.*,p.name product FROM salesman_routes sr JOIN products p ON p.id=sr.product_id WHERE sr.status='open' ORDER BY route_date DESC")->fetchAll();
$reworks=$pdo->query("SELECT r.*,p.name product FROM rework_orders r JOIN products p ON p.id=r.product_id WHERE r.status='open' ORDER BY r.id DESC")->fetchAll();
$periods=$pdo->query('SELECT * FROM accounting_periods ORDER BY period_start DESC')->fetchAll();
$costs=$pdo->query("SELECT p.name,
    COALESCE((SELECT SUM(pc.consumed_qty * rm.avg_cost) FROM production_runs pr2 JOIN production_consumption pc ON pc.production_run_id=pr2.id JOIN raw_materials rm ON rm.id=pc.material_id WHERE pr2.product_id=p.id),0) material_cost,
    COALESCE((SELECT SUM(pr3.qty_produced * pp.quantity * pi.avg_cost) FROM production_runs pr3 JOIN product_packaging pp ON pp.product_id=pr3.product_id JOIN packaging_items pi ON pi.id=pp.packaging_item_id WHERE pr3.product_id=p.id),0) packaging_cost,
    COALESCE((SELECT SUM(oa.allocated_amount) FROM overhead_allocations oa JOIN production_runs pr4 ON pr4.id=oa.production_run_id WHERE pr4.product_id=p.id),0) overhead_cost,
    COALESCE((SELECT SUM(pr5.qty_produced) FROM production_runs pr5 WHERE pr5.product_id=p.id),0) output_qty
    FROM products p WHERE p.active=1 ORDER BY p.name")->fetchAll();
$packaging=$pdo->query('SELECT * FROM packaging_items WHERE active=1 ORDER BY name')->fetchAll();
$core=$pdo->query("SELECT * FROM packaging_items WHERE code='CP' LIMIT 1")->fetch();
?>
<div class="page-head"><div><h1>Operations Control Center</h1><span class="muted">Payables, returns, route reconciliation, expenses, rework, packaging, periods, and manufacturing cost</span></div></div>
<?php if(!empty($ok)): ?><div class="alert alert-ok"><?=h($ok)?></div><?php endif; ?><?php if(!empty($err)): ?><div class="alert alert-error"><?=h($err)?></div><?php endif; ?>
<div class="form-grid" style="grid-template-columns:repeat(2,1fr)">
<div class="panel"><h2 style="margin-top:0">Record Expense</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="form" value="expense"><label>Category<select name="category_id" required><?php foreach($categories as $c): ?><option value="<?=$c['id']?>"><?=h($c['name'])?></option><?php endforeach;?></select></label><label>Amount<input type="number" name="amount" min="0.01" step="0.01" required></label><label>Date<input type="date" name="expense_date" value="<?=date('Y-m-d')?>" required></label><label>Payment Account<select name="payment_account_id" required><?php foreach($cash as $a): ?><option value="<?=$a['id']?>"><?=h($a['name'])?></option><?php endforeach;?></select></label><label class="span2">Description<input name="description" required></label><button class="btn btn-primary" type="submit">Save Expense</button></form></div>
<div class="panel"><h2 style="margin-top:0">Supplier Payment</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="form" value="supplier_payment"><label>Supplier<select name="supplier_id" required><?php foreach($suppliers as $s): ?><option value="<?=$s['id']?>"><?=h($s['name'])?></option><?php endforeach;?></select></label><label>Amount<input type="number" name="amount" min="0.01" step="0.01" required></label><label>Method<select name="method"><option>cash</option><option>bank</option><option>mobile_money</option></select></label><label>Date<input type="date" name="payment_date" value="<?=date('Y-m-d')?>"></label><label class="span2">Reference<input name="reference"></label><button class="btn btn-primary" type="submit">Record Supplier Payment</button></form></div>
<div class="panel"><h2 style="margin-top:0">Sales Return</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="form" value="sales_return"><label>Invoice<select name="sale_id" required><?php foreach($sales as $s): ?><option value="<?=$s['id']?>"><?=h($s['invoice_no'])?></option><?php endforeach;?></select></label><label>Quantity<input type="number" name="qty" min="0.01" step="0.01" required></label><label class="span2">Reason<input name="reason"></label><button class="btn btn-primary" type="submit">Post Return</button></form></div>
<div class="panel"><h2 style="margin-top:0">Packaging &amp; Core Paper</h2><p class="muted">Packaging items and recipes are stored in <code>packaging_items</code> and <code>product_packaging</code>. Core paper is tracked by kilogram using the CP item.</p><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="form" value="expense"><label>Packaging / Core item<select name="category_id"><?php foreach($categories as $c): ?><option value="<?=$c['id']?>"><?=h($c['name'])?></option><?php endforeach;?></select></label><label>Quantity / kg<input type="number" name="amount" step="0.01" min="0.01"></label><label class="span2">Movement note<input name="description" value="Packaging or core-paper movement"></label><input type="hidden" name="payment_account_id" value="<?=$cash[0]['id']??0?>"><input type="hidden" name="expense_date" value="<?=date('Y-m-d')?>"><button class="btn btn-ghost" type="submit">Record Control Cost</button></form></div>
<div class="panel"><h2 style="margin-top:0">Add Packaging Item</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="form" value="packaging"><label>Code<input name="code" placeholder="CL-100-PACK" required></label><label>Name<input name="name" placeholder="Classic 100-pack wrap" required></label><label>Brand<input name="brand"></label><label>Pack level<select name="pack_level"><option>single-pack</option><option>10-pack</option><option>100-pack</option><option>12-pack</option><option>bulk-sack</option></select></label><label>UOM<select name="uom"><option>kg</option><option>pcs</option><option>sack</option></select></label><label>Quantity per pack<input name="qty_per_pack" type="number" step=".0001" min="0" value="1"></label><label>Average cost<input name="avg_cost" type="number" step=".01" min="0" value="0"></label><button class="btn btn-primary">Save Packaging Item</button></form></div>
<div class="panel"><h2 style="margin-top:0">Core Paper Movement</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="form" value="core_paper"><label>Movement<select name="movement_type"><option>receipt</option><option>consumption</option><option>adjustment</option></select></label><label>Quantity kg<input name="quantity_kg" type="number" step=".01" min=".01" required></label><label>Waste kg<input name="waste_kg" type="number" step=".01" min="0" value="0"></label><label>Production run<select name="production_run_id"><option value="">None</option><?php foreach($runs as $r):?><option value="<?=$r['id']?>"><?=h($r['run_no'])?></option><?php endforeach;?></select></label><label class="span2">Reason<input name="reason"></label><button class="btn btn-primary">Record Core Paper</button></form></div>
</div>
<h2>Salesman Route Reconciliation</h2><div class="table-responsive"><table class="table"><thead><tr><th>Date</th><th>Salesman</th><th>Product</th><th>Issued</th><th>Close Route</th></tr></thead><tbody><?php foreach($routes as $r): ?><tr><td><?=h($r['route_date'])?></td><td><?=h($r['salesman'])?></td><td><?=h($r['product'])?></td><td><?=money($r['issued'])?></td><td><form method="post" style="display:flex;gap:.4rem"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="form" value="route_close"><input type="hidden" name="route_id" value="<?=$r['id']?>"><input name="sold" type="number" step=".01" placeholder="Sold" required><input name="returned" type="number" step=".01" placeholder="Returned" required><button class="btn btn-primary btn-sm">Reconcile</button></form></td></tr><?php endforeach;?><?php if(!$routes):?><tr><td colspan="5" class="muted">No open salesman routes.</td></tr><?php endif;?></tbody></table></div>
<div class="form-grid" style="grid-template-columns:repeat(2,1fr)"><div class="panel"><h2 style="margin-top:0">Open Rework Order</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="form" value="rework_open"><label>Production Run<select name="run_id" required><?php foreach($runs as $r):?><option value="<?=$r['id']?>"><?=h($r['run_no'])?></option><?php endforeach;?></select></label><label>Product<select name="product_id" required><?php foreach($products as $p):?><option value="<?=$p['id']?>"><?=h($p['name'])?></option><?php endforeach;?></select></label><label>Damaged qty<input name="damaged_qty" type="number" min=".01" step=".01" required></label><label>Reason<input name="reason" required></label><button class="btn btn-primary">Open Rework</button></form></div><div class="panel"><h2 style="margin-top:0">Complete Rework</h2><?php foreach($reworks as $r):?><form method="post" style="display:flex;gap:.4rem;align-items:center;margin-bottom:.5rem"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="form" value="rework_complete"><input type="hidden" name="rework_id" value="<?=$r['id']?>"><strong><?=h($r['rework_no'])?></strong><span><?=money($r['damaged_qty'])?> damaged</span><input name="recovered_qty" type="number" min="0" step=".01" placeholder="Recovered" required><input name="final_waste_qty" type="number" min="0" step=".01" placeholder="Waste" required><button class="btn btn-primary btn-sm">Complete</button></form><?php endforeach;?><?php if(!$reworks):?><span class="muted">No open rework orders.</span><?php endif;?></div></div>
<h2>Manufacturing Cost by Product</h2><div class="table-responsive"><table class="table"><thead><tr><th>Product</th><th>Materials</th><th>Packaging</th><th>Factory overhead</th><th>Total cost</th><th>Output</th><th>Cost / unit</th></tr></thead><tbody><?php foreach($costs as $c): $total=(float)$c['material_cost']+(float)$c['packaging_cost']+(float)$c['overhead_cost'];?><tr><td><?=h($c['name'])?></td><td><?=money($c['material_cost'])?> UGX</td><td><?=money($c['packaging_cost'])?> UGX</td><td><?=money($c['overhead_cost'])?> UGX</td><td><strong><?=money($total)?> UGX</strong></td><td><?=money($c['output_qty'])?></td><td><?= $c['output_qty']>0 ? money($total/$c['output_qty'],2) : '0' ?> UGX</td></tr><?php endforeach;?></tbody></table></div>
<?php if($fin):?><h2>Accounting Periods</h2><div class="panel"><form method="post" style="display:flex;gap:.5rem;align-items:end;flex-wrap:wrap"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="form" value="new_period"><label>Start<input type="date" name="period_start" required></label><label>End<input type="date" name="period_end" required></label><button class="btn btn-primary">Open Period</button></form></div><div class="table-responsive"><table class="table"><thead><tr><th>Period</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($periods as $p):?><tr><td><?=h($p['period_start'])?> to <?=h($p['period_end'])?></td><td><?=h($p['status'])?></td><td><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="form" value="period"><input type="hidden" name="period_id" value="<?=$p['id']?>"><input type="hidden" name="period_action" value="<?=$p['status']==='open'?'close':'reopen'?>"><input name="reason" placeholder="Reason if reopening"><button class="btn btn-ghost btn-sm"><?=$p['status']==='open'?'Close':'Reopen'?></button></form></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
