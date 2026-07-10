const loginScreen = document.getElementById('login-screen');
const sellerApp = document.getElementById('seller-app');
const loginForm = document.getElementById('login-form');
const loginError = document.getElementById('login-error');

let queueRefreshTimer = null;

function showTab(name) {
  document.querySelectorAll('.tab').forEach((t) => t.classList.toggle('active', t.dataset.tab === name));
  document.querySelectorAll('.tab-panel').forEach((p) => p.classList.toggle('active', p.id === `tab-${name}`));
}

function stockClass(qty) {
  if (qty <= 0) return 'stock-out';
  if (qty <= 5) return 'stock-low';
  return 'stock-ok';
}

function stockLabel(qty) {
  if (qty <= 0) return 'Out of stock';
  if (qty <= 5) return 'Low stock';
  return 'In stock';
}

function escapeHtml(s) {
  const d = document.createElement('div');
  d.textContent = s ?? '';
  return d.innerHTML;
}

function inventoryRow(p) {
  const qty = parseInt(p.stock_quantity, 10);
  return `
    <tr>
      <td>${defaultIconForCategory(p.category)}</td>
      <td>${escapeHtml(p.name)}</td>
      <td>${formatMoney(p.price)}</td>
      <td class="${stockClass(qty)}">${qty}</td>
      <td class="${stockClass(qty)}">${stockLabel(qty)}</td>
      <td>
        ${p.is_active ? `<button type="button" class="btn btn-danger seller-remove-product" data-id="${p.id}" style="padding:0.35rem 0.6rem;font-size:0.8rem">Remove</button>` : '<span style="color:var(--muted)">Removed</span>'}
      </td>
    </tr>`;
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
  if (queueRefreshTimer) clearInterval(queueRefreshTimer);
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
    <div class="stat-card"><div class="value">${s.low_stock_count ?? 0}</div><div class="label">Low or out of stock</div></div>
  `;

  document.getElementById('inventory-pastry-tbody').innerHTML =
    (groups.pastry || []).map(inventoryRow).join('') ||
    '<tr><td colspan="6" style="color:var(--muted)">No pastries yet</td></tr>';

  document.getElementById('inventory-drink-tbody').innerHTML =
    (groups.drink || []).map(inventoryRow).join('') ||
    '<tr><td colspan="6" style="color:var(--muted)">No drinks yet</td></tr>';

  document.querySelectorAll('.seller-remove-product').forEach((btn) => {
    btn.addEventListener('click', async () => {
      if (!confirm('Remove this product from active use?')) return;
      try {
        await apiDelete('products.php', { id: parseInt(btn.dataset.id, 10) }, false, true);
        await loadInventory();
      } catch (err) {
        alert(err.message);
      }
    });
  });
}

async function loadTransactions() {
  const date = document.getElementById('tx-date')?.value || new Date().toISOString().slice(0, 10);
  const data = await apiGet('transactions.php', { date }, false, true);
  document.getElementById('tx-summary').textContent =
    `${data.count} sales · Total ${formatMoney(data.total_sales || 0)}`;
  document.getElementById('transactions-tbody').innerHTML = (data.transactions || [])
    .map(
      (t) => `
    <tr>
      <td>${t.id}</td>
      <td>${new Date(t.created_at).toLocaleTimeString()}</td>
      <td>${escapeHtml(t.full_name)}</td>
      <td>${escapeHtml(t.class_name)}</td>
      <td>${escapeHtml(t.terminal_name || '-')}</td>
      <td>${formatMoney(t.total_amount)}</td>
    </tr>`
    )
    .join('');
}

function renderQueueStats(data) {
  const stats = document.getElementById('queue-stats');
  if (!stats) return;
  stats.innerHTML = `
    <div class="stat-card"><div class="value">${data.queue?.length || 0}</div><div class="label">Orders in view</div></div>
    <div class="stat-card"><div class="value">${data.pending_count || 0}</div><div class="label">Pending</div></div>
    <div class="stat-card"><div class="value">${data.prepared_count || 0}</div><div class="label">Prepared</div></div>
  `;
}

function syncMessage(sync) {
  if (!sync) return 'Queue loaded.';
  if (sync.orders || sync.inventory) {
    const parts = [];
    if (sync.orders?.ok) {
      parts.push(`orders: fetched ${sync.orders.fetched || 0}, imported ${sync.orders.imported || 0}`);
    }
    if (sync.inventory?.ok) {
      parts.push(`inventory: pushed ${sync.inventory.pushed || 0}, updated ${sync.inventory.updated_remote || 0}`);
    }
    return parts.length ? `Daily sync complete: ${parts.join(' | ')}.` : 'Daily sync complete.';
  }
  if (sync.ok) {
    return `Sync complete. Fetched ${sync.fetched || 0}, imported ${sync.imported || 0}, skipped ${sync.skipped || 0}.`;
  }
  return sync.message || 'Sync skipped.';
}

function renderQueueRows(rows) {
  const tbody = document.getElementById('queue-tbody');
  if (!tbody) return;
  tbody.innerHTML =
    rows
      .map((order) => {
        const items = (order.items || []).map((item) => `${item.quantity}x ${item.product_name}`).join(', ');
        const actionButtons = [];
        if (order.fulfillment_status === 'pending') {
          actionButtons.push(`<button type="button" class="btn btn-secondary queue-action-btn" data-id="${order.id}" data-status="prepared">Mark prepared</button>`);
        }
        if (order.fulfillment_status === 'prepared' || order.fulfillment_status === 'pending') {
          actionButtons.push(`<button type="button" class="btn btn-success queue-serve-btn" data-id="${order.id}">Served</button>`);
        }
        if (order.fulfillment_status !== 'completed' && order.fulfillment_status !== 'cancelled') {
          actionButtons.push(`<button type="button" class="btn btn-danger queue-action-btn" data-id="${order.id}" data-status="cancelled">Cancel</button>`);
        }
        return `
          <tr>
            <td>${new Date(order.created_at).toLocaleTimeString()}</td>
            <td><strong>${escapeHtml(order.student_name)}</strong><br><span style="color:var(--muted)">${escapeHtml(order.class_name)}</span></td>
            <td>${escapeHtml(items || '-')}</td>
            <td>${escapeHtml(order.notes || '-')}</td>
            <td>${escapeHtml(order.fulfillment_status)}</td>
            <td><div class="queue-action-group">${actionButtons.join(' ') || '<span style="color:var(--muted)">No action</span>'}</div></td>
          </tr>`;
      })
      .join('') || '<tr><td colspan="6" style="color:var(--muted)">No queued orders for this date.</td></tr>';

  tbody.querySelectorAll('.queue-action-btn').forEach((btn) => {
    btn.addEventListener('click', async () => {
      try {
        await apiPost(
          'order-queue.php',
          {
            action: 'update_status',
            id: parseInt(btn.dataset.id, 10),
            status: btn.dataset.status,
          },
          false,
          true
        );
        await loadQueue(false);
      } catch (err) {
        alert(err.message);
      }
    });
  });

  tbody.querySelectorAll('.queue-serve-btn').forEach((btn) => {
    btn.addEventListener('click', async () => {
      if (!confirm('Mark this queue as served and archive it to CSV?')) return;
      try {
        await apiPost(
          'order-queue.php',
          {
            action: 'serve',
            id: parseInt(btn.dataset.id, 10),
          },
          false,
          true
        );
        await loadQueue(false);
      } catch (err) {
        alert(err.message);
      }
    });
  });
}

async function loadQueue(withSync = false) {
  const date = document.getElementById('queue-date')?.value || new Date().toISOString().slice(0, 10);
  const status = document.getElementById('queue-status')?.value || 'active';
  const data = await apiGet('order-queue.php', { date, status, sync: withSync ? '1' : '0' }, false, true);
  renderQueueStats(data);
  renderQueueRows(data.queue || []);
  const msg = document.getElementById('queue-sync-msg');
  if (msg) msg.textContent = syncMessage(data.sync);
}

document.getElementById('btn-refresh-queue')?.addEventListener('click', () => loadQueue(false));
document.getElementById('btn-sync-queue')?.addEventListener('click', async () => {
  try {
    await loadQueue(true);
  } catch (err) {
    const msg = document.getElementById('queue-sync-msg');
    if (msg) msg.textContent = err.message;
  }
});
document.getElementById('queue-date')?.addEventListener('change', () => loadQueue(false));
document.getElementById('queue-status')?.addEventListener('change', () => loadQueue(false));

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
    loadInventory();
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
  const today = new Date().toISOString().slice(0, 10);
  document.getElementById('tx-date').value = today;
  document.getElementById('queue-date').value = today;
  loadQueue(true);
  loadInventory();
  loadTransactions();
  if (queueRefreshTimer) clearInterval(queueRefreshTimer);
  queueRefreshTimer = setInterval(() => loadQueue(true).catch(() => {}), 60000);
}

document.getElementById('tx-date')?.addEventListener('change', loadTransactions);

(async () => {
  if (await checkAuth()) showApp();
})();
