const loginScreen = document.getElementById('login-screen');
const studentApp = document.getElementById('student-app');
const loginForm = document.getElementById('login-form');
const loginError = document.getElementById('login-error');

let currentStudent = null;
let products = [];
let orderCart = [];

function studentHeaders() {
  const token = sessionStorage.getItem('student_token');
  return token ? { 'X-Student-Token': token } : {};
}

async function studentGet(endpoint, params = {}) {
  const qs = new URLSearchParams(params).toString();
  const url = `${API_BASE}/${endpoint}${qs ? '?' + qs : ''}`;
  const res = await fetch(url, { headers: studentHeaders() });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || 'Request failed');
  return data;
}

async function studentPost(endpoint, body) {
  const res = await fetch(`${API_BASE}/${endpoint}`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...studentHeaders(),
    },
    body: JSON.stringify(body),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || 'Request failed');
  return data;
}

function escapeHtml(s) {
  const d = document.createElement('div');
  d.textContent = s ?? '';
  return d.innerHTML;
}

function tomorrowIso() {
  const d = new Date();
  d.setDate(d.getDate() + 1);
  return d.toISOString().slice(0, 10);
}

function setStats() {
  const stats = document.getElementById('student-stats');
  const prepayNote = document.getElementById('wallet-prepay-note');
  if (!stats || !currentStudent) return;
  stats.innerHTML = `
    <div class="stat-card"><div class="value">${escapeHtml(currentStudent.student_number)}</div><div class="label">Student number</div></div>
    <div class="stat-card"><div class="value">${escapeHtml(currentStudent.class_name)}</div><div class="label">Class</div></div>
    <div class="stat-card"><div class="value">${formatMoney(currentStudent.balance)}</div><div class="label">Current wallet balance</div></div>
  `;
  if (prepayNote) {
    prepayNote.textContent = `Current wallet balance: ${formatMoney(currentStudent.balance)}. Your wallet is charged immediately when you queue an order.`;
  }
}

async function checkAuth() {
  if (!sessionStorage.getItem('student_token')) return false;
  try {
    const data = await studentGet('student-auth.php');
    currentStudent = data.student;
    return true;
  } catch {
    return false;
  }
}

loginForm?.addEventListener('submit', async (e) => {
  e.preventDefault();
  loginError.classList.add('hidden');
  try {
    const data = await studentPost('student-auth.php', {
      student_number: document.getElementById('student-number').value.trim(),
    });
    sessionStorage.setItem('student_token', data.token);
    currentStudent = data.student;
    await showApp();
  } catch (err) {
    loginError.textContent = err.message;
    loginError.classList.remove('hidden');
  }
});

document.getElementById('btn-logout')?.addEventListener('click', () => {
  sessionStorage.removeItem('student_token');
  currentStudent = null;
  orderCart = [];
  studentApp.classList.add('hidden');
  loginScreen.classList.remove('hidden');
});

function productMenuCard(product) {
  const item = orderCart.find((entry) => entry.product_id === product.id);
  const qty = item ? item.quantity : 0;
  return `
    <div class="order-menu-item">
      <div>
        <strong>${escapeHtml(product.name)}</strong>
        <div class="menu-meta">${formatMoney(product.price)}</div>
      </div>
      <div class="qty-picker">
        <button type="button" data-action="dec" data-id="${product.id}">-</button>
        <span>${qty}</span>
        <button type="button" data-action="inc" data-id="${product.id}">+</button>
      </div>
    </div>`;
}

function bindMenuButtons(container) {
  container.querySelectorAll('button[data-id]').forEach((btn) => {
    btn.addEventListener('click', () => updateCart(parseInt(btn.dataset.id, 10), btn.dataset.action));
  });
}

function renderMenuGroup(productsInGroup, containerId, emptyId) {
  const container = document.getElementById(containerId);
  const empty = document.getElementById(emptyId);
  if (!container) return;

  if (!productsInGroup.length) {
    container.innerHTML = '';
    empty?.classList.remove('hidden');
    return;
  }

  empty?.classList.add('hidden');
  container.innerHTML = productsInGroup.map(productMenuCard).join('');
  bindMenuButtons(container);
}

function renderMenu() {
  const snacks = products.filter((product) => String(product.category).toLowerCase() === 'pastry');
  const juices = products.filter((product) => String(product.category).toLowerCase() === 'drink');
  renderMenuGroup(snacks, 'snacks-menu', 'snacks-empty');
  renderMenuGroup(juices, 'juices-menu', 'juices-empty');
}

function updateCart(productId, action) {
  const existing = orderCart.find((item) => item.product_id === productId);
  if (action === 'inc') {
    if (existing) existing.quantity += 1;
    else orderCart.push({ product_id: productId, quantity: 1 });
  } else if (existing) {
    existing.quantity -= 1;
    if (existing.quantity <= 0) {
      orderCart = orderCart.filter((item) => item.product_id !== productId);
    }
  }

  renderMenu();
  renderTotal();
}

function renderTotal() {
  const total = orderCart.reduce((sum, item) => {
    const product = products.find((entry) => entry.id === item.product_id);
    return sum + (product ? Number(product.price) * item.quantity : 0);
  }, 0);
  document.getElementById('order-total').textContent = `Total: ${formatMoney(total)}`;
}

async function loadProducts() {
  const data = await apiGet('products.php', { menu: '1' });
  products = data.products || [];
  renderMenu();
  renderTotal();
}

function renderOrders(orders) {
  const tbody = document.getElementById('scheduled-orders-tbody');
  if (!tbody) return;
  tbody.innerHTML =
    orders
      .map((order) => {
        const items = (order.items || []).map((item) => `${item.quantity}x ${item.product_name}`).join(', ');
        return `
          <tr>
            <td>${escapeHtml(order.scheduled_date)}</td>
            <td>${escapeHtml(items || '-')}</td>
            <td>${formatMoney(order.total_amount)}</td>
            <td>${escapeHtml(order.fulfillment_status)}</td>
            <td>${escapeHtml(order.sync_status)}</td>
          </tr>`;
      })
      .join('') || '<tr><td colspan="5" style="color:var(--muted)">No scheduled orders yet.</td></tr>';
}

async function loadOrders() {
  const data = await studentGet('student-orders.php');
  currentStudent = data.student || currentStudent;
  setStats();
  renderOrders(data.orders || []);
}

document.getElementById('btn-open-date-picker')?.addEventListener('click', () => {
  const input = document.getElementById('order-date');
  if (!input) return;
  if (typeof input.showPicker === 'function') {
    input.showPicker();
  } else {
    input.focus();
  }
});

document.getElementById('form-student-order')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const msg = document.getElementById('order-msg');
  msg.classList.add('hidden');
  if (!orderCart.length) {
    msg.textContent = 'Select at least one snack or juice.';
    msg.className = 'alert alert-error';
    msg.classList.remove('hidden');
    return;
  }

  try {
    const data = await studentPost('student-orders.php', {
      scheduled_date: document.getElementById('order-date').value,
      notes: document.getElementById('order-notes').value.trim(),
      items: orderCart,
    });
    currentStudent.balance = data.order.balance_after;
    msg.textContent = `Payment successful. Order queued and wallet balance is now ${formatMoney(data.order.balance_after)}.`;
    msg.className = 'alert alert-success';
    msg.classList.remove('hidden');
    orderCart = [];
    e.target.reset();
    document.getElementById('order-date').value = tomorrowIso();
    setStats();
    renderMenu();
    renderTotal();
    await Promise.all([loadProducts(), loadOrders()]);
  } catch (err) {
    msg.textContent = err.message;
    msg.className = 'alert alert-error';
    msg.classList.remove('hidden');
  }
});

async function showApp() {
  loginScreen.classList.add('hidden');
  studentApp.classList.remove('hidden');
  document.getElementById('student-greeting').textContent = currentStudent ? currentStudent.full_name : 'Student';
  setStats();
  document.getElementById('order-date').min = tomorrowIso();
  document.getElementById('order-date').value = tomorrowIso();
  await Promise.all([loadProducts(), loadOrders()]);
}

(async () => {
  if (await checkAuth()) {
    await showApp();
  }
})();
