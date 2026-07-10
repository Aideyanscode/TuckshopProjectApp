const loginScreen = document.getElementById('login-screen');
const parentApp = document.getElementById('parent-app');
const loginForm = document.getElementById('login-form');
const loginError = document.getElementById('login-error');

let linkedStudents = [];
let paystackReady = false;
let paystackPublicKey = '';

function showTab(name) {
  document.querySelectorAll('.tab').forEach((t) => t.classList.toggle('active', t.dataset.tab === name));
  document.querySelectorAll('.tab-panel').forEach((p) => p.classList.toggle('active', p.id === `tab-${name}`));
}

function escape(s) {
  const d = document.createElement('div');
  d.textContent = s ?? '';
  return d.innerHTML;
}

async function checkAuth() {
  if (!sessionStorage.getItem('parent_token')) return false;
  try {
    await apiGet('parent-auth.php', {}, false, false, true);
    return true;
  } catch {
    return false;
  }
}

loginForm?.addEventListener('submit', async (e) => {
  e.preventDefault();
  loginError.classList.add('hidden');
  try {
    const data = await apiPost('parent-auth.php', {
      email: document.getElementById('parent-email').value,
      password: document.getElementById('parent-password').value,
    });
    sessionStorage.setItem('parent_token', data.token);
    sessionStorage.setItem('parent_name', data.parent.full_name);
    showApp();
  } catch (err) {
    loginError.textContent = err.message;
    loginError.classList.remove('hidden');
  }
});

document.getElementById('btn-logout')?.addEventListener('click', () => {
  sessionStorage.removeItem('parent_token');
  sessionStorage.removeItem('parent_name');
  parentApp.classList.add('hidden');
  loginScreen.classList.remove('hidden');
});

document.querySelectorAll('.tab').forEach((tab) => {
  tab.addEventListener('click', () => showTab(tab.dataset.tab));
});

function fillStudentSelects() {
  const topupSelect = document.getElementById('topup-student');
  const txFilter = document.getElementById('tx-student-filter');
  const options =
    '<option value="">— Select child —</option>' +
    linkedStudents
      .map(
        (s) =>
          `<option value="${s.id}" data-nfc="${escape(s.nfc_uid || '')}">${escape(s.full_name)} (${escape(s.class_name)}) — ₦${Number(s.balance).toLocaleString()}</option>`
      )
      .join('');

  if (topupSelect) {
    const prev = topupSelect.value;
    topupSelect.innerHTML = options;
    if (prev) topupSelect.value = prev;
  }

  if (txFilter) {
    const prev = txFilter.value;
    txFilter.innerHTML =
      '<option value="">All linked children</option>' +
      linkedStudents.map((s) => `<option value="${s.id}">${escape(s.full_name)}</option>`).join('');
    if (prev) txFilter.value = prev;
  }
}

function updateSelectedStudentInfo() {
  const info = document.getElementById('selected-student-info');
  const select = document.getElementById('topup-student');
  const nfc = document.getElementById('topup-nfc')?.value.trim();
  if (!info) return;

  if (nfc) {
    const match = linkedStudents.find((s) => s.nfc_uid && s.nfc_uid.toLowerCase() === nfc.toLowerCase());
    if (match) {
      info.textContent = `NFC matched: ${match.full_name} — current balance ${formatMoney(match.balance)}`;
      return;
    }
    info.textContent = 'Enter a linked child\'s NFC ID or pick from the list.';
    return;
  }

  const id = parseInt(select?.value || '0', 10);
  const student = linkedStudents.find((s) => s.id == id);
  info.textContent = student
    ? `${student.full_name} — NFC: ${student.nfc_uid || 'not set'} — balance ${formatMoney(student.balance)}`
    : '';
}

async function loadPaystackConfig() {
  const data = await apiGet('paystack.php', {}, false, false, true);
  paystackReady = data.configured;
  paystackPublicKey = data.public_key || '';
  const warn = document.getElementById('paystack-warning');
  const btn = document.getElementById('btn-pay');
  if (!paystackReady) {
    warn.textContent =
      'Paystack is not configured yet. Add your public and secret keys in config/config.php or config.local.php.';
    warn.classList.remove('hidden');
    if (btn) btn.disabled = true;
  } else {
    warn.classList.add('hidden');
    if (btn) btn.disabled = false;
  }
}

async function loadStudents() {
  const data = await apiGet('parent-students.php', {}, false, false, true);
  linkedStudents = data.students || [];
  fillStudentSelects();
  renderChildren();
  updateSelectedStudentInfo();
}

function renderChildren() {
  const tbody = document.getElementById('children-tbody');
  const stats = document.getElementById('children-stats');
  if (!tbody) return;

  const totalBalance = linkedStudents.reduce((sum, s) => sum + Number(s.balance), 0);
  if (stats) {
    stats.innerHTML = `
      <div class="stat-card"><div class="value">${linkedStudents.length}</div><div class="label">Linked children</div></div>
      <div class="stat-card"><div class="value">${formatMoney(totalBalance)}</div><div class="label">Combined balance</div></div>
    `;
  }

  tbody.innerHTML =
    linkedStudents
      .map(
        (s) => `
      <tr>
        <td>${escape(s.full_name)}</td>
        <td>${escape(s.class_name)}</td>
        <td><code>${escape(s.student_number)}</code></td>
        <td><code>${s.nfc_uid || '—'}</code></td>
        <td>${formatMoney(s.balance)}</td>
      </tr>`
      )
      .join('') || '<tr><td colspan="5" style="color:var(--muted)">No children linked yet. Ask the school admin to link your account.</td></tr>';
}

async function loadTransactions() {
  const studentId = document.getElementById('tx-student-filter')?.value || '';
  const params = studentId ? { student_id: studentId } : {};
  const data = await apiGet('parent-transactions.php', params, false, false, true);

  const txBody = document.getElementById('transactions-tbody');
  if (txBody) {
    txBody.innerHTML = (data.transactions || [])
      .map((t) => {
        const items = (t.items || []).map((i) => `${i.quantity}× ${i.product_name}`).join(', ') || '—';
        return `
        <tr>
          <td>${new Date(t.created_at).toLocaleString()}</td>
          <td>${escape(t.full_name)}</td>
          <td>${escape(items)}</td>
          <td>${formatMoney(t.total_amount)}</td>
          <td>${formatMoney(t.balance_after)}</td>
        </tr>`;
      })
      .join('') || '<tr><td colspan="5" style="color:var(--muted)">No purchases yet</td></tr>';
  }

  const topBody = document.getElementById('topups-tbody');
  if (topBody) {
    topBody.innerHTML = (data.topups || [])
      .map(
        (t) => `
      <tr>
        <td>${new Date(t.created_at).toLocaleString()}</td>
        <td>${escape(t.full_name)}</td>
        <td>${formatMoney(t.amount)}</td>
        <td>${escape(t.method)}</td>
        <td>${escape(t.reference_note || '—')}</td>
      </tr>`
      )
      .join('') || '<tr><td colspan="5" style="color:var(--muted)">No top-ups yet</td></tr>';
  }
}

document.getElementById('topup-student')?.addEventListener('change', () => {
  const nfc = document.getElementById('topup-nfc');
  if (nfc) nfc.value = '';
  updateSelectedStudentInfo();
});

document.getElementById('topup-nfc')?.addEventListener('input', () => {
  const select = document.getElementById('topup-student');
  if (select) select.value = '';
  updateSelectedStudentInfo();
});

document.getElementById('tx-student-filter')?.addEventListener('change', loadTransactions);
document.getElementById('btn-refresh-tx')?.addEventListener('click', loadTransactions);

document.getElementById('form-topup')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const msg = document.getElementById('topup-msg');
  msg.classList.add('hidden');

  if (!paystackReady) {
    msg.textContent = 'Paystack is not configured.';
    msg.className = 'alert alert-error';
    msg.classList.remove('hidden');
    return;
  }

  const studentId = parseInt(document.getElementById('topup-student').value || '0', 10);
  const nfcUid = document.getElementById('topup-nfc').value.trim();
  const amount = parseFloat(document.getElementById('topup-amount').value);

  if (!studentId && !nfcUid) {
    msg.textContent = 'Select a child or enter their NFC card ID.';
    msg.className = 'alert alert-error';
    msg.classList.remove('hidden');
    return;
  }

  const btn = document.getElementById('btn-pay');
  btn.disabled = true;

  try {
    const init = await apiPost(
      'paystack.php',
      {
        action: 'initialize',
        student_id: studentId || undefined,
        nfc_uid: nfcUid || undefined,
        amount,
      },
      false,
      false,
      true
    );

    const handler = PaystackPop.setup({
      key: init.public_key || paystackPublicKey,
      email: init.email,
      amount: init.amount_kobo,
      ref: init.reference,
      currency: 'NGN',
      metadata: {
        student_id: init.student.id,
        student_name: init.student.full_name,
      },
      callback: async (response) => {
        try {
          const verified = await apiPost(
            'paystack.php',
            { action: 'verify', reference: response.reference },
            false,
            false,
            true
          );
          msg.textContent = `Payment successful! ₦${Number(verified.amount).toLocaleString()} added. New balance: ${formatMoney(verified.new_balance)}`;
          msg.className = 'alert alert-success';
          msg.classList.remove('hidden');
          document.getElementById('form-topup').reset();
          await loadStudents();
          await loadTransactions();
        } catch (err) {
          msg.textContent = err.message;
          msg.className = 'alert alert-error';
          msg.classList.remove('hidden');
        } finally {
          btn.disabled = !paystackReady;
        }
      },
      onClose: () => {
        btn.disabled = !paystackReady;
      },
    });
    handler.openIframe();
  } catch (err) {
    msg.textContent = err.message;
    msg.className = 'alert alert-error';
    msg.classList.remove('hidden');
    btn.disabled = !paystackReady;
  }
});

async function showApp() {
  loginScreen.classList.add('hidden');
  parentApp.classList.remove('hidden');
  const name = sessionStorage.getItem('parent_name') || 'Parent';
  const greeting = document.getElementById('parent-greeting');
  if (greeting) greeting.textContent = name;
  await Promise.all([loadPaystackConfig(), loadStudents(), loadTransactions()]);
}

(async () => {
  if (await checkAuth()) {
    await showApp();
  }
})();
