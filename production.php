<?php
require_once __DIR__ . '/../includes/header.php';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'run') {
    csrf_check();
    $productId = (int)$_POST['product_id'];
    $batchId = (int)($_POST['batch_id'] ?? 0) ?: null;
    $qty = (float)$_POST['qty_produced'];
    $kg = $_POST['est_kg_consumed'] !== '' ? (float)$_POST['est_kg_consumed'] : null;
    $waste = (float)($_POST['waste_kg'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');

    if ($qty <= 0) {
        $err = 'Quantity produced must be greater than zero.';
    } else {
        $pdo->beginTransaction();
        try {
            $runNo = 'PR-' . date('Ymd') . '-' . random_int(100, 999);
            
            // Insert Production Run
            $pdo->prepare("INSERT INTO production_runs (run_no, product_id, batch_id, qty_produced, est_kg_consumed, est_kg_remaining, waste_kg, remarks, user_id)
                           VALUES (?, ?, ?, ?, ?, (SELECT IF(id IS NOT NULL, qty_remaining - ?, 0) FROM batches WHERE id = ?), ?, ?, ?)")
                ->execute([$runNo, $productId, $batchId, $qty, $kg, $kg ?? 0, $batchId, $waste, $remarks, $u['id']]);

            // Business Rule #1: Immediately increase finished goods stock without approval step
            $pdo->prepare("INSERT INTO finished_stock (product_id, qty) VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)")->execute([$productId, $qty]);
            
            $pdo->prepare("INSERT INTO stock_movements (item_type, item_id, qty, from_state, to_state, movement_type, reference, user_id)
                           VALUES ('finished_good', ?, ?, 'Production Floor', 'Finished Goods Warehouse', 'production', ?, ?)")
                ->execute([$productId, $qty, $runNo, $u['id']]);

            // Update Jumbo Batch remaining weight if batch selected
            if ($batchId && $kg) {
                $pdo->prepare("UPDATE batches SET qty_remaining = GREATEST(0, qty_remaining - ?), status = IF(qty_remaining - ? <= 0, 'consumed', 'partially_consumed') WHERE id = ?")
                    ->execute([$kg, $kg, $batchId]);
                
                $pdo->prepare("UPDATE raw_materials rm JOIN batches b ON b.material_id = rm.id SET rm.stock_qty = GREATEST(0, rm.stock_qty - ?) WHERE b.id = ?")
                    ->execute([$kg, $batchId]);

                $pdo->prepare("INSERT INTO stock_movements (item_type, item_id, batch_id, qty, from_state, to_state, movement_type, reference, user_id)
                               VALUES ('raw_material', (SELECT material_id FROM batches WHERE id=?), ?, ?, 'Stored Raw Material', 'Issued to Production', 'production_consumption', ?, ?)")
                    ->execute([$batchId, $batchId, $kg, $runNo, $u['id']]);
            }

            $pdo->commit();
            audit('create', 'production', $runNo, null, $_POST);
            $ok = "Production Run <strong>$runNo</strong> recorded successfully! Finished goods stock increased by " . money($qty) . " pcs.";
        } catch (Throwable $e) {
            $pdo->rollBack();
            $err = 'Failed to log production run: ' . $e->getMessage();
        }
    }
}

// Ghion ERP: Edit / Revise Production Run
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'edit_run') {
    csrf_check();
    $runId = (int)$_POST['run_id'];
    $productId = (int)$_POST['product_id'];
    $batchId = (int)($_POST['batch_id'] ?? 0) ?: null;
    $newQty = (float)$_POST['qty_produced'];
    $newKg = $_POST['est_kg_consumed'] !== '' ? (float)$_POST['est_kg_consumed'] : null;
    $newWaste = (float)($_POST['waste_kg'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');

    $oldRunStmt = $pdo->prepare("SELECT * FROM production_runs WHERE id=?");
    $oldRunStmt->execute([$runId]);
    $oldRun = $oldRunStmt->fetch();

    if (!$oldRun) {
        $err = 'Production run record not found.';
    } elseif ($newQty <= 0) {
        $err = 'Quantity produced must be greater than zero.';
    } else {
        $pdo->beginTransaction();
        try {
            $qtyDelta = $newQty - (float)$oldRun['qty_produced'];
            $kgDelta = ($newKg ?? 0) - ((float)$oldRun['est_kg_consumed'] ?? 0);

            if ($oldRun['product_id'] == $productId) {
                $pdo->prepare("UPDATE finished_stock SET qty = qty + ? WHERE product_id = ?")->execute([$qtyDelta, $productId]);
            } else {
                $pdo->prepare("UPDATE finished_stock SET qty = qty - ? WHERE product_id = ?")->execute([(float)$oldRun['qty_produced'], $oldRun['product_id']]);
                $pdo->prepare("INSERT INTO finished_stock (product_id, qty) VALUES (?, ?) ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)")->execute([$productId, $newQty]);
            }

            if ($oldRun['batch_id'] && $oldRun['est_kg_consumed']) {
                $pdo->prepare("UPDATE batches SET qty_remaining = qty_remaining + ? WHERE id=?")->execute([(float)$oldRun['est_kg_consumed'], $oldRun['batch_id']]);
            }
            if ($batchId && $newKg) {
                $pdo->prepare("UPDATE batches SET qty_remaining = GREATEST(0, qty_remaining - ?), status = IF(qty_remaining - ? <= 0, 'consumed', 'partially_consumed') WHERE id = ?")
                    ->execute([$newKg, $newKg, $batchId]);
            }

            $pdo->prepare("UPDATE production_runs SET product_id=?, batch_id=?, qty_produced=?, est_kg_consumed=?, waste_kg=?, remarks=? WHERE id=?")
                ->execute([$productId, $batchId, $newQty, $newKg, $newWaste, $remarks, $runId]);

            $pdo->commit();
            audit('update', 'production', $oldRun['run_no'], $oldRun, $_POST, 'Production run revised');
            $ok = "Production Run <strong>" . h($oldRun['run_no']) . "</strong> revised. Stock updated by " . ($qtyDelta >= 0 ? "+":"") . money($qtyDelta) . " pcs.";
        } catch (Throwable $e) {
            $pdo->rollBack();
            $err = 'Error editing production run: ' . $e->getMessage();
        }
    }
}

$products = $pdo->query("SELECT * FROM products WHERE active=1 ORDER BY name")->fetchAll();
$batches = $pdo->query("SELECT b.*, m.name material FROM batches b JOIN raw_materials m ON m.id=b.material_id WHERE b.qty_remaining > 0 ORDER BY b.id DESC")->fetchAll();
$runs = $pdo->query("SELECT pr.*, p.name product, m.name material, b.batch_no FROM production_runs pr JOIN products p ON p.id=pr.product_id LEFT JOIN batches b ON b.id=pr.batch_id LEFT JOIN raw_materials m ON m.id=b.material_id ORDER BY pr.id DESC LIMIT 50")->fetchAll();

$totProducedUnits = array_sum(array_column($runs, 'qty_produced'));
$totKgConsumed = array_sum(array_column($runs, 'est_kg_consumed'));
$totWasteKg = array_sum(array_column($runs, 'waste_kg'));
$yieldEfficiency = $totKgConsumed > 0 ? ($totProducedUnits / $totKgConsumed) : 0;
?>

<div class="page-head">
  <div>
    <h1>GHION Manufacturing &amp; Assembly Center</h1>
    <span class="muted">Log manufacturing output, edit production runs, and track yield scrap efficiency</span>
  </div>
</div>

<?php if (!empty($ok)): ?><div class="alert alert-ok"><?= $ok ?></div><?php endif; ?>
<?php if (!empty($err)): ?><div class="alert alert-error"><?= h($err) ?></div><?php endif; ?>

<!-- Summary Cards Grid -->
<div class="cards">
  <div class="card">
    <div class="card-label">Total Units Manufactured</div>
    <div class="card-value" style="color: var(--primary);"><?= money($totProducedUnits) ?> <span style="font-size:0.9rem; color:var(--muted);">pcs</span></div>
    <span class="muted" style="font-size: 0.8rem;">Finished goods logged</span>
  </div>

  <div class="card">
    <div class="card-label">Jumbo Raw Material Issued</div>
    <div class="card-value"><?= money($totKgConsumed) ?> <span style="font-size:0.9rem; color:var(--muted);">kg</span></div>
    <span class="muted" style="font-size: 0.8rem;">Input weight to production</span>
  </div>

  <div class="card">
    <div class="card-label">Assembly Yield Efficiency</div>
    <div class="card-value" style="color: var(--success);"><?= money($yieldEfficiency, 1) ?> <span style="font-size:0.9rem; color:var(--muted);">pcs / kg</span></div>
    <span class="muted" style="font-size: 0.8rem;">Finished output ratio</span>
  </div>
</div>

<div class="panel">
  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
    <h2 style="margin:0;">New Production Run</h2>
    <span class="badge active">Auto Stock Update Enabled</span>
  </div>

  <form method="post" class="form-grid">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="form" value="run">
    
    <label>Target Finished Product *
      <select name="product_id" required>
        <option value="">— Select Product —</option>
        <?php foreach ($products as $p): ?>
          <option value="<?= $p['id'] ?>"><?= h($p['name']) ?> (<?= h($p['code']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>Jumbo Raw Material Batch (Optional)
      <select name="batch_id">
        <option value="">— No specific batch —</option>
        <?php foreach ($batches as $b): ?>
          <option value="<?= $b['id'] ?>"><?= h($b['batch_no']) ?> · <?= h($b['material']) ?> (est. <?= money($b['qty_remaining']) ?> kg remaining)</option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>Quantity Produced (individual pcs) *
      <input type="number" step="0.01" min="0.01" name="qty_produced" placeholder="e.g. 500" required>
    </label>

    <label>Estimated Jumbo Weight Consumed (kg)
      <input type="number" step="0.01" min="0" name="est_kg_consumed" placeholder="e.g. 120.5">
    </label>

    <label>Scrap / Waste Weight (kg)
      <input type="number" step="0.01" min="0" name="waste_kg" value="0">
    </label>

    <label class="span2">Production Notes / Operator Remarks
      <input name="remarks" maxlength="255" placeholder="e.g. Shift A batch run, core roll replacement">
    </label>

    <div class="spanfull" style="margin-top: 0.5rem;">
      <button class="btn btn-primary" type="submit">
        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
        <span>Save Production Run &amp; Update Stock</span>
      </button>
    </div>
  </form>
</div>

<h2>Production History Log &amp; Transaction Edit</h2>
<div class="table-responsive">
  <table class="table">
    <thead>
      <tr>
        <th>Run No.</th>
        <th>Date &amp; Time</th>
        <th>Product</th>
        <th>Batch / Jumbo</th>
        <th class="num">Qty (pcs)</th>
        <th class="num">Est. kg Consumed</th>
        <th class="num">Est. kg Left</th>
        <th class="num">Waste (kg)</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($runs as $r): ?>
      <tr>
        <td><strong><?= h($r['run_no']) ?></strong></td>
        <td style="font-size: 0.82rem; color: var(--muted);"><?= date('d M Y, H:i', strtotime($r['created_at'])) ?></td>
        <td><?= h($r['product']) ?></td>
        <td><?= $r['batch_no'] ? h($r['batch_no']) . ' <span class="muted">(' . h($r['material']) . ')</span>' : '—' ?></td>
        <td class="num"><strong><?= money($r['qty_produced']) ?></strong></td>
        <td class="num"><?= $r['est_kg_consumed'] !== null ? money($r['est_kg_consumed']) : '—' ?></td>
        <td class="num"><?= $r['est_kg_remaining'] !== null ? money($r['est_kg_remaining']) : '—' ?></td>
        <td class="num"><?= money($r['waste_kg']) ?></td>
        <td>
          <button class="btn btn-ghost btn-sm" onclick='showEditRunModal(<?= json_encode($r) ?>)'>Edit</button>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
function showEditRunModal(r) {
  const html = `
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="edit_run">
      <input type="hidden" name="run_id" value="${r.id}">

      <label>Target Product *
        <select name="product_id" required>
          <?php foreach ($products as $p): ?>
            <option value="<?= $p['id'] ?>"><?= h($p['name']) ?> (<?= h($p['code']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>Jumbo Raw Material Batch
        <select name="batch_id">
          <option value="">— None —</option>
          <?php foreach ($batches as $b): ?>
            <option value="<?= $b['id'] ?>"><?= h($b['batch_no']) ?> · <?= h($b['material']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>Quantity Produced (pcs) *
        <input type="number" step="0.01" min="0.01" name="qty_produced" value="${r.qty_produced || ''}" required>
      </label>

      <label>Est. Jumbo Weight Consumed (kg)
        <input type="number" step="0.01" min="0" name="est_kg_consumed" value="${r.est_kg_consumed !== null ? r.est_kg_consumed : ''}">
      </label>

      <label>Waste Weight (kg)
        <input type="number" step="0.01" min="0" name="waste_kg" value="${r.waste_kg || 0}">
      </label>

      <label class="span2">Remarks
        <input name="remarks" value="${r.remarks || ''}">
      </label>

      <div class="span2" style="margin-top: 1rem;">
        <button class="btn btn-primary btn-block" type="submit">Revise Production Run &amp; Recalculate Stock</button>
      </div>
    </form>
  `;
  openModal('Edit Production Run — ' + r.run_no, html);
  setTimeout(() => {
    const selP = document.querySelector('.modal-body select[name="product_id"]');
    const selB = document.querySelector('.modal-body select[name="batch_id"]');
    if (selP) selP.value = r.product_id;
    if (selB && r.batch_id) selB.value = r.batch_id;
  }, 50);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
