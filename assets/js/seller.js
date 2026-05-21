const loginScreen = document.getElementById('login-screen');
const sellerApp = document.getElementById('seller-app');
const loginForm = document.getElementById('login-form');
const loginError = document.getElementById('login-error');

function showTab(name) {
  document.querySelectorAll('.tab').forEach((t) => t.classList.toggle('active', t.dataset.tab === name));
  document.querySelectorAll('.tab-panel').forEach((p) => p.classList.toggle('active', p.id === `tab-${name}`));
}

function stockClass(qty) {
  if (qty <= 0) return 'stock-out';
  if (qty <= 5) return 'stock-low';
  return 'stock-ok';
}

function escape(s) {
  const d = document.createElement('div');
  d.textContent = s ?? '';
  return d.innerHTML;
}

function inventoryRow(p) {
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
        await apiPut('inventory.php', { product_id: id, stock_quantity: parseInt(input.value, 10) || 0 }, false, true);
        await LiveSync.refreshNow('catalog', loadInventory);
      } catch (e) {
        alert(e.message);
      }
    });
  });
}

async function checkAuth() {
  if (!sessionStorage.getItem('seller_token')) return false;
  try {
    await apiGet('seller-auth.php', {}, false, true);
    return true;
  } catch {
    return false;
  }
}

loginForm?.addEventListener('submit', async (e) => {
  e.preventDefault();
  loginError.classList.add('hidden');
  try {
    const data = await apiPost('seller-auth.php', {
      username: document.getElementById('seller-user').value,
      password: document.getElementById('seller-pass').value,
    });
    sessionStorage.setItem('seller_token', data.token);
    sessionStorage.setItem('seller_name', data.seller.full_name);
    showApp();
  } catch (err) {
    loginError.textContent = err.message;
    loginError.classList.remove('hidden');
  }
});

document.getElementById('btn-logout')?.addEventListener('click', () => {
  sessionStorage.removeItem('seller_token');
  sessionStorage.removeItem('seller_name');
  sellerApp.classList.add('hidden');
  loginScreen.classList.remove('hidden');
});

document.querySelectorAll('.tab').forEach((tab) => {
  tab.addEventListener('click', () => showTab(tab.dataset.tab));
});

async function loadInventory() {
  const data = await apiGet('inventory.php', {}, false, true);
  const s = data.summary || {};
  const groups = data.groups || groupProductsByCategory(data.inventory || []);

  document.getElementById('inventory-stats').innerHTML = `
    <div class="stat-card"><div class="value">${s.pastry_units ?? 0}</div><div class="label">Pastry items in stock</div></div>
    <div class="stat-card"><div class="value">${s.drink_units ?? 0}</div><div class="label">Drink items in stock</div></div>
    <div class="stat-card"><div class="value">${s.low_stock_count ?? 0}</div><div class="label">Low / out of stock</div></div>
  `;

  const pastryTbody = document.getElementById('inventory-pastry-tbody');
  const drinkTbody = document.getElementById('inventory-drink-tbody');
  if (pastryTbody) {
    pastryTbody.innerHTML =
      (groups.pastry || []).map(inventoryRow).join('') ||
      '<tr><td colspan="5" style="color:var(--muted)">No pastries yet</td></tr>';
    bindStockSaveButtons(pastryTbody);
  }
  if (drinkTbody) {
    drinkTbody.innerHTML =
      (groups.drink || []).map(inventoryRow).join('') ||
      '<tr><td colspan="5" style="color:var(--muted)">No drinks yet</td></tr>';
    bindStockSaveButtons(drinkTbody);
  }
}

async function loadTransactions() {
  const date = document.getElementById('tx-date')?.value || new Date().toISOString().slice(0, 10);
  const data = await apiGet('transactions.php', { date }, false, true);
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

document.getElementById('form-add-item')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const msg = document.getElementById('item-form-msg');
  const category = document.getElementById('item-category').value;
  try {
    await apiPost(
      'products.php',
      {
        name: document.getElementById('item-name').value,
        price: parseFloat(document.getElementById('item-price').value),
        category,
        icon: defaultIconForCategory(category),
        stock_quantity: parseInt(document.getElementById('item-stock').value, 10) || 0,
      },
      false,
      true
    );
    msg.textContent = `Added to ${category === 'drink' ? 'Drinks' : 'Pastries'}`;
    msg.className = 'alert alert-success';
    msg.classList.remove('hidden');
    e.target.reset();
    document.getElementById('item-category').value = category;
    await LiveSync.refreshNow('catalog', loadInventory);
  } catch (err) {
    msg.textContent = err.message;
    msg.className = 'alert alert-error';
    msg.classList.remove('hidden');
  }
});

function showApp() {
  loginScreen.classList.add('hidden');
  sellerApp.classList.remove('hidden');
  document.getElementById('seller-display-name').textContent =
    sessionStorage.getItem('seller_name') || 'Seller';
  document.getElementById('tx-date').value = new Date().toISOString().slice(0, 10);
  loadInventory();
  loadTransactions();
  startLiveSync();
}

function startLiveSync() {
  LiveSync.register('catalog', loadInventory);
  LiveSync.register('transactions', loadTransactions);
  LiveSync.start();
}

document.getElementById('tx-date')?.addEventListener('change', loadTransactions);

(async () => {
  if (await checkAuth()) showApp();
})();
