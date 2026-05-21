let settings = {};
let products = [];
let student = null;
let cart = [];
let terminalId = 1;
let currency = '₦';

const els = {
  nfcScreen: document.getElementById('nfc-screen'),
  posScreen: document.getElementById('pos-screen'),
  nfcInput: document.getElementById('nfc-input'),
  studentName: document.getElementById('student-name'),
  studentMeta: document.getElementById('student-meta'),
  balance: document.getElementById('balance'),
  productSections: document.getElementById('product-sections'),
  cartItems: document.getElementById('cart-items'),
  cartTotal: document.getElementById('cart-total'),
  btnConfirm: document.getElementById('btn-confirm'),
  btnCancel: document.getElementById('btn-cancel'),
  btnClearCard: document.getElementById('btn-clear-card'),
  terminalSelect: document.getElementById('terminal-select'),
  receiptModal: document.getElementById('receipt-modal'),
  receiptBody: document.getElementById('receipt-body'),
  alertBox: document.getElementById('alert-box'),
};

async function reloadSettings() {
  const data = await apiGet('settings.php');
  settings = data.settings || {};
  currency = settings.currency_symbol || '₦';
}

async function reloadProducts() {
  const prodData = await apiGet('products.php', { menu: '1' });
  products = prodData.products || [];
  renderProducts();
}

async function init() {
  try {
    const data = await apiGet('settings.php');
    settings = data.settings || {};
    currency = settings.currency_symbol || '₦';
    terminalId = parseInt(localStorage.getItem('terminal_id') || '1', 10);

    if (els.terminalSelect && data.terminals) {
      els.terminalSelect.innerHTML = data.terminals
        .map((t) => `<option value="${t.id}" ${t.id == terminalId ? 'selected' : ''}>${t.name}</option>`)
        .join('');
      els.terminalSelect.addEventListener('change', (e) => {
        terminalId = parseInt(e.target.value, 10);
        localStorage.setItem('terminal_id', String(terminalId));
      });
    }

    await reloadProducts();
    LiveSync.register('catalog', reloadProducts);
    LiveSync.register('settings', reloadSettings);
    LiveSync.start();
  } catch (e) {
    showAlert(e.message, 'error');
  }
}

function productButtonHtml(p) {
  const stock = parseInt(p.stock_quantity, 10) || 0;
  const out = stock <= 0;
  const icon = p.icon || defaultIconForCategory(p.category);
  return `
    <button type="button" class="product-btn${out ? ' out-of-stock' : ''}" data-id="${p.id}" ${!student || out ? 'disabled' : ''}>
      <span class="icon">${icon}</span>
      <span class="name">${escapeHtml(p.name)}</span>
      <span class="price">${formatMoney(p.price, currency)}</span>
      <span class="stock-badge">${out ? 'Out of stock' : stock + ' left'}</span>
    </button>`;
}

function renderProducts() {
  const groups = groupProductsByCategory(products);
  els.productSections.innerHTML = MENU_GROUPS.map((g) => {
    const items = groups[g.key];
    if (!items.length) {
      return `
      <section class="menu-group">
        <h3 class="menu-group-title">${g.icon} ${g.label}</h3>
        <p class="menu-group-empty">No items yet</p>
      </section>`;
    }
    return `
      <section class="menu-group">
        <h3 class="menu-group-title">${g.icon} ${g.label}</h3>
        <div class="product-grid">${items.map(productButtonHtml).join('')}</div>
      </section>`;
  }).join('');

  els.productSections.querySelectorAll('.product-btn').forEach((btn) => {
    btn.addEventListener('click', () => addToCart(parseInt(btn.dataset.id, 10)));
  });
}

function escapeHtml(s) {
  const d = document.createElement('div');
  d.textContent = s;
  return d.innerHTML;
}

async function lookupCard(uid) {
  if (!uid.trim()) return;
  hideAlert();
  try {
    const data = await apiGet('nfc.php', { uid: uid.trim() });
    if (data.status === 'linked') {
      setStudent(data.student);
      els.nfcInput.value = '';
    } else {
      showAlert(`New card (${uid}). Bind it in Admin → Students.`, 'error');
    }
  } catch (e) {
    showAlert(e.message, 'error');
  }
}

function setStudent(s) {
  student = s;
  els.nfcScreen.classList.add('hidden');
  els.posScreen.classList.remove('hidden');
  document.querySelector('.pos-student-bar')?.classList.remove('empty');

  els.studentName.textContent = s.full_name;
  els.studentMeta.textContent = `${s.class_name} · ${s.student_number}`;
  els.balance.textContent = formatMoney(s.balance, currency);

  if (s.daily_remaining != null) {
    els.studentMeta.textContent += ` · Daily left: ${formatMoney(s.daily_remaining, currency)}`;
  }

  cart = [];
  renderCart();
  renderProducts();
}

function clearStudent() {
  student = null;
  cart = [];
  els.nfcScreen.classList.remove('hidden');
  els.posScreen.classList.add('hidden');
  document.querySelector('.pos-student-bar')?.classList.add('empty');
  els.studentName.textContent = 'Tap NFC card';
  els.studentMeta.textContent = 'Waiting for card…';
  els.balance.textContent = '—';
  els.nfcInput.focus();
  renderCart();
  renderProducts();
}

function addToCart(productId) {
  if (!student) return;
  const p = products.find((x) => x.id == productId);
  const stock = parseInt(p?.stock_quantity, 10) || 0;
  if (!p || stock <= 0) return;
  const existing = cart.find((c) => c.product_id === productId);
  const inCart = existing ? existing.quantity : 0;
  if (inCart + 1 > stock) {
    showAlert(`Only ${stock} ${p.name} in stock`, 'error');
    return;
  }
  if (existing) {
    existing.quantity += 1;
  } else {
    cart.push({ product_id: productId, quantity: 1 });
  }
  renderCart();
}

function renderCart() {
  let total = 0;
  els.cartItems.innerHTML = cart
    .map((item) => {
      const p = products.find((x) => x.id == item.product_id);
      if (!p) return '';
      const line = p.price * item.quantity;
      total += line;
      return `
      <div class="cart-item">
        <span>${escapeHtml(p.name)} × ${item.quantity}</span>
        <span class="qty-controls">
          <button type="button" data-action="dec" data-id="${p.id}">−</button>
          <button type="button" data-action="inc" data-id="${p.id}">+</button>
          <strong>${formatMoney(line, currency)}</strong>
        </span>
      </div>`;
    })
    .join('');

  els.cartItems.querySelectorAll('button').forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = parseInt(btn.dataset.id, 10);
      const item = cart.find((c) => c.product_id === id);
      if (btn.dataset.action === 'inc') item.quantity += 1;
      else item.quantity -= 1;
      if (item.quantity <= 0) cart = cart.filter((c) => c.product_id !== id);
      renderCart();
    });
  });

  els.cartTotal.textContent = formatMoney(total, currency);
  els.btnConfirm.disabled = !student || cart.length === 0;
}

async function confirmPurchase() {
  if (!student || cart.length === 0) return;
  hideAlert();
  els.btnConfirm.disabled = true;
  try {
    const data = await apiPost('purchase.php', {
      student_id: student.id,
      items: cart,
      terminal_id: terminalId,
    });
    const tx = data.transaction;
    showReceipt(tx);
    student.balance = tx.balance_after;
    els.balance.textContent = formatMoney(student.balance, currency);
    cart = [];
    await LiveSync.refreshNow(['catalog', 'transactions'], reloadProducts);
    renderCart();
  } catch (e) {
    showAlert(e.message, 'error');
  } finally {
    els.btnConfirm.disabled = cart.length === 0;
  }
}

function showReceipt(tx) {
  const lines = tx.items
    .map((i) => `${i.product_name} ×${i.quantity} … ${formatMoney(i.line_total, currency)}`)
    .join('<br>');
  els.receiptBody.innerHTML = `
    <strong>${settings.school_name || 'Tuckshop'}</strong><br>
    ${new Date(tx.created_at).toLocaleString()}<br>
    <hr style="border:none;border-top:1px dashed #999;margin:8px 0">
    ${tx.student_name}<br>
    ${lines}<br>
    <hr style="border:none;border-top:1px dashed #999;margin:8px 0">
    <strong>TOTAL: ${formatMoney(tx.total, currency)}</strong><br>
    Balance: ${formatMoney(tx.balance_after, currency)}
  `;
  els.receiptModal.classList.remove('hidden');
}

function showAlert(msg, type = 'error') {
  els.alertBox.textContent = msg;
  els.alertBox.className = `alert alert-${type === 'error' ? 'error' : 'success'}`;
  els.alertBox.classList.remove('hidden');
}

function hideAlert() {
  els.alertBox.classList.add('hidden');
}

els.nfcInput?.addEventListener('keydown', (e) => {
  if (e.key === 'Enter') {
    e.preventDefault();
    lookupCard(els.nfcInput.value);
  }
});

els.nfcInput?.addEventListener('input', () => {
  const v = els.nfcInput.value.trim();
  if (v.length >= 8) lookupCard(v);
});

els.btnConfirm?.addEventListener('click', confirmPurchase);
els.btnCancel?.addEventListener('click', () => {
  cart = [];
  renderCart();
});
els.btnClearCard?.addEventListener('click', clearStudent);

document.getElementById('btn-close-receipt')?.addEventListener('click', () => {
  els.receiptModal.classList.add('hidden');
});

init();
els.nfcInput?.focus();
