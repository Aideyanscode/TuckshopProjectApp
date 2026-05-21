/**
 * API base path — works when served from project root via PHP built-in server or Apache.
 */
const API_BASE = (() => {
  const path = window.location.pathname;
  if (path.includes('/pos/') || path.includes('/admin/') || path.includes('/seller/')) {
    return '../api';
  }
  return 'api';
})();

function authHeaders(admin = false, seller = false) {
  const headers = {};
  if (admin) {
    const token = sessionStorage.getItem('admin_token');
    if (token) headers['X-Admin-Token'] = token;
  }
  if (seller) {
    const token = sessionStorage.getItem('seller_token');
    if (token) headers['X-Seller-Token'] = token;
  }
  return headers;
}

async function apiGet(endpoint, params = {}, admin = false, seller = false) {
  const qs = new URLSearchParams(params).toString();
  const url = `${API_BASE}/${endpoint}${qs ? '?' + qs : ''}`;
  const res = await fetch(url, { headers: authHeaders(admin, seller) });
  return parseApiResponse(res);
}

async function apiPost(endpoint, body, admin = false, seller = false) {
  const headers = { 'Content-Type': 'application/json', ...authHeaders(admin, seller) };
  const res = await fetch(`${API_BASE}/${endpoint}`, {
    method: 'POST',
    headers,
    body: JSON.stringify(body),
  });
  return parseApiResponse(res);
}

async function parseApiResponse(res) {
  const text = await res.text();
  let data = {};
  if (text) {
    try {
      data = JSON.parse(text);
    } catch {
      const snippet = text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 120);
      throw new Error(
        snippet.includes("doesn't exist") || snippet.includes('sellers')
          ? 'Database not fully set up. Open http://localhost/TuckshopProjectApp/install.php or run the sellers migration in phpMyAdmin.'
          : `Server error (invalid JSON): ${snippet || res.status}`
      );
    }
  }
  if (!res.ok) throw new Error(data.error || 'Request failed');
  return data;
}

async function apiPut(endpoint, body, admin = null, seller = null) {
  if (admin === null && seller === null) {
    admin = !!sessionStorage.getItem('admin_token');
    seller = !admin && !!sessionStorage.getItem('seller_token');
  }
  const res = await fetch(`${API_BASE}/${endpoint}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json', ...authHeaders(!!admin, !!seller) },
    body: JSON.stringify(body),
  });
  return parseApiResponse(res);
}

function formatMoney(amount, symbol = '₦') {
  return `${symbol}${Number(amount).toLocaleString('en-NG', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}`;
}
