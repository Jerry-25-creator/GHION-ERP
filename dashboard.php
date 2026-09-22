<?php
require_once __DIR__ . '/../includes/header.php';
$pdo = db(); $fin = is_financial();

$todayProd = $pdo->query("SELECT COALESCE(SUM(qty_produced),0) t FROM production_runs WHERE DATE(created_at)=CURDATE()")->fetch()['t'];
$todaySales = $pdo->query("SELECT COALESCE(SUM(total),0) t FROM sales WHERE DATE(sale_date)=CURDATE()")->fetch()['t'];
$receivables = $pdo->query("SELECT COALESCE(SUM(balance),0) t FROM sales WHERE balance > 0")->fetch()['t'];
$lowStock = $pdo->query("SELECT COUNT(*) t FROM raw_materials WHERE stock_qty <= 200 AND active=1")->fetch()['t'];
$pendingFin = $fin ? $pdo->query("SELECT COUNT(*) t FROM purchases WHERE financial_status='pending'")->fetch()['t'] : null;

$recentProd = $pdo->query("SELECT pr.run_no, p.name product, pr.qty_produced, pr.created_at FROM production_runs pr JOIN products p ON p.id=pr.product_id ORDER BY pr.id DESC LIMIT 5")->fetchAll();
$recentSales = $pdo->query("SELECT s.invoice_no, c.name customer, s.total, s.payment_status, s.sale_date FROM sales s JOIN customers c ON c.id=s.customer_id ORDER BY s.id DESC LIMIT 5")->fetchAll();

$cashBal = $fin ? $pdo->query("SELECT COALESCE(SUM(balance),0) t FROM cash_accounts")->fetch()['t'] : 0;
?>

<div class="page-head">
  <div>
    <h1>Executive Dashboard</h1>
    <span class="muted"><?= date('l, d F Y') ?> · Overview of Factory Operations &amp; Finances</span>
  </div>
</div>

<!-- Quick Action Bar -->
<div class="quick-actions">
  <a href="<?= url('modules/sales.php') ?>" class="action-card">
    <div class="action-icon">
      <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
    </div>
    <span>New Sale / Invoice</span>
  </a>
  <a href="<?= url('modules/production.php') ?>" class="action-card">
    <div class="action-icon">
      <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
    </div>
    <span>Log Production Run</span>
  </a>
  <a href="<?= url('modules/inventory.php') ?>" class="action-card">
    <div class="action-icon">
      <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
    </div>
    <span>Receive Jumbo Batch</span>
  </a>
  <a href="<?= url('modules/customers.php') ?>" class="action-card">
    <div class="action-icon">
      <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
    </div>
    <span>Customer Balances</span>
  </a>
</div>

<!-- KPI Cards Grid -->
<div class="cards">
  <div class="card">
    <div class="card-label">Today's Production</div>
    <div class="card-value"><?= money($todayProd) ?> <span style="font-size: 0.9rem; font-weight: 500; color: var(--muted);">pcs</span></div>
    <a href="<?= url('modules/production.php') ?>" class="card-link">Manage production &rarr;</a>
  </div>
  
  <div class="card">
    <div class="card-label">Today's Sales Revenue</div>
    <div class="card-value"><?= money($todaySales) ?> <span style="font-size: 0.9rem; font-weight: 500; color: var(--muted);">UGX</span></div>
    <a href="<?= url('modules/sales.php') ?>" class="card-link">View sales ledger &rarr;</a>
  </div>
  
  <div class="card">
    <div class="card-label">Outstanding Receivables</div>
    <div class="card-value"><?= money($receivables) ?> <span style="font-size: 0.9rem; font-weight: 500; color: var(--muted);">UGX</span></div>
    <a href="<?= url('modules/customers.php') ?>" class="card-link">Customer ledgers &rarr;</a>
  </div>
  
  <div class="card <?= $lowStock > 0 ? 'warn' : '' ?>">
    <div class="card-label">Low Raw Material Alerts</div>
    <div class="card-value"><?= money($lowStock) ?> <span style="font-size: 0.9rem; font-weight: 500; color: var(--muted);">items</span></div>
    <a href="<?= url('modules/inventory.php') ?>" class="card-link">Check inventory &rarr;</a>
  </div>

  <?php if ($fin): ?>
  <div class="card">
    <div class="card-label">Purchases Awaiting Amounts</div>
    <div class="card-value"><?= money($pendingFin) ?> <span style="font-size: 0.9rem; font-weight: 500; color: var(--muted);">orders</span></div>
    <a href="<?= url('modules/purchases.php') ?>" class="card-link">Enter purchase costs &rarr;</a>
  </div>
  <?php endif; ?>
</div>

<div class="form-grid" style="grid-template-columns: 1fr 1fr; margin-top: 1rem;">
  <!-- Recent Production Runs -->
  <div class="panel" style="margin-bottom: 0;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
      <h2 style="margin: 0;">Recent Production Runs</h2>
      <a href="<?= url('modules/production.php') ?>" class="btn btn-ghost btn-sm">View All &rarr;</a>
    </div>
    <div class="table-responsive" style="margin-bottom: 0;">
      <table class="table">
        <thead>
          <tr>
            <th>Run No.</th>
            <th>Product</th>
            <th class="num">Quantity</th>
            <th>Recorded</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentProd as $r): ?>
          <tr>
            <td><strong><?= h($r['run_no']) ?></strong></td>
            <td><?= h($r['product']) ?></td>
            <td class="num"><?= money($r['qty_produced']) ?> pcs</td>
            <td style="font-size: 0.8rem; color: var(--muted);"><?= date('d M Y, H:i', strtotime($r['created_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$recentProd): ?>
          <tr><td colspan="4" class="muted" style="text-align: center; padding: 2rem;">No production runs recorded yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Recent Sales Invoices -->
  <div class="panel" style="margin-bottom: 0;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
      <h2 style="margin: 0;">Recent Sales Invoices</h2>
      <a href="<?= url('modules/sales.php') ?>" class="btn btn-ghost btn-sm">View All &rarr;</a>
    </div>
    <div class="table-responsive" style="margin-bottom: 0;">
      <table class="table">
        <thead>
          <tr>
            <th>Invoice</th>
            <th>Customer</th>
            <th class="num">Total (UGX)</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentSales as $s): ?>
          <tr>
            <td><strong><?= h($s['invoice_no']) ?></strong></td>
            <td><?= h($s['customer']) ?></td>
            <td class="num"><?= money($s['total']) ?></td>
            <td><span class="badge <?= h($s['payment_status']) ?>"><?= h($s['payment_status']) ?></span></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$recentSales): ?>
          <tr><td colspan="4" class="muted" style="text-align: center; padding: 2rem;">No sales invoices recorded yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
