<?php
require_once __DIR__ . '/../includes/header.php';
$pdo = db(); $fin = is_financial();

// Handle Create New Finished Product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'product') {
    csrf_check();
    $pcode = trim($_POST['code']);
    $pname = trim($_POST['name']);
    $pcat = trim($_POST['category']);
    $puom = $_POST['uom'] ?? 'pcs';
    $pprice = (float)($_POST['standard_price'] ?? 0);

    if (empty($pcode) || empty($pname)) {
        $err = 'Product code and name are required.';
    } else {
        $pdo->prepare("INSERT INTO products (code, name, category, uom, standard_price) VALUES (?,?,?,?,?)")
            ->execute([$pcode, $pname, $pcat, $puom, $pprice]);
        $pid = $pdo->lastInsertId();
        $pdo->prepare("INSERT IGNORE INTO finished_stock (product_id, qty) VALUES (?, 0)")->execute([$pid]);
        audit('create', 'products', $pid, null, ['code'=>$pcode, 'name'=>$pname]);
        $ok = "Product <strong>" . h($pname) . "</strong> ($pcode) created successfully.";
    }
}

// Handle Edit Finished Product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'edit_product') {
    csrf_check();
    $pid = (int)$_POST['product_id'];
    $pcode = trim($_POST['code']);
    $pname = trim($_POST['name']);
    $pcat = trim($_POST['category']);
    $pprice = (float)($_POST['standard_price'] ?? 0);
    $active = (int)($_POST['active'] ?? 1);

    if (empty($pcode) || empty($pname)) {
        $err = 'Product code and name are required.';
    } else {
        $pdo->prepare("UPDATE products SET code=?, name=?, category=?, standard_price=?, active=? WHERE id=?")
            ->execute([$pcode, $pname, $pcat, $pprice, $active, $pid]);
        audit('update', 'products', $pid, null, $_POST);
        $ok = "Product <strong>" . h($pname) . "</strong> updated successfully.";
    }
}

// Handle Create New Raw Material
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'raw_material') {
    csrf_check();
    $mcode = trim($_POST['code']);
    $mname = trim($_POST['name']);
    $muom = $_POST['uom'] ?? 'kg';

    if (empty($mcode) || empty($mname)) {
        $err = 'Material code and name are required.';
    } else {
        $pdo->prepare("INSERT INTO raw_materials (code, name, uom) VALUES (?,?,?)")
            ->execute([$mcode, $mname, $muom]);
        $mid = $pdo->lastInsertId();
        audit('create', 'raw_materials', $mid, null, ['code'=>$mcode, 'name'=>$mname]);
        $ok = "Raw material <strong>" . h($mname) . "</strong> ($mcode) created successfully.";
    }
}

// Handle Edit Raw Material
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'edit_raw_material') {
    csrf_check();
    $mid = (int)$_POST['material_id'];
    $mcode = trim($_POST['code']);
    $mname = trim($_POST['name']);

    if (empty($mcode) || empty($mname)) {
        $err = 'Material code and name are required.';
    } else {
        $pdo->prepare("UPDATE raw_materials SET code=?, name=? WHERE id=?")
            ->execute([$mcode, $mname, $mid]);
        audit('update', 'raw_materials', $mid, null, $_POST);
        $ok = "Raw material <strong>" . h($mname) . "</strong> updated successfully.";
    }
}

// Handle Jumbo Batch Operational Receipt
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'receipt') {
    csrf_check();
    $batchNo = trim($_POST['batch_no']);
    $matId = (int)$_POST['material_id'];
    $qty = (float)$_POST['qty'];
    $ref = trim($_POST['reference'] ?? '');

    $exists = $pdo->prepare("SELECT COUNT(*) FROM batches WHERE batch_no=?");
    $exists->execute([$batchNo]);
    
    if ($exists->fetchColumn() > 0) {
        $err = "Batch number '$batchNo' already exists. Duplicate batch numbers are not allowed.";
    } else {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO batches (batch_no, material_id, qty_received, qty_remaining, reference) VALUES (?,?,?,?,?)")
                ->execute([$batchNo, $matId, $qty, $qty, $ref]);
            $bid = $pdo->lastInsertId();

            $pdo->prepare("UPDATE raw_materials SET stock_qty = stock_qty + ? WHERE id = ?")->execute([$qty, $matId]);

            $pdo->prepare("INSERT INTO stock_movements (item_type, item_id, batch_id, qty, from_state, to_state, movement_type, reference, user_id)
                           VALUES ('raw_material', ?, ?, ?, 'Supplier Delivery', 'Raw Materials Store', 'receipt', ?, ?)")
                ->execute([$matId, $bid, $qty, $batchNo, $u['id']]);

            $pdo->commit();
            audit('create', 'inventory_batch', $batchNo, null, ['batch_no'=>$batchNo, 'qty'=>$qty]);
            $ok = "Jumbo Batch <strong>$batchNo</strong> received ($qty kg). Financial unit cost entry pending (Director/Consultant).";
        } catch (Throwable $e) {
            $pdo->rollBack();
            $err = 'Error receiving batch: ' . $e->getMessage();
        }
    }
}

// Handle Stocktake Physical Count Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'stocktake') {
    csrf_check();
    $itemType = $_POST['item_type'];
    $itemId = (int)$_POST['item_id'];
    $physicalQty = (float)$_POST['physical_qty'];
    $reason = trim($_POST['reason'] ?? '');

    if ($itemType === 'raw_material') {
        $systemQty = (float)$pdo->query("SELECT stock_qty FROM raw_materials WHERE id=$itemId")->fetchColumn();
    } else {
        $systemQty = (float)$pdo->query("SELECT COALESCE(qty,0) FROM finished_stock WHERE product_id=$itemId")->fetchColumn();
    }

    $variance = $physicalQty - $systemQty;
    $stkNo = 'STK-' . date('Ymd') . '-' . random_int(100, 999);

    $pdo->prepare("INSERT INTO stocktakes (stocktake_no, item_type, item_id, system_qty, physical_qty, variance, reason, counted_by, status)
                   VALUES (?,?,?,?,?,?,?,?,'counted')")
        ->execute([$stkNo, $itemType, $itemId, $systemQty, $physicalQty, $variance, $reason, $u['id']]);
    
    audit('create', 'stocktakes', $stkNo, null, ['variance'=>$variance]);
    $ok = "Stocktake count <strong>$stkNo</strong> submitted (Variance: " . money($variance) . "). Awaiting Director/Consultant posting adjustment.";
}

$materials = $pdo->query("SELECT * FROM raw_materials WHERE active=1 ORDER BY name")->fetchAll();
$products = $pdo->query("SELECT p.*, COALESCE(fs.qty,0) stock FROM products p LEFT JOIN finished_stock fs ON fs.product_id=p.id ORDER BY p.name")->fetchAll();

$stock = $pdo->query("SELECT rm.* FROM raw_materials rm WHERE active=1 ORDER BY name")->fetchAll();

$batches = $pdo->query("SELECT b.batch_no, m.name material, b.qty_received, b.qty_remaining, b.status, b.received_at" . ($fin ? ", b.price_per_tonne, b.total_cost" : "") . "
                        FROM batches b JOIN raw_materials m ON m.id=b.material_id ORDER BY b.id DESC LIMIT 50")->fetchAll();

// Calculate Total Inventory Valuation
$totFgValue = 0;
foreach ($products as $f) {
    $totFgValue += ((float)$f['stock'] * (float)$f['standard_price']);
}
$totRawKg = array_sum(array_column($stock, 'stock_qty'));
?>

<div class="page-head">
  <div>
    <h1>GHION Inventory Center &amp; Asset Valuation</h1>
    <span class="muted">Item list, stock asset valuation, jumbo roll register, and physical stocktake counts</span>
  </div>
</div>

<?php if (!empty($ok)): ?><div class="alert alert-ok"><?= $ok ?></div><?php endif; ?>
<?php if (!empty($err)): ?><div class="alert alert-error"><?= h($err) ?></div><?php endif; ?>

<!-- Summary KPI Cards -->
<div class="cards">
  <div class="card">
    <div class="card-label">Finished Goods Inventory Asset Value</div>
    <div class="card-value" style="color: var(--primary);"><?= money($totFgValue) ?> <span style="font-size:0.9rem; color:var(--muted);">UGX</span></div>
    <span class="muted" style="font-size: 0.8rem;">Current product valuation</span>
  </div>

  <div class="card">
    <div class="card-label">Raw Materials Store Balance</div>
    <div class="card-value" style="color: var(--success);"><?= money($totRawKg) ?> <span style="font-size:0.9rem; color:var(--muted);">kg</span></div>
    <span class="muted" style="font-size: 0.8rem;">Jumbo paper stock on hand</span>
  </div>

  <div class="card">
    <div class="card-label">Catalog Products &amp; Items</div>
    <div class="card-value"><?= count($products) ?> <span style="font-size:0.9rem; color:var(--muted);">products</span></div>
    <span class="muted" style="font-size: 0.8rem;">Active manufactured items</span>
  </div>
</div>

<div class="form-grid" style="grid-template-columns: 1fr 1fr;">
  <!-- Create New Finished Product -->
  <div class="panel">
    <h2 style="margin-top:0;">Add New Finished Product</h2>
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="product">

      <label>Product Code *
        <input name="code" placeholder="e.g. TP-VIR-01" required>
      </label>

      <label>Product Name *
        <input name="name" placeholder="e.g. Virgin Soft Toilet Paper 10s" required>
      </label>

      <label>Category *
        <input name="category" placeholder="Toilet Paper / Serviettes" required>
      </label>

      <label>Standard Selling Price (UGX)
        <input type="number" step="0.01" min="0" name="standard_price" placeholder="15000">
      </label>

      <div class="span2" style="margin-top: 0.5rem;">
        <button class="btn btn-primary" type="submit">
          <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
          <span>Create Finished Product</span>
        </button>
      </div>
    </form>
  </div>

  <!-- Create New Raw Material -->
  <div class="panel">
    <h2 style="margin-top:0;">Add New Raw Material</h2>
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="raw_material">

      <label>Material Code *
        <input name="code" placeholder="e.g. VJ-800" required>
      </label>

      <label>Material Name *
        <input name="name" placeholder="e.g. Virgin Jumbo Roll 800mm" required>
      </label>

      <div class="span2" style="margin-top: 0.5rem;">
        <button class="btn btn-primary" type="submit">
          <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
          <span>Create Raw Material</span>
        </button>
      </div>
    </form>
  </div>
</div>

<div class="form-grid" style="grid-template-columns: 1fr 1fr;">
  <!-- Receive Jumbo Batch Panel -->
  <div class="panel">
    <h2 style="margin-top:0;">Receive Jumbo Batch (Operational)</h2>
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="receipt">

      <label>Jumbo Batch No. *
        <input name="batch_no" placeholder="e.g. JB-2026-089" required>
      </label>

      <label>Material Type *
        <select name="material_id" required>
          <?php foreach ($materials as $m): ?>
            <option value="<?= $m['id'] ?>"><?= h($m['name']) ?> (<?= h($m['code']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>Net Delivered Weight (kg) *
        <input type="number" step="0.01" min="0.01" name="qty" placeholder="e.g. 1500" required>
      </label>

      <label>Supplier Delivery Ref
        <input name="reference" placeholder="e.g. DN-90122">
      </label>

      <div class="span2" style="margin-top: 0.5rem;">
        <button class="btn btn-primary" type="submit">Receive Batch into Store</button>
      </div>
    </form>
  </div>

  <!-- Physical Stocktake Entry Panel -->
  <div class="panel">
    <h2 style="margin-top:0;">Physical Stocktake Count</h2>
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="stocktake">

      <label>Item Category *
        <select name="item_type" id="stkItemType" onchange="toggleStkItems()" required>
          <option value="raw_material">Raw Material (kg)</option>
          <option value="finished_good">Finished Product (pcs)</option>
        </select>
      </label>

      <label>Item Name *
        <select name="item_id" id="stkItemId" required>
          <?php foreach ($materials as $m): ?>
            <option value="<?= $m['id'] ?>" class="opt-raw"><?= h($m['name']) ?> (System: <?= money($m['stock_qty']) ?> kg)</option>
          <?php endforeach; ?>
          <?php foreach ($products as $f): ?>
            <option value="<?= $f['id'] ?>" class="opt-fg" style="display:none;"><?= h($f['name']) ?> (System: <?= money($f['stock']) ?> pcs)</option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>Physical Count Quantity *
        <input type="number" step="0.01" min="0" name="physical_qty" placeholder="Actual floor count" required>
      </label>

      <label class="span2">Variance Reason / Stocktake Notes
        <input name="reason" placeholder="e.g. Damage in storage, core weight discrepancy">
      </label>

      <div class="span2" style="margin-top: 0.5rem;">
        <button class="btn btn-primary" type="submit">Submit Stocktake Count</button>
      </div>
    </form>
  </div>
</div>

<!-- GHION Inventory Asset Valuation Summary Table -->
<h2>Inventory Asset Valuation Summary</h2>
<div class="table-responsive">
  <table class="table">
    <thead>
      <tr>
        <th>Item Code</th>
        <th>Product Name</th>
        <th>Category</th>
        <th class="num">Standard Price (UGX)</th>
        <th class="num">Qty on Hand (pcs)</th>
        <th class="num">Total Asset Valuation (UGX)</th>
        <th>Status</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($products as $f): $itemVal = (float)$f['stock'] * (float)$f['standard_price']; ?>
      <tr>
        <td><strong><?= h($f['code']) ?></strong></td>
        <td><?= h($f['name']) ?></td>
        <td><span class="badge" style="background:#f1f5f9; color:var(--ink);"><?= h($f['category']) ?></span></td>
        <td class="num"><?= money($f['standard_price']) ?></td>
        <td class="num"><strong><?= money($f['stock']) ?></strong></td>
        <td class="num" style="font-weight:700; color:var(--primary);"><?= money($itemVal) ?></td>
        <td>
          <?php if ((float)$f['stock'] <= 50): ?>
            <span class="badge warn">Low Stock Alert</span>
          <?php else: ?>
            <span class="badge active">In Stock</span>
          <?php endif; ?>
        </td>
        <td>
          <button class="btn btn-ghost btn-sm" onclick='showEditProductModal(<?= json_encode($f) ?>)'>Edit Item</button>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Master Raw Materials Catalog -->
<h2>Raw Materials Master Catalog</h2>
<div class="table-responsive">
  <table class="table">
    <thead>
      <tr>
        <th>Code</th>
        <th>Name</th>
        <th class="num">Stock Balance (kg)</th>
        <th>Status</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($stock as $s): ?>
      <tr>
        <td><strong><?= h($s['code']) ?></strong></td>
        <td><?= h($s['name']) ?></td>
        <td class="num"><strong><?= money($s['stock_qty']) ?> kg</strong></td>
        <td>
          <?php if ((float)$s['stock_qty'] <= 200): ?>
            <span class="badge warn">Reorder Point Warning</span>
          <?php else: ?>
            <span class="badge active">Optimal Stock</span>
          <?php endif; ?>
        </td>
        <td>
          <button class="btn btn-ghost btn-sm" onclick='showEditRawMaterialModal(<?= json_encode($s) ?>)'>Edit Material</button>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<h2>Jumbo Batch Register</h2>
<div class="table-responsive">
  <table class="table">
    <thead>
      <tr>
        <th>Batch No.</th>
        <th>Material</th>
        <th class="num">Received (kg)</th>
        <th class="num">Remaining (kg)</th>
        <th>Status</th>
        <?php if ($fin): ?>
          <th class="num">Price / Tonne</th>
          <th class="num">Total Cost</th>
        <?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($batches as $b): ?>
      <tr>
        <td><strong><?= h($b['batch_no']) ?></strong></td>
        <td><?= h($b['material']) ?></td>
        <td class="num"><?= money($b['qty_received']) ?></td>
        <td class="num"><strong><?= money($b['qty_remaining']) ?></strong></td>
        <td><span class="badge <?= h($b['status']) ?>"><?= h(str_replace('_', ' ', $b['status'])) ?></span></td>
        <?php if ($fin): ?>
          <td class="num"><?= $b['price_per_tonne'] !== null ? money($b['price_per_tonne']) . ' UGX' : '<em class="muted">Pending</em>' ?></td>
          <td class="num"><?= $b['total_cost'] !== null ? money($b['total_cost']) . ' UGX' : '<em class="muted">Pending</em>' ?></td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
function toggleStkItems() {
  const type = document.getElementById('stkItemType').value;
  document.querySelectorAll('#stkItemId option').forEach(opt => {
    if (type === 'raw_material') {
      opt.style.display = opt.classList.contains('opt-raw') ? 'block' : 'none';
    } else {
      opt.style.display = opt.classList.contains('opt-fg') ? 'block' : 'none';
    }
  });
}

function showEditProductModal(p) {
  const html = `
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="edit_product">
      <input type="hidden" name="product_id" value="${p.id}">

      <label>Product Code *
        <input name="code" value="${p.code || ''}" required>
      </label>

      <label>Product Name *
        <input name="name" value="${p.name || ''}" required>
      </label>

      <label>Category *
        <input name="category" value="${p.category || ''}" required>
      </label>

      <label>Standard Selling Price (UGX)
        <input type="number" step="0.01" min="0" name="standard_price" value="${p.standard_price || 0}">
      </label>

      <label class="check">
        <input type="checkbox" name="active" value="1" ${p.active==1?'checked':''}> Product Active
      </label>

      <div class="span2" style="margin-top: 1rem;">
        <button class="btn btn-primary btn-block" type="submit">Update Finished Product</button>
      </div>
    </form>
  `;
  openModal('Edit Product — ' + p.name, html);
}

function showEditRawMaterialModal(m) {
  const html = `
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="edit_raw_material">
      <input type="hidden" name="material_id" value="${m.id}">

      <label>Material Code *
        <input name="code" value="${m.code || ''}" required>
      </label>

      <label>Material Name *
        <input name="name" value="${m.name || ''}" required>
      </label>

      <div class="span2" style="margin-top: 1rem;">
        <button class="btn btn-primary btn-block" type="submit">Update Raw Material</button>
      </div>
    </form>
  `;
  openModal('Edit Raw Material — ' + m.name, html);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
