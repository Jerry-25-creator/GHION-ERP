<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$u = current_user();
$pdo = db();
$fin = is_financial();

// ─── Excel Export Handlers ────────────────────────────────────
if (($_GET['export'] ?? '') === 'sales') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="ghion_sales_' . date('Ymd') . '.xls"');
    $rows = $pdo->query("SELECT s.invoice_no, s.sale_date, c.name customer, s.salesman, s.payment_method, s.total, s.amount_paid, s.balance, s.payment_status FROM sales s JOIN customers c ON c.id=s.customer_id ORDER BY s.id DESC")->fetchAll();
    echo "Invoice\tDate\tCustomer\tSalesman\tMethod\tTotal\tPaid\tBalance\tStatus\n";
    foreach ($rows as $r) echo implode("\t", [$r['invoice_no'], $r['sale_date'], $r['customer'], $r['salesman']??'—', $r['payment_method'], $r['total'], $r['amount_paid'], $r['balance'], $r['payment_status']]) . "\n";
    audit('export', 'reports', 'sales_excel'); exit;
}
if (($_GET['export'] ?? '') === 'tb' && $fin) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="ghion_trial_balance_' . date('Ymd') . '.xls"');
    $rows = $pdo->query("SELECT a.code, a.name, a.category, COALESCE(SUM(jl.debit),0) dr, COALESCE(SUM(jl.credit),0) cr FROM accounts a LEFT JOIN journal_lines jl ON jl.account_id=a.id GROUP BY a.id ORDER BY a.code")->fetchAll();
    echo "Code\tAccount\tCategory\tDebit\tCredit\n";
    foreach ($rows as $r) echo "{$r['code']}\t{$r['name']}\t{$r['category']}\t{$r['dr']}\t{$r['cr']}\n";
    audit('export', 'reports', 'trial_balance_excel'); exit;
}
if (($_GET['export'] ?? '') === 'production') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="ghion_production_' . date('Ymd') . '.xls"');
    $rows = $pdo->query("SELECT pr.run_no, pr.created_at, p.name product, pr.qty_produced, pr.est_kg_consumed, pr.waste_kg FROM production_runs pr JOIN products p ON p.id=pr.product_id ORDER BY pr.id DESC")->fetchAll();
    echo "Run No\tDate\tProduct\tQty (pcs)\tEst kg Consumed\tWaste (kg)\n";
    foreach ($rows as $r) echo "{$r['run_no']}\t{$r['created_at']}\t{$r['product']}\t{$r['qty_produced']}\t{$r['est_kg_consumed']}\t{$r['waste_kg']}\n";
    audit('export', 'reports', 'production_excel'); exit;
}
if (($_GET['export'] ?? '') === 'purchases') {
  require_role('director', 'consultant');
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="ghion_purchases_' . date('Ymd') . '.xls"');
  $rows = $pdo->query("SELECT p.reference, p.created_at, s.name supplier, p.total_cost, p.unit_cost, p.financial_status FROM purchases p LEFT JOIN suppliers s ON s.id=p.supplier_id ORDER BY p.id DESC")->fetchAll();
    echo "PO Number\tDate\tSupplier\tTotal\tCost Price\tStatus\n";
  foreach ($rows as $r) echo "{$r['reference']}\t{$r['created_at']}\t{$r['supplier']}\t{$r['total_cost']}\t{$r['unit_cost']}\t{$r['financial_status']}\n";
    audit('export', 'reports', 'purchases_excel'); exit;
}
if (($_GET['export'] ?? '') === 'payroll') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="ghion_payroll_' . date('Ymd') . '.xls"');
  $rows = $pdo->query("SELECT pr.period, pr.created_at run_date, e.name employee, pl.gross gross_pay, (pl.paye + pl.nssf + pl.other_deductions) deductions, pl.net net_pay, pr.status FROM payroll_runs pr JOIN payroll_lines pl ON pl.run_id=pr.id JOIN employees e ON e.id=pl.employee_id ORDER BY pr.id DESC, pl.id DESC")->fetchAll();
    echo "Period\tRun Date\tEmployee\tGross Pay\tDeductions\tNet Pay\tStatus\n";
    foreach ($rows as $r) echo "{$r['period']}\t{$r['run_date']}\t{$r['employee']}\t{$r['gross_pay']}\t{$r['deductions']}\t{$r['net_pay']}\t{$r['status']}\n";
    audit('export', 'reports', 'payroll_excel'); exit;
}

  require_once __DIR__ . '/../includes/header.php';

// ─── Date Filter ──────────────────────────────────────────────
$filter = $_GET['range'] ?? 'month';
$where = "1=1";
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');
switch ($filter) {
    case 'today':     $where = "DATE(sale_date)=CURDATE()"; break;
    case 'yesterday': $where = "DATE(sale_date)=DATE_SUB(CURDATE(),INTERVAL 1 DAY)"; break;
    case 'week':      $where = "YEARWEEK(sale_date,1)=YEARWEEK(CURDATE(),1)"; break;
    case 'month':     $where = "DATE_FORMAT(sale_date,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')"; break;
    case 'year':      $where = "YEAR(sale_date)=YEAR(CURDATE())"; break;
    case 'custom':    $where = "DATE(sale_date) BETWEEN " . $pdo->quote($from) . " AND " . $pdo->quote($to); break;
}

// ─── Report Queries ───────────────────────────────────────────
$byProduct  = $pdo->query("SELECT p.name, SUM(sl.qty) qty, SUM(sl.line_total) amount FROM sale_lines sl JOIN sales s ON s.id=sl.sale_id JOIN products p ON p.id=sl.product_id WHERE $where GROUP BY p.id ORDER BY amount DESC")->fetchAll();
$bySalesman = $pdo->query("SELECT COALESCE(NULLIF(s.salesman,''),'Direct Store Sale') sm, COUNT(*) invoices, SUM(s.total) amount FROM sales s WHERE $where GROUP BY sm ORDER BY amount DESC")->fetchAll();
$byCustomer = $pdo->query("SELECT c.name, COUNT(*) invoices, SUM(s.total) amount, SUM(s.balance) balance FROM sales s JOIN customers c ON c.id=s.customer_id WHERE $where GROUP BY c.id ORDER BY amount DESC")->fetchAll();
$totalSales = $pdo->query("SELECT COALESCE(SUM(total),0) FROM sales WHERE $where")->fetchColumn();
$totalPaid  = $pdo->query("SELECT COALESCE(SUM(amount_paid),0) FROM sales WHERE $where")->fetchColumn();
$totalOut   = $totalSales - $totalPaid;
$invoiceCount = $pdo->query("SELECT COUNT(*) FROM sales WHERE $where")->fetchColumn();

// Production summary
$prodSummary = $pdo->query("SELECT p.name, SUM(pr.qty_produced) qty, SUM(pr.est_kg_consumed) kg FROM production_runs pr JOIN products p ON p.id=pr.product_id GROUP BY p.id ORDER BY qty DESC")->fetchAll();

// Purchases by supplier
$purchSupp = $pdo->query("SELECT COALESCE(s.name,'Unknown') supplier, COUNT(*) orders, SUM(p.total_cost) amount FROM purchases p LEFT JOIN suppliers s ON s.id=p.supplier_id GROUP BY p.supplier_id ORDER BY amount DESC LIMIT 10")->fetchAll();

// Inventory Stock status
$stockStatus = $pdo->query("SELECT p.name, COALESCE(fs.qty,0) stock, p.standard_price FROM products p LEFT JOIN finished_stock fs ON fs.product_id=p.id WHERE p.active=1 ORDER BY stock ASC")->fetchAll();

// Top AR Balances
$topAR = $pdo->query("SELECT c.name, SUM(s.balance) outstanding FROM sales s JOIN customers c ON c.id=s.customer_id WHERE s.balance>0 GROUP BY c.id ORDER BY outstanding DESC LIMIT 10")->fetchAll();

// Payroll last period
$lastPayroll = $pdo->query("SELECT pr.period, SUM(pl.gross) gross, SUM(pl.net) net, SUM(pl.paye + pl.nssf + pl.other_deductions) ded, COUNT(pl.id) emp FROM payroll_runs pr JOIN payroll_lines pl ON pl.run_id=pr.id GROUP BY pr.period ORDER BY pr.period DESC LIMIT 5")->fetchAll();
?>

<div class="page-head">
  <div>
    <h1>GHION Report Center</h1>
    <span class="muted">Analytics, financial summaries, and Excel data exports</span>
  </div>
  <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
    <a class="btn btn-ghost btn-sm" href="?export=sales&range=<?= h($filter) ?>&from=<?= h($from) ?>&to=<?= h($to) ?>">
      <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
      Sales Report
    </a>
    <a class="btn btn-ghost btn-sm" href="?export=purchases">
      <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
      Purchases
    </a>
    <a class="btn btn-ghost btn-sm" href="?export=production">
      <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
      Production
    </a>
    <?php if ($fin): ?>
    <a class="btn btn-ghost btn-sm" href="?export=tb">
      <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
      Trial Balance
    </a>
    <a class="btn btn-ghost btn-sm" href="?export=payroll">
      <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
      Payroll
    </a>
    <?php endif; ?>
  </div>
</div>

<!-- Date Filter Bar -->
<div class="panel" style="padding:0.75rem 1.25rem;">
  <form method="get" style="display:flex; align-items:center; gap:1rem; flex-wrap:wrap; margin:0;">
    <label style="margin:0; display:flex; align-items:center; gap:0.5rem; font-weight:600; font-size:0.88rem;">
      Reporting Range:
      <select name="range" onchange="this.form.submit()" style="width:170px;">
        <?php foreach(['today'=>'Today','yesterday'=>'Yesterday','week'=>'This Week','month'=>'This Month','year'=>'This Year','custom'=>'Custom Range'] as $k=>$v): ?>
          <option value="<?= $k ?>" <?= $filter===$k?'selected':'' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php if ($filter === 'custom'): ?>
      <input type="date" name="from" value="<?= h($from) ?>" style="width:150px;">
      <span>to</span>
      <input type="date" name="to" value="<?= h($to) ?>" style="width:150px;">
      <button class="btn btn-sm btn-primary" type="submit">Apply</button>
    <?php endif; ?>
  </form>
</div>

<!-- KPI Cards -->
<div class="cards" style="margin-bottom:1.5rem;">
  <div class="card">
    <div class="card-label">Revenue (Period)</div>
    <div class="card-value" style="color:var(--primary);"><?= money($totalSales) ?> <span style="font-size:0.85rem;color:var(--muted);">UGX</span></div>
    <span class="muted" style="font-size:0.8rem;"><?= $invoiceCount ?> invoices</span>
  </div>
  <div class="card">
    <div class="card-label">Cash Collected</div>
    <div class="card-value" style="color:var(--success);"><?= money($totalPaid) ?> <span style="font-size:0.85rem;color:var(--muted);">UGX</span></div>
    <span class="muted" style="font-size:0.8rem;">Amount received</span>
  </div>
  <div class="card warn">
    <div class="card-label">A/R Outstanding</div>
    <div class="card-value" style="color:var(--danger);"><?= money($totalOut) ?> <span style="font-size:0.85rem;color:var(--muted);">UGX</span></div>
    <span class="muted" style="font-size:0.8rem;">Unpaid invoices</span>
  </div>
</div>

<!-- Report Tabs -->
<div style="display:flex; align-items:center; gap:0; margin-bottom:1rem; border-bottom:2px solid var(--border); overflow-x:auto;">
  <?php foreach(['sales_tab'=>'Sales Analysis','prod_tab'=>'Production','inv_tab'=>'Inventory / Stock','purch_tab'=>'Purchases','ar_tab'=>'A/R Aging','payroll_tab'=>'Payroll Summary'] as $k=>$lbl): ?>
    <a onclick="switchRTab('<?= $k ?>')" id="rtab-btn-<?= $k ?>"
       style="padding:0.65rem 1.1rem; font-weight:600; font-size:0.85rem; text-decoration:none; white-space:nowrap; border-bottom:3px solid <?= $k==='sales_tab'?'var(--primary)':'transparent' ?>; color:<?= $k==='sales_tab'?'var(--primary)':'var(--muted)' ?>; margin-bottom:-2px; cursor:pointer;">
      <?= $lbl ?>
    </a>
  <?php endforeach; ?>
</div>

<!-- Sales Analysis Tab -->
<div id="rtab-sales_tab" class="rtab-content">
  <div class="form-grid" style="grid-template-columns:1fr 1fr;">
    <!-- By Product -->
    <div class="panel">
      <h3 style="margin-top:0;">Sales Revenue by Product</h3>
      <div class="table-responsive" style="margin-bottom:0;">
        <table class="table">
          <thead><tr><th>Product Name</th><th class="num">Units Sold (pcs)</th><th class="num">Revenue (UGX)</th></tr></thead>
          <tbody>
            <?php foreach ($byProduct as $r): ?>
            <tr>
              <td><strong><?= h($r['name']) ?></strong></td>
              <td class="num"><?= money($r['qty']) ?></td>
              <td class="num" style="font-weight:700; color:var(--primary);"><?= money($r['amount']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$byProduct): ?><tr><td colspan="3" class="muted" style="text-align:center;padding:2rem;">No sales recorded for this period.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <!-- By Salesman -->
    <div class="panel">
      <h3 style="margin-top:0;">Sales Performance by Rep</h3>
      <div class="table-responsive" style="margin-bottom:0;">
        <table class="table">
          <thead><tr><th>Salesperson</th><th class="num">Invoices</th><th class="num">Revenue (UGX)</th></tr></thead>
          <tbody>
            <?php foreach ($bySalesman as $r): ?>
            <tr>
              <td><strong><?= h($r['sm']) ?></strong></td>
              <td class="num"><?= $r['invoices'] ?></td>
              <td class="num" style="font-weight:700; color:var(--primary);"><?= money($r['amount']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$bySalesman): ?><tr><td colspan="3" class="muted" style="text-align:center;padding:2rem;">No data.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <!-- By Customer -->
  <div class="panel">
    <h3 style="margin-top:0;">Sales by Customer Account</h3>
    <div class="table-responsive" style="margin-bottom:0;">
      <table class="table">
        <thead><tr><th>Customer</th><th class="num">Invoices</th><th class="num">Total Billed (UGX)</th><th class="num">Outstanding Balance</th></tr></thead>
        <tbody>
          <?php foreach ($byCustomer as $r): ?>
          <tr>
            <td><strong><?= h($r['name']) ?></strong></td>
            <td class="num"><?= $r['invoices'] ?></td>
            <td class="num" style="font-weight:700; color:var(--primary);"><?= money($r['amount']) ?></td>
            <td class="num" style="<?= $r['balance']>0?'color:var(--danger);font-weight:700;':'' ?>"><?= money($r['balance']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$byCustomer): ?><tr><td colspan="4" class="muted" style="text-align:center;padding:2rem;">No customer transactions for this period.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Production Tab -->
<div id="rtab-prod_tab" class="rtab-content" style="display:none;">
  <div class="panel">
    <h3 style="margin-top:0;">Production Summary by Product</h3>
    <div class="table-responsive" style="margin-bottom:0;">
      <table class="table">
        <thead><tr><th>Product</th><th class="num">Total Units Produced (pcs)</th><th class="num">Est. Raw Material (kg)</th></tr></thead>
        <tbody>
          <?php foreach ($prodSummary as $r): ?>
          <tr>
            <td><strong><?= h($r['name']) ?></strong></td>
            <td class="num" style="font-weight:700; color:var(--primary);"><?= money($r['qty']) ?></td>
            <td class="num"><?= money($r['kg']) ?> kg</td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$prodSummary): ?><tr><td colspan="3" class="muted" style="text-align:center;padding:2rem;">No production runs recorded.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Inventory Tab -->
<div id="rtab-inv_tab" class="rtab-content" style="display:none;">
  <div class="panel">
    <h3 style="margin-top:0;">Finished Goods Stock Status</h3>
    <div class="table-responsive" style="margin-bottom:0;">
        <table class="table">
        <thead><tr><th>Product</th><th class="num">Current Stock (pcs)</th><th class="num">Value @ Standard Price (UGX)</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($stockStatus as $r): ?>
          <?php $low = $r['stock'] <= 0; ?>
          <tr class="<?= $low ? 'warn-row' : '' ?>">
            <td><strong><?= h($r['name']) ?></strong></td>
            <td class="num" style="<?= $low?'color:var(--danger);font-weight:700;':'' ?>"><?= money($r['stock']) ?></td>
            <td class="num"><?= money($r['stock'] * $r['standard_price']) ?></td>
            <td><?php if ($low): ?><span class="badge unpaid">⚠ Low Stock / Reorder</span><?php else: ?><span class="badge paid">✓ Adequate</span><?php endif; ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$stockStatus): ?><tr><td colspan="4" class="muted" style="text-align:center;padding:2rem;">No products configured.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Purchases Tab -->
<div id="rtab-purch_tab" class="rtab-content" style="display:none;">
  <div class="panel">
    <h3 style="margin-top:0;">Top Suppliers by Purchase Volume</h3>
    <div class="table-responsive" style="margin-bottom:0;">
      <table class="table">
        <thead><tr><th>Supplier</th><th class="num">Purchase Orders</th><th class="num">Total Spend (UGX)</th></tr></thead>
        <tbody>
          <?php foreach ($purchSupp as $r): ?>
          <tr>
            <td><strong><?= h($r['supplier']) ?></strong></td>
            <td class="num"><?= $r['orders'] ?></td>
            <td class="num" style="font-weight:700; color:var(--primary);"><?= money($r['amount']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$purchSupp): ?><tr><td colspan="3" class="muted" style="text-align:center;padding:2rem;">No purchase data.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- A/R Aging Tab -->
<div id="rtab-ar_tab" class="rtab-content" style="display:none;">
  <div class="panel">
    <h3 style="margin-top:0;">Accounts Receivable Aging — Top Outstanding Balances</h3>
    <p class="muted" style="font-size:0.82rem; margin-bottom:1rem;">Customers with unpaid or partially paid invoice balances. Follow up to collect outstanding amounts.</p>
    <div class="table-responsive" style="margin-bottom:0;">
      <table class="table">
        <thead><tr><th>Customer</th><th class="num">Outstanding Balance (UGX)</th><th>Action</th></tr></thead>
        <tbody>
          <?php foreach ($topAR as $r): ?>
          <tr>
            <td><strong><?= h($r['name']) ?></strong></td>
            <td class="num" style="color:var(--danger); font-weight:800;"><?= money($r['outstanding']) ?></td>
            <td><a href="<?= url('modules/customers.php') ?>" class="btn btn-ghost btn-sm">View Customer</a></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$topAR): ?><tr><td colspan="3" class="muted" style="text-align:center;padding:2rem;">No outstanding A/R balances — all invoices are paid! 🎉</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Payroll Summary Tab -->
<div id="rtab-payroll_tab" class="rtab-content" style="display:none;">
  <div class="panel">
    <h3 style="margin-top:0;">Payroll Summary — Last 5 Pay Periods</h3>
    <div class="table-responsive" style="margin-bottom:0;">
      <table class="table">
        <thead><tr><th>Pay Period</th><th class="num">Employees Paid</th><th class="num">Total Gross (UGX)</th><th class="num">Total Deductions (UGX)</th><th class="num">Total Net Pay (UGX)</th></tr></thead>
        <tbody>
          <?php foreach ($lastPayroll as $r): ?>
          <tr>
            <td><strong><?= h($r['period']) ?></strong></td>
            <td class="num"><?= $r['emp'] ?></td>
            <td class="num"><?= money($r['gross']) ?></td>
            <td class="num" style="color:var(--danger);"><?= money($r['ded']) ?></td>
            <td class="num" style="color:var(--success); font-weight:700;"><?= money($r['net']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$lastPayroll): ?><tr><td colspan="5" class="muted" style="text-align:center;padding:2rem;">No payroll runs processed yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
function switchRTab(tab) {
  document.querySelectorAll('.rtab-content').forEach(el => el.style.display = 'none');
  document.querySelectorAll('[id^="rtab-btn-"]').forEach(el => {
    el.style.borderBottomColor = 'transparent';
    el.style.color = 'var(--muted)';
  });
  document.getElementById('rtab-' + tab).style.display = 'block';
  const btn = document.getElementById('rtab-btn-' + tab);
  if (btn) { btn.style.borderBottomColor = 'var(--primary)'; btn.style.color = 'var(--primary)'; }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
