<?php
require_once __DIR__ . '/../includes/header.php';
$pdo = db();

// Handle New Customer Registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'customer') {
    csrf_check();
    $name = trim($_POST['name']);
    $type = $_POST['type'];
    $phone = trim($_POST['phone']);
    $contact = trim($_POST['contact']);
    $address = trim($_POST['address']);
    $tin = trim($_POST['tin']);
    $opening = (float)($_POST['opening_balance'] ?? 0);

    if (empty($name)) {
        $err = 'Customer name is required.';
    } else {
        $pdo->prepare("INSERT INTO customers (name, type, phone, contact, address, tin, opening_balance) VALUES (?,?,?,?,?,?,?)")
            ->execute([$name, $type, $phone, $contact, $address, $tin, $opening]);
        $cid = $pdo->lastInsertId();
        audit('create', 'customers', $cid, null, ['name'=>$name]);
        $ok = "Customer <strong>" . h($name) . "</strong> registered successfully.";
    }
}

// Handle Edit Customer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'edit_customer') {
    csrf_check();
    $cid = (int)$_POST['customer_id'];
    $name = trim($_POST['name']);
    $type = $_POST['type'];
    $phone = trim($_POST['phone']);
    $contact = trim($_POST['contact']);
    $address = trim($_POST['address']);
    $tin = trim($_POST['tin']);
    $active = (int)($_POST['active'] ?? 1);

    if (empty($name)) {
        $err = 'Customer name is required.';
    } else {
        $pdo->prepare("UPDATE customers SET name=?, type=?, phone=?, contact=?, address=?, tin=?, active=? WHERE id=?")
            ->execute([$name, $type, $phone, $contact, $address, $tin, $active, $cid]);
        audit('update', 'customers', $cid, null, $_POST);
        $ok = "Customer <strong>" . h($name) . "</strong> updated successfully.";
    }
}

// Handle Receive Customer Payment against Outstanding Balance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'payment') {
    csrf_check();
    $customerId = (int)$_POST['customer_id'];
    $amount = (float)$_POST['amount'];
    $method = $_POST['method'];
    $reference = trim($_POST['reference'] ?? '');

    if ($amount <= 0) {
        $err = 'Payment amount must be greater than zero.';
    } else {
        $pdo->beginTransaction();
        try {
            $rcptNo = 'RCPT-' . date('Ymd') . '-' . random_int(100, 999);
            
            $pdo->prepare("INSERT INTO payments (receipt_no, customer_id, amount, method, reference, received_by) VALUES (?,?,?,?,?,?)")
                ->execute([$rcptNo, $customerId, $amount, $method, $reference, $u['id']]);

            // Allocate payment to unpaid/partial sales invoices for this customer
            $salesStmt = $pdo->prepare("SELECT id, balance FROM sales WHERE customer_id = ? AND balance > 0 ORDER BY id ASC");
            $salesStmt->execute([$customerId]);
            $unpaidSales = $salesStmt->fetchAll();

            $remAmount = $amount;
            $updateSale = $pdo->prepare("UPDATE sales SET amount_paid = amount_paid + ?, balance = balance - ?, payment_status = IF(balance - ? <= 0, 'paid', 'partial') WHERE id = ?");

            foreach ($unpaidSales as $s) {
                if ($remAmount <= 0) break;
                $alloc = min($remAmount, (float)$s['balance']);
                $updateSale->execute([$alloc, $alloc, $alloc, $s['id']]);
                $remAmount -= $alloc;
            }

            // Accounting Journal: Dr Cash/Bank/Mobile Money (1300/1310/1320)  Cr Trade Receivables (1200)
            $drAccCode = ['cash'=>'1300','bank'=>'1310','mobile_money'=>'1320'][$method];
            $drAccId = $pdo->query("SELECT id FROM accounts WHERE code='$drAccCode'")->fetch()['id'] ?? null;
            $crAccId = $pdo->query("SELECT id FROM accounts WHERE code='1200'")->fetch()['id'] ?? null;

            if ($drAccId && $crAccId) {
                $pdo->prepare("INSERT INTO journal_entries (entry_no, source_type, source_id, narration, posted_by) VALUES (?, 'payment', ?, ?, ?)")
                    ->execute(['JE-' . $rcptNo, $customerId, "Customer Receipt $rcptNo (" . ucfirst($method) . ")", $u['id']]);
                $jeId = $pdo->lastInsertId();

                $pdo->prepare("INSERT INTO journal_lines (entry_id, account_id, debit, credit) VALUES (?, ?, ?, 0)")
                    ->execute([$jeId, $drAccId, $amount]);
                $pdo->prepare("INSERT INTO journal_lines (entry_id, account_id, debit, credit) VALUES (?, ?, 0, ?)")
                    ->execute([$jeId, $crAccId, $amount]);
            }

            // Update Cash Accounts Balance
            $cashAccType = ['cash'=>'cash','bank'=>'bank','mobile_money'=>'mobile_money'][$method] ?? null;
            if ($cashAccType) {
                $pdo->prepare("UPDATE cash_accounts SET balance = balance + ? WHERE type = ? LIMIT 1")
                    ->execute([$amount, $cashAccType]);
            }

            $pdo->commit();
            audit('create', 'payments', $rcptNo, null, $_POST);
            $ok = "Payment receipt <strong>$rcptNo</strong> of " . money($amount) . " UGX recorded. Customer ledger updated.";
        } catch (Throwable $e) {
            $pdo->rollBack();
            $err = 'Failed to record payment: ' . $e->getMessage();
        }
    }
}

// Fetch Customer Accounts with Aging Breakdown
$rows = $pdo->query("SELECT c.*, COALESCE((SELECT SUM(balance) FROM sales s WHERE s.customer_id=c.id),0) + c.opening_balance balance
                     FROM customers c WHERE c.id != 1 ORDER BY c.name")->fetchAll();

// Calculate Accounts Receivable Aging Summary (Current 0-30d, 31-60d, 61-90d, 90+d)
$agingData = [];
foreach ($rows as $c) {
    $cid = $c['id'];
    $sales = $pdo->query("SELECT balance, DATEDIFF(CURDATE(), sale_date) days FROM sales WHERE customer_id=$cid AND balance > 0")->fetchAll();
    
    $c30 = 0; $c60 = 0; $c90 = 0; $c90Plus = 0;
    foreach ($sales as $s) {
        $bal = (float)$s['balance'];
        $d = (int)$s['days'];
        if ($d <= 30) $c30 += $bal;
        elseif ($d <= 60) $c60 += $bal;
        elseif ($d <= 90) $c90 += $bal;
        else $c90Plus += $bal;
    }
    
    $agingData[$cid] = [
        'c30' => $c30 + ($c['opening_balance'] > 0 ? (float)$c['opening_balance'] : 0),
        'c60' => $c60,
        'c90' => $c90,
        'c90Plus' => $c90Plus,
        'total' => $c['balance']
    ];
}

$totReceivables = array_sum(array_column($rows, 'balance'));
$totCollected = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments")->fetchColumn();

$recentPayments = $pdo->query("SELECT p.*, c.name customer FROM payments p JOIN customers c ON c.id=p.customer_id ORDER BY p.id DESC LIMIT 20")->fetchAll();
?>

<div class="page-head">
  <div>
    <h1>GHION Customer Center</h1>
    <span class="muted">Manage customer directory, track Accounts Receivable aging, and process payment receipts</span>
  </div>
</div>

<?php if (!empty($ok)): ?><div class="alert alert-ok"><?= $ok ?></div><?php endif; ?>
<?php if (!empty($err)): ?><div class="alert alert-error"><?= h($err) ?></div><?php endif; ?>

<!-- Summary Cards Grid -->
<div class="cards">
  <div class="card warn">
    <div class="card-label">Total Open Receivables</div>
    <div class="card-value" style="color: var(--danger);"><?= money($totReceivables) ?> <span style="font-size:0.9rem; color:var(--muted);">UGX</span></div>
    <span class="muted" style="font-size: 0.8rem;">Outstanding customer credit</span>
  </div>

  <div class="card">
    <div class="card-label">Total Collections Received</div>
    <div class="card-value" style="color: var(--success);"><?= money($totCollected) ?> <span style="font-size:0.9rem; color:var(--muted);">UGX</span></div>
    <span class="muted" style="font-size: 0.8rem;">Payments processed to date</span>
  </div>

  <div class="card">
    <div class="card-label">Active Customer Accounts</div>
    <div class="card-value"><?= count($rows) ?> <span style="font-size:0.9rem; color:var(--muted);">accounts</span></div>
    <span class="muted" style="font-size: 0.8rem;">Registered wholesalers &amp; shops</span>
  </div>
</div>

<div class="form-grid" style="grid-template-columns: 1fr 1fr;">
  <!-- New Customer Registration Panel -->
  <div class="panel">
    <h2 style="margin-top:0;">Register New Customer</h2>
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="customer">

      <label class="span2">Customer / Company Name *
        <input name="name" placeholder="e.g. Kampala Distributors Ltd" required>
      </label>

      <label>Customer Category
        <select name="type">
          <option>Wholesaler</option>
          <option>Supermarket</option>
          <option>Shop/Retailer</option>
          <option>Distributor</option>
          <option>Institution</option>
          <option>Individual</option>
          <option>Other business</option>
        </select>
      </label>

      <label>Phone Number
        <input name="phone" placeholder="+256 700 000 000">
      </label>

      <label>Contact Person
        <input name="contact" placeholder="Manager Name">
      </label>

      <label>Location / Address
        <input name="address" placeholder="Industrial Area, Kampala">
      </label>

      <label>TIN Number
        <input name="tin" placeholder="1000123456">
      </label>

      <label>Opening Credit Balance (UGX)
        <input type="number" step="0.01" name="opening_balance" value="0">
      </label>

      <div class="span2" style="margin-top: 0.5rem;">
        <button class="btn btn-primary" type="submit">
          <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
          <span>Save Customer Record</span>
        </button>
      </div>
    </form>
  </div>

  <!-- Record Customer Payment Panel -->
  <div class="panel">
    <h2 style="margin-top:0;">Receive Payment / Post Receipt</h2>
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="payment">

      <label class="span2">Select Customer Account *
        <select name="customer_id" required>
          <option value="">— Select Customer —</option>
          <?php foreach ($rows as $c): ?>
            <option value="<?= $c['id'] ?>"><?= h($c['name']) ?> (Balance: <?= money($c['balance']) ?> UGX)</option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>Amount Received (UGX) *
        <input type="number" step="0.01" min="0.01" name="amount" placeholder="e.g. 500000" required>
      </label>

      <label>Payment Method *
        <select name="method" required>
          <option value="cash">Cash on Hand</option>
          <option value="bank">Bank Transfer / Cheque</option>
          <option value="mobile_money">Mobile Money</option>
        </select>
      </label>

      <label class="span2">Bank Ref / Receipt Reference
        <input name="reference" placeholder="e.g. Cheque #49021 or MM Tx ID">
      </label>

      <div class="span2" style="margin-top: 0.5rem;">
        <button class="btn btn-primary" type="submit">
          <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          <span>Post Payment &amp; Update Ledger</span>
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Accounts Receivable Aging Summary Table (GHION ERP Format) -->
<h2>Accounts Receivable (A/R) Aging Summary</h2>
<div class="table-responsive">
  <table class="table">
    <thead>
      <tr>
        <th>Customer Account</th>
        <th>Category</th>
        <th class="num">0 – 30 Days</th>
        <th class="num">31 – 60 Days</th>
        <th class="num">61 – 90 Days</th>
        <th class="num">90+ Days</th>
        <th class="num">Total Balance (UGX)</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $c): $ag = $agingData[$c['id']]; ?>
      <tr>
        <td><strong><?= h($c['name']) ?></strong></td>
        <td><span class="badge" style="background:#f1f5f9; color:var(--ink);"><?= h($c['type']) ?></span></td>
        <td class="num"><?= $ag['c30'] > 0 ? money($ag['c30']) : '—' ?></td>
        <td class="num"><?= $ag['c60'] > 0 ? money($ag['c60']) : '—' ?></td>
        <td class="num" style="<?= $ag['c90']>0?'color:var(--warning);font-weight:700;':'' ?>"><?= $ag['c90'] > 0 ? money($ag['c90']) : '—' ?></td>
        <td class="num" style="<?= $ag['c90Plus']>0?'color:var(--danger);font-weight:700;':'' ?>"><?= $ag['c90Plus'] > 0 ? money($ag['c90Plus']) : '—' ?></td>
        <td class="num" style="<?= $ag['total']>0?'color:var(--danger);font-weight:800;':'' ?>">
          <strong><?= money($ag['total']) ?></strong>
        </td>
        <td>
          <button class="btn btn-ghost btn-sm" onclick='showEditCustomerModal(<?= json_encode($c) ?>)'>Edit Profile</button>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
      <tr><td colspan="8" class="muted" style="text-align: center; padding: 2rem;">No customer accounts registered yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<h2>Recent Payment Receipts</h2>
<div class="table-responsive">
  <table class="table">
    <thead>
      <tr>
        <th>Receipt No.</th>
        <th>Date</th>
        <th>Customer</th>
        <th>Method</th>
        <th>Reference</th>
        <th class="num">Amount (UGX)</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($recentPayments as $p): ?>
      <tr>
        <td><strong><?= h($p['receipt_no']) ?></strong></td>
        <td style="font-size: 0.82rem; color: var(--muted);"><?= date('d M Y, H:i', strtotime($p['payment_date'])) ?></td>
        <td><?= h($p['customer']) ?></td>
        <td><span class="badge" style="background:#f1f5f9; color:var(--ink);"><?= h(ucfirst($p['method'])) ?></span></td>
        <td><?= h($p['reference'] ?? '—') ?></td>
        <td class="num" style="color: var(--success); font-weight: 700;"><?= money($p['amount']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
function showEditCustomerModal(c) {
  const html = `
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="edit_customer">
      <input type="hidden" name="customer_id" value="${c.id}">

      <label class="span2">Customer / Company Name *
        <input name="name" value="${c.name || ''}" required>
      </label>

      <label>Category
        <select name="type">
          <option ${c.type==='Wholesaler'?'selected':''}>Wholesaler</option>
          <option ${c.type==='Supermarket'?'selected':''}>Supermarket</option>
          <option ${c.type==='Shop/Retailer'?'selected':''}>Shop/Retailer</option>
          <option ${c.type==='Distributor'?'selected':''}>Distributor</option>
          <option ${c.type==='Institution'?'selected':''}>Institution</option>
          <option ${c.type==='Individual'?'selected':''}>Individual</option>
          <option ${c.type==='Other business'?'selected':''}>Other business</option>
        </select>
      </label>

      <label>Phone Number
        <input name="phone" value="${c.phone || ''}">
      </label>

      <label>Contact Person
        <input name="contact" value="${c.contact || ''}">
      </label>

      <label>Address / Location
        <input name="address" value="${c.address || ''}">
      </label>

      <label>TIN Number
        <input name="tin" value="${c.tin || ''}">
      </label>

      <label class="check">
        <input type="checkbox" name="active" value="1" ${c.active==1?'checked':''}> Customer Account Active
      </label>

      <div class="span2" style="margin-top: 1rem;">
        <button class="btn btn-primary btn-block" type="submit">Update Customer Profile</button>
      </div>
    </form>
  `;
  openModal('Edit Customer — ' + c.name, html);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
