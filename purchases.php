<?php
require_once __DIR__ . '/../includes/header.php';
$pdo = db(); $fin = is_financial();

// Handle Register New Supplier
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'supplier') {
    csrf_check();
    $sname = trim($_POST['name']);
    $sphone = trim($_POST['phone']);
    $scontact = trim($_POST['contact']);
    $stin = trim($_POST['tin']);

    if (empty($sname)) {
        $err = 'Supplier name is required.';
    } else {
        $pdo->prepare("INSERT INTO suppliers (name, phone, contact, tin) VALUES (?,?,?,?)")
            ->execute([$sname, $sphone, $scontact, $stin]);
        $sid = $pdo->lastInsertId();
        audit('create', 'suppliers', $sid, null, ['name'=>$sname]);
        $ok = "Supplier <strong>" . h($sname) . "</strong> registered successfully.";
    }
}

// Handle Edit Supplier
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'edit_supplier') {
    csrf_check();
    $sid = (int)$_POST['supplier_id'];
    $sname = trim($_POST['name']);
    $sphone = trim($_POST['phone']);
    $scontact = trim($_POST['contact']);
    $stin = trim($_POST['tin']);

    if (empty($sname)) {
        $err = 'Supplier name is required.';
    } else {
        $pdo->prepare("UPDATE suppliers SET name=?, phone=?, contact=?, tin=? WHERE id=?")
            ->execute([$sname, $sphone, $scontact, $stin, $sid]);
        audit('update', 'suppliers', $sid, null, $_POST);
        $ok = "Supplier <strong>" . h($sname) . "</strong> updated successfully.";
    }
}

// Accountant / Operational User: Receive Purchase
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'receipt') {
    csrf_check();
    $ref = trim($_POST['reference']);
    $supId = (int)$_POST['supplier_id'];
    $itemType = $_POST['item_type'];
    $itemName = trim($_POST['item_name']);
    $qty = (float)$_POST['qty'];
    $uom = $_POST['uom'];
    $batchNo = trim($_POST['batch_no'] ?? '') ?: null;
    $notes = trim($_POST['notes'] ?? '');

    if (empty($ref) || empty($itemName) || $qty <= 0) {
        $err = 'Please fill in all required purchase receipt fields.';
    } else {
        $pdo->prepare("INSERT INTO purchases (reference, supplier_id, item_type, item_name, qty, uom, batch_no, notes, received_by)
                       VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$ref, $supId, $itemType, $itemName, $qty, $uom, $batchNo, $notes, $u['id']]);
        
        audit('create', 'purchases', $ref, null, ['item'=>$itemName, 'qty'=>$qty]);
        $ok = "Purchase receipt <strong>" . h($ref) . "</strong> recorded. Financial unit cost pending (Director/Consultant entry).";
    }
}

// Director / Consultant: Enter or Edit Financial Amounts & Post to Accounting
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['form'] ?? '') === 'price' || ($_POST['form'] ?? '') === 'edit_price') && $fin) {
    csrf_check();
    $purId = (int)$_POST['purchase_id'];
    $unitCost = (float)$_POST['unit_cost'];
    
    $purStmt = $pdo->prepare("SELECT * FROM purchases WHERE id=?");
    $purStmt->execute([$purId]);
    $purchase = $purStmt->fetch();

    if (!$purchase) {
        $err = 'Purchase record not found.';
    } else {
        $qty = (float)$purchase['qty'];
        $totalCost = $unitCost * $qty;

        $pdo->beginTransaction();
        try {
            // 1. Update Purchases table
            $pdo->prepare("UPDATE purchases SET unit_cost=?, total_cost=?, financial_status='complete', priced_by=? WHERE id=?")
                ->execute([$unitCost, $totalCost, $u['id'], $purId]);

            // 2. If purchase is linked to a Jumbo Batch, update Batch price_per_tonne / total_cost
            if ($purchase['batch_no']) {
                $pricePerTonne = ($purchase['uom'] === 'tonne') ? $unitCost : ($unitCost * 1000);
                $pdo->prepare("UPDATE batches SET price_per_tonne=?, total_cost=? WHERE batch_no=?")
                    ->execute([$pricePerTonne, $totalCost, $purchase['batch_no']]);
            }

            // 3. Post/Update Accounting Journal: Dr Raw Materials/Inventory (1000)  Cr Accounts Payable (2000)
            $drAccId = $pdo->query("SELECT id FROM accounts WHERE code='1000'")->fetch()['id'] ?? null;
            $crAccId = $pdo->query("SELECT id FROM accounts WHERE code='2000'")->fetch()['id'] ?? null;

            if ($drAccId && $crAccId) {
                $pdo->prepare("DELETE FROM journal_entries WHERE source_type='purchase' AND source_id=?")->execute([$purId]);

                $pdo->prepare("INSERT INTO journal_entries (entry_no, source_type, source_id, narration, posted_by) VALUES (?, 'purchase', ?, ?, ?)")
                    ->execute(['JE-PUR-' . $purchase['reference'], $purId, "Purchase " . $purchase['reference'] . " (" . $purchase['item_name'] . ")", $u['id']]);
                $jeId = $pdo->lastInsertId();

                $pdo->prepare("INSERT INTO journal_lines (entry_id, account_id, debit, credit) VALUES (?, ?, ?, 0)")
                    ->execute([$jeId, $drAccId, $totalCost]);
                $pdo->prepare("INSERT INTO journal_lines (entry_id, account_id, debit, credit) VALUES (?, ?, 0, ?)")
                    ->execute([$jeId, $crAccId, $totalCost]);
            }

            $pdo->commit();
            audit('update', 'purchases', $purId, ['old_cost'=>$purchase['unit_cost']], ['unit_cost'=>$unitCost, 'total_cost'=>$totalCost], 'Financial pricing attached/revised');
            $ok = "Financial cost attached for <strong>" . h($purchase['reference']) . "</strong> (" . money($totalCost) . " UGX). Journal updated.";
        } catch (Throwable $e) {
            $pdo->rollBack();
            $err = 'Failed to update purchase pricing: ' . $e->getMessage();
        }
    }
}

// Edit Purchase Transaction Details
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'edit_purchase') {
    csrf_check();
    $purId = (int)$_POST['purchase_id'];
    $ref = trim($_POST['reference']);
    $supId = (int)$_POST['supplier_id'];
    $itemName = trim($_POST['item_name']);
    $qty = (float)$_POST['qty'];
    $uom = $_POST['uom'];
    $batchNo = trim($_POST['batch_no'] ?? '') ?: null;
    $notes = trim($_POST['notes'] ?? '');

    $purStmt = $pdo->prepare("SELECT * FROM purchases WHERE id=?");
    $purStmt->execute([$purId]);
    $oldPur = $purStmt->fetch();

    if ($oldPur) {
        $unitCost = $oldPur['unit_cost'] !== null ? (float)$oldPur['unit_cost'] : null;
        $totalCost = $unitCost !== null ? ($unitCost * $qty) : null;

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE purchases SET reference=?, supplier_id=?, item_name=?, qty=?, uom=?, batch_no=?, notes=?, total_cost=? WHERE id=?")
                ->execute([$ref, $supId, $itemName, $qty, $uom, $batchNo, $notes, $totalCost, $purId]);

            if ($totalCost !== null) {
                $pdo->prepare("UPDATE journal_lines jl JOIN journal_entries je ON je.id=jl.entry_id SET jl.debit = IF(jl.debit > 0, ?, 0), jl.credit = IF(jl.credit > 0, ?, 0) WHERE je.source_type='purchase' AND je.source_id=?")
                    ->execute([$totalCost, $totalCost, $purId]);
            }

            $pdo->commit();
            audit('update', 'purchases', $purId, $oldPur, $_POST, 'Purchase transaction revised');
            $ok = "Purchase record <strong>" . h($ref) . "</strong> revised successfully.";
        } catch (Throwable $e) {
            $pdo->rollBack();
            $err = 'Error editing purchase: ' . $e->getMessage();
        }
    }
}

$suppliers = $pdo->query("SELECT * FROM suppliers WHERE active=1 ORDER BY name")->fetchAll();
$pending = $pdo->query("SELECT p.id, p.reference, p.supplier_id, p.item_name, p.qty, p.uom, p.batch_no, p.notes, p.financial_status, p.created_at, s.name supplier
                        FROM purchases p JOIN suppliers s ON s.id=p.supplier_id WHERE p.financial_status='pending' ORDER BY p.id DESC")->fetchAll();

$cols = $fin ? ", p.unit_cost, p.total_cost" : "";
$history = $pdo->query("SELECT p.id, p.reference, p.supplier_id, p.item_name, p.qty, p.uom, p.batch_no, p.notes, p.financial_status, p.created_at, s.name supplier $cols
                        FROM purchases p JOIN suppliers s ON s.id=p.supplier_id ORDER BY p.id DESC LIMIT 50")->fetchAll();

// Vendor Accounts Payable Summary
$vendorSummary = $pdo->query("SELECT s.id, s.name, s.phone, s.contact, s.tin,
  COUNT(p.id) deliveries_count" . ($fin ? ", COALESCE(SUM(p.total_cost),0) total_purchases" : "") . "
  FROM suppliers s LEFT JOIN purchases p ON p.supplier_id=s.id
  GROUP BY s.id ORDER BY s.name")->fetchAll();

$totPurchasesVal = $fin ? $pdo->query("SELECT COALESCE(SUM(total_cost),0) FROM purchases WHERE financial_status='complete'")->fetchColumn() : 0;
?>

<div class="page-head">
  <div>
    <h1>GHION Vendor &amp; Expense Center</h1>
    <span class="muted">Operational material receipts, supplier profiles, and Accounts Payable tracking</span>
  </div>
</div>

<?php if (!empty($ok)): ?><div class="alert alert-ok"><?= $ok ?></div><?php endif; ?>
<?php if (!empty($err)): ?><div class="alert alert-error"><?= h($err) ?></div><?php endif; ?>

<!-- Summary Cards Grid -->
<div class="cards">
  <div class="card">
    <div class="card-label">Open Bills / Pending Pricing</div>
    <div class="card-value" style="color: var(--warning);"><?= count($pending) ?> <span style="font-size:0.9rem; color:var(--muted);">deliveries</span></div>
    <span class="muted" style="font-size: 0.8rem;">Awaiting Director cost pricing</span>
  </div>

  <?php if ($fin): ?>
  <div class="card">
    <div class="card-label">Total Completed Purchases</div>
    <div class="card-value" style="color: var(--primary);"><?= money($totPurchasesVal) ?> <span style="font-size:0.9rem; color:var(--muted);">UGX</span></div>
    <span class="muted" style="font-size: 0.8rem;">Priced vendor inventory bills</span>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-label">Registered Vendors / Suppliers</div>
    <div class="card-value"><?= count($suppliers) ?> <span style="font-size:0.9rem; color:var(--muted);">suppliers</span></div>
    <span class="muted" style="font-size: 0.8rem;">Active raw material vendors</span>
  </div>
</div>

<div class="form-grid" style="grid-template-columns: 1fr 1fr;">
  <!-- Receive Supplier Delivery Form -->
  <div class="panel">
    <h2 style="margin-top:0;">Receive Supplier Delivery</h2>
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="receipt">

      <label>Supplier Invoice / Delivery Ref *
        <input name="reference" placeholder="e.g. SUP-INV-9901" required>
      </label>

      <label>Supplier *
        <select name="supplier_id" required>
          <option value="">— Select Supplier —</option>
          <?php foreach ($suppliers as $s): ?>
            <option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>Item Classification
        <select name="item_type">
          <option>Jumbo Raw Material</option>
          <option>Packaging</option>
          <option>Core Paper</option>
          <option>Factory Materials</option>
          <option>Repairs &amp; Maintenance</option>
          <option>Services</option>
          <option>Other</option>
        </select>
      </label>

      <label>Item / Material Description *
        <input name="item_name" placeholder="e.g. Virgin Jumbo Roll 800mm" required>
      </label>

      <label>Quantity Delivered *
        <input type="number" step="0.01" min="0.01" name="qty" placeholder="1000" required>
      </label>

      <label>Unit of Measure (UOM)
        <select name="uom">
          <option>kg</option>
          <option>pcs</option>
          <option>sack</option>
          <option>tonne</option>
          <option>service</option>
        </select>
      </label>

      <label>Jumbo Batch No. (if applicable)
        <input name="batch_no" placeholder="e.g. JB-2026-089">
      </label>

      <label class="span2">Delivery Notes
        <input name="notes" placeholder="e.g. Received in good condition at main store">
      </label>

      <div class="span2" style="margin-top: 0.5rem;">
        <button class="btn btn-primary" type="submit">
          <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          <span>Save Purchase Receipt</span>
        </button>
      </div>
    </form>
  </div>

  <!-- Add New Supplier Panel -->
  <div class="panel">
    <h2 style="margin-top:0;">Register New Supplier / Vendor</h2>
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="supplier">

      <label class="span2">Supplier / Vendor Company Name *
        <input name="name" placeholder="e.g. Century Paper Mill Ltd" required>
      </label>

      <label>Phone Number
        <input name="phone" placeholder="+256 700 000 000">
      </label>

      <label>Contact Representative
        <input name="contact" placeholder="Sales Manager">
      </label>

      <label class="span2">TIN Number
        <input name="tin" placeholder="1000987654">
      </label>

      <div class="span2" style="margin-top: 0.5rem;">
        <button class="btn btn-primary" type="submit">
          <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
          <span>Save Supplier Profile</span>
        </button>
      </div>
    </form>
  </div>
</div>

<!-- GHION Vendor Accounts Payable Summary Table -->
<h2>Vendor Accounts Summary (Suppliers)</h2>
<div class="table-responsive">
  <table class="table">
    <thead>
      <tr>
        <th>Vendor / Supplier Name</th>
        <th>Phone</th>
        <th>Contact Person</th>
        <th>TIN</th>
        <th class="num">Deliveries Logged</th>
        <?php if ($fin): ?><th class="num">Total Billed Purchases (UGX)</th><?php endif; ?>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($vendorSummary as $v): ?>
      <tr>
        <td><strong><?= h($v['name']) ?></strong></td>
        <td><?= h($v['phone'] ?? '—') ?></td>
        <td><?= h($v['contact'] ?? '—') ?></td>
        <td><?= h($v['tin'] ?? '—') ?></td>
        <td class="num"><?= money($v['deliveries_count']) ?></td>
        <?php if ($fin): ?>
          <td class="num" style="font-weight:700; color:var(--primary);"><?= money($v['total_purchases']) ?></td>
        <?php endif; ?>
        <td>
          <button class="btn btn-ghost btn-sm" onclick='showEditSupplierModal(<?= json_encode($v) ?>)'>Edit Profile</button>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Purchases Awaiting Financial Amounts -->
<h2>Open Vendor Bills Awaiting Financial Pricing (<?= count($pending) ?>)</h2>
<div class="table-responsive">
  <table class="table">
    <thead>
      <tr>
        <th>Reference</th>
        <th>Supplier</th>
        <th>Item Description</th>
        <th class="num">Quantity</th>
        <th>Date Received</th>
        <th>Financial Action</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($pending as $p): ?>
      <tr>
        <td><strong><?= h($p['reference']) ?></strong></td>
        <td><?= h($p['supplier']) ?></td>
        <td><?= h($p['item_name']) ?></td>
        <td class="num"><?= money($p['qty']) ?> <?= h($p['uom']) ?></td>
        <td style="font-size: 0.82rem; color: var(--muted);"><?= date('d M Y, H:i', strtotime($p['created_at'])) ?></td>
        <td>
          <?php if ($fin): ?>
            <form method="post" style="display: flex; gap: 0.5rem; align-items: center;">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="form" value="price">
              <input type="hidden" name="purchase_id" value="<?= $p['id'] ?>">
              
              <input type="number" step="0.01" min="0" name="unit_cost" placeholder="Unit cost (UGX)" required style="width: 140px; padding: 0.35rem 0.6rem;">
              <button class="btn btn-primary btn-sm" type="submit">Attach Cost</button>
            </form>
          <?php else: ?>
            <span class="muted" style="font-size: 0.8rem;">Confidential (Director/Consultant action required)</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$pending): ?>
      <tr><td colspan="6" class="muted" style="text-align: center; padding: 2rem;">No pending purchases awaiting financial pricing.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Completed Purchase History -->
<h2>Purchase History Log</h2>
<div class="table-responsive">
  <table class="table">
    <thead>
      <tr>
        <th>Reference</th>
        <th>Supplier</th>
        <th>Item Description</th>
        <th class="num">Quantity</th>
        <th>Status</th>
        <th>Date</th>
        <?php if ($fin): ?><th class="num">Total Cost (UGX)</th><?php endif; ?>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($history as $p): ?>
      <tr>
        <td><strong><?= h($p['reference']) ?></strong></td>
        <td><?= h($p['supplier']) ?></td>
        <td><?= h($p['item_name']) ?></td>
        <td class="num"><?= money($p['qty']) ?> <?= h($p['uom']) ?></td>
        <td><span class="badge <?= h($p['financial_status']) ?>"><?= h($p['financial_status']) ?></span></td>
        <td style="font-size: 0.82rem; color: var(--muted);"><?= date('d M Y, H:i', strtotime($p['created_at'])) ?></td>
        <?php if ($fin): ?>
          <td class="num" style="font-weight: 700; color: var(--ink);"><?= $p['total_cost'] !== null ? money($p['total_cost']) : '—' ?></td>
        <?php endif; ?>
        <td>
          <button class="btn btn-ghost btn-sm" onclick='showEditPurchaseModal(<?= json_encode($p) ?>, <?= $fin?1:0 ?>)'>Edit</button>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
function showEditSupplierModal(v) {
  const html = `
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="edit_supplier">
      <input type="hidden" name="supplier_id" value="${v.id}">

      <label class="span2">Supplier Name *
        <input name="name" value="${v.name || ''}" required>
      </label>

      <label>Phone Number
        <input name="phone" value="${v.phone || ''}">
      </label>

      <label>Contact Representative
        <input name="contact" value="${v.contact || ''}">
      </label>

      <label class="span2">TIN Number
        <input name="tin" value="${v.tin || ''}">
      </label>

      <div class="span2" style="margin-top: 1rem;">
        <button class="btn btn-primary btn-block" type="submit">Update Supplier Profile</button>
      </div>
    </form>
  `;
  openModal('Edit Supplier — ' + v.name, html);
}

function showEditPurchaseModal(p, isFin) {
  const html = `
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="edit_purchase">
      <input type="hidden" name="purchase_id" value="${p.id}">

      <label>Supplier Invoice / Ref *
        <input name="reference" value="${p.reference || ''}" required>
      </label>

      <label>Supplier *
        <select name="supplier_id" required>
          <?php foreach ($suppliers as $s): ?>
            <option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="span2">Item Description *
        <input name="item_name" value="${p.item_name || ''}" required>
      </label>

      <label>Quantity *
        <input type="number" step="0.01" min="0.01" name="qty" value="${p.qty || ''}" required>
      </label>

      <label>UOM
        <select name="uom">
          <option ${p.uom==='kg'?'selected':''}>kg</option>
          <option ${p.uom==='pcs'?'selected':''}>pcs</option>
          <option ${p.uom==='sack'?'selected':''}>sack</option>
          <option ${p.uom==='tonne'?'selected':''}>tonne</option>
          <option ${p.uom==='service'?'selected':''}>service</option>
        </select>
      </label>

      <label>Jumbo Batch No.
        <input name="batch_no" value="${p.batch_no || ''}">
      </label>

      <label class="span2">Delivery Notes
        <input name="notes" value="${p.notes || ''}">
      </label>

      <div class="span2" style="margin-top: 1rem;">
        <button class="btn btn-primary btn-block" type="submit">Revise Purchase Transaction</button>
      </div>
    </form>
    ${isFin ? `
      <hr style="margin: 1.5rem 0; border: none; border-top: 1px solid var(--border);">
      <h3>Revise Financial Unit Cost (Director / Consultant)</h3>
      <form method="post" style="display:flex; gap:0.5rem; align-items:center;">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="form" value="edit_price">
        <input type="hidden" name="purchase_id" value="${p.id}">
        <input type="number" step="0.01" min="0" name="unit_cost" value="${p.unit_cost || ''}" placeholder="Unit cost" required style="flex:1;">
        <button class="btn btn-primary" type="submit">Update Cost &amp; Post Journal</button>
      </form>
    ` : ''}
  `;
  openModal('Edit Purchase Transaction — ' + p.reference, html);
  setTimeout(() => {
    const sel = document.querySelector('.modal-body select[name="supplier_id"]');
    if (sel) sel.value = p.supplier_id;
  }, 50);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
