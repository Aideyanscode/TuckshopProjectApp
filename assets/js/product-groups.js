/** Pastries and drinks — the two menu groups for POS, admin, and seller. */
const MENU_GROUPS = [
  { key: 'pastry', label: 'Pastries', icon: '🥧' },
  { key: 'drink', label: 'Drinks', icon: '🧃' },
];

function normalizeCategory(category) {
  const c = String(category || '').toLowerCase();
  return c === 'drink' || c === 'drinks' ? 'drink' : 'pastry';
}

function groupProductsByCategory(products) {
  const groups = { pastry: [], drink: [] };
  for (const p of products) {
    const cat = normalizeCategory(p.category);
    groups[cat].push(p);
  }
  return groups;
}

function defaultIconForCategory(category) {
  return normalizeCategory(category) === 'drink' ? '🧃' : '🥧';
}

function renderGroupedProductRows(products, rowFn) {
  const groups = groupProductsByCategory(products);
  return MENU_GROUPS.map((g) => {
    const items = groups[g.key];
    if (!items.length) return '';
    const rows = items.map((p) => rowFn(p, g.key)).join('');
    return `
      <tr class="group-header-row"><td colspan="6" style="background:var(--surface2);font-weight:600;padding:0.75rem;">${g.icon} ${g.label}</td></tr>
      ${rows}`;
  }).join('');
}
