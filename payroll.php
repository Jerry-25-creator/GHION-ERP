<?php
require_once __DIR__ . '/../includes/header.php';
$pdo = db(); $fin = is_financial();

// Statutory Rates Update (Director / Consultant)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'rates' && $fin) {
    csrf_check();
    foreach (['paye_rate','nssf_rate','nssf_employer_rate'] as $k) {
        $pdo->prepare("INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v=VALUES(v)")
            ->execute([$k, (string)(float)$_POST[$k]]);
    }
    audit('update', 'settings', 'tax_rates', null, $_POST, 'Statutory rates updated');
    $ok = 'Statutory tax &amp; NSSF rates updated.';
}

$R = fn($k, $d) => $pdo->query("SELECT v FROM settings WHERE k='$k'")->fetch()['v'] ?? $d;
$payeRate = (float)$R('paye_rate', 0); 
$nssfRate = (float)$R('nssf_rate', 5); 
$nssfEr = (float)$R('nssf_employer_rate', 10);

// Register New Employee
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'employee') {
    csrf_check();
    $name = trim($_POST['name']);
    if (empty($name)) {
        $err = 'Employee name is required.';
    } else {
        $pdo->prepare("INSERT INTO employees (name, phone, nin, job_title, department, basic_salary, paye_applicable, nssf_applicable) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$name, trim($_POST['phone']), trim($_POST['nin']), trim($_POST['job_title']), trim($_POST['department']),
                       (float)$_POST['basic_salary'], isset($_POST['paye_applicable'])?1:0, isset($_POST['nssf_applicable'])?1:0]);
        audit('create', 'employees', $pdo->lastInsertId(), null, ['name'=>$name]);
        $ok = "Employee <strong>" . h($name) . "</strong> registered successfully.";
    }
}

// Generate Payroll Run
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'run') {
    csrf_check();
    $period = $_POST['period']; // YYYY-MM
    $emps = $pdo->query("SELECT * FROM employees WHERE status='active'")->fetchAll();

    if (empty($emps)) {
        $err = 'No active employees to include in payroll run.';
    } else {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO payroll_runs (period, created_by, status) VALUES (?, ?, 'draft')")->execute([$period, $u['id']]);
            $runId = $pdo->lastInsertId();
            $li = $pdo->prepare("INSERT INTO payroll_lines (run_id, employee_id, basic, allowances, bonus, gross, paye, nssf, other_deductions, net) VALUES (?,?,?,?,?,?,?,?,?,?)");

            foreach ($emps as $e) {
                $allow = (float)($_POST['allow'][$e['id']] ?? 0);
                $bonus = (float)($_POST['bonus'][$e['id']] ?? 0);
                $gross = $e['basic_salary'] + $allow + $bonus;
                
                $paye = $e['paye_applicable'] ? ($gross * $payeRate / 100) : 0;
                $nssf = $e['nssf_applicable'] ? ($e['basic_salary'] * $nssfRate / 100) : 0;
                $other = (float)($_POST['ded'][$e['id']] ?? 0);
                $net = max(0, $gross - $paye - $nssf - $other);

                $li->execute([$runId, $e['id'], $e['basic_salary'], $allow, $bonus, $gross, $paye, $nssf, $other, $net]);
            }

            $pdo->commit();
            audit('create', 'payroll', $period, null, ['employees'=>count($emps)]);
            $ok = "Payroll run for <strong>$period</strong> created in Draft (" . count($emps) . " employees). Review and approve payment below.";
        } catch (Throwable $e) {
            $pdo->rollBack();
            $err = 'Error generating payroll run: ' . $e->getMessage();
        }
    }
}

// Approve & Mark Payroll as Paid
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'approve_payroll' && $fin) {
    csrf_check();
    $runId = (int)$_POST['run_id'];
    
    $run = $pdo->query("SELECT * FROM payroll_runs WHERE id=$runId AND status='draft'")->fetch();
    if ($run) {
        $totals = $pdo->query("SELECT SUM(gross) tot_gross, SUM(paye) tot_paye, SUM(nssf) tot_nssf, SUM(net) tot_net FROM payroll_lines WHERE run_id=$runId")->fetch();
        
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE payroll_runs SET status='paid' WHERE id=?")->execute([$runId]);

            // Post Accounting Journal:
            // Dr Salaries & Wages Expense (7000)  [Gross Total]
            // Cr PAYE Payable (2100)
            // Cr NSSF Payable (2110)
            // Cr Payroll Payable / Cash (2200)   [Net Total]
            $drExpId = $pdo->query("SELECT id FROM accounts WHERE code='7000'")->fetch()['id'] ?? null;
            $crPayeId = $pdo->query("SELECT id FROM accounts WHERE code='2100'")->fetch()['id'] ?? null;
            $crNssfId = $pdo->query("SELECT id FROM accounts WHERE code='2110'")->fetch()['id'] ?? null;
            $crNetId = $pdo->query("SELECT id FROM accounts WHERE code='2200'")->fetch()['id'] ?? null;

            if ($drExpId) {
                $pdo->prepare("INSERT INTO journal_entries (entry_no, source_type, source_id, narration, posted_by) VALUES (?, 'payroll', ?, ?, ?)")
                    ->execute(['JE-PAYROLL-' . $run['period'], $runId, "Payroll Disbursement " . $run['period'], $u['id']]);
                $jeId = $pdo->lastInsertId();

                // Debit Salaries Expense
                $pdo->prepare("INSERT INTO journal_lines (entry_id, account_id, debit, credit) VALUES (?, ?, ?, 0)")
                    ->execute([$jeId, $drExpId, (float)$totals['tot_gross']]);

                // Credit Liabilities
                if ($crPayeId && (float)$totals['tot_paye'] > 0) {
                    $pdo->prepare("INSERT INTO journal_lines (entry_id, account_id, debit, credit) VALUES (?, ?, 0, ?)")
                        ->execute([$jeId, $crPayeId, (float)$totals['tot_paye']]);
                }
                if ($crNssfId && (float)$totals['tot_nssf'] > 0) {
                    $pdo->prepare("INSERT INTO journal_lines (entry_id, account_id, debit, credit) VALUES (?, ?, 0, ?)")
                        ->execute([$jeId, $crNssfId, (float)$totals['tot_nssf']]);
                }
                if ($crNetId && (float)$totals['tot_net'] > 0) {
                    $pdo->prepare("INSERT INTO journal_lines (entry_id, account_id, debit, credit) VALUES (?, ?, 0, ?)")
                        ->execute([$jeId, $crNetId, (float)$totals['tot_net']]);
                }
            }

            $pdo->commit();
            audit('update', 'payroll', $runId, ['status'=>'draft'], ['status'=>'paid']);
            $ok = "Payroll run for <strong>" . h($run['period']) . "</strong> approved and marked as Paid. Accounting journal posted.";
        } catch (Throwable $e) {
            $pdo->rollBack();
            $err = 'Error approving payroll: ' . $e->getMessage();
        }
    }
}

$emps = $pdo->query("SELECT * FROM employees ORDER BY status, name")->fetchAll();
$runs = $pdo->query("SELECT * FROM payroll_runs ORDER BY id DESC LIMIT 12")->fetchAll();

$viewRunId = (int)($_GET['run'] ?? ($runs[0]['id'] ?? 0));
$selectedRun = null;
$lines = [];

if ($viewRunId > 0) {
    $rStmt = $pdo->prepare("SELECT * FROM payroll_runs WHERE id=?");
    $rStmt->execute([$viewRunId]);
    $selectedRun = $rStmt->fetch();

    $lStmt = $pdo->prepare("SELECT pl.*, e.name, e.job_title, e.department, e.nin FROM payroll_lines pl JOIN employees e ON e.id=pl.employee_id WHERE pl.run_id=?");
    $lStmt->execute([$viewRunId]);
    $lines = $lStmt->fetchAll();
}
?>

<div class="page-head">
  <div>
    <h1>Payroll &amp; Employee Management</h1>
    <span class="muted">Statutory PAYE &amp; NSSF calculation, monthly runs, and payslips</span>
  </div>
</div>

<?php if (!empty($ok)): ?><div class="alert alert-ok"><?= $ok ?></div><?php endif; ?>
<?php if (!empty($err)): ?><div class="alert alert-error"><?= h($err) ?></div><?php endif; ?>

<!-- Statutory Tax Rates Config -->
<?php if ($fin): ?>
<div class="panel">
  <h2 style="margin-top:0;">Statutory Rates Config</h2>
  <form method="post" class="form-grid">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="form" value="rates">

    <label>PAYE Rate (%)
      <input type="number" step="0.01" name="paye_rate" value="<?= $payeRate ?>">
    </label>

    <label>NSSF Employee Rate (%)
      <input type="number" step="0.01" name="nssf_rate" value="<?= $nssfRate ?>">
    </label>

    <label>NSSF Employer Contribution (%)
      <input type="number" step="0.01" name="nssf_employer_rate" value="<?= $nssfEr ?>">
    </label>

    <div style="margin-top: 1.5rem;">
      <button class="btn btn-primary" type="submit">Update Statutory Rates</button>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="form-grid" style="grid-template-columns: 1fr 2fr;">
  <!-- New Employee Registration -->
  <div class="panel">
    <h2 style="margin-top:0;">Add New Employee</h2>
    <form method="post" class="form-grid" style="grid-template-columns: 1fr;">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="employee">

      <label>Full Name *<input name="name" placeholder="John Kayiwa" required></label>
      <label>Phone Number<input name="phone" placeholder="+256 700 000 000"></label>
      <label>NIN Number<input name="nin" placeholder="CM900..."></label>
      <label>Job Title<input name="job_title" placeholder="Machine Operator"></label>
      <label>Department<input name="department" placeholder="Production"></label>
      <label>Basic Salary (UGX) *<input type="number" step="0.01" name="basic_salary" placeholder="800000" required></label>

      <label class="check"><input type="checkbox" name="paye_applicable" checked> PAYE Tax Deductible</label>
      <label class="check" style="margin-top:0;"><input type="checkbox" name="nssf_applicable" checked> NSSF Contribution Applicable</label>

      <button class="btn btn-primary" type="submit" style="margin-top: 1rem;">
        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
        <span>Register Employee</span>
      </button>
    </form>
  </div>

  <!-- Execute Monthly Payroll Run -->
  <div class="panel">
    <h2 style="margin-top:0;">Execute Monthly Payroll Run</h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="run">

      <div style="display: flex; gap: 1rem; align-items: center; margin-bottom: 1rem;">
        <label style="margin:0;">Select Payroll Month:
          <input type="month" name="period" required value="<?= date('Y-m') ?>" style="width: 180px;">
        </label>
        <span class="muted" style="font-size: 0.82rem;">PAYE: <?= $payeRate ?>% | NSSF Employee: <?= $nssfRate ?>%</span>
      </div>

      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th>Employee</th>
              <th class="num">Basic Salary</th>
              <th>Allowances</th>
              <th>Bonus</th>
              <th>Other Deductions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($emps as $e): if ($e['status'] !== 'active') continue; ?>
            <tr>
              <td>
                <strong><?= h($e['name']) ?></strong><br>
                <span class="muted" style="font-size:0.75rem;"><?= h($e['job_title'] ?? 'Staff') ?> · <?= $e['paye_applicable']?'PAYE':'No PAYE' ?>, <?= $e['nssf_applicable']?'NSSF':'No NSSF' ?></span>
              </td>
              <td class="num"><?= money($e['basic_salary']) ?></td>
              <td><input type="number" step="0.01" name="allow[<?= $e['id'] ?>]" value="0" style="width:100px;"></td>
              <td><input type="number" step="0.01" name="bonus[<?= $e['id'] ?>]" value="0" style="width:100px;"></td>
              <td><input type="number" step="0.01" name="ded[<?= $e['id'] ?>]" value="0" style="width:100px;"></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <button class="btn btn-primary" type="submit" style="margin-top: 1rem;">
        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
        <span>Generate Draft Payroll Run</span>
      </button>
    </form>
  </div>
</div>

<h2>Payroll Runs History</h2>
<div class="table-responsive">
  <table class="table">
    <thead>
      <tr>
        <th>Payroll Period</th>
        <th>Status</th>
        <th>Created Date</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($runs as $r): ?>
      <tr>
        <td><strong><?= h($r['period']) ?></strong></td>
        <td><span class="badge <?= h($r['status']) ?>"><?= h(ucfirst($r['status'])) ?></span></td>
        <td style="font-size: 0.82rem; color: var(--muted);"><?= date('d M Y, H:i', strtotime($r['created_at'])) ?></td>
        <td>
          <a class="btn btn-ghost btn-sm" href="?run=<?= $r['id'] ?>">View Payslips &rarr;</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Payslip Breakdown View -->
<?php if ($selectedRun && $lines): ?>
<div class="panel" style="margin-top: 2rem;">
  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
    <div>
      <h2 style="margin:0;">Payslips Breakdown — Period <?= h($selectedRun['period']) ?></h2>
      <span class="muted">Status: <span class="badge <?= h($selectedRun['status']) ?>"><?= h(ucfirst($selectedRun['status'])) ?></span></span>
    </div>

    <?php if ($selectedRun['status'] === 'draft' && $fin): ?>
      <form method="post" style="margin:0;">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="form" value="approve_payroll">
        <input type="hidden" name="run_id" value="<?= $selectedRun['id'] ?>">
        <button class="btn btn-primary" type="submit">
          <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
          <span>Approve &amp; Mark Paid (Post Journal)</span>
        </button>
      </form>
    <?php endif; ?>
  </div>

  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>Employee</th>
          <th class="num">Basic</th>
          <th class="num">Gross</th>
          <th class="num">PAYE Tax</th>
          <th class="num">NSSF (5%)</th>
          <th class="num">Other Ded.</th>
          <th class="num">Net Payable</th>
          <th>Payslip</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lines as $l): ?>
        <tr>
          <td>
            <strong><?= h($l['name']) ?></strong><br>
            <span class="muted" style="font-size:0.75rem;"><?= h($l['job_title'] ?? 'Staff') ?></span>
          </td>
          <td class="num"><?= money($l['basic']) ?></td>
          <td class="num"><?= money($l['gross']) ?></td>
          <td class="num" style="color:var(--danger);"><?= money($l['paye']) ?></td>
          <td class="num" style="color:var(--warning);"><?= money($l['nssf']) ?></td>
          <td class="num"><?= money($l['other_deductions']) ?></td>
          <td class="num" style="font-weight: 800; color: var(--success); font-size: 1rem;"><?= money($l['net']) ?></td>
          <td>
            <button class="btn btn-ghost btn-sm" onclick='showPayslipModal(<?= json_encode($l) ?>, <?= json_encode($selectedRun['period']) ?>)'>
              <span>View Payslip</span>
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<script>
function showPayslipModal(line, period) {
  const html = `
    <div style="padding: 1rem; font-family: var(--font-body);">
      <div style="text-align: center; border-bottom: 2px solid var(--border); padding-bottom: 1rem; margin-bottom: 1rem;">
        <h2 style="margin:0; font-size: 1.25rem;">GHION INVESTMENTS AND ENTERPRISE LTD</h2>
        <div style="font-size: 0.85rem; color: var(--muted);">Employee Payslip · Period ${period}</div>
      </div>
      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 1rem; font-size: 0.9rem;">
        <div><strong>Employee:</strong> ${line.name}</div>
        <div><strong>Job Title:</strong> ${line.job_title || 'Staff'}</div>
        <div><strong>Department:</strong> ${line.department || 'General'}</div>
        <div><strong>NIN:</strong> ${line.nin || '—'}</div>
      </div>
      <table style="width:100%; border-collapse: collapse; font-size: 0.9rem;" class="table">
        <tr><td>Basic Salary</td><td style="text-align:right;"><strong>${parseFloat(line.basic).toLocaleString()} UGX</strong></td></tr>
        <tr><td>Allowances</td><td style="text-align:right;">${parseFloat(line.allowances).toLocaleString()} UGX</td></tr>
        <tr><td>Bonus</td><td style="text-align:right;">${parseFloat(line.bonus).toLocaleString()} UGX</td></tr>
        <tr style="background:#f8fafc; font-weight:700;"><td>Gross Earnings</td><td style="text-align:right;">${parseFloat(line.gross).toLocaleString()} UGX</td></tr>
        <tr><td>PAYE Tax Deducted</td><td style="text-align:right; color:var(--danger);">-${parseFloat(line.paye).toLocaleString()} UGX</td></tr>
        <tr><td>NSSF Contribution (5%)</td><td style="text-align:right; color:var(--warning);">-${parseFloat(line.nssf).toLocaleString()} UGX</td></tr>
        <tr><td>Other Deductions</td><td style="text-align:right;">-${parseFloat(line.other_deductions).toLocaleString()} UGX</td></tr>
        <tr style="background:var(--success-bg); font-weight:800; font-size: 1.05rem;">
          <td>NET PAYABLE</td>
          <td style="text-align:right; color:var(--success);">${parseFloat(line.net).toLocaleString()} UGX</td>
        </tr>
      </table>
      <div style="margin-top: 1.5rem; text-align: center;">
        <button class="btn btn-primary" onclick="window.print()">Print Payslip</button>
      </div>
    </div>
  `;
  openModal('Employee Payslip — ' + line.name, html);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
