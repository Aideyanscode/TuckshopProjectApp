let students = [];
let products = [];
let settings = {};

const loginScreen = document.getElementById('login-screen');
const adminApp = document.getElementById('admin-app');
const loginForm = document.getElementById('login-form');
const loginError = document.getElementById('login-error');

function showTab(name) {
  document.querySelectorAll('.tab').forEach((t) => t.classList.toggle('active', t.dataset.tab === name));
  document.querySelectorAll('.tab-panel').forEach((p) => p.classList.toggle('active', p.id === `tab-${name}`));
}

async function checkAuth() {
  const token = sessionStorage.getItem('admin_token');
  if (!token) return false;
  try {
    await apiGet('settings.php', { verify: '1' }, true);
    return true;
  } catch {
    return false;
  }
}

loginForm?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const password = document.getElementById('admin-password').value;
  sessionStorage.setItem('admin_token', password);
  try {
    await apiGet('settings.php', { verify: '1' }, true);
    loginScreen.classList.add('hidden');
    adminApp.classList.remove('hidden');
    loadAll().then(startLiveSync);
  } catch {
    sessionStorage.removeItem('admin_token');
    loginError.textContent = 'Wrong password';
    loginError.classList.remove('hidden');
  }
});

document.querySelectorAll('.tab').forEach((tab) => {
  tab.addEventListener('click', () => showTab(tab.dataset.tab));
});

async function refreshCatalogViews() {
  await Promise.all([loadInventory(), loadProducts()]);
}

async function loadAll() {
  await Promise.all([
    refreshCatalogViews(),
    loadSettings(),
    loadStudents(),
    loadTransactions(),
    loadSellers(),
  ]);
}

function startLiveSync() {
  LiveSync.register('catalog', refreshCatalogViews);
  LiveSync.register('students', loadStudents);
  LiveSync.register('sellers', loadSellers);
  LiveSync.register('transactions', loadTransactions);
  LiveSync.start();
}

function stockClass(qty) {
  if (qty <= 0) return 'stock-out';
  if (qty <= 5) return 'stock-low';
  return 'stock-ok';
}

function inventoryAdminRow(p) {
  const qty = parseInt(p.stock_quantity, 10);
  return `
    <tr>
      <td>${p.icon || defaultIconForCategory(p.category)}</td>
      <td>${escape(p.name)}</td>
      <td>₦${Number(p.price).toLocaleString()}</td>
      <td class="${stockClass(qty)}">${qty}</td>
      <td>
        <input type="number" min="0" value="${qty}" data-stock-id="${p.id}" style="width:72px;padding:0.35rem;border-radius:6px;border:1px solid var(--surface2);background:var(--bg);color:var(--text);">
        <button type="button" class="btn btn-secondary" style="padding:0.35rem 0.6rem;font-size:0.8rem;margin-left:0.35rem" data-save-stock="${p.id}">Save</button>
      </td>
    </tr>`;
}

function bindStockSaveButtons(container) {
  if (!container) return;
  container.querySelectorAll('[data-save-stock]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const id = parseInt(btn.dataset.saveStock, 10);
      const input = container.querySelector(`input[data-stock-id="${id}"]`);
      try {
        await apiPut('inventory.php', { product_id: id, stock_quantity: parseInt(input.value, 10) || 0 }, true);
        await LiveSync.refreshNow('catalog', refreshCatalogViews);
      } catch (e) {
        alert(e.message);
      }
    });
  });
}

async function loadInventory() {
  const data = await apiGet('inventory.php', {}, true);
  const s = data.summary || {};
  const groups = data.groups || groupProductsByCategory(data.inventory || []);

  const statsEl = document.getElementById('inventory-stats');
  if (statsEl) {
    statsEl.innerHTML = `
      <div class="stat-card"><div class="value">${s.pastry_units ?? 0}</div><div class="label">Pastry items in stock</div></div>
      <div class="stat-card"><div class="value">${s.drink_units ?? 0}</div><div class="label">Drink items in stock</div></div>
      <div class="stat-card"><div class="value">${s.low_stock_count ?? 0}</div><div class="label">Low / out of stock</div></div>
    `;
  }

  const pastryTbody = document.getElementById('inventory-pastry-tbody');
  const drinkTbody = document.getElementById('inventory-drink-tbody');
  if (pastryTbody) {
    pastryTbody.innerHTML =
      (groups.pastry || []).map(inventoryAdminRow).join('') ||
      '<tr><td colspan="5" style="color:var(--muted)">No pastries</td></tr>';
    bindStockSaveButtons(pastryTbody);
  }
  if (drinkTbody) {
    drinkTbody.innerHTML =
      (groups.drink || []).map(inventoryAdminRow).join('') ||
      '<tr><td colspan="5" style="color:var(--muted)">No drinks</td></tr>';
    bindStockSaveButtons(drinkTbody);
  }
}

async function loadSettings() {
  const data = await apiGet('settings.php');
  settings = data.settings || {};
  document.getElementById('set-school').value = settings.school_name || '';
  document.getElementById('set-max-daily').value = settings.max_daily_spend || '';
  document.getElementById('set-max-drinks').value = settings.max_drinks_per_day || '';
  document.getElementById('set-max-pastries').value = settings.max_pastries_per_day || '';
}

async function loadStudents() {
  const data = await apiGet('students.php', { q: document.getElementById('student-search')?.value || '' });
  students = data.students || [];
  const tbody = document.getElementById('students-tbody');
  tbody.innerHTML = students
    .map(
      (s) => `
    <tr>
      <td>${escape(s.student_number)}</td>
      <td>${escape(s.full_name)}</td>
      <td>${escape(s.class_name)}</td>
      <td>₦${Number(s.balance).toLocaleString()}</td>
      <td><code>${s.nfc_uid || '—'}</code></td>
      <td>
        <button type="button" class="btn btn-secondary" style="padding:0.35rem 0.6rem;font-size:0.8rem" data-topup="${s.id}">Top-up</button>
        <button type="button" class="btn btn-secondary" style="padding:0.35rem 0.6rem;font-size:0.8rem" data-bind="${s.id}">Bind NFC</button>
      </td>
    </tr>`
    )
    .join('');

  tbody.querySelectorAll('[data-topup]').forEach((btn) => {
    btn.addEventListener('click', () => openTopup(parseInt(btn.dataset.topup, 10)));
  });
  tbody.querySelectorAll('[data-bind]').forEach((btn) => {
    btn.addEventListener('click', () => openBind(parseInt(btn.dataset.bind, 10)));
  });
}

function productListRow(p) {
  const qty = parseInt(p.stock_quantity, 10) || 0;
  return `
    <tr>
      <td>${p.icon || defaultIconForCategory(p.category)}</td>
      <td>${escape(p.name)}</td>
      <td>₦${Number(p.price).toLocaleString()}</td>
      <td class="${stockClass(qty)}">${qty}</td>
      <td>
        <input type="number" min="0" value="${qty}" data-stock-id="${p.id}" style="width:72px;padding:0.35rem;border-radius:6px;border:1px solid var(--surface2);background:var(--bg);color:var(--text);">
        <button type="button" class="btn btn-secondary" style="padding:0.35rem 0.6rem;font-size:0.8rem;margin-left:0.35rem" data-save-stock="${p.id}">Save</button>
      </td>
      <td>${p.is_active ? 'Yes' : 'No'}</td>
    </tr>`;
}

async function loadProducts() {
  const data = await apiGet('products.php', { active: '0', menu: '1' });
  products = data.products || [];
  const groups = groupProductsByCategory(products);
  const pastryTbody = document.getElementById('products-pastry-tbody');
  const drinkTbody = document.getElementById('products-drink-tbody');
  if (pastryTbody) {
    pastryTbody.innerHTML =
      (groups.pastry || []).map(productListRow).join('') ||
      '<tr><td colspan="6" style="color:var(--muted)">No pastries</td></tr>';
    bindStockSaveButtons(pastryTbody);
  }
  if (drinkTbody) {
    drinkTbody.innerHTML =
      (groups.drink || []).map(productListRow).join('') ||
      '<tr><td colspan="6" style="color:var(--muted)">No drinks</td></tr>';
    bindStockSaveButtons(drinkTbody);
  }
}

async function loadTransactions() {
  const date = document.getElementById('tx-date')?.value || new Date().toISOString().slice(0, 10);
  const data = await apiGet('transactions.php', { date });
  document.getElementById('tx-summary').textContent =
    `${data.count} sales · Total ₦${Number(data.total_sales || 0).toLocaleString()}`;
  document.getElementById('transactions-tbody').innerHTML = (data.transactions || [])
    .map(
      (t) => `
    <tr>
      <td>${t.id}</td>
      <td>${new Date(t.created_at).toLocaleTimeString()}</td>
      <td>${escape(t.full_name)}</td>
      <td>${escape(t.class_name)}</td>
      <td>${escape(t.terminal_name || '—')}</td>
      <td>₦${Number(t.total_amount).toLocaleString()}</td>
    </tr>`
    )
    .join('');
}

function escape(s) {
  const d = document.createElement('div');
  d.textContent = s ?? '';
  return d.innerHTML;
}

document.getElementById('student-search')?.addEventListener('input', debounce(loadStudents, 300));
document.getElementById('tx-date')?.addEventListener('change', loadTransactions);

document.getElementById('form-add-student')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const msg = document.getElementById('student-form-msg');
  try {
    await apiPost(
      'students.php',
      {
        student_number: document.getElementById('new-number').value,
        full_name: document.getElementById('new-name').value,
        class_name: document.getElementById('new-class').value,
        nfc_uid: document.getElementById('new-nfc').value || null,
        balance: parseFloat(document.getElementById('new-balance').value) || 0,
      },
      true
    );
    msg.textContent = 'Student added';
    msg.className = 'alert alert-success';
    e.target.reset();
    msg.classList.remove('hidden');
    await LiveSync.refreshNow('students', loadStudents);
  } catch (err) {
    msg.textContent = err.message;
    msg.className = 'alert alert-error';
    msg.classList.remove('hidden');
  }
});

document.getElementById('form-add-product')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  try {
    await apiPost(
      'products.php',
      {
        name: document.getElementById('prod-name').value,
        price: parseFloat(document.getElementById('prod-price').value),
        category: document.getElementById('prod-category').value,
        icon: document.getElementById('prod-icon').value || defaultIconForCategory(document.getElementById('prod-category').value),
        stock_quantity: parseInt(document.getElementById('prod-stock').value, 10) || 0,
      },
      true
    );
    e.target.reset();
    await LiveSync.refreshNow('catalog', refreshCatalogViews);
  } catch (err) {
    alert(err.message);
  }
});

document.getElementById('form-settings')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  try {
    await apiPut('settings.php', {
      school_name: document.getElementById('set-school').value,
      max_daily_spend: document.getElementById('set-max-daily').value,
      max_drinks_per_day: document.getElementById('set-max-drinks').value,
      max_pastries_per_day: document.getElementById('set-max-pastries').value,
    });
    alert('Settings saved');
    await LiveSync.refreshNow('settings', loadSettings);
  } catch (err) {
    alert(err.message);
  }
});

document.getElementById('btn-export')?.addEventListener('click', () => {
  const date = document.getElementById('tx-date').value;
  const token = sessionStorage.getItem('admin_token');
  window.open(`../api/export.php?date=${date}&admin_token=${encodeURIComponent(token)}`, '_blank');
});

function openTopup(studentId) {
  const s = students.find((x) => x.id == studentId);
  const amount = prompt(`Top-up amount for ${s?.full_name} (₦):`);
  if (!amount || isNaN(amount)) return;
  apiPost(
    'topups.php',
    {
      student_id: studentId,
      amount: parseFloat(amount),
      method: 'cash',
      recorded_by: 'admin',
    },
    true
  )
    .then(async () => {
      alert('Top-up recorded');
      await LiveSync.refreshNow('students', loadStudents);
    })
    .catch((e) => alert(e.message));
}

function openBind(studentId) {
  const uid = prompt('Scan or enter NFC card UID:');
  if (!uid) return;
  apiPost('nfc.php', { student_id: studentId, nfc_uid: uid.trim() }, true)
    .then(async () => {
      alert('Card linked');
      await LiveSync.refreshNow('students', loadStudents);
    })
    .catch((e) => alert(e.message));
}

async function loadSellers() {
  const data = await apiGet('sellers.php', {}, true);
  const tbody = document.getElementById('sellers-tbody');
  if (!tbody) return;
  tbody.innerHTML = (data.sellers || [])
    .map(
      (s) => `
    <tr>
      <td><code>${escape(s.username)}</code></td>
      <td>${escape(s.full_name)}</td>
      <td>${s.is_active ? 'Active' : 'Disabled'}</td>
      <td>
        ${
          s.is_active
            ? `<button type="button" class="btn btn-danger" style="padding:0.35rem 0.6rem;font-size:0.8rem" data-disable-seller="${s.id}">Disable</button>`
            : '<span style="color:var(--muted)">—</span>'
        }
      </td>
    </tr>`
    )
    .join('');

  tbody.querySelectorAll('[data-disable-seller]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      if (!confirm('Disable this seller? They will not be able to log in.')) return;
      try {
        await apiPut('sellers.php', { id: parseInt(btn.dataset.disableSeller, 10), is_active: 0 });
        await LiveSync.refreshNow('sellers', loadSellers);
      } catch (e) {
        alert(e.message);
      }
    });
  });
}

document.getElementById('form-add-seller')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const msg = document.getElementById('seller-form-msg');
  try {
    await apiPost(
      'sellers.php',
      {
        username: document.getElementById('seller-username').value,
        full_name: document.getElementById('seller-name').value,
        password: document.getElementById('seller-password').value,
      },
      true
    );
    msg.textContent = 'Seller created — they can log in at Seller portal';
    msg.className = 'alert alert-success';
    msg.classList.remove('hidden');
    e.target.reset();
    await LiveSync.refreshNow('sellers', loadSellers);
  } catch (err) {
    msg.textContent = err.message;
    msg.className = 'alert alert-error';
    msg.classList.remove('hidden');
  }
});

function debounce(fn, ms) {
  let t;
  return (...args) => {
    clearTimeout(t);
    t = setTimeout(() => fn(...args), ms);
  };
}

(async () => {
  if (await checkAuth()) {
    loginScreen.classList.add('hidden');
    adminApp.classList.remove('hidden');
    document.getElementById('tx-date').value = new Date().toISOString().slice(0, 10);
    loadAll().then(startLiveSync);
  }
})();
