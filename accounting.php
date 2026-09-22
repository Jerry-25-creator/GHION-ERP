<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('director', 'consultant');
require_once __DIR__ . '/../includes/header.php';
$pdo = db();

// Handle Manual Journal Entry Posting
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'manual_je') {
    csrf_check();
    $narration = trim($_POST['narration']);
    $lines = $_POST['lines'] ?? [];

    $totDr = 0; $totCr = 0; $clean = [];
    foreach ($lines as $l) {
        $accId = (int)($l['account_id'] ?? 0);
        $dr = (float)($l['debit'] ?? 0);
        $cr = (float)($l['credit'] ?? 0);
        if ($accId > 0 && ($dr > 0 || $cr > 0)) {
            $clean[] = ['accId'=>$accId, 'dr'=>$dr, 'cr'=>$cr];
            $totDr += $dr; $totCr += $cr;
        }
    }

    if (empty($clean)) {
        $err = 'Add at least one valid debit and credit line item.';
    } elseif (abs($totDr - $totCr) > 0.01) {
        $err = "Journal entry is out of balance! Total Debits (" . money($totDr) . ") must equal Total Credits (" . money($totCr) . ").";
    } else {
        $pdo->beginTransaction();
        try {
            $jeNo = 'JE-MAN-' . date('Ymd') . '-' . random_int(100, 999);
            $pdo->prepare("INSERT INTO journal_entries (entry_no, source_type, narration, posted_by) VALUES (?, 'manual', ?, ?)")
                ->execute([$jeNo, $narration, $u['id']]);
            $jeId = $pdo->lastInsertId();
            $liStmt = $pdo->prepare("INSERT INTO journal_lines (entry_id, account_id, debit, credit) VALUES (?, ?, ?, ?)");
            foreach ($clean as $c) $liStmt->execute([$jeId, $c['accId'], $c['dr'], $c['cr']]);
            $pdo->commit();
            audit('create', 'accounting', $jeNo, null, $_POST);
            $ok = "Manual Journal Entry <strong>$jeNo</strong> posted successfully.";
        } catch (Throwable $e) { $pdo->rollBack(); $err = 'Error posting journal entry: ' . $e->getMessage(); }
    }
}

// ─── Fetch Trial Balance ───────────────────────────────────
$tb = $pdo->query("SELECT a.id, a.code, a.name, a.category,
  COALESCE(SUM(jl.debit),0) dr, COALESCE(SUM(jl.credit),0) cr
  FROM accounts a LEFT JOIN journal_lines jl ON jl.account_id = a.id
  GROUP BY a.id ORDER BY a.code")->fetchAll();

$totDr = array_sum(array_column($tb, 'dr'));
$totCr = array_sum(array_column($tb, 'cr'));
$isBalanced = abs($totDr - $totCr) < 0.01;

// ─── Profit & Loss (Income Statement) ──────────────────────
// Revenue accounts carry credits; cost and operating accounts carry debits.
$plRange = $_GET['pl_range'] ?? 'year';
$plWhere = match($plRange) {
    'month' => "DATE_FORMAT(je.entry_date,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')",
    'quarter' => "QUARTER(je.entry_date)=QUARTER(CURDATE()) AND YEAR(je.entry_date)=YEAR(CURDATE())",
    'year' => "YEAR(je.entry_date)=YEAR(CURDATE())",
    default => "YEAR(je.entry_date)=YEAR(CURDATE())",
};

$plRows = $pdo->query("SELECT a.code, a.name, a.category,
  COALESCE(SUM(jl.credit)-SUM(jl.debit), 0) net_cr,
  COALESCE(SUM(jl.debit)-SUM(jl.credit), 0) net_dr
  FROM accounts a
  LEFT JOIN journal_lines jl ON jl.account_id = a.id
  LEFT JOIN journal_entries je ON je.id = jl.entry_id AND $plWhere
  WHERE a.category IN ('Revenue','Cost of Sales','Direct Manufacturing Costs','Factory Overheads','Selling & Distribution','Administration','Finance Costs','Other Income','Other Expenses','Tax')
  GROUP BY a.id ORDER BY a.category, a.code")->fetchAll();

$revenue = 0; $cogs = 0; $expenses = 0;
$revenueRows = []; $cogsRows = []; $expenseRows = [];
foreach ($plRows as $r) {
    switch ($r['category']) {
        case 'Revenue':     $rev = max(0, (float)$r['net_cr']); $revenue += $rev; $revenueRows[] = ['name'=>$r['name'],'code'=>$r['code'],'amount'=>$rev]; break;
        case 'Cost of Sales': $c = max(0, (float)$r['net_dr']); $cogs += $c; $cogsRows[] = ['name'=>$r['name'],'code'=>$r['code'],'amount'=>$c]; break;
        case 'Direct Manufacturing Costs':
        case 'Factory Overheads':
        case 'Selling & Distribution':
        case 'Administration':
        case 'Finance Costs':
        case 'Other Expenses':
        case 'Tax':           $e = max(0, (float)$r['net_dr']); $expenses += $e; $expenseRows[] = ['name'=>$r['name'],'code'=>$r['code'],'amount'=>$e]; break;
        case 'Other Income':  $rev = max(0, (float)$r['net_cr']); $revenue += $rev; $revenueRows[] = ['name'=>$r['name'],'code'=>$r['code'],'amount'=>$rev]; break;
    }
}
$grossProfit = $revenue - $cogs;
$netProfit = $grossProfit - $expenses;

// ─── Balance Sheet ─────────────────────────────────────────
$bsRows = $pdo->query("SELECT a.code, a.name, a.category,
  COALESCE(SUM(jl.debit),0) dr, COALESCE(SUM(jl.credit),0) cr
  FROM accounts a LEFT JOIN journal_lines jl ON jl.account_id = a.id
  WHERE a.category IN ('Assets','Liabilities','Equity')
  GROUP BY a.id ORDER BY a.category, a.code")->fetchAll();

$assets = 0; $liabilities = 0; $equity = 0;
$assetRows = []; $liabilityRows = []; $equityRows = [];
foreach ($bsRows as $r) {
    $balance = match($r['category']) {
        'Assets'   => (float)$r['dr'] - (float)$r['cr'],
        'Liabilities'=> (float)$r['cr'] - (float)$r['dr'],
        'Equity'  => (float)$r['cr'] - (float)$r['dr'],
        default   => 0
    };
    switch ($r['category']) {
        case 'Assets':     $assets += $balance; $assetRows[] = ['name'=>$r['name'],'code'=>$r['code'],'amount'=>$balance]; break;
        case 'Liabilities': $liabilities += $balance; $liabilityRows[] = ['name'=>$r['name'],'code'=>$r['code'],'amount'=>$balance]; break;
        case 'Equity':    $equity += $balance; $equityRows[] = ['name'=>$r['name'],'code'=>$r['code'],'amount'=>$balance]; break;
    }
}

$accountsList = $pdo->query("SELECT * FROM accounts WHERE active=1 ORDER BY code")->fetchAll();
$entries = $pdo->query("SELECT je.*, u.name posted_by_name FROM journal_entries je LEFT JOIN users u ON u.id=je.posted_by ORDER BY je.id DESC LIMIT 50")->fetchAll();

// Cash Account Balances
$cashAccounts = $pdo->query("SELECT * FROM cash_accounts ORDER BY type")->fetchAll();
?>

<div class="page-head">
  <div>
    <h1>General Ledger &amp; Accounting</h1>
    <span class="muted">GHION INVESTMENTS AND ENTERPRISE LTD Financial Statements · Director / Consultant Access Only</span>
  </div>
  <div style="display:flex; gap:0.5rem;">
    <a class="btn btn-ghost btn-sm" href="<?= url('modules/reports.php?export=tb') ?>">
      <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
      Export Trial Balance (Excel)
    </a>
  </div>
</div>

<?php if (!empty($ok)): ?><div class="alert alert-ok"><?= $ok ?></div><?php endif; ?>
<?php if (!empty($err)): ?><div class="alert alert-error"><?= h($err) ?></div><?php endif; ?>

<!-- Cash Positions -->
<div class="cards">
  <?php foreach ($cashAccounts as $ca): ?>
  <div class="card">
    <div class="card-label"><?= h(ucwords(str_replace('_',' ',$ca['type']))) ?> Balance</div>
    <div class="card-value" style="color:var(--primary);"><?= money($ca['balance']) ?> <span style="font-size:0.85rem;color:var(--muted);">UGX</span></div>
    <span class="muted" style="font-size:0.8rem;">Current liquidity balance</span>
  </div>
  <?php endforeach; ?>
  <div class="card <?= $isBalanced ? '' : 'warn' ?>">
    <div class="card-label">Ledger Status</div>
    <div class="card-value" style="font-size:1.25rem; color:<?= $isBalanced ? 'var(--success)' : 'var(--danger)' ?>;"><?= $isBalanced ? '✓ Balanced' : '⚠ Imbalanced' ?></div>
    <span class="muted" style="font-size:0.8rem;">Dr: <?= money($totDr) ?> / Cr: <?= money($totCr) ?></span>
  </div>
</div>

<!-- Company Accounting Tabs -->
<div style="display:flex; align-items:center; gap:0; margin-bottom:1rem; border-bottom:2px solid var(--border);">
  <?php foreach(['pl'=>'Profit &amp; Loss','bs'=>'Balance Sheet','tb'=>'Trial Balance','je'=>'Journal Entries','post'=>'Post Journal Entry'] as $k=>$lbl): ?>
    <a href="#tab-<?= $k ?>" onclick="switchTab('<?= $k ?>')" class="acc-tab" id="tab-btn-<?= $k ?>"
       style="padding:0.65rem 1.25rem; font-weight:600; font-size:0.88rem; text-decoration:none; border-bottom:3px solid <?= $k==='pl'?'var(--primary)':'transparent' ?>; color:<?= $k==='pl'?'var(--primary)':'var(--muted)' ?>; margin-bottom:-2px; cursor:pointer;">
      <?= $lbl ?>
    </a>
  <?php endforeach; ?>
</div>

<!-- P&L Tab -->
<div id="tab-pl" class="acc-tab-content">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
    <h2 style="margin:0;">Profit &amp; Loss Statement</h2>
    <form method="get" style="display:flex;gap:0.5rem;align-items:center;">
      <select name="pl_range" onchange="this.form.submit()" style="width:160px; padding:0.4rem 0.6rem; border:1px solid var(--border); border-radius:6px;">
        <?php foreach(['month'=>'This Month','quarter'=>'This Quarter','year'=>'This Year'] as $k=>$v): ?>
          <option value="<?= $k ?>" <?= $plRange===$k?'selected':'' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
      <input type="hidden" name="tab" value="pl">
    </form>
  </div>
  <div class="panel" style="font-family:var(--font-body);">
    <table style="width:100%; border-collapse:collapse;">
      <tbody>
        <tr style="background:var(--primary-light);">
          <td colspan="2" style="padding:0.75rem 1rem; font-weight:800; font-size:1.05rem; color:var(--primary);">REVENUE</td>
        </tr>
        <?php foreach ($revenueRows as $r): ?>
        <tr style="border-bottom:1px solid var(--border);">
          <td style="padding:0.5rem 1rem 0.5rem 2rem; color:var(--muted);"><?= h($r['code']) ?> — <?= h($r['name']) ?></td>
          <td style="text-align:right; padding:0.5rem 1rem;"><?= money($r['amount']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$revenueRows): ?><tr><td colspan="2" style="padding:0.5rem 1rem 0.5rem 2rem; color:var(--muted);">No revenue accounts with activity</td></tr><?php endif; ?>
        <tr style="background:#f1f5f9; font-weight:700;">
          <td style="padding:0.65rem 1rem;">Total Revenue</td>
          <td style="text-align:right; padding:0.65rem 1rem; color:var(--success);"><?= money($revenue) ?></td>
        </tr>

        <tr style="background:var(--primary-light);">
          <td colspan="2" style="padding:0.75rem 1rem; font-weight:800; font-size:1.05rem; color:var(--primary);">COST OF GOODS SOLD</td>
        </tr>
        <?php foreach ($cogsRows as $r): ?>
        <tr style="border-bottom:1px solid var(--border);">
          <td style="padding:0.5rem 1rem 0.5rem 2rem; color:var(--muted);"><?= h($r['code']) ?> — <?= h($r['name']) ?></td>
          <td style="text-align:right; padding:0.5rem 1rem;"><?= money($r['amount']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$cogsRows): ?><tr><td colspan="2" style="padding:0.5rem 1rem 0.5rem 2rem; color:var(--muted);">No COGS accounts with activity</td></tr><?php endif; ?>
        <tr style="background:#f1f5f9; font-weight:700;">
          <td style="padding:0.65rem 1rem;">Total COGS</td>
          <td style="text-align:right; padding:0.65rem 1rem; color:var(--danger);">(<?= money($cogs) ?>)</td>
        </tr>

        <tr style="border-top:3px double var(--border); font-weight:800; font-size:1.05rem;">
          <td style="padding:0.75rem 1rem;">GROSS PROFIT</td>
          <td style="text-align:right; padding:0.75rem 1rem; color:<?= $grossProfit >= 0 ? 'var(--success)' : 'var(--danger)' ?>;"><?= money($grossProfit) ?></td>
        </tr>

        <tr style="background:var(--primary-light);">
          <td colspan="2" style="padding:0.75rem 1rem; font-weight:800; font-size:1.05rem; color:var(--primary);">OPERATING EXPENSES</td>
        </tr>
        <?php foreach ($expenseRows as $r): ?>
        <tr style="border-bottom:1px solid var(--border);">
          <td style="padding:0.5rem 1rem 0.5rem 2rem; color:var(--muted);"><?= h($r['code']) ?> — <?= h($r['name']) ?></td>
          <td style="text-align:right; padding:0.5rem 1rem;"><?= money($r['amount']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$expenseRows): ?><tr><td colspan="2" style="padding:0.5rem 1rem 0.5rem 2rem; color:var(--muted);">No expense accounts with activity</td></tr><?php endif; ?>
        <tr style="background:#f1f5f9; font-weight:700;">
          <td style="padding:0.65rem 1rem;">Total Expenses</td>
          <td style="text-align:right; padding:0.65rem 1rem; color:var(--danger);">(<?= money($expenses) ?>)</td>
        </tr>

        <tr style="border-top:3px double var(--border); font-weight:800; font-size:1.2rem; background:<?= $netProfit >= 0 ? '#d1fae5' : '#fee2e2' ?>;">
          <td style="padding:1rem;">NET <?= $netProfit >= 0 ? 'PROFIT' : 'LOSS' ?></td>
          <td style="text-align:right; padding:1rem; color:<?= $netProfit >= 0 ? 'var(--success)' : 'var(--danger)' ?>;"><?= money(abs($netProfit)) ?></td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Balance Sheet Tab -->
<div id="tab-bs" class="acc-tab-content" style="display:none;">
  <h2 style="margin-bottom:1rem;">Balance Sheet (As of <?= date('d F Y') ?>)</h2>
  <div class="form-grid" style="grid-template-columns:1fr 1fr; align-items:start;">
    <div class="panel">
      <h3 style="margin-top:0; color:var(--primary);">ASSETS</h3>
      <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
        <?php foreach ($assetRows as $r): ?>
        <tr style="border-bottom:1px solid var(--border);">
          <td style="padding:0.45rem 0; color:var(--muted);"><?= h($r['code']) ?> — <?= h($r['name']) ?></td>
          <td style="text-align:right; padding:0.45rem 0;"><?= money($r['amount']) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr style="border-top:3px double var(--border); font-weight:800; font-size:1.05rem;">
          <td style="padding:0.65rem 0;">TOTAL ASSETS</td>
          <td style="text-align:right; padding:0.65rem 0; color:var(--primary);"><?= money($assets) ?></td>
        </tr>
      </table>
    </div>
    <div>
      <div class="panel" style="margin-bottom:1rem;">
        <h3 style="margin-top:0; color:var(--danger);">LIABILITIES</h3>
        <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
          <?php foreach ($liabilityRows as $r): ?>
          <tr style="border-bottom:1px solid var(--border);">
            <td style="padding:0.45rem 0; color:var(--muted);"><?= h($r['code']) ?> — <?= h($r['name']) ?></td>
            <td style="text-align:right; padding:0.45rem 0;"><?= money($r['amount']) ?></td>
          </tr>
          <?php endforeach; ?>
          <tr style="border-top:3px double var(--border); font-weight:800;">
            <td style="padding:0.65rem 0;">TOTAL LIABILITIES</td>
            <td style="text-align:right; padding:0.65rem 0; color:var(--danger);"><?= money($liabilities) ?></td>
          </tr>
        </table>
      </div>
      <div class="panel">
        <h3 style="margin-top:0; color:var(--success);">EQUITY</h3>
        <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
          <?php foreach ($equityRows as $r): ?>
          <tr style="border-bottom:1px solid var(--border);">
            <td style="padding:0.45rem 0; color:var(--muted);"><?= h($r['code']) ?> — <?= h($r['name']) ?></td>
            <td style="text-align:right; padding:0.45rem 0;"><?= money($r['amount']) ?></td>
          </tr>
          <?php endforeach; ?>
          <tr style="border-bottom:1px solid var(--border);">
            <td style="padding:0.45rem 0; color:var(--muted);">Retained Earnings (Net P&amp;L)</td>
            <td style="text-align:right; padding:0.45rem 0; color:<?= $netProfit>=0?'var(--success)':'var(--danger)' ?>;"><?= money($netProfit) ?></td>
          </tr>
          <tr style="border-top:3px double var(--border); font-weight:800;">
            <td style="padding:0.65rem 0;">TOTAL EQUITY</td>
            <td style="text-align:right; padding:0.65rem 0; color:var(--success);"><?= money($equity + $netProfit) ?></td>
          </tr>
        </table>
        <?php $checkBalance = $assets - $liabilities - $equity - $netProfit; ?>
        <div style="margin-top:0.75rem; padding:0.5rem 0.75rem; border-radius:6px; background:<?= abs($checkBalance)<1?'#d1fae5':'#fee2e2' ?>; font-size:0.82rem; font-weight:700;">
          <?= abs($checkBalance) < 1 ? '✓ Balance Sheet is balanced (Assets = Liabilities + Equity)' : '⚠ Balance Sheet discrepancy: ' . money(abs($checkBalance)) . ' UGX — post adjusting entries.' ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Trial Balance Tab -->
<div id="tab-tb" class="acc-tab-content" style="display:none;">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
    <h2 style="margin:0;">Trial Balance</h2>
    <span class="badge <?= $isBalanced ? 'paid' : 'unpaid' ?>" style="font-size:0.88rem; padding:0.4rem 0.85rem;">
      <?= $isBalanced ? '✓ Balanced Ledger' : '⚠ Imbalance: ' . money(abs($totDr - $totCr)) . ' UGX' ?>
    </span>
  </div>
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>Code</th><th>Account Title</th><th>Classification</th><th class="num">Debit (UGX)</th><th class="num">Credit (UGX)</th></tr></thead>
      <tbody>
        <?php foreach ($tb as $r): if ($r['dr'] == 0 && $r['cr'] == 0) continue; ?>
        <tr>
          <td><strong><?= h($r['code']) ?></strong></td>
          <td><?= h($r['name']) ?></td>
          <td><span class="badge" style="background:#f1f5f9;color:var(--ink);"><?= h($r['category']) ?></span></td>
          <td class="num"><?= $r['dr'] ? money($r['dr'], 2) : '—' ?></td>
          <td class="num"><?= $r['cr'] ? money($r['cr'], 2) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="total">
          <td colspan="3">TOTALS</td>
          <td class="num"><?= money($totDr, 2) ?></td>
          <td class="num"><?= money($totCr, 2) ?></td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Journal Entries Tab -->
<div id="tab-je" class="acc-tab-content" style="display:none;">
  <h2 style="margin-bottom:1rem;">Journal Entry Register (Last 50 Entries)</h2>
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>Entry No.</th><th>Date</th><th>Source</th><th>Narration</th><th>Posted By</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($entries as $e): ?>
        <tr>
          <td><strong><?= h($e['entry_no']) ?></strong></td>
          <td style="font-size:0.82rem; color:var(--muted);"><?= date('d M Y, H:i', strtotime($e['entry_date'])) ?></td>
          <td><span class="badge" style="background:#f1f5f9;color:var(--ink);"><?= h(ucfirst($e['source_type'] ?? 'general')) ?></span></td>
          <td><?= h($e['narration']) ?></td>
          <td><?= h($e['posted_by_name'] ?? 'System') ?></td>
          <td><button class="btn btn-ghost btn-sm" onclick="showJeLines(<?= $e['id'] ?>, '<?= h($e['entry_no']) ?>')">View Lines</button></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Post Journal Entry Tab -->
<div id="tab-post" class="acc-tab-content" style="display:none;">
  <h2 style="margin-top:0;">Post Manual Journal Entry</h2>
  <p class="muted" style="font-size:0.82rem; margin-bottom:1rem;">Record double-entry adjusting journal entries. Total debits must equal total credits.</p>
  <div class="panel">
    <form method="post" id="jeForm">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="manual_je">

      <label style="margin-bottom:1rem;">Journal Narration / Description *
        <input name="narration" placeholder="e.g. Month-end depreciation adjustment" required>
      </label>

      <div class="table-responsive">
        <table class="table" id="jeLinesTable">
          <thead><tr><th style="width:50%;">Account</th><th class="num" style="width:23%;">Debit Amount (UGX)</th><th class="num" style="width:23%;">Credit Amount (UGX)</th><th style="width:4%;"></th></tr></thead>
          <tbody>
            <?php for ($i = 0; $i < 2; $i++): ?>
            <tr>
              <td>
                <select name="lines[<?= $i ?>][account_id]" required>
                  <option value="">— Select Account —</option>
                  <?php foreach ($accountsList as $a): ?>
                    <option value="<?= $a['id'] ?>"><?= h($a['code']) ?> — <?= h($a['name']) ?> (<?= h($a['category']) ?>)</option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><input type="number" step="0.01" min="0" name="lines[<?= $i ?>][debit]" class="num" value="0"></td>
              <td><input type="number" step="0.01" min="0" name="lines[<?= $i ?>][credit]" class="num" value="0"></td>
              <td><button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('tr').remove()">✕</button></td>
            </tr>
            <?php endfor; ?>
          </tbody>
        </table>
      </div>
      <div style="display:flex; justify-content:space-between; align-items:center; margin-top:1rem;">
        <button type="button" class="btn" onclick="addJeLine()">+ Add Journal Line</button>
        <button class="btn btn-primary" type="submit">Post Journal Entry</button>
      </div>
    </form>
  </div>
</div>

<script>
// Tab switching
function switchTab(tab) {
  document.querySelectorAll('.acc-tab-content').forEach(el => el.style.display = 'none');
  document.querySelectorAll('.acc-tab').forEach(el => {
    el.style.borderBottomColor = 'transparent';
    el.style.color = 'var(--muted)';
  });
  document.getElementById('tab-' + tab).style.display = 'block';
  const btn = document.getElementById('tab-btn-' + tab);
  if (btn) { btn.style.borderBottomColor = 'var(--primary)'; btn.style.color = 'var(--primary)'; }
}

let jeIdx = 2;
function addJeLine() {
  const tbody = document.querySelector('#jeLinesTable tbody');
  const row = tbody.rows[0].cloneNode(true);
  row.querySelectorAll('select, input').forEach(el => {
    el.name = el.name.replace(/\d+/, jeIdx);
    el.value = el.tagName === 'INPUT' ? 0 : '';
  });
  tbody.appendChild(row);
  jeIdx++;
}

function showJeLines(jeId, jeNo) {
  fetch(`<?= url('modules/accounting.php') ?>?ajax=je_lines&id=` + jeId)
    .then(r => r.json())
    .then(data => {
      let rows = data.map(l => `<tr><td>${l.code} — ${l.name}</td><td style="text-align:right;">${parseFloat(l.debit).toLocaleString()}</td><td style="text-align:right;">${parseFloat(l.credit).toLocaleString()}</td></tr>`).join('');
      openModal('Journal Entry Lines: ' + jeNo, `<table class="table"><thead><tr><th>Account</th><th class="num">Debit (UGX)</th><th class="num">Credit (UGX)</th></tr></thead><tbody>${rows}</tbody></table>`);
    });
}

// Handle AJAX request for JE lines
</script>

<?php
// AJAX: Get journal entry lines
if (($_GET['ajax'] ?? '') === 'je_lines' && isset($_GET['id'])) {
    $jeId = (int)$_GET['id'];
    $lines = $pdo->query("SELECT a.code, a.name, jl.debit, jl.credit FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id WHERE jl.entry_id=$jeId")->fetchAll();
    header('Content-Type: application/json');
    echo json_encode($lines);
    exit;
}
require_once __DIR__ . '/../includes/footer.php';
?>
