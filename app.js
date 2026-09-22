// GHION ERP — Frontend Logic, UI Interactivity & Sync Indicator
(function () {
  'use strict';

  // 1. Sidebar Mobile Toggle
  document.addEventListener('DOMContentLoaded', function () {
    const toggleBtn = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebarBackdrop');

    if (toggleBtn && sidebar && backdrop) {
      toggleBtn.addEventListener('click', function () {
        sidebar.classList.toggle('open');
        backdrop.classList.toggle('active');
      });
      backdrop.addEventListener('click', function () {
        sidebar.classList.remove('open');
        backdrop.classList.remove('active');
      });
    }
  });

  // 2. Offline transaction queue
  const syncDbName = 'ghion-erp-offline';
  const syncStoreName = 'transactions';
  const syncTargets = new Set([
    '/modules/customers.php',
    '/modules/inventory.php',
    '/modules/payroll.php',
    '/modules/production.php',
    '/modules/purchases.php',
    '/modules/operations.php',
    '/modules/sales.php'
  ]);

  function syncDb() {
    return new Promise(function (resolve, reject) {
      const request = indexedDB.open(syncDbName, 1);
      request.onupgradeneeded = function () {
        request.result.createObjectStore(syncStoreName, { keyPath: 'uuid' });
      };
      request.onsuccess = function () { resolve(request.result); };
      request.onerror = function () { reject(request.error); };
    });
  }

  async function queuedTransactions() {
    const db = await syncDb();
    return new Promise(function (resolve, reject) {
      const request = db.transaction(syncStoreName, 'readonly').objectStore(syncStoreName).getAll();
      request.onsuccess = function () { resolve(request.result || []); };
      request.onerror = function () { reject(request.error); };
    });
  }

  async function queueTransaction(transaction) {
    const db = await syncDb();
    return new Promise(function (resolve, reject) {
      const request = db.transaction(syncStoreName, 'readwrite').objectStore(syncStoreName).put(transaction);
      request.onsuccess = resolve;
      request.onerror = function () { reject(request.error); };
    });
  }

  async function removeQueuedTransaction(uuid) {
    const db = await syncDb();
    return new Promise(function (resolve, reject) {
      const request = db.transaction(syncStoreName, 'readwrite').objectStore(syncStoreName).delete(uuid);
      request.onsuccess = resolve;
      request.onerror = function () { reject(request.error); };
    });
  }

  function transactionUuid() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
    return 'offline-' + Date.now() + '-' + Math.random().toString(16).slice(2);
  }

  function appRoot() {
    return window.location.pathname.includes('/modules')
      ? window.location.pathname.split('/modules')[0]
      : window.location.pathname.replace(/\/index\.php$/, '').replace(/\/$/, '');
  }

  function formPayload(form) {
    const payload = [];
    for (const entry of new FormData(form).entries()) {
      if (typeof entry[1] !== 'string') throw new Error('File uploads cannot be queued offline yet.');
      if (entry[0] === 'csrf') continue;
      payload.push([entry[0], entry[1]]);
    }
    return payload;
  }

  async function sendTransaction(transaction) {
    const response = await fetch(appRoot() + '/sync.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(transaction),
      credentials: 'same-origin'
    });
    const result = await response.json().catch(function () { return {}; });
    if (!response.ok || !['accepted', 'duplicate'].includes(result.status)) {
      throw new Error(result.message || 'Synchronization failed.');
    }
    return result;
  }

  function showSyncMessage(message) {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.textContent = message;
    container.appendChild(toast);
    setTimeout(function () { toast.remove(); }, 5000);
  }

  async function syncPendingTransactions() {
    if (!navigator.onLine) return;
    const transactions = await queuedTransactions().catch(function () { return []; });
    for (const transaction of transactions) {
      try {
        await sendTransaction(transaction);
        await removeQueuedTransaction(transaction.uuid);
      } catch (error) {
        transaction.attempts = (transaction.attempts || 0) + 1;
        transaction.lastError = error.message;
        await queueTransaction(transaction).catch(function () {});
      }
    }
    updateSyncStatus();
  }

  async function updateSyncStatus() {
    const badge = document.getElementById('syncBadge');
    const text = document.getElementById('syncText');
    if (!badge || !text) return;

    const pending = (await queuedTransactions().catch(function () { return []; })).length;
    if (navigator.onLine) {
      badge.classList.remove('offline');
      text.textContent = pending > 0 ? `Online — ${pending} transaction(s) waiting to sync` : 'Online — Synced';
    } else {
      badge.classList.add('offline');
      text.textContent = `Offline — ${pending} transaction(s) pending sync`;
    }
  }

  document.addEventListener('submit', async function (event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() !== 'post') return;
    const target = new URL(form.action || window.location.href, window.location.href).pathname.replace(appRoot(), '') || '/index.php';
    if (!syncTargets.has(target) || form.dataset.syncBusy === '1') return;

    let payload;
    try { payload = formPayload(form); } catch (error) {
      if (!navigator.onLine) {
        event.preventDefault();
        showSyncMessage(error.message);
      }
      return;
    }

    event.preventDefault();
    form.dataset.syncBusy = '1';
    const transaction = {
      uuid: transactionUuid(),
      target: target,
      payload: payload,
      created_at: new Date().toISOString(),
      attempts: 0
    };

    try {
      if (!navigator.onLine) throw new Error('offline');
      await sendTransaction(transaction);
      window.location.reload();
    } catch (error) {
      await queueTransaction(transaction);
      showSyncMessage(navigator.onLine ? 'Saved locally. It will retry automatically.' : 'Saved offline. It will sync when connection returns.');
      updateSyncStatus();
      form.dataset.syncBusy = '0';
    }
  }, true);

  window.addEventListener('online', syncPendingTransactions);
  window.addEventListener('offline', updateSyncStatus);
  document.addEventListener('DOMContentLoaded', function () {
    updateSyncStatus();
    syncPendingTransactions();
  });
  updateSyncStatus();

})();

// 3. Modal Dialog Controls
function openModal(title, htmlContent) {
  const backdrop = document.getElementById('appModalBackdrop');
  const titleEl = document.getElementById('appModalTitle');
  const bodyEl = document.getElementById('appModalBody');

  if (backdrop && titleEl && bodyEl) {
    titleEl.textContent = title;
    bodyEl.innerHTML = htmlContent;
    backdrop.classList.add('active');
  }
}

function closeModal() {
  const backdrop = document.getElementById('appModalBackdrop');
  const dialog = document.getElementById('appModalDialog');
  if (backdrop) {
    backdrop.classList.remove('active');
  }
  if (dialog) {
    dialog.style.maxWidth = '580px';
  }
}

// Close modal when clicking outside dialog
document.addEventListener('click', function (e) {
  const backdrop = document.getElementById('appModalBackdrop');
  if (e.target === backdrop) {
    closeModal();
  }
});

// 4. Company Quick-Create (+ New) Modal Launcher
function openCompanyNewModal() {
  const basePath = window.location.pathname.includes('/modules') 
    ? window.location.pathname.split('/modules')[0] 
    : window.location.pathname.replace(/\/index\.php$/, '').replace(/\/$/, '');

  const html = `
    <div class="qb-grid" style="padding: 0.5rem 0;">
      <div>
        <div class="qb-col-title">Customers</div>
        <div class="qb-link-list">
          <a class="qb-link-item" href="${basePath}/modules/sales.php">+ Create Sales Invoice</a>
          <a class="qb-link-item" href="${basePath}/modules/customers.php">+ Receive Customer Payment</a>
          <a class="qb-link-item" href="${basePath}/modules/customers.php">+ Register New Customer</a>
          <a class="qb-link-item" href="${basePath}/modules/customers.php">Customer Directory &amp; Ledgers</a>
        </div>
      </div>
      <div>
        <div class="qb-col-title">Vendors / Purchases</div>
        <div class="qb-link-list">
          <a class="qb-link-item" href="${basePath}/modules/purchases.php">+ Receive Supplier Delivery</a>
          <a class="qb-link-item" href="${basePath}/modules/purchases.php">+ Attach Bill Cost (Financial)</a>
          <a class="qb-link-item" href="${basePath}/modules/purchases.php">+ Register New Supplier</a>
          <a class="qb-link-item" href="${basePath}/modules/purchases.php">Vendor Accounts Payable</a>
        </div>
      </div>
      <div>
        <div class="qb-col-title">Manufacturing</div>
        <div class="qb-link-list">
          <a class="qb-link-item" href="${basePath}/modules/production.php">+ Log Production Run</a>
          <a class="qb-link-item" href="${basePath}/modules/inventory.php">+ Add New Finished Product</a>
          <a class="qb-link-item" href="${basePath}/modules/inventory.php">+ Add New Raw Material</a>
          <a class="qb-link-item" href="${basePath}/modules/inventory.php">+ Submit Physical Stocktake</a>
        </div>
      </div>
      <div>
        <div class="qb-col-title">Accounting &amp; Reports</div>
        <div class="qb-link-list">
          <a class="qb-link-item" href="${basePath}/modules/accounting.php">+ Post Manual Journal Entry</a>
          <a class="qb-link-item" href="${basePath}/modules/accounting.php">Profit &amp; Loss Statement</a>
          <a class="qb-link-item" href="${basePath}/modules/accounting.php">Balance Sheet Report</a>
          <a class="qb-link-item" href="${basePath}/modules/reports.php">Report Center &amp; Exports</a>
        </div>
      </div>
    </div>
  `;
  openModal('GHION INVESTMENTS AND ENTERPRISE LTD Quick-Create Center (+ New)', html);
  
  const dialog = document.getElementById('appModalDialog');
  if (dialog) {
    dialog.style.maxWidth = '850px';
  }
}
