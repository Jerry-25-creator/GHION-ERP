# GHION ERP — System Audit: Gap Analysis & Implementation Plan

**Date:** 2026-09-22
**Method:** The codebase was reorganized into the directory structure its own code expects, deployed to a real MariaDB instance, booted with PHP's built-in server, and exercised live as all three roles (Director, Accountant, Consultant) — including logins, a full jumbo-batch → production-run → finished-goods cycle, the confidential purchase-pricing workflow (with a direct API-bypass attempt as the Accountant), and database inspection after each action. This live testing was combined with a systematic, section-by-section static review of every PHP file and both SQL schemas against all 83 sections of the master build prompt.

---

## 1. Executive Summary

GHION ERP is a substantial, mostly-real implementation — not a mockup. Role-based access control, CSRF protection, the audit trail, the confidential-purchase-pricing workflow, core financial statements (Trial Balance / P&L / Balance Sheet), and — surprisingly — a genuine offline IndexedDB sync-queue are all real, working code, not stubs. Roughly a third of the "expansion" schema, however, consists of tables that were clearly designed for specific spec requirements (production consumption, waste categorization, overhead allocation, product packaging/materials BOMs, machines, stock adjustments) but have **zero write paths anywhere in the application** — they exist only as `CREATE TABLE` statements. And the accounting close is broken: **no COGS is ever posted**, and neither is production consumption, finished-goods transfer, direct expenses, or supplier payments — so the P&L and Balance Sheet, while mechanically correct, are working from an incomplete general ledger.

**The system cannot be deployed today.** Every PHP file references `../includes/`, `../config/`, `../modules/`, `../assets/` paths that do not exist in the repository — it was committed with all files flat at the repository root. As committed, every single page fatals with HTTP 500. This is fixed by reorganizing files into the folders the code already expects (see §3.1) — no logic changes required — but it must be fixed before anything else matters.

## 2. Critical / Blocking Issues (fix before anything else)

| # | Issue | Impact | Evidence |
|---|---|---|---|
| 1 | **App does not boot.** Every file's `require_once __DIR__.'/../includes/...'` etc. points at non-existent directories; repo is flat. | Total outage — HTTP 500 on every page. | Confirmed live: `php -S` + `curl` → Fatal error on `index.php:3`. |
| 2 | **No COGS ever posted.** `sales.php` posts revenue only; no `Dr Cost of Sales / Cr Finished Goods` entry anywhere. `finished_stock.avg_cost` is declared but never written by any INSERT/UPDATE in the codebase. | P&L gross profit / Balance Sheet inventory value are both wrong from day one. | Confirmed live (sold 5,000pcs, checked `journal_entries` — only the purchase entry existed). Corroborated independently by 2 of 5 audit agents. |
| 3 | **Production posts no accounting entries at all** (no WIP/Mfg-Cost Dr, no Raw-Material Cr, no Finished-Goods Dr). | Manufacturing cost is entirely invisible to the general ledger; Manufacturing Account (spec §50) is structurally impossible without this. | Confirmed live: ran a production run, `journal_entries` table unchanged. |
| 4 | **Direct expenses and supplier payments never post journal entries or touch `cash_accounts.balance`.** | Cash balances and P&L silently diverge from the recorded transactions the moment either feature is used. | `operations.php` expense/supplier_payment handlers, confirmed by static review — no `journal_entries` INSERT in either branch. |
| 5 | **Confidentiality leak in Reports.** The Purchases tab and Payroll Summary tab in `reports.php` render confidential totals (`SUM(p.total_cost)`, gross/net payroll) to *every* logged-in user unconditionally — only the Excel export buttons are gated. `?export=payroll` also has no server-side role check at all (URL-exploitable). | Direct violation of the core confidentiality requirement (spec §2, §37, §57), reachable without bypassing anything. | `reports.php` — Purchases tab query/render and Payroll tab query/render have no `if ($fin)` guard; `export=payroll` branch has no `require_role`. |
| 6 | **Batch remaining-weight bug.** `UPDATE batches SET qty_remaining = GREATEST(0, qty_remaining - ?), status = IF(qty_remaining - ? <= 0, 'consumed', ...)` — because MySQL evaluates the second `qty_remaining` reference *after* the first SET clause has already applied, the status computation double-subtracts and prematurely marks batches `'consumed'` while real stock remains. | Batches with real remaining stock disappear from "available" listings; production runs against them will use a phantom-empty batch. | Reproduced live: batch had 400kg, consumed 380kg (20kg should remain, `partially_consumed`), actual result: `qty_remaining=20, status='consumed'`. |
| 7 | **`can()` permission-check function has a caching bug** (`auth.php`) — caches only the first permission checked per request; a second, different `can()` call in the same request silently returns `false` regardless of actual grant. | Latent landmine: any future permission gate added using `can()` (rather than `is_financial()`/`require_role()`) will intermittently and silently deny access it should grant. | Static review of `auth.php`. |
| 8 | **Offline sync silently breaks for the entire Operations module.** `app.js` whitelists `/modules/operations.php` for offline queuing, but `sync.php`'s server-side `$allowedTargets` array omits it — every offline-queued expense, supplier payment, sales return, route reconciliation, rework entry, or period action will be rejected with HTTP 422 when sync is attempted, with no user-facing explanation beyond a generic failure. | Users will believe expenses/returns/etc. recorded while offline are safely queued; they are silently unrecoverable as queued and must be re-entered. | Static review: `app.js` target whitelist vs. `sync.php` `$allowedTargets`. |
| 9 | **Negative inventory is silently clamped to zero**, not flagged, violating an explicit spec rule. `production.php` uses `GREATEST(0, qty_remaining - ?)` and `GREATEST(0, stock_qty - ?)` — over-consumption just disappears with no exception record. | A production run can be logged against a batch that doesn't have enough material left, and the system will never tell anyone. | Static review, `production.php`. |
| 10 | **Stocktakes and stock adjustments are recorded but never posted.** No code path anywhere sets `stocktakes.status='posted'` or applies a counted variance to `finished_stock`/`raw_materials.stock_qty`. The parallel `stock_adjustments` table (built specifically for the Director/Consultant "finalize" step in spec §25) is never inserted into anywhere. | The single explicit business rule "only Director/Consultant may finalize a stock adjustment" (spec §25, §29 in business rules) has no code to enforce, because the finalize action doesn't exist. On-hand quantities can never be corrected through the system after a physical count. | Confirmed by 2 of 5 audit agents independently via repo-wide grep for `status='posted'` / `stock_adjustments`. |

## 3. Section-by-Section Compliance Matrix

Status legend: **W**=Working, **P**=Partial, **M**=Missing.

| § | Topic | Status | One-line summary |
|---|---|---|---|
| 1 | Core users (3 roles, individual logins) | W | Verified live — 3 accounts created via `setup.php`, each logs in independently. |
| 2–3 | Security & permissions (page + data level) | P | Page/query-level RBAC genuinely enforced for purchases/inventory cost fields (verified live incl. bypass attempt); **but Reports page leaks confidential totals** (see Blocker #5); `can()` has a caching bug. |
| 4 | Tech architecture / offline / cloud DB | P | Real MySQL backend; a genuinely working IndexedDB+sync-queue offline layer exists (rare to see this actually implemented) but has a real server-side gap (Blocker #8) and no service worker for offline page *viewing*. |
| 5 | Design/UI (white/blue, professional, minimal) | W | Confirmed via `style.css` — consistent theme, no dated look. |
| 6 | Dashboard | P | Shows production/sales/receivables/low-stock/pending-pricing tiles; missing most of the proactive alerts spec §78 asks for. |
| 7 | Global search | M | No search box exists anywhere in `header.php`/`sidebar.php`. |
| 8 | Standard date filters | P | Present in Reports but not consistently applied (e.g. Production tab ignores the selected range). |
| 9 | GHION products (7 seeded, configurable) | P | All 7 products seeded correctly; create/edit UI exists but has no UOM selector, no production-method field, and the BOM tables (`product_materials`/`product_packaging`) are never written to. |
| 10 | Manufacturing process (stretch→emboss→cut→pack) | M | No process/machine modeling at all; brand-conditional embossing step doesn't exist in code; the 1→10→100 pack hierarchy columns are seeded but **never read anywhere**. |
| 11 | Napkins/serviettes | M | Same packaging-config gap as §10/§18; no napkin-specific process. |
| 12 | Kitchen towels (12-pack) | P | `units_per_pack=12` seeded but never used in any calculation. |
| 13 | Raw materials (kg, tonne→kg costing) | P | Formula exists once in `costing.php` as an aggregate; per-batch flow is backwards (Director enters cost-per-kg, tonne price is back-derived) and the batch-receipt/purchase-receipt are two disconnected, non-atomic entry points. |
| 14 | Jumbo receipt/batch tracking | P | Duplicate-batch detection real; but `supplier_id` is never set on receipt, no document upload, lifecycle states beyond stored/partially_consumed/consumed never used, no location link. |
| 15 | Jumbo consumption without scale | P | Running-balance subtraction works (modulo Blocker #6's bug); the purpose-built `production_consumption` table for opening/consumed/estimated-remaining/variance tracking is **never populated**; physical verification via stocktake never posts back to the batch. |
| 16 | Production batch rule (1 run → 1 batch) | P | Structurally enforced at the run level; traceability **breaks at the point of sale** — `sale_lines` has no batch/run linkage at all. |
| 17 | Core paper/core tubes | P | Movement log table exists and is written to; no running-balance report is ever computed from it (write-only log). |
| 18 | Packaging materials (per brand/level, kg, not hard-coded 25kg) | P | `packaging_items` genuinely configurable, no hard-coded sack size; but the product↔packaging BOM (`product_packaging`) is never populated, and `packaging_items.stock_qty` is never incremented/decremented by any transaction. |
| 19 | Weighted average cost valuation | **M** | Confirmed **zero** writes to `avg_cost` anywhere in the codebase (raw materials or finished goods); reported inventory value uses a fixed `standard_price`, not a computed cost. |
| 20 | Finished goods (immediate stock increase, logical states) | P | Immediate increase confirmed working (core business rule #1 explicitly honored); but salesman stock issuance never decrements `finished_stock` — **double-counted inventory**. |
| 21 | Production run record | P | Most fields captured; `status` field exists but is never set to `'wip'` by any code path; no variance ever populated. |
| 22 | WIP (intermediate long-core carry-forward) | **M** | Schema stub only (`status` ENUM includes `'wip'`); zero code ever uses it. |
| 23 | Waste (kg, reason/category, reporting) | P | Flat `waste_kg` captured on every run and reported on; the purpose-built `production_waste` table (with category+reason) is **never populated** — no reason/category is ever actually recorded. |
| 24 | Rework/renovation | W (minor gap) | Genuinely well implemented — correctly bypasses `production_runs` so rework isn't counted as new output, enforces damaged=recovered+waste, fully audited. Missing: recovery-rate calculation/display. |
| 25 | Physical stocktakes (Director/Consultant post only) | P | Counting works and preserves variance; **the finalize/post step doesn't exist anywhere** (Blocker #10). |
| 26 | Sales (full invoice detail, flexible pricing) | P | All required fields captured; any price can be typed freely with no formal override flag/reason — recoverable only by diffing against `standard_price` after the fact. |
| 27 | Customer types | P | All 7 required types present as hard-coded `<option>` list in two places — not a manageable table, so "authorized users can add new types" is false. |
| 28 | Customer master, ledger, aging | P | Aging buckets work (4, not 5 — no "not yet due" Current bucket); **opening balance is structurally broken** — never reduced by any payment, permanently inflating the ledger; no customer-statement view exists. |
| 29 | Payments (multi-method, allocation) | P | Real FIFO multi-invoice allocation exists; but the purpose-built `payment_allocations` table is never written to (only `sales.balance` is mutated directly, no audit-grade allocation record); excess/unapplied payment amounts are silently discarded, not stored as credit. |
| 30 | Accounting for sales | P | Revenue-side posting is correct and complete; **COGS side is entirely absent** (Blocker #2). |
| 31 | Sales returns | P | Correctly restores inventory as a separate, non-destructive record; **never reverses revenue or the receivable/payment balance** — no journal entry posted at all. |
| 32 | Salesman route stock (issue ≠ sale, reconciliation) | P | Correctly modeled as separate from a sale; but issuing stock **never decrements finished_stock** (not a real transfer, so goods are double-counted); reconciliation **hard-blocks** on any mismatch instead of flagging a variance as the spec requires. |
| 33 | Salesman sales (walk-in customer support) | W | Generic "Cash/Walk-in Customers" seeded and pre-selected; per-invoice payment method works. |
| 34 | Salesman collection reconciliation (expected vs actual cash) | **M** | Only stock quantities are reconciled; no expected-vs-actual cash comparison exists at all; no cash-on-hand→bank transfer feature exists anywhere in the codebase. |
| 35 | Sales commissions/bonuses | P | Manual bonus entry works and flows into payroll; no architectural seam (no `commissions` table) prepared for a future rule engine — bonus and commission are one undifferentiated field. |
| 36 | Purchase category extensibility | P | `item_type` is a hard-coded HTML `<select>`, not a lookup table — adding a category requires editing code. |
| 37 | Confidential purchase workflow | **W** | Verified live including a direct API-bypass attempt as Accountant — genuinely, robustly implemented at the query level, not just the UI. Only gap: no attachment upload despite being "optional" in spec. |
| 38 | Supplier accounting (payables, part-payment, credit/advance) | P | Allocation logic works; **supplier payments post no journal entry and never decrement cash** (part of Blocker #4); no re-allocation of an existing advance to a later invoice; no supplier statement/aging report. |
| 39 | Direct expenses | P | Captured with correct classification; **posts no journal entry, no cash decrement** (Blocker #4); no expense history view exists in the UI at all (write-only). |
| 40 | Petty cash extensibility | P | `cash_accounts.type` is a closed MySQL ENUM — adding "petty cash" as a new type requires a schema migration, not a data change. |
| 41 | Cash/Bank/Mobile Money accounts | P | Sales/payments target accounts **by `type` with `LIMIT 1`**, not by a specific account id — adding a second Bank/MoMo account (explicitly required by spec) will route money unpredictably; no UI to create new accounts; **no transfer feature exists at all**. |
| 42 | Bank/MoMo reconciliation | **M** | Entirely absent — no import, no matching, no reconciliation table of any kind. |
| 43 | Payroll employee master | P | Core fields present; **missing TIN, NSSF number, hire date**; **no employee edit form exists at all** (create-only); NIN/salary are shown to non-financial roles in the payslip modal with no `$fin` gate — a second confidentiality leak alongside Blocker #5. |
| 44 | Payroll calculation (PAYE/NSSF per-employee) | P | Correctly computes zero when not applicable (verified in code); no commission or overtime field exists; employer NSSF rate is captured but never used anywhere. |
| 45 | Payroll payments (method, reference, paid status) | P | Payslip + correct double-entry journal posting confirmed; no payment-method/reference field exists at all; approval is all-or-nothing per run, not per employee; never touches `cash_accounts`. |
| 46 | Exited employees | **M** | Schema fully supports it (`status`, `exit_date`); **zero UI or code path ever sets it** — feature does not exist in practice. |
| 47 | Tax system (VAT/WHT off by default, configurable) | P | PAYE/NSSF correctly employee-specific and non-mandatory; **VAT and withholding tax do not exist anywhere** (no column, setting, or account) — "configurable but disabled" is false; enabling either would require new development, not a settings toggle. No effective-dating on rate changes. |
| 48 | Chart of Accounts (expandable) | **M** | Fixed 18-account SQL seed; **zero UI to add/edit/deactivate an account**. |
| 49 | Automatic accounting (9 required flows) | P | 4 of 8-9 flows post real double-entry journals (purchase pricing, sale revenue, customer payment, payroll). **4 do not post anything**: production consumption, finished production, COGS, expenses/supplier payments (Blockers #2–4). |
| 50 | Manufacturing Account | **M** | Completely absent — not even a stub; the underlying data (opening/closing RM value, allocated overhead, waste cost) doesn't exist to compute it from. |
| 51 | Product-level manufacturing costing + drill-down | P | Only raw-material cost is calculated (one weighted-ish average across all products); no packaging/labour/overhead; **zero drill-down** — flat, non-clickable tables. |
| 52 | Factory overhead allocation (configurable drivers) | **M** | Schema fully supports it (`overhead_categories` with the exact driver ENUM the spec names); **zero seed data, zero UI, zero allocation logic anywhere**. |
| 53 | Overhead absorption (actual vs. allocated) | **M** | Direct consequence of §52 — no data exists to report a variance from. |
| 54 | Machine master | P | Table exists with exactly the right columns; **zero UI, zero PHP references anywhere** — cannot register a single machine today. |
| 55 | Financial reporting | P | Trial Balance/P&L/Balance Sheet are genuinely real, computed, and balance-checked. General Ledger is actually just a journal-entry register (no per-account drill-down). Manufacturing Account, Cash Flow Statement, and Bank Reconciliation are **entirely absent**. |
| 56 | Management reports | P | Sales/Payroll reports are real; Production/Inventory reports ignore date filters and don't break out by batch/date; waste and rework are never reported on despite having populated-ish source tables. |
| 57 | Report confidentiality | P (with a real leak) | See Blocker #5 — this is the most important single fix in the whole security area. |
| 58 | Excel export | P | 5 of ~20 report types have real, working file-download exports (not decorative); the payroll export is missing its server-side role check (URL-exploitable, same root cause as Blocker #5). |
| 59 | Invoices and receipts | P | Invoice and payslip print views are real and complete; **no printable receipt view exists** for customer payments; sales returns aren't a true credit note (no financial/journal effect, no document); no PDF generation anywhere, only browser print. |
| 60 | Audit trail (search/export) | W | Search and Excel export both genuinely work, gated correctly to Director/Consultant. |
| 61 | Stock control | P | Movement logging is real; negative inventory is silently clamped rather than flagged (Blocker #9); edit-sale path has no stock-sufficiency guard at all. |
| 62 | Master data CRUD | P | Real CRUD exists for products, raw materials, customers, suppliers (create+edit); create-only for employees/packaging; **no UI at all** for machines, departments, UOM, product categories, chart of accounts, tax rules, customer/supplier types, payment accounts. |
| 63 | Backups | P | Manual backup (export+download+history) genuinely works; **no scheduling exists** (no cron — "daily default" is false); **no restore functionality exists anywhere**, despite the UI describing one. |
| 64 | Notifications | **M** | Table exists; zero references anywhere in the app. |
| 65 | Offline design | P | A real, well-built implementation (IndexedDB, UUID dedup, preserved timestamps, sync-status indicator, retry-on-reconnect, sync history) — better than most such claims turn out to be. But: Blocker #8 (Operations module rejected server-side), no offline page-viewing (no service worker/manifest), file uploads explicitly can't be queued offline. |
| 66 | Data validation | P | Extensive real server-side validation exists (not just HTML5); no duplicate-invoice or duplicate-payment-reference detection; invoice numbers are random, not sequential. |
| 67 | Transaction reversals (no silent deletes) | W | Confirmed — no `DELETE` of any financial transaction exists anywhere in the codebase; edits are audit-logged in-place revisions, a reasonable middle ground. |
| 68 | Data relationships (FK chains) | P | Core chains are real; `journal_entries.source_type/source_id` is necessarily polymorphic (no real FK); `production_consumption` — meant to link run→batch consumption — is a dead table, breaking that specific link in practice. |
| 69 | Search and drill-down | **M** | No drill-down links between any reports; no global search box exists anywhere (same finding as §7). |
| 70 | Reconciliation tools | P | Salesman and supplier-payment reconciliation genuinely work; stock/bank/MoMo/cash-till reconciliation **do not exist** — `stock_adjustments` is a fully-built orphan table with a permission (`post_stock_adjustments`) that nothing ever checks because nothing ever calls it. |
| 71 | Accounting periods | P | Open/close/reopen UI is real and audited; **but period status is never checked anywhere a transaction is actually posted** — closing a period is currently cosmetic. |
| 72 | Performance | P | Real indexes on high-traffic tables; `LIMIT`-capped queries but **no real pagination** (no OFFSET) — older records simply become inaccessible past the cap. |
| 73 | Responsive design | **W** | Confirmed real: working `@media` breakpoints, off-canvas mobile sidebar, responsive tables/forms. |
| 74 | Configurability (cross-cutting) | P | Genuinely configurable: users, PAYE/NSSF rates, packaging items, products/materials/customers/suppliers (partially). Not configurable without code/DB changes: pack sizes, costing rules, overhead drivers, tax rules beyond 2 flat percentages, departments, machines, commission formulas, invoice numbering, stock locations. |
| 75 | The 30 explicit business rules | P | See the per-rule terse breakdown in §4.4 below — roughly half working, half partial/missing, directly traceable to the blockers and gaps above. |
| 76 | Reporting standards (management vs. financial vs. tax separation) | **M** | Reports are grouped by convenience ("near the module"), not by report class; no tax-report category exists at all. |
| 77 | User experience | W (qualitative) | Reasonably minimal-click for a server-rendered multi-page app; single form-then-table per module; modal-based editing keeps context. |
| 78 | Error prevention / proactive alerts | P | Only low-stock and pending-pricing are surfaced; the other ~10 alert types the spec asks for (unreconciled routes/collections, high waste, duplicate refs, unallocated payments, accounting imbalance, unreconciled bank, etc.) do not exist on the dashboard. |
| 79–81 | Implementation approach / DB / testing | — | 2-commit git history (single large drop, not phased delivery); ~35 tables exist, roughly a third are orphaned (never written to by any code); **zero automated tests anywhere in the repo**. |
| 82–83 | Acceptance criteria / final verdict | — | See Executive Summary. |

## 4. Detailed Findings and Fix Plan, by Domain

### 4.1 Deployment & Architecture
- **Fix the path structure.** Move `header.php`, `footer.php`, `sidebar.php`, `auth.php`, `403.php` into `includes/`; `database.php` into `config/`; `style.css`/`app.js` into `assets/css/` and `assets/js/`; all remaining module pages (`dashboard.php`, `sales.php`, `customers.php`, `production.php`, `inventory.php`, `purchases.php`, `payroll.php`, `accounting.php`, `costing.php`, `reports.php`, `settings.php`, `audit.php`, `backups.php`, `migrate.php`, `operations.php`, `403.php`) into `modules/`; keep `index.php`, `logout.php`, `setup.php`, `sync.php` at the repository root — this exact split was validated live in this audit (the app boots correctly once laid out this way, no code changes needed). Add a one-line note to the README about the required layout so it can never silently regress again.
- Add real pagination (`OFFSET`, "next page" links) to every list view currently relying on a bare `LIMIT` (sales, purchases, production, audit history).
- Add date-bounded indexes to support the Trial Balance / P&L aggregate queries as transaction volume grows.

### 4.2 Security, RBAC & Confidentiality
- Close the Reports-page leak: wrap the Purchases-tab and Payroll-Summary-tab queries/rendering in `if ($fin)`, exactly matching the pattern already correctly used for their export buttons.
- Add the missing `require_role`/`$fin` guard inside the `export=payroll` handler itself (currently only the UI link is hidden).
- Gate the payslip modal and payroll-run salary table behind `$fin` so NIN/salary/NSSF/PAYE are not shown to the Accountant role.
- Fix `can()`'s per-request caching bug before any future feature relies on it.
- Add an `attachment_path` column + optional upload to `purchases` (and wire the existing one on `expenses` into an actual `<input type="file">`, since currently zero file uploads exist anywhere in the app despite the column already being present on `expenses`).

### 4.3 Accounting Integrity (the biggest functional gap)
This is the highest-value fix cluster — until it's done, every financial statement in the system is working from an incomplete ledger.
1. Compute and persist a running weighted-average cost:
   - `raw_materials.avg_cost` recalculated on every priced batch/purchase: `(old_qty*old_avg_cost + new_qty*new_unit_cost) / (old_qty+new_qty)`.
   - `finished_stock.avg_cost` recalculated from production run cost (material + packaging + labour + allocated overhead) ÷ qty produced, once §4.4's costing pipeline exists.
2. Post a journal entry for **every** production run: `Dr Work in Progress/Direct Mfg Cost` / `Cr Raw Material Inventory` at consumption, and `Dr Finished Goods` / `Cr WIP` at completion.
3. Post `Dr Cost of Sales / Cr Finished Goods Inventory` on every sale line, at `qty × finished_stock.avg_cost`, alongside the existing revenue entry.
4. Post `Dr Accounts Payable / Cr Cash-or-Bank` on every supplier payment, and decrement the correct `cash_accounts` row.
5. Post `Dr [category's expense account] / Cr Cash-or-Bank` on every direct expense, and decrement `cash_accounts`.
6. Reverse revenue and the receivable/cash balance (plus COGS, once #3 exists) on every sales return, instead of only restoring inventory.
7. Stop addressing cash accounts by `type` with `LIMIT 1`; add a `cash_account_id` selector to every form that touches cash, so a second Bank/MoMo account (explicitly required by the spec) can be added safely.
8. Add a real inter-account **Transfer** feature (`Dr` destination / `Cr` source) — currently doesn't exist at all, so a cash deposit to the bank has no correct way to be recorded.
9. Populate `payment_allocations` on every FIFO split instead of only mutating `sales.balance` directly, and store unapplied/excess payment as customer credit instead of discarding it.
10. Enforce accounting-period locks: add a shared `assert_period_open($date)` check and call it from every posting path (sales, purchase pricing, payroll approval, manual journal entry, stock adjustments) — currently closing a period has no effect anywhere.

### 4.4 Manufacturing & Inventory Traceability
1. Fix the batch `qty_remaining`/`status` double-subtraction bug (Blocker #6) — split into two sequential statements or reference the pre-update value via a subquery/CTE instead of relying on MySQL's left-to-right SET evaluation.
2. Populate `production_consumption` on every production run (opening/consumed/estimated-remaining/variance by batch) instead of only adjusting `batches.qty_remaining` directly — this also fixes the cost calculations in `operations.php` that currently always resolve to zero because they read from this table.
3. Populate `production_waste` with category + reason per run, replacing (or supplementing) the flat `waste_kg` field; add a waste-by-category/reason report.
4. Build the stocktake **posting** workflow: a Director/Consultant-only action (the `post_stock_adjustments` permission already exists and is unused) that writes to `stock_adjustments`, updates `raw_materials.stock_qty`/`finished_stock.qty` by the variance, and flips `stocktakes.status` to `posted`.
5. Fix salesman route issuance to be a real stock transfer: decrement `finished_stock` and log a `stock_movements` row (`Finished Goods Warehouse` → `Salesman Stock`) on issue, and reverse it on close.
6. Change route reconciliation from a hard block on mismatch to a recorded, flagged variance (add a `variance` column and require an explanation instead of throwing an exception that prevents closing at all).
7. Replace the silent `GREATEST(0, ...)` clamps in `production.php` with a check that raises a visible exception/variance record when consumption exceeds what's available.
8. Build UI to populate `product_materials` and `product_packaging` (currently schema-only, zero rows) — this is the prerequisite for §51's per-product costing and §10/§18's pack-hierarchy requirements.
9. Actually read and use `units_per_pack`/`packs_per_master` (seeded but never referenced anywhere) to compute and retain both the individual-roll and pack-level quantities through production → finished stock → sales.
10. Add a running opening/received/used/closing balance report on top of the already-populated `core_paper_movements` log (currently write-only).
11. Add a computed rework recovery rate (`recovered_qty/damaged_qty`) to the rework UI, and auto-derive `product_id` from the selected production run instead of independent re-entry.

### 4.5 Sales, Customers, Salesmen
1. Fix the customer-ledger opening-balance bug — it's never reduced by any payment, permanently inflating every customer's closing balance; give it its own payable-off line or convert it to a synthetic "opening" invoice.
2. Add a `customer_adjustments` table for approved write-offs/credits, feeding the balance formula (currently "Approved adjustments" from spec §28 has no implementation at all).
3. Add a real printable/exportable per-customer statement view.
4. Add price-override flagging: compare the entered price against `products.standard_price` at save time and store an explicit `is_override`/`override_reason`, rather than requiring a manual diff after the fact.
5. Convert `customers.type` from a hard-coded HTML option list into a real `customer_types` lookup table with a management UI, mirroring the existing `product_categories` pattern.
6. Add expected-vs-actual cash reconciliation to route closing (expected = sum of that route's sales at invoice price; require an explanation on variance instead of silently accepting whatever numbers balance).
7. Add a `commissions` table (employee/salesman, period, basis, rate, computed amount, is_manual) so today's manual bonus entries become naturally replaceable by rule-generated ones later without a schema migration of history.

### 4.6 Purchasing & Suppliers
1. Convert the `item_type` purchase-category `<select>` from hard-coded HTML options into a lookup table (or reuse `expense_categories`), with an admin CRUD screen.
2. Add a Supplier Statement / Supplier Aging report mirroring the existing customer A/R aging pattern.
3. Allow re-allocation of an existing unallocated supplier advance/credit to a later invoice (currently allocation only happens once, automatically, at payment-creation time).
4. Change `cash_accounts.type` from a closed MySQL ENUM to a lookup-table-backed value so a future "petty cash" type doesn't require a schema migration.

### 4.7 Payroll & Tax
1. Add `tin`, `nssf_number`, `hire_date` columns to `employees`, and — critically — **build an employee edit form**, since none exists today (create-only; PAYE/NSSF applicability can never be changed after an employee is created).
2. Add commission and overtime fields/calculations to the payroll run.
3. Either wire the already-captured `nssf_employer_rate` into an employer-contribution journal line, or remove the dead setting.
4. Add payment method, cash-account, reference, and per-employee paid/unpaid status to payroll disbursement (currently all-or-nothing per run, and never touches `cash_accounts`).
5. Build the exited-employee workflow end to end: an "Exit" action (date + reason, sets `status='exited'`) and a "Reactivate" action — the schema fully supports this today but zero UI exists.
6. Replace the flat `settings` key/value rate storage with a proper `tax_rates` table (`tax_type`, `rate`, `effective_from`, `effective_to`) so PAYE/NSSF/future-VAT/future-WHT all get real effective-dating and audit history, instead of each rate change silently overwriting the last with no way to prove what rate was in force for a past payroll run.
7. Add (inactive-by-default) VAT and withholding-tax settings, ledger accounts, and transaction-level override/exemption fields — today enabling either would require new development, not a configuration change, which contradicts the spec's explicit requirement.

### 4.8 Reporting, Search & Drill-down
1. Add a global search box to the header, hitting a lightweight cross-entity endpoint (customer/supplier/employee/product/invoice/batch), permission-filtered.
2. Add row-level deep links from every report so drill-down actually works (e.g., a sales report row links to its invoice, which links to the customer, which links to their ledger).
3. Add a true per-account General Ledger view (the current "General Ledger" tab is really just a journal-entry register).
4. Build the Manufacturing Account report once §4.3/§4.4's cost data exists (opening/closing RM value, materials consumed, direct labour, packaging, core paper, allocated overhead, waste — down to Cost of Production → COGS → Gross Profit).
5. Build a Cash Flow Statement and a Bank/MoMo reconciliation screen (statement import, auto-match by amount/date/ref, manual match, unmatched queue, reconciliation history) — neither exists in any form today.
6. Build the Factory Overhead Allocation and Absorption pipeline: seed `overhead_categories`, add a data-entry screen for actual overhead per category per period, compute `allocated_amount` per the category's configured driver, and report actual-vs-allocated variance explicitly.
7. Build a Machines CRUD screen — the table exists with the right columns; nothing references it anywhere, and it's a prerequisite for a machine-hours overhead driver.
8. Extend Excel export coverage beyond the current 5 report types (sales, TB, production, purchases, payroll) to at minimum P&L, Balance Sheet, and A/R & A/P aging, which are already fully computed and just need an export wrapper.
9. Reorganize `reports.php` + `accounting.php` into explicit categories (Management / Operational / Accounting / Financial Statements / Tax) instead of grouping by "which module it's near."

### 4.9 Master Data & Configurability
Build CRUD UI (most likely consolidated into `settings.php` or a new master-data module) for the tables that already exist but have zero write path: Chart of Accounts, Machines, Departments, Units of Measure, Product Categories, Inventory Locations, Customer/Supplier Types, Overhead Categories, Payment Accounts. Add a configurable invoice-numbering scheme (currently hard-coded random-suffix generation).

### 4.10 Offline, Backup, Notifications
1. Add `/modules/operations.php` to `sync.php`'s server-side allowed-targets list (Blocker #8) — the single highest-value offline fix, since the client already queues these transactions correctly.
2. Add a service worker + manifest so at least recently-viewed pages remain browsable offline (today only form submission is offline-capable — GET page loads simply fail with no connectivity).
3. Add scheduled daily backups (cron or an equivalent app-level scheduler) — today backup is 100% manual-trigger-only despite the UI implying otherwise.
4. Build an actual restore workflow, heavily audited and Director-only — currently doesn't exist despite the UI describing one.
5. Build a minimal in-system notification feed (the `notifications` table exists, zero code references it).

### 4.11 UX, Documents, Alerts
1. Add a printable receipt view for customer payments (invoice and payslip already have real print views; receipts don't).
2. Turn sales returns into a true credit note: reduce the original invoice's balance and post the reversing journal entry (§4.3.6), with its own printable document.
3. Extend the dashboard with an "Exceptions & Alerts" panel: open/unreconciled salesman routes, unposted stocktakes, unallocated supplier payments, ledger-imbalance flag, waste-rate-over-threshold — all currently invisible outside their own module.
4. Add duplicate-payment-reference detection (duplicate-batch detection already exists and is a good model to copy).
5. Switch invoice numbering to a sequential, pre-checked counter instead of `random_int`-based generation.

## 5. Phased Implementation Plan

**Phase 0 — Unblock (days, not weeks).**
Fix the directory layout (§4.1); fix the batch double-subtraction bug (§4.4.1); close the two Reports/Payroll confidentiality leaks and the `export=payroll` guard (§4.2); fix the `can()` caching bug (§4.2). *Exit criteria: the app boots on a clean deploy, and an Accountant account genuinely cannot see any confidential figure anywhere in the UI, an export, or a direct URL.*

**Phase 1 — Accounting integrity.**
All of §4.3: weighted-average costing, the four missing journal-posting flows (production consumption, finished goods, COGS, expenses/supplier payments), cash-account-by-id + transfers, payment allocations, period locking. *Exit criteria: for a full month of seeded transactions, Trial Balance debits = credits, and the Balance Sheet's inventory value matches a manual weighted-average calculation.*

**Phase 2 — Inventory & manufacturing traceability.**
§4.4 in full: production_consumption, production_waste, stocktake posting, real salesman stock transfers, flagged (not blocking) route reconciliation, negative-inventory flagging, product BOMs, pack-hierarchy usage, core-paper balance reporting, rework recovery rate. *Exit criteria: a raw-material batch can be traced through to the specific sale it ended up in; a physical stocktake can actually change what the system reports as on-hand.*

**Phase 3 — Manufacturing costing & overheads.**
Machines CRUD, overhead category/allocation pipeline and absorption report, the Manufacturing Account report, full product-level costing with drill-down. *Exit criteria: product profitability for each of the 7 SKUs can be drilled down from P&L to individual production-run consumption.*

**Phase 4 — Master data & configurability; payroll/tax completeness.**
Chart of Accounts CRUD, customer/supplier types as real tables, UOM/categories/locations wired up, tax-rate table with VAT/WHT scaffolding, employee edit + exit/reactivate workflow, commission architecture. *Exit criteria: every item in spec §62's master-data list can be added by an authorized user with zero code changes.*

**Phase 5 — Reporting, reconciliation, search.**
Global search, report drill-down, per-account General Ledger, Cash Flow Statement, bank/MoMo reconciliation, supplier statement/aging, expanded Excel export coverage, report categorization. *Exit criteria: every report named in spec §55/§56 exists and is reachable from a drill-down path.*

**Phase 6 — UX, documents, alerts.**
Printable receipts, true credit-note sales returns, dashboard exceptions panel, duplicate-reference detection, sequential invoice numbering.

**Phase 7 — Offline, backup, notifications, infra hardening.**
Fix the Operations offline-sync gap, add a service worker for offline viewing, scheduled backups + real restore, notification feed, real pagination, additional indexes.

**Phase 8 — Testing & QA.**
Add an automated test suite (there is currently none at all) covering: accounting-balance invariants, RBAC/confidentiality boundaries (including the exact bypass techniques already validated manually in this audit), offline sync round-trips, and the full set of 30 business rules in spec §75. Run a full walkthrough of every module as each of the three roles before sign-off.

## 6. What's Already Working Well (preserve, don't regress)

- Role-based access control and the confidential-purchase-pricing workflow — genuinely robust, verified via a direct server-side bypass attempt.
- CSRF protection on every state-changing form.
- The audit trail — detailed, searchable, exportable, and correctly gated.
- Core financial statements: Trial Balance, P&L, and Balance Sheet are real, computed, and self-balance-checked.
- Per-employee PAYE/NSSF applicability, correctly computing zero when not applicable.
- The offline IndexedDB + sync-queue layer — a real implementation with UUID-based dedup, preserved original timestamps, and a genuine sync-status indicator (once Blocker #8 is fixed, this is most of the way to spec).
- Responsive design — real CSS breakpoints, not an afterthought.
- Duplicate-batch-number detection, insufficient-stock blocking on sale, and the rework workflow's damaged=recovered+waste enforcement — all solid examples of the validation rigor the rest of the system should be brought up to.
- Immediate finished-goods stock increase on production with no approval gate (core business rule #1), and no silent deletion of any financial transaction anywhere in the codebase.
