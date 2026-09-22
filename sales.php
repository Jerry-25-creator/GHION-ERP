<?php
require_once __DIR__ . '/../includes/header.php';
$pdo = db();

// Ensure generic walk-in customer exists
$pdo->exec("INSERT IGNORE INTO customers (id, name, type) VALUES (1, 'Cash/Walk-in Customers', 'Individual')");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'sale') {
    csrf_check();
    $customerId = (int)$_POST['customer_id'];
    $method = $_POST['payment_method'];
    $discount = (float)($_POST['discount'] ?? 0);
    $salesman = trim($_POST['salesman'] ?? '') ?: null;
    $remarks = trim($_POST['remarks'] ?? '');
    
    $lines = $_POST['lines'] ?? [];
    $subtotal = 0; $clean = [];

    foreach ($lines as $l) {
        if (empty($l['product_id']) || (float)$l['qty'] <= 0) continue;
        $qty = (float)$l['qty']; $price = (float)$l['price'];
        $disc = (float)($l['discount'] ?? 0);
        $lineTotal = max(0, ($qty * $price) - $disc);
        $clean[] = ['pid'=>(int)$l['product_id'], 'qty'=>$qty, 'price'=>$price, 'disc'=>$disc, 'lt'=>$lineTotal];
        $subtotal += $lineTotal;
    }

    $grandTotal = max(0, $subtotal - $discount);

    if (empty($clean)) {
        $err = 'Please add at least one valid line item.';
    } else {
        $pdo->beginTransaction();
        try {
            $inv = 'INV-' . date('Ymd') . '-' . random_int(1000, 9999);
            $paid = ($method === 'credit') ? 0 : $grandTotal;
            $status = ($method === 'credit') ? 'unpaid' : 'paid';
            $balance = $grandTotal - $paid;

            $pdo->prepare("INSERT INTO sales (invoice_no, customer_id, salesman, subtotal, discount, total, payment_method, payment_status, amount_paid, balance, remarks, user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$inv, $customerId, $salesman, $subtotal, $discount, $grandTotal, $method, $status, $paid, $balance, $remarks, $u['id']]);
            $saleId = $pdo->lastInsertId();

            $liStmt = $pdo->prepare("INSERT INTO sale_lines (sale_id, product_id, qty, unit_price, discount, line_total) VALUES (?,?,?,?,?,?)");
            $stkCheck = $pdo->prepare("SELECT fs.qty, p.name FROM products p LEFT JOIN finished_stock fs ON fs.product_id=p.id WHERE p.id=?");
            $stkUpdate = $pdo->prepare("UPDATE finished_stock SET qty = qty - ? WHERE product_id = ? AND qty >= ?");

            foreach ($clean as $c) {
                $stkCheck->execute([$c['pid']]);
                $stkInfo = $stkCheck->fetch();
                $avail = (float)($stkInfo['qty'] ?? 0);
                if ($avail < $c['qty']) throw new Exception("Insufficient stock for " . ($stkInfo['name'] ?? 'Product') . ". Available: " . money($avail) . " pcs, Requested: " . money($c['qty']) . " pcs.");
                $liStmt->execute([$saleId, $c['pid'], $c['qty'], $c['price'], $c['disc'], $c['lt']]);
                $stkUpdate->execute([$c['qty'], $c['pid'], $c['qty']]);
                $pdo->prepare("INSERT INTO stock_movements (item_type, item_id, qty, from_state, to_state, movement_type, reference, user_id) VALUES ('finished_good', ?, ?, 'Finished Goods Warehouse', 'Customer Delivery', 'sale', ?, ?)")
                    ->execute([$c['pid'], $c['qty'], $inv, $u['id']]);
            }

            $acctCode = ['cash'=>'1300','bank'=>'1310','mobile_money'=>'1320','credit'=>'1200'][$method];
            $drAccId = $pdo->query("SELECT id FROM accounts WHERE code='$acctCode'")->fetch()['id'] ?? null;
            $crAccId = $pdo->query("SELECT id FROM accounts WHERE code='4000'")->fetch()['id'] ?? null;
            if ($drAccId && $crAccId) {
                $pdo->prepare("INSERT INTO journal_entries (entry_no, source_type, source_id, narration, posted_by) VALUES (?, 'sale', ?, ?, ?)")
                    ->execute(['JE-' . $inv, $saleId, "Sale Invoice $inv (" . ucfirst($method) . ")", $u['id']]);
                $jeId = $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO journal_lines (entry_id, account_id, debit, credit) VALUES (?, ?, ?, 0)")->execute([$jeId, $drAccId, $grandTotal]);
                $pdo->prepare("INSERT INTO journal_lines (entry_id, account_id, debit, credit) VALUES (?, ?, 0, ?)")->execute([$jeId, $crAccId, $grandTotal]);
            }
            if ($paid > 0) {
                $cashAccType = ['cash'=>'cash','bank'=>'bank','mobile_money'=>'mobile_money'][$method] ?? null;
                if ($cashAccType) $pdo->prepare("UPDATE cash_accounts SET balance = balance + ? WHERE type = ? LIMIT 1")->execute([$paid, $cashAccType]);
            }
            $pdo->commit();
            audit('create', 'sales', $inv, null, $_POST);
            $ok = "Invoice <strong>$inv</strong> created for " . money($grandTotal) . " UGX ($status). Stock &amp; ledger updated.";
        } catch (Throwable $e) { $pdo->rollBack(); $err = 'Error saving sale: ' . $e->getMessage(); }
    }
}

// Ghion ERP: Edit / Revise Sales Invoice
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'edit_sale') {
    csrf_check();
    $saleId = (int)$_POST['sale_id'];
    $customerId = (int)$_POST['customer_id'];
    $method = $_POST['payment_method'];
    $discount = (float)($_POST['discount'] ?? 0);
    $salesman = trim($_POST['salesman'] ?? '') ?: null;
    $remarks = trim($_POST['remarks'] ?? '');

    $oldSale = $pdo->query("SELECT * FROM sales WHERE id=$saleId")->fetch();
    if (!$oldSale) { $err = 'Invoice not found.'; } else {
        $lines = $_POST['lines'] ?? [];
        $subtotal = 0; $clean = [];
        foreach ($lines as $l) {
            if (empty($l['product_id']) || (float)$l['qty'] <= 0) continue;
            $qty=(float)$l['qty']; $price=(float)$l['price']; $disc=(float)($l['discount']??0);
            $lineTotal = max(0,($qty*$price)-$disc);
            $clean[] = ['pid'=>(int)$l['product_id'],'qty'=>$qty,'price'=>$price,'disc'=>$disc,'lt'=>$lineTotal];
            $subtotal += $lineTotal;
        }
        $grandTotal = max(0, $subtotal - $discount);
        if (empty($clean)) { $err = 'Invoice must contain at least one valid line item.'; }
        else {
            $pdo->beginTransaction();
            try {
                // Revert old finished stock
                $oldLines = $pdo->query("SELECT * FROM sale_lines WHERE sale_id=$saleId")->fetchAll();
                foreach ($oldLines as $ol) $pdo->prepare("UPDATE finished_stock SET qty = qty + ? WHERE product_id = ?")->execute([(float)$ol['qty'], $ol['product_id']]);
                $pdo->prepare("DELETE FROM sale_lines WHERE sale_id=?")->execute([$saleId]);

                $paid = ($method==='credit') ? 0 : $grandTotal;
                $status = ($method==='credit') ? 'unpaid' : 'paid';
                $balance = $grandTotal - $paid;

                $liStmt = $pdo->prepare("INSERT INTO sale_lines (sale_id, product_id, qty, unit_price, discount, line_total) VALUES (?,?,?,?,?,?)");
                $stkUpdate = $pdo->prepare("UPDATE finished_stock SET qty = qty - ? WHERE product_id = ?");
                foreach ($clean as $c) {
                    $liStmt->execute([$saleId, $c['pid'], $c['qty'], $c['price'], $c['disc'], $c['lt']]);
                    $stkUpdate->execute([$c['qty'], $c['pid']]);
                }

                $pdo->prepare("UPDATE sales SET customer_id=?, salesman=?, subtotal=?, discount=?, total=?, payment_method=?, payment_status=?, amount_paid=?, balance=?, remarks=? WHERE id=?")
                    ->execute([$customerId, $salesman, $subtotal, $discount, $grandTotal, $method, $status, $paid, $balance, $remarks, $saleId]);

                $acctCode = ['cash'=>'1300','bank'=>'1310','mobile_money'=>'1320','credit'=>'1200'][$method];
                $drAccId = $pdo->query("SELECT id FROM accounts WHERE code='$acctCode'")->fetch()['id'] ?? null;
                $crAccId = $pdo->query("SELECT id FROM accounts WHERE code='4000'")->fetch()['id'] ?? null;
                if ($drAccId && $crAccId) {
                    $pdo->prepare("DELETE FROM journal_entries WHERE source_type='sale' AND source_id=?")->execute([$saleId]);
                    $pdo->prepare("INSERT INTO journal_entries (entry_no, source_type, source_id, narration, posted_by) VALUES (?, 'sale', ?, ?, ?)")
                        ->execute(['JE-'.$oldSale['invoice_no'], $saleId, "Revised Sale Invoice ".$oldSale['invoice_no'], $u['id']]);
                    $jeId = $pdo->lastInsertId();
                    $pdo->prepare("INSERT INTO journal_lines (entry_id, account_id, debit, credit) VALUES (?, ?, ?, 0)")->execute([$jeId, $drAccId, $grandTotal]);
                    $pdo->prepare("INSERT INTO journal_lines (entry_id, account_id, debit, credit) VALUES (?, ?, 0, ?)")->execute([$jeId, $crAccId, $grandTotal]);
                }
                $pdo->commit();
                audit('update', 'sales', $oldSale['invoice_no'], $oldSale, $_POST, 'Sales invoice revised');
                $ok = "Invoice <strong>".$oldSale['invoice_no']."</strong> revised (".money($grandTotal)." UGX). Stock &amp; journals updated.";
            } catch (Throwable $e) { $pdo->rollBack(); $err = 'Failed to revise invoice: '.$e->getMessage(); }
        }
    }
}

// Handle Record Payment (from Sales page)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'quick_payment') {
    csrf_check();
    $saleId = (int)$_POST['sale_id'];
    $amount = (float)$_POST['amount'];
    $method = $_POST['method'];
    $pdo->prepare("UPDATE sales SET amount_paid=amount_paid+?, balance=balance-?, payment_status=IF(balance-?<=0,'paid','partial') WHERE id=?")
        ->execute([$amount, $amount, $amount, $saleId]);
    $ok = "Payment of ".money($amount)." UGX recorded on invoice.";
}

$customers = $pdo->query("SELECT * FROM customers WHERE active=1 ORDER BY name")->fetchAll();
$products = $pdo->query("SELECT p.*, COALESCE(fs.qty,0) stock FROM products p LEFT JOIN finished_stock fs ON fs.product_id=p.id WHERE p.active=1 ORDER BY p.name")->fetchAll();

// Invoice Status Counts
$allCount   = $pdo->query("SELECT COUNT(*) FROM sales")->fetchColumn();
$paidCount  = $pdo->query("SELECT COUNT(*) FROM sales WHERE payment_status='paid'")->fetchColumn();
$unpaidCount= $pdo->query("SELECT COUNT(*) FROM sales WHERE payment_status='unpaid'")->fetchColumn();
$partialCount=$pdo->query("SELECT COUNT(*) FROM sales WHERE payment_status='partial'")->fetchColumn();
$totalRevenue=$pdo->query("SELECT COALESCE(SUM(total),0) FROM sales")->fetchColumn();
$totalCollected=$pdo->query("SELECT COALESCE(SUM(amount_paid),0) FROM sales")->fetchColumn();

// Active filter
$filter = $_GET['status'] ?? 'all';
$whereStatus = match($filter) {
    'paid'    => "WHERE s.payment_status='paid'",
    'unpaid'  => "WHERE s.payment_status='unpaid'",
    'partial' => "WHERE s.payment_status='partial'",
    default   => ""
};
$sales = $pdo->query("SELECT s.*, c.name customer FROM sales s JOIN customers c ON c.id=s.customer_id $whereStatus ORDER BY s.id DESC LIMIT 100")->fetchAll();
?>

<div class="page-head">
  <div>
    <h1>GHION Sales Center</h1>
    <span class="muted">Create invoices, track payment status, and manage your sales pipeline</span>
  </div>
  <div style="display:flex; gap:0.5rem;">
    <a class="btn btn-ghost btn-sm" href="<?= url('modules/reports.php?export=sales') ?>">
      <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
      Export Sales (Excel)
    </a>
  </div>
</div>

<?php if (!empty($ok)): ?><div class="alert alert-ok"><?= $ok ?></div><?php endif; ?>
<?php if (!empty($err)): ?><div class="alert alert-error"><?= h($err) ?></div><?php endif; ?>

<!-- GHION Summary KPI Cards -->
<div class="cards">
  <div class="card">
    <div class="card-label">Total Revenue (Billed)</div>
    <div class="card-value" style="color:var(--primary);"><?= money($totalRevenue) ?> <span style="font-size:0.9rem;color:var(--muted);">UGX</span></div>
    <span class="muted" style="font-size:0.8rem;"><?= $allCount ?> invoices issued</span>
  </div>
  <div class="card">
    <div class="card-label">Total Collected</div>
    <div class="card-value" style="color:var(--success);"><?= money($totalCollected) ?> <span style="font-size:0.9rem;color:var(--muted);">UGX</span></div>
    <span class="muted" style="font-size:0.8rem;"><?= $paidCount ?> invoices fully paid</span>
  </div>
  <div class="card warn">
    <div class="card-label">Outstanding A/R Balance</div>
    <div class="card-value" style="color:var(--danger);"><?= money($totalRevenue - $totalCollected) ?> <span style="font-size:0.9rem;color:var(--muted);">UGX</span></div>
    <span class="muted" style="font-size:0.8rem;"><?= $unpaidCount + $partialCount ?> invoices unpaid / partial</span>
  </div>
</div>

<!-- New Sales Invoice Panel -->
<div class="panel">
  <h2 style="margin-top:0;">New Sales Invoice</h2>
  <form method="post" id="saleForm">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="form" value="sale">
    
    <div class="form-grid" style="margin-bottom:1.25rem;">
      <label>Customer *
        <select name="customer_id" required>
          <?php foreach ($customers as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $c['id']==1?'selected':'' ?>><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Sales Representative
        <input name="salesman" placeholder="e.g. John Mukasa">
      </label>
      <label>Payment Method *
        <select name="payment_method" required>
          <option value="cash">Cash (Immediate Receipt)</option>
          <option value="bank">Bank Transfer / Cheque</option>
          <option value="mobile_money">Mobile Money (MTN / Airtel)</option>
          <option value="credit">Credit / Account Receivable</option>
        </select>
      </label>
      <label>Global Invoice Discount (UGX)
        <input type="number" step="0.01" min="0" name="discount" id="globalDiscount" value="0">
      </label>
      <label class="span2">Invoice Notes / Special Instructions
        <input name="remarks" placeholder="e.g. LPO Ref #9021, Delivery to Nakawa Store">
      </label>
    </div>

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem;">
      <h3 style="margin:0;">Line Items</h3>
      <button type="button" class="btn btn-sm" onclick="addLine()">+ Add Line</button>
    </div>
    <div class="table-responsive">
      <table class="table" id="linesTable">
        <thead>
          <tr>
            <th style="width:38%;">Product</th>
            <th style="width:14%;">Qty (pcs)</th>
            <th style="width:18%;">Unit Price (UGX)</th>
            <th style="width:14%;">Line Disc.</th>
            <th class="num" style="width:14%;">Line Total</th>
            <th style="width:2%;"></th>
          </tr>
        </thead>
        <tbody>
          <tr class="line-row">
            <td>
              <select name="lines[0][product_id]" class="product-select" onchange="onProductChange(this)" required>
                <option value="">— Select Product —</option>
                <?php foreach ($products as $p): ?>
                  <option value="<?= $p['id'] ?>" data-price="<?= $p['standard_price'] ?>" data-stock="<?= $p['stock'] ?>">
                    <?= h($p['name']) ?> (Stock: <?= money($p['stock']) ?> pcs)
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input type="number" step="0.01" min="0.01" name="lines[0][qty]" class="line-qty" oninput="calcLine(this)" required placeholder="0"></td>
            <td><input type="number" step="0.01" min="0" name="lines[0][price]" class="line-price" oninput="calcLine(this)" required placeholder="0"></td>
            <td><input type="number" step="0.01" min="0" name="lines[0][discount]" class="line-disc" oninput="calcLine(this)" value="0"></td>
            <td class="num line-total" style="font-weight:700;">0</td>
            <td><button type="button" class="btn btn-ghost btn-sm" onclick="removeLine(this)">✕</button></td>
          </tr>
        </tbody>
      </table>
    </div>

    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:1rem;">
      <span class="muted" style="font-size:0.82rem;">Stock levels are validated on save. Insufficient stock will prevent submission.</span>
      <div style="text-align:right;">
        Grand Total: <strong id="grandTotalDisplay" style="font-size:1.4rem; color:var(--primary);">0</strong> UGX
      </div>
    </div>

    <div style="margin-top:1.5rem; text-align:right;">
      <button class="btn btn-primary" type="submit" style="padding:0.75rem 2rem;">
        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
        Save &amp; Complete Invoice
      </button>
    </div>
  </form>
</div>

<!-- GHION Invoice Status Bar Filter -->
<div style="display:flex; align-items:center; gap:0; margin-bottom:1rem; border-bottom:2px solid var(--border);">
  <?php foreach (['all'=>"All ($allCount)",'paid'=>"Paid ($paidCount)",'partial'=>"Partial ($partialCount)",'unpaid'=>"Unpaid ($unpaidCount)"] as $k=>$label): ?>
    <a href="?status=<?= $k ?>" style="padding:0.65rem 1.25rem; font-weight:600; font-size:0.88rem; text-decoration:none; border-bottom: 3px solid <?= $filter===$k ? 'var(--primary)' : 'transparent' ?>; color:<?= $filter===$k ? 'var(--primary)' : 'var(--muted)' ?>; margin-bottom:-2px;">
      <?= $label ?>
    </a>
  <?php endforeach; ?>
</div>

<!-- Sales History Table -->
<div class="table-responsive">
  <table class="table">
    <thead>
      <tr>
        <th>Invoice No.</th>
        <th>Date</th>
        <th>Customer</th>
        <th>Salesman</th>
        <th>Payment Method</th>
        <th class="num">Total (UGX)</th>
        <th class="num">Paid</th>
        <th class="num">Balance</th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($sales as $s): ?>
      <tr>
        <td><strong><?= h($s['invoice_no']) ?></strong></td>
        <td style="font-size:0.82rem;color:var(--muted);"><?= date('d M Y', strtotime($s['sale_date'])) ?></td>
        <td><?= h($s['customer']) ?></td>
        <td><?= h($s['salesman'] ?? '—') ?></td>
        <td><span class="badge" style="background:#f1f5f9;color:var(--ink);"><?= h(ucfirst($s['payment_method'])) ?></span></td>
        <td class="num"><strong><?= money($s['total']) ?></strong></td>
        <td class="num"><?= money($s['amount_paid']) ?></td>
        <td class="num" style="<?= $s['balance']>0 ? 'color:var(--danger);font-weight:700;' : '' ?>"><?= money($s['balance']) ?></td>
        <td><span class="badge <?= h($s['payment_status']) ?>"><?= h($s['payment_status']) ?></span></td>
        <td style="display:flex;gap:0.35rem;flex-wrap:wrap;">
          <button class="btn btn-ghost btn-sm" onclick='showEditSaleModal(<?= json_encode($s) ?>)'>Edit</button>
          <?php if ($s['balance'] > 0): ?>
            <button class="btn btn-ghost btn-sm" style="color:var(--success);" onclick='showPayModal(<?= $s['id'] ?>, <?= $s['balance'] ?>)'>Receive Payment</button>
          <?php endif; ?>
          <button class="btn btn-ghost btn-sm" onclick='showViewInvoiceModal(<?= json_encode($s) ?>)'>View</button>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$sales): ?>
        <tr><td colspan="10" class="muted" style="text-align:center;padding:2rem;">No invoices found for this filter.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<script>
let lineIdx = 1;

function onProductChange(selectEl) {
  const opt = selectEl.options[selectEl.selectedIndex];
  const row = selectEl.closest('tr');
  const priceInput = row.querySelector('.line-price');
  if (opt && opt.dataset.price) priceInput.value = parseFloat(opt.dataset.price) || 0;
  calcLine(selectEl);
}

function calcLine(element) {
  const row = element.closest('tr');
  const qty = parseFloat(row.querySelector('.line-qty').value) || 0;
  const price = parseFloat(row.querySelector('.line-price').value) || 0;
  const disc = parseFloat(row.querySelector('.line-disc').value) || 0;
  row.querySelector('.line-total').textContent = Math.max(0, (qty * price) - disc).toLocaleString();
  updateGrandTotal();
}

function updateGrandTotal() {
  let sub = 0;
  document.querySelectorAll('#linesTable tbody tr').forEach(row => {
    const qty = parseFloat(row.querySelector('.line-qty').value) || 0;
    const price = parseFloat(row.querySelector('.line-price').value) || 0;
    const disc = parseFloat(row.querySelector('.line-disc').value) || 0;
    sub += Math.max(0, (qty * price) - disc);
  });
  const gd = parseFloat(document.getElementById('globalDiscount').value) || 0;
  document.getElementById('grandTotalDisplay').textContent = Math.max(0, sub - gd).toLocaleString();
}
document.getElementById('globalDiscount').addEventListener('input', updateGrandTotal);

function addLine() {
  const tbody = document.querySelector('#linesTable tbody');
  const newRow = tbody.rows[0].cloneNode(true);
  newRow.querySelectorAll('select,input').forEach(el => {
    el.name = el.name.replace(/\d+/, lineIdx);
    el.value = el.classList.contains('line-disc') ? 0 : '';
  });
  newRow.querySelector('.line-total').textContent = '0';
  tbody.appendChild(newRow);
  lineIdx++;
}

function removeLine(btn) {
  const tbody = document.querySelector('#linesTable tbody');
  if (tbody.rows.length > 1) { btn.closest('tr').remove(); updateGrandTotal(); }
}

function showPayModal(saleId, balance) {
  const html = `
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="quick_payment">
      <input type="hidden" name="sale_id" value="${saleId}">
      <label class="span2">Amount to Receive (UGX) — Outstanding: <strong>${balance.toLocaleString()}</strong>
        <input type="number" step="0.01" max="${balance}" name="amount" value="${balance}" required>
      </label>
      <label>Payment Method *
        <select name="method" required>
          <option value="cash">Cash</option>
          <option value="bank">Bank Transfer</option>
          <option value="mobile_money">Mobile Money</option>
        </select>
      </label>
      <div class="span2" style="margin-top:1rem;">
        <button class="btn btn-primary btn-block" type="submit">Post Payment Receipt</button>
      </div>
    </form>
  `;
  openModal('Receive Payment on Invoice', html);
}

function showViewInvoiceModal(s) {
  const html = `
    <div style="font-family:var(--font-body); max-width:600px; margin:0 auto;">
      <div style="text-align:center; border-bottom:2px solid var(--border); padding-bottom:1rem; margin-bottom:1rem;">
        <h2 style="margin:0; font-size:1.25rem;">GHION INVESTMENTS AND ENTERPRISE LTD</h2>
        <div style="font-size:0.85rem; color:var(--muted);">TAX INVOICE · ${s.invoice_no}</div>
        <div style="font-size:0.82rem; color:var(--muted);">Date: ${s.sale_date ? s.sale_date.substring(0,10) : ''}</div>
      </div>
      <table style="width:100%; border-collapse:collapse; font-size:0.9rem; margin-bottom:1rem;">
        <tr><td><strong>Customer:</strong></td><td>${s.customer}</td></tr>
        <tr><td><strong>Salesman:</strong></td><td>${s.salesman || '—'}</td></tr>
        <tr><td><strong>Payment Method:</strong></td><td>${s.payment_method || ''}</td></tr>
        ${s.remarks ? `<tr><td><strong>Notes:</strong></td><td>${s.remarks}</td></tr>` : ''}
      </table>
      <table style="width:100%; border-collapse:collapse; font-size:0.88rem;" class="table">
        <tr style="background:#f8fafc;"><td><strong>Subtotal</strong></td><td style="text-align:right;">${parseFloat(s.subtotal).toLocaleString()} UGX</td></tr>
        <tr><td>Discount</td><td style="text-align:right; color:var(--danger);">-${parseFloat(s.discount).toLocaleString()} UGX</td></tr>
        <tr style="background:var(--primary-light); font-weight:800; font-size:1rem;"><td><strong>TOTAL DUE</strong></td><td style="text-align:right; color:var(--primary);">${parseFloat(s.total).toLocaleString()} UGX</td></tr>
        <tr><td>Amount Paid</td><td style="text-align:right; color:var(--success);">${parseFloat(s.amount_paid).toLocaleString()} UGX</td></tr>
        <tr style="font-weight:700;"><td>Balance Outstanding</td><td style="text-align:right; color:var(--danger);">${parseFloat(s.balance).toLocaleString()} UGX</td></tr>
      </table>
      <div style="margin-top:1.5rem; text-align:center;">
        <button class="btn btn-primary" onclick="window.print()">Print Invoice</button>
      </div>
    </div>
  `;
  openModal('Sales Invoice — ' + s.invoice_no, html);
  document.getElementById('appModalDialog').style.maxWidth = '680px';
}

function showEditSaleModal(s) {
  const html = `
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="edit_sale">
      <input type="hidden" name="sale_id" value="${s.id}">
      <label>Customer *
        <select name="customer_id" required>
          <?php foreach ($customers as $c): ?>
            <option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Salesman
        <input name="salesman" value="${s.salesman || ''}">
      </label>
      <label>Payment Method *
        <select name="payment_method" required>
          <option value="cash" ${s.payment_method==='cash'?'selected':''}>Cash</option>
          <option value="bank" ${s.payment_method==='bank'?'selected':''}>Bank</option>
          <option value="mobile_money" ${s.payment_method==='mobile_money'?'selected':''}>Mobile Money</option>
          <option value="credit" ${s.payment_method==='credit'?'selected':''}>Credit</option>
        </select>
      </label>
      <label>Global Discount (UGX)
        <input type="number" step="0.01" min="0" name="discount" value="${s.discount || 0}">
      </label>
      <label class="span2">Invoice Notes
        <input name="remarks" value="${s.remarks || ''}">
      </label>
      <div class="span2">
        <h4 style="margin:0.5rem 0;">Line Items</h4>
        <table class="table"><thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Discount</th></tr></thead>
        <tbody>
          <tr>
            <td><select name="lines[0][product_id]" required>
              <?php foreach ($products as $p): ?><option value="<?= $p['id'] ?>" data-price="<?= $p['standard_price'] ?>"><?= h($p['name']) ?></option><?php endforeach; ?>
            </select></td>
            <td><input type="number" step="0.01" min="0.01" name="lines[0][qty]" value="${parseFloat(s.total)>0 ? 1 : 1}" required style="width:80px;"></td>
            <td><input type="number" step="0.01" min="0" name="lines[0][price]" value="${s.total}" required style="width:110px;"></td>
            <td><input type="number" step="0.01" min="0" name="lines[0][discount]" value="0" style="width:80px;"></td>
          </tr>
        </tbody></table>
      </div>
      <div class="span2" style="margin-top:1rem;">
        <button class="btn btn-primary btn-block" type="submit">Revise Invoice &amp; Update Stock/Journals</button>
      </div>
    </form>
  `;
  openModal('Edit Sales Invoice — ' + s.invoice_no, html);
  setTimeout(() => {
    const selC = document.querySelector('.modal-body select[name="customer_id"]');
    if (selC) selC.value = s.customer_id;
  }, 50);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
