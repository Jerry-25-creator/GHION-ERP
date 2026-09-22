<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('director', 'consultant');
require_once __DIR__ . '/../includes/header.php';
$pdo = db();

// Product-level costing: direct material cost baseline (weighted avg jumbo cost) + waste ratio + standard price margin
$rows = $pdo->query("SELECT p.id, p.name, p.code, p.category, p.standard_price,
  COALESCE((SELECT SUM(qty_produced) FROM production_runs WHERE product_id=p.id),0) produced,
  COALESCE((SELECT SUM(est_kg_consumed) FROM production_runs WHERE product_id=p.id),0) kg_used,
  COALESCE((SELECT SUM(waste_kg) FROM production_runs WHERE product_id=p.id),0) waste
  FROM products p WHERE p.active=1 ORDER BY p.name")->fetchAll();

$avgJumbo = $pdo->query("SELECT AVG(price_per_tonne) a FROM batches WHERE price_per_tonne IS NOT NULL")->fetch()['a'] ?? 0;
$costPerKg = $avgJumbo > 0 ? ($avgJumbo / 1000) : 0;
?>

<div class="page-head">
  <div>
    <h1>Manufacturing Costing &amp; Profitability</h1>
    <span class="muted">Confidential Product Margin Analysis · Director / Consultant Access Only</span>
  </div>
</div>

<div class="panel">
  <h2 style="margin-top:0;">Raw Material Cost Basis Overview</h2>
  <p class="muted">
    Weighted Average Jumbo Price: 
    <strong style="color:var(--primary); font-size:1.1rem;"><?= $avgJumbo ? money($avgJumbo) . ' UGX / tonne' : 'No priced batches recorded yet' ?></strong>
    <?= $costPerKg > 0 ? ' (≈ ' . money($costPerKg, 2) . ' UGX per kg raw material)' : '' ?>
  </p>
</div>

<h2>Product Material Baseline &amp; Waste Analysis</h2>
<div class="table-responsive">
  <table class="table">
    <thead>
      <tr>
        <th>Product Name</th>
        <th>Code</th>
        <th class="num">Units Produced</th>
        <th class="num">Est. Material Used (kg)</th>
        <th class="num">Waste (kg)</th>
        <th class="num">Waste Rate (%)</th>
        <th class="num">Total Material Cost (UGX)</th>
        <th class="num">Est. Cost / Unit</th>
        <th class="num">Standard Selling Price</th>
        <th class="num">Est. Material Margin</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): 
        $wasteRate = $r['produced'] > 0 ? ($r['waste'] / ($r['kg_used'] ?: 1)) * 100 : 0;
        $totalMatCost = $r['kg_used'] * $costPerKg;
        $unitMatCost = $r['produced'] > 0 ? ($totalMatCost / $r['produced']) : 0;
        $sellingPrice = (float)$r['standard_price'];
        $unitMargin = $sellingPrice > 0 ? ($sellingPrice - $unitMatCost) : 0;
      ?>
      <tr>
        <td><strong><?= h($r['name']) ?></strong></td>
        <td><?= h($r['code']) ?></td>
        <td class="num"><?= money($r['produced']) ?></td>
        <td class="num"><?= money($r['kg_used']) ?> kg</td>
        <td class="num" style="<?= $r['waste']>0?'color:var(--warning);':'' ?>"><?= money($r['waste']) ?> kg</td>
        <td class="num"><strong><?= money($wasteRate, 1) ?>%</strong></td>
        <td class="num"><?= $costPerKg > 0 ? money($totalMatCost) : '—' ?></td>
        <td class="num"><?= $costPerKg > 0 ? money($unitMatCost, 2) : '—' ?></td>
        <td class="num"><?= $sellingPrice > 0 ? money($sellingPrice) . ' UGX' : '<em class="muted">Not Set</em>' ?></td>
        <td class="num" style="<?= $unitMargin > 0 ? 'color:var(--success);font-weight:700;' : '' ?>">
          <?= $sellingPrice > 0 && $costPerKg > 0 ? money($unitMargin, 2) . ' UGX' : '—' ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<p class="muted" style="font-size: 0.82rem; margin-top: 1rem;">
  Note: This baseline model evaluates direct jumbo raw material input and scrap rates. Overhead allocation drivers (direct labour, factory electricity, core paper, packaging) are configured under Accounting &amp; Settings.
</p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
