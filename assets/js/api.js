/**
 * API base path - works when served from project root via PHP built-in server or Apache.
 */
const API_BASE = (() => {
  const path = window.location.pathname;
  if (
    path.includes('/pos/') ||
    path.includes('/admin/') ||
    path.includes('/seller/') ||
    path.includes('/parent/') ||
    path.includes('/student/')
  ) {
    return '../api';
  }
  return 'api';
})();

function authHeaders(admin = false, seller = false, parent = false) {
  const headers = {};
  if (admin) {
    const token = sessionStorage.getItem('admin_token');
    if (token) headers['X-Admin-Token'] = token;
  }
  if (seller) {
    const token = sessionStorage.getItem('seller_token');
    if (token) headers['X-Seller-Token'] = token;
  }
  if (parent) {
    const token = sessionStorage.getItem('parent_token');
    if (token) headers['X-Parent-Token'] = token;
  }
  return headers;
}

async function apiGet(endpoint, params = {}, admin = false, seller = false, parent = false) {
  const qs = new URLSearchParams(params).toString();
  const url = `${API_BASE}/${endpoint}${qs ? '?' + qs : ''}`;
  const res = await fetch(url, { headers: authHeaders(admin, seller, parent) });
  const data = await res.json();
  if (!res.ok && data.error) throw new Error(data.error);
  return data;
}

async function apiPost(endpoint, body, admin = false, seller = false, parent = false) {
  const headers = { 'Content-Type': 'application/json', ...authHeaders(admin, seller, parent) };
  const res = await fetch(`${API_BASE}/${endpoint}`, {
    method: 'POST',
    headers,
    body: JSON.stringify(body),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || 'Request failed');
  return data;
}

async function apiPut(endpoint, body) {
  const token = sessionStorage.getItem('admin_token');
  const res = await fetch(`${API_BASE}/${endpoint}`, {
    method: 'PUT',
    headers: {
      'Content-Type': 'application/json',
      'X-Admin-Token': token || '',
    },
    body: JSON.stringify(body),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || 'Request failed');
  return data;
}

async function apiDelete(endpoint, body = {}, admin = false, seller = false, parent = false) {
  const headers = { 'Content-Type': 'application/json', ...authHeaders(admin, seller, parent) };
  const res = await fetch(`${API_BASE}/${endpoint}`, {
    method: 'DELETE',
    headers,
    body: JSON.stringify(body),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || 'Request failed');
  return data;
}

function formatMoney(amount, symbol = 'N') {
  return `${symbol}${Number(amount).toLocaleString('en-NG', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  })}`;
}
