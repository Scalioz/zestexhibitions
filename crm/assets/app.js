/* Zest CRM front end. Talks to api.php; all permissions are enforced on the server. */
(() => {
'use strict';
const CSRF = document.querySelector('meta[name=csrf]').content;
const $ = s => document.querySelector(s);
const view = () => $('#view');
const B = { me: null };           // boot data
const C = {};                      // per-view cache
let draft = null;                  // daily report draft
let newClient = null;              // new client draft

// ---------- utils ----------
const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
function inr(n) {
  if (n === null || n === undefined || n === '' || isNaN(n)) return '—';
  n = Number(n); const neg = n < 0; n = Math.abs(n);
  const r = Math.round(n * 100) / 100; let [i, f] = r.toFixed(2).split('.');
  let t = i.slice(-3), h = i.slice(0, -3);
  while (h.length > 2) { t = h.slice(-2) + ',' + t; h = h.slice(0, -2); }
  if (h) t = h + ',' + t;
  return (neg ? '−' : '') + '₹' + t + (f !== '00' ? '.' + f : '');
}
const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const fd = d => { if (!d) return '—'; const [y, m, dd] = String(d).slice(0, 10).split('-'); return `${dd} ${MON[+m - 1]} ${y.slice(2)}`; };
const DOW = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const longDate = d => { const x = new Date(d + 'T00:00:00Z'); return `${DOW[x.getUTCDay()]}, ${fd(d)}`; };
const num = v => v === null || v === undefined || v === '' ? '' : String(+v);
const cap = s => s ? s[0].toUpperCase() + s.slice(1) : '';
const isAdmin = () => B.me && B.me.role === 'admin';
const pill = s => `<span class="pill p-${esc(s)}">${esc(s)}</span>`;
const userName = id => (B.users.find(u => +u.id === +id) || {}).name || '—';

function toast(m, err) { const t = $('#toast'); t.textContent = m; t.className = 'toast' + (err ? ' err' : ''); t.hidden = false; clearTimeout(t._t); t._t = setTimeout(() => t.hidden = true, err ? 4500 : 2200); }

async function api(a, data = {}) {
  const r = await fetch('api.php?a=' + a, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF }, body: JSON.stringify(data), credentials: 'same-origin' });
  if (r.status === 401) { location.href = 'login.php'; throw new Error('signed out'); }
  let j; try { j = await r.json(); } catch { throw new Error('Server error. Please try again.'); }
  if (!r.ok || j.error) throw new Error(j.error || 'Request failed');
  return j;
}
async function upload(entity, id, file) {
  const fdta = new FormData(); fdta.append('entity', entity); fdta.append('entity_id', id); fdta.append('file', file);
  const r = await fetch('api.php?a=upload', { method: 'POST', headers: { 'X-CSRF': CSRF }, body: fdta, credentials: 'same-origin' });
  const j = await r.json().catch(() => ({ error: 'Upload failed' }));
  if (!r.ok || j.error) throw new Error(`${file.name}: ${j.error || 'upload failed'}`);
  return j;
}
async function uploadAll(entity, id, files) { for (const f of files) await upload(entity, id, f); }
async function act(fn, okMsg) { try { const r = await fn(); if (okMsg) toast(okMsg); return r; } catch (e) { toast(e.message, true); throw e; } }

const fileChips = (list, canDel) => list && list.length
  ? `<div class="files">${list.map(f => `<a class="chip" href="file.php?id=${f.id}" target="_blank" rel="noopener" title="${esc(f.orig_name)}">📎 <span>${esc(f.orig_name)}</span></a>${canDel ? `<button class="btn link" data-delfile="${f.id}" title="Remove file" aria-label="Remove ${esc(f.orig_name)}">✕</button>` : ''}`).join('')}</div>` : '<span class="sub">No file yet</span>';
const upBtn = (entity, id, label = 'Upload') => `<label class="up">⬆ ${esc(label)}<input type="file" multiple data-upload="${entity}" data-eid="${id}" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.xlsx,.xls,.csv,.doc,.docx,.ppt,.pptx,.dwg,.dxf,.skp,.zip"></label>`;

// line drawing of an exhibition stall, used in empty states
const STALL = `<svg viewBox="0 0 160 110" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path d="M20 90 L80 105 L140 90 L80 75 Z" opacity=".5"/><path d="M20 90 V35 L80 50 V105"/><path d="M80 50 L140 35 V90"/><path d="M20 35 L80 20 L140 35"/><rect x="30" y="52" width="38" height="18" transform="skewY(14)" opacity=".7"/><path d="M95 62 h28 v16 h-28z" opacity=".6"/><circle cx="109" cy="55" r="4"/></svg>`;
const blank = (msg, btn = '') => `<div class="blank">${STALL}<div>${msg}</div>${btn}</div>`;
const PLAN = `<svg viewBox="0 0 300 140" aria-hidden="true">${Array.from({ length: 24 }, (_, i) => { const x = (i % 8) * 36 + 4, y = Math.floor(i / 8) * 44 + 6; const f = [2, 5, 9, 12, 19].includes(i); return `<rect x="${x}" y="${y}" width="30" height="36" rx="2" fill="${f ? '#e9b949' : 'none'}" stroke="#fff" stroke-opacity=".6"/>`; }).join('')}</svg>`;

// ---------- routing ----------
const routes = {};
function parseHash() { const h = (location.hash || '#home').slice(1); const [name, id, tab] = h.split('-'); return { name: name || 'home', id: id ? +id : null, tab }; }
async function route() {
  const r = parseHash();
  C.editing = null;
  renderNav(r.name);
  const fn = routes[r.name] || routes.home;
  if ((r.name in ADMIN_ONLY) && !isAdmin()) { location.hash = '#home'; return; }
  if ((r.name in RECORDS_ONLY) && !B.records) { location.hash = '#home'; return; }
  try { await fn(r); } catch (e) { if (e.message !== 'signed out') view().innerHTML = `<div class="alert bad"><b>Could not load this page</b><span class="m">${esc(e.message)}</span></div>`; }
  window.scrollTo(0, 0);
}
const ADMIN_ONLY = { clientlog: 1, reportlog: 1, team: 1, formats: 1, settings: 1, dnq: 1 };
const RECORDS_ONLY = { clients: 1, client: 1, project: 1, newclient: 1 };
async function refresh() { const y = window.scrollY; const r = parseHash(); await (routes[r.name] || routes.home)(r); window.scrollTo(0, y); }

function renderNav(cur) {
  const map = { client: 'clients', project: 'clients' };
  const on = map[cur] || cur;
  const L = (k, l, b) => `<a href="#${k}" class="${on === k ? 'on' : ''}">${l}${b ? `<span class="badge">${b}</span>` : ''}</a>`;
  $('#nav').innerHTML = isAdmin()
    ? `<div class="sec">Overview</div>${L('home', 'Business dashboard')}${L('clientlog', 'Client log')}${L('reportlog', 'Daily reports', B.flags || '')}
       <div class="sec">Records</div>${L('clients', 'All clients')}${L('newclient', '+ New client')}${L('dnq', 'Do-not-quote list')}
       <div class="sec">Admin</div>${L('team', 'Team')}${L('formats', 'Report formats')}${L('settings', 'Holidays & lists')}`
    : B.records ? `${L('home', 'My dashboard')}${L('clients', 'My clients')}${L('newclient', '+ New client')}${L('report', 'Daily report', B.flags || '')}`
    : `${L('home', 'My dashboard')}${L('report', 'Daily report', B.flags || '')}`;
}

// ---------- HOME ----------
routes.home = async () => isAdmin() ? adminHome() : empHome();

async function empHome() {
  const d = await api('home'); B.flags = d.missing.length; renderNav('home');
  const first = B.me.name.split(' ')[0];
  const hr = new Date().getHours(); const greet = hr < 12 ? 'Good morning' : hr < 17 ? 'Good afternoon' : 'Good evening';
  if (!B.records) {
    view().innerHTML = `
    <div class="welcome">${PLAN}<div><h1>${greet}, ${esc(first)}</h1><p>${longDate(B.today)} · ${esc(B.me.department)}</p></div>
     <div class="row"><a class="btn" href="#report">Open daily report</a></div></div>
    ${d.missing.length ? `<div class="alert bad"><b>Daily report not submitted</b><span class="m">Missing for: ${d.missing.map(fd).join(', ')}. Admin can see this.</span></div>` : ''}
    ${!d.today_done && d.today_working ? `<div class="alert warn"><b>Today's report is pending</b><span class="m">Submit before you leave for the day. <a href="#report">Open daily report →</a></span></div>` : ''}
    ${d.today_done ? '<div class="alert" style="background:var(--ok-bg);color:var(--ok)"><b>Today\'s report is submitted</b></div>' : ''}
    <div class="kpis"><div class="kpi"><span>Reports this month</span><b>${d.reports_month}</b></div><div class="kpi"><span>Entries this month</span><b>${d.entries_month}</b></div><div class="kpi hl"><span>Missed days</span><b>${d.missing.length}</b></div></div>`;
    return;
  }
  view().innerHTML = `
  <div class="welcome">${PLAN}<div><h1>${greet}, ${esc(first)}</h1><p>${longDate(B.today)} · ${esc(B.me.department)}</p></div>
   <div class="row"><a class="btn" href="#newclient">+ New client</a></div></div>
  ${d.missing.length ? `<div class="alert bad"><b>Daily report not submitted</b><span class="m">Missing for: ${d.missing.map(fd).join(', ')}. Admin can see this.</span></div>` : ''}
  ${!d.today_done && d.today_working ? `<div class="alert warn"><b>Today's report is pending</b><span class="m">Submit before you leave for the day. <a href="#report">Open daily report →</a></span></div>` : ''}
  <div class="kpis">
    <div class="kpi"><span>My clients</span><b>${d.clients}</b></div>
    <div class="kpi"><span>Orders won</span><b>${d.won}</b><small>${inr(d.won_total)} billed</small></div>
    <div class="kpi"><span>Open enquiries / quotes</span><b>${d.open}</b></div>
    <div class="kpi hl"><span>Balance to collect</span><b>${inr(d.due)}</b></div>
  </div>
  <div class="panel"><h2>My active orders & enquiries</h2>${projTable(d.projects)}</div>`;
}

async function adminHome(r) {
  const f = C.dash || (C.dash = { from: '', to: '' });
  const d = await api('home', f);
  B.flags = d.employees.reduce((s, e) => s + e.missing.length, 0); renderNav('home');
  const flags = d.employees.filter(e => e.missing.length);
  const maxB = Math.max(1, ...d.employees.map(e => e.business));
  view().innerHTML = `
  <div class="head"><div><h1>Business dashboard</h1><p>${f.from || f.to ? `Expos from ${f.from ? fd(f.from) : 'start'} to ${f.to ? fd(f.to) : 'today'}` : 'All time · all employees'}</p></div>
   <form class="row" id="dashf"><label class="f">From<input type="date" name="from" value="${f.from}"></label><label class="f">To<input type="date" name="to" value="${f.to}"></label><button class="btn ghost sm" style="align-self:flex-end">Apply</button>${f.from || f.to ? '<button type="button" class="btn link" id="dashclear" style="align-self:flex-end">Clear</button>' : ''}</form></div>
  ${flags.length ? `<div class="alert bad"><b>Daily reports not received</b><span class="m">${flags.map(e => `${esc(e.name)} — ${e.missing.slice(-5).map(fd).join(', ')}${e.missing.length > 5 ? ` (+${e.missing.length - 5} more)` : ''}`).join('<br>')}</span><div><a class="btn sm" href="#reportlog">Open report log</a></div></div>` : ''}
  <div class="kpis">
   <div class="kpi hl"><span>Business captured</span><b>${inr(d.captured)}</b><small>${d.captured_n} orders · incl. GST</small></div>
   <div class="kpi"><span>Business missed</span><b>${inr(d.missed)}</b><small>${d.missed_n} rejected / lost</small></div>
   <div class="kpi"><span>Received from clients</span><b>${inr(d.received)}</b><small>Outstanding ${inr(d.outstanding)}</small></div>
   <div class="kpi"><span>GST received</span><b>${inr(d.gst_received)}</b><small>of ${inr(d.gst_billed)} billed</small></div>
   <div class="kpi"><span>Paid to vendors</span><b>${inr(d.vendor_paid)}</b></div>
   <div class="kpi"><span>Expenses · losses</span><b>${inr(d.expenses)}</b><small>Losses ${inr(d.losses)}</small></div>
   <div class="kpi"><span>Profit on won orders</span><b>${inr(d.profit)}</b><small>excl. GST, after vendor, expenses, losses</small></div>
  </div>
  <div class="panel"><h2>Employee performance</h2>${d.employees.length ? `<div class="tw"><table><tr><th>Employee</th><th>Dept.</th><th class="r">Clients</th><th class="r">Won / orders</th><th class="r">Business generated</th><th></th><th class="r">Missed</th><th class="r">Collected</th><th class="r">Reports this month</th><th>Report status</th></tr>
   ${d.employees.map(e => `<tr><td><b>${esc(e.name)}</b><div class="sub">${esc(e.designation || '')}</div></td><td>${esc(e.department)}</td><td class="r num">${e.clients}</td><td class="r num">${e.won} / ${e.orders}</td><td class="r num">${inr(e.business)}</td><td style="width:90px"><div class="bar"><i style="width:${Math.round(e.business / maxB * 100)}%"></i></div></td><td class="r num">${inr(e.missed)}</td><td class="r num">${inr(e.collected)}</td><td class="r num">${e.reports_month}</td><td>${e.missing.length ? `<span class="pill p-bad">${e.missing.length} missed</span>` : '<span class="pill p-ok">on track</span>'}</td></tr>`).join('')}</table></div>` : blank('No employees yet.', '<a class="btn sm" href="#team">Create employee logins</a>')}</div>
  <div class="grid">
   <div class="panel"><h2>Why business was missed</h2>${d.lost.length ? `<div class="tw"><table><tr><th>Client · expo</th><th>By</th><th>Reason</th><th class="r">Value</th></tr>${d.lost.map(p => `<tr class="click" data-go="project-${p.id}"><td>${esc(p.company)}<div class="sub">${esc(p.expo)}</div></td><td>${p.rejected_by === 'us' ? 'Zest' : p.rejected_by === 'client' ? 'Client' : '—'}</td><td>${esc(p.reason || '—')}</td><td class="r num">${inr(p.offered)}</td></tr>`).join('')}</table></div>` : '<div class="empty">Nothing missed in this period.</div>'}</div>
   <div class="panel"><h2>Vendor payments</h2>${d.vendors.length ? `<div class="tw"><table><tr><th>Vendor</th><th class="r">Jobs</th><th class="r">Paid</th></tr>${d.vendors.map(v => `<tr><td>${esc(v.name)}</td><td class="r num">${v.jobs}</td><td class="r num">${inr(v.paid)}</td></tr>`).join('')}<tr><td><b>Total</b></td><td></td><td class="r num"><b>${inr(d.vendor_paid)}</b></td></tr></table></div>` : '<div class="empty">No vendor payments recorded.</div>'}</div>
  </div>
  <div class="panel"><h2>Customers by business</h2>${d.customers.length ? `<div class="tw"><table><tr><th>Client</th><th class="r">Orders</th><th class="r">Business</th><th class="r">Received</th><th>Payment</th></tr>
   ${d.customers.map(x => `<tr class="click" data-go="client-${x.id}"><td>${esc(x.company)}</td><td class="r num">${x.orders}</td><td class="r num">${inr(x.total)}</td><td class="r num">${inr(x.received)}</td><td>${x.total - x.received > 0.5 ? `<span class="pill p-quoted">${inr(x.total - x.received)} due</span>` : '<span class="pill p-ok">fully realised</span>'}</td></tr>`).join('')}</table></div>` : '<div class="empty">No won orders yet.</div>'}</div>
  <div class="panel"><h2>Latest orders</h2>${projTable(d.recent, true, true)}</div>`;
  $('#dashf').onsubmit = e => { e.preventDefault(); const fm = new FormData(e.target); C.dash = { from: fm.get('from'), to: fm.get('to') }; refresh(); };
  if ($('#dashclear')) $('#dashclear').onclick = () => { C.dash = { from: '', to: '' }; refresh(); };
}

function projTable(ps, showOwner, showClient = true) {
  if (!ps || !ps.length) return blank('No orders yet.');
  return `<div class="tw"><table><tr>${showClient ? '<th>Client</th>' : ''}<th>Expo</th><th>Dates</th><th class="r">Area</th><th>Status</th>${showOwner ? '<th>Handled by</th>' : ''}<th class="r">Bill total</th><th class="r">Balance</th></tr>
  ${ps.map(p => { const b = p.bill || {}; return `<tr class="click" data-go="project-${p.id}">${showClient ? `<td>${esc(p.company)}</td>` : ''}<td>${esc(p.expo)}<div class="sub">${esc(p.location || '')}</div></td><td class="num">${fd(p.start_date)}</td><td class="r num">${p.area_sqm ? num(p.area_sqm) + ' sqm' : '—'}${p.space_type ? `<div class="sub">${esc(p.space_type)}</div>` : ''}</td><td>${pill(p.status)}</td>${showOwner ? `<td>${esc(p.owner || userName(p.owner_id))}</td>` : ''}<td class="r num">${b.base ? inr(b.total) : '—'}</td><td class="r num">${b.base ? inr(b.balance) : '—'}</td></tr>`; }).join('')}</table></div>`;
}

// ---------- CLIENTS ----------
routes.clients = async () => {
  const qv = C.cq || '';
  const d = await api('clients', { q: qv });
  view().innerHTML = `
  <div class="head"><div><h1>${isAdmin() ? 'All clients' : 'My clients'}</h1><p>${d.clients.length} client${d.clients.length === 1 ? '' : 's'}${qv ? ` matching “${esc(qv)}”` : ''}</p></div>
   <div class="row"><input id="cq" placeholder="Search company, person, city, phone" value="${esc(qv)}" style="min-width:260px"><a class="btn" href="#newclient">+ New client</a></div></div>
  <div class="panel">${d.clients.length ? `<div class="tw"><table><tr><th>Company</th><th>Contact</th><th>City</th>${isAdmin() ? '<th>Handled by</th>' : ''}<th class="r">Won / orders</th><th class="r">Business</th><th class="r">Outstanding</th></tr>
  ${d.clients.map(c => `<tr class="click" data-go="client-${c.id}"><td><b>${esc(c.company)}</b>${+c.dnq ? '<div class="dnq">⛔ DO NOT QUOTE</div>' : ''}</td><td>${esc(c.person || '')}<div class="sub">${esc(c.designation || '')}</div></td><td>${esc(c.city || '')}</td>${isAdmin() ? `<td>${esc(c.owner)}</td>` : ''}<td class="r num">${c.won} / ${c.orders}</td><td class="r num">${inr(c.business)}</td><td class="r num">${inr(c.due)}</td></tr>`).join('')}
  </table></div>` : blank(qv ? 'No client matches that search.' : 'No clients yet. Add your first one.', '<a class="btn sm" href="#newclient">+ New client</a>')}</div>`;
  let t; $('#cq').oninput = e => { clearTimeout(t); t = setTimeout(() => { C.cq = e.target.value.trim(); refresh().then(() => { const i = $('#cq'); i.focus(); i.setSelectionRange(i.value.length, i.value.length); }); }, 350); };
};

routes.dnq = async () => {
  const d = await api('clients', { dnq: 1 });
  view().innerHTML = `<div class="head"><div><h1>Do-not-quote list</h1><p>Everyone sees a red alert when they type a matching company, email or phone.</p></div></div>
  <div class="panel">${d.clients.length ? `<div class="tw"><table><tr><th>Company</th><th>Since</th><th>Reason</th><th>Note</th><th>Flagged by</th><th>Handled by</th></tr>${d.clients.map(c => `<tr class="click" data-go="client-${c.id}"><td><b>${esc(c.company)}</b></td><td class="num">${fd(c.dnq_on)}</td><td>${esc(c.dnq_reason)}</td><td>${esc(c.dnq_note || '')}</td><td>${esc(c.dnq_by || '')}</td><td>${esc(c.owner)}</td></tr>`).join('')}</table></div>` : blank('No client is flagged.')}</div>`;
};

routes.newclient = async () => {
  const n = newClient || (newClient = { company: '', email: '', phone: '', person: '', designation: '', city: '', location: '', gst_no: '', notes: '', owner_id: '', contacts: [], cards: [], briefs: [], expo: { expo: '', location: '', start_date: '', area_sqm: '', space_type: '', stall_no: '' } });
  const F = (k, l, t = 'text') => `<label class="f">${l}<input data-nc="${k}" type="${t}" value="${esc(n[k])}"></label>`;
  view().innerHTML = `
  <div class="head"><div><h1>New client</h1><p>As you type the company, email or phone, the CRM checks every past record, including other employees' clients.</p></div></div>
  <div class="panel"><h2>Client details</h2>
   <div class="grid">${F('company', 'Company name *')}${F('email', 'Email', 'email')}${F('phone', 'Phone', 'tel')}${F('person', 'Contact person')}${F('designation', 'Designation')}${F('city', 'City')}${F('gst_no', 'GSTIN')}
   ${isAdmin() ? `<label class="f">Handled by<select data-nc="owner_id"><option value="">Me</option>${B.users.filter(u => +u.active).map(u => `<option value="${u.id}" ${+n.owner_id === +u.id ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select></label>` : ''}</div>
   <div class="grid" style="margin-top:12px"><label class="f">Company location / address<textarea data-nc="location">${esc(n.location)}</textarea></label><label class="f">Notes<textarea data-nc="notes">${esc(n.notes)}</textarea></label></div>
   <div id="dup" style="margin-top:12px"></div>
  </div>
  <div class="panel"><h2>Expo enquiry</h2><p class="sub" style="margin-top:0">The expo this client is talking to us about. This creates their first expo order.</p>
   <div class="grid g3">${[['expo', 'Expo name *'], ['location', 'Expo location / city'], ['start_date', 'Expo start date', 'date'], ['area_sqm', 'Area chosen (sqm)', 'number'], ['stall_no', 'Stall no.']].map(([k, l, t]) => `<label class="f">${l}<input data-nce="${k}" type="${t || 'text'}" value="${esc(n.expo[k])}"></label>`).join('')}
   <label class="f">Space type<select data-nce="space_type"><option value="">Select</option>${['Bare space', 'Shell scheme'].map(o => `<option ${n.expo.space_type === o ? 'selected' : ''}>${o}</option>`).join('')}</select></label></div></div>
  <div class="panel"><div class="ph"><h2>Others involved in the deal</h2><button class="btn ghost sm" id="nc-addc">+ Add person</button></div>
   ${n.contacts.length ? n.contacts.map((o, i) => `<div class="grid g3" style="margin-bottom:8px">${['name', 'designation', 'phone', 'email'].map(k => `<label class="f">${cap(k)}<input data-ncc="${i}" data-k="${k}" value="${esc(o[k])}"></label>`).join('')}</div>`).join('') : '<div class="sub">Purchase head, director, consultant — anyone else in the decision.</div>'}
  </div>
  <div class="panel"><h2>Client brief / requirements</h2><p class="sub" style="margin-top:0">Upload the brief the customer gave — PDF, image, Word, Excel or PowerPoint.</p><div class="row"><label class="up">⬆ Upload brief<input type="file" multiple id="nc-brief" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.ppt,.pptx"></label>${n.briefs.map(f => `<span class="chip">📎 <span>${esc(f.name)}</span></span>`).join('')}</div></div>
  <div class="panel"><h2>Business cards</h2><div class="row"><label class="up">⬆ Add business card (photo / PDF)<input type="file" multiple id="nc-cards" accept=".jpg,.jpeg,.png,.webp,.heic,.pdf"></label>${n.cards.map(f => `<span class="chip">📎 <span>${esc(f.name)}</span></span>`).join('')}</div></div>
  <div class="row"><button class="btn" id="nc-save">Save client</button><a class="btn ghost" href="#clients">Cancel</a></div>`;
  dupCheck();
};
let dupT;
function dupCheck() {
  clearTimeout(dupT);
  dupT = setTimeout(async () => {
    const box = $('#dup'); if (!box || !newClient) return;
    const { company, email, phone } = newClient;
    if ((company || '').trim().length < 3 && !email && (phone || '').replace(/\D/g, '').length < 10) { box.innerHTML = ''; return; }
    const d = await api('dup', { company, email, phone }).catch(() => ({ matches: [] }));
    box.innerHTML = d.matches.map(m => +m.dnq
      ? `<div class="alert bad"><b>⛔ Do not quote — ${esc(m.company)}</b><span class="m">Flagged on ${fd(m.dnq_on)} by ${esc(m.dnq_by)} · Reason: <b>${esc(m.dnq_reason)}</b>${m.dnq_note ? '<br>' + esc(m.dnq_note) : ''}</span><span class="m sub">Handled earlier by ${esc(m.owner)}. Speak to admin before replying.</span></div>`
      : `<div class="alert warn"><b>Already in the CRM — ${esc(m.company)}</b><span class="m">Handled by ${esc(m.owner)} · ${m.orders} order(s)${m.last ? ` · last: ${esc(m.last.expo)} (${esc(m.last.status)})` : ''}. ${m.mine ? `<a href="#client-${m.id}">Open this client →</a>` : 'Check with them before adding a duplicate.'}</span></div>`).join('<div style="height:8px"></div>');
  }, 300);
}

routes.client = async ({ id }) => {
  const { client: c } = await api('client', { id });
  C.client = c;
  const won = c.projects.filter(p => ['won', 'completed'].includes(p.status));
  const T = won.reduce((a, p) => (a.t += p.bill.total, a.r += p.bill.received, a.b += p.bill.balance, a), { t: 0, r: 0, b: 0 });
  const edit = C.editClient === c.id;
  const F = (k, l, t = 'text') => `<label class="f">${l}<input name="${k}" type="${t}" value="${esc(c[k])}"></label>`;
  view().innerHTML = `
  <div class="head"><div><div class="crumb"><a href="#clients">← Clients</a></div><h1>${esc(c.company)}</h1><p>${esc(c.city || '')}${c.city ? ' · ' : ''}handled by ${esc(c.owner)}</p></div>
   <div class="row">${isAdmin() ? '<button class="btn link" id="client-del">Delete client</button>' : ''}<a class="btn ghost" href="ledger.php?client=${c.id}" target="_blank" rel="noopener">Ledger</a>${+c.dnq || !isAdmin() ? '' : '<button class="btn ghost" id="dnq-open">Mark “Do not quote”</button>'}<button class="btn" id="np-open">+ New expo order</button></div></div>
  ${+c.dnq ? `<div class="alert bad"><b>⛔ Do not quote</b><span class="m">Since ${fd(c.dnq_on)} · ${esc(c.dnq_reason)}${c.dnq_note ? ' — ' + esc(c.dnq_note) : ''} · flagged by ${esc(c.dnq_by)}</span>${isAdmin() ? '<div><button class="btn sm ghost" id="dnq-off">Remove flag</button></div>' : ''}</div>` : ''}
  <div id="dnq-form"></div>
  <form class="panel" id="np-form" hidden><h3>New expo order</h3><div class="grid g3"><label class="f">Expo name *<input name="expo" required></label><label class="f">Location<input name="location"></label><label class="f">Start date<input type="date" name="start_date"></label><label class="f">Area chosen (sqm)<input type="number" step="0.01" name="area_sqm"></label><label class="f">Space type<select name="space_type"><option value="">Select</option><option>Bare space</option><option>Shell scheme</option></select></label><label class="f">Stall no.<input name="stall_no"></label></div><div class="row" style="margin-top:10px"><button class="btn">Create order</button><button type="button" class="btn ghost" id="np-cancel">Cancel</button></div></form>
  <div class="kpis"><div class="kpi"><span>Orders</span><b>${won.length} won / ${c.projects.length}</b></div><div class="kpi hl"><span>Total business</span><b>${inr(T.t)}</b></div><div class="kpi"><span>Received</span><b>${inr(T.r)}</b></div><div class="kpi"><span>Outstanding</span><b style="color:${T.b > 0.5 ? 'var(--bad)' : 'var(--ok)'}">${inr(T.b)}</b></div></div>
  <div class="panel"><h2>Orders</h2>${projTable(c.projects.map(p => ({ ...p, company: c.company })), isAdmin(), false)}</div>
  <div class="grid">
   <div class="panel"><div class="ph"><h3>Client details</h3>${edit ? '' : '<button class="btn ghost sm" id="ce-open">Edit</button>'}</div>
    ${edit ? `<form id="ce-form"><div class="grid g3">${F('company', 'Company name')}${F('email', 'Email', 'email')}${F('phone', 'Phone')}${F('person', 'Contact person')}${F('designation', 'Designation')}${F('city', 'City')}${F('gst_no', 'GSTIN')}</div>
      <div class="grid" style="margin-top:10px"><label class="f">Location / address<textarea name="location">${esc(c.location)}</textarea></label><label class="f">Notes<textarea name="notes">${esc(c.notes)}</textarea></label></div>
      ${isAdmin() ? `<div class="grid g3" style="margin-top:10px"><label class="f">Handled by<select name="owner_id">${B.users.filter(u => +u.active).map(u => `<option value="${u.id}" ${+u.id === +c.owner_id ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select></label><label class="row sub" style="align-self:end"><input type="checkbox" name="move_orders" checked> Move their orders too</label></div>` : ''}
      <div class="row" style="margin-top:10px"><button class="btn">Save</button><button type="button" class="btn ghost" id="ce-cancel">Cancel</button></div></form>`
    : `<div>${esc(c.person || '—')}${c.designation ? ' · ' + esc(c.designation) : ''}</div><div class="sub">${esc(c.email || '')}${c.email && c.phone ? ' · ' : ''}${esc(c.phone || '')}</div><div class="sub">${esc(c.location || '')}</div>${c.gst_no ? `<div class="sub">GSTIN ${esc(c.gst_no)}</div>` : ''}${c.notes ? `<p class="sub">${esc(c.notes)}</p>` : ''}`}
   </div>
   <div class="panel"><h3>Others in the deal</h3>
    ${c.contacts.length ? `<div class="tw"><table>${c.contacts.map(o => `<tr><td><b>${esc(o.name)}</b><div class="sub">${esc(o.designation || '')}</div></td><td class="sub">${esc(o.phone || '')}<br>${esc(o.email || '')}</td><td class="r"><button class="btn link" data-delcontact="${o.id}" aria-label="Remove">✕</button></td></tr>`).join('')}</table></div>` : '<div class="sub">Nobody added.</div>'}
    <form class="grid g3" id="ct-form" style="margin-top:10px"><label class="f">Name<input name="name" required></label><label class="f">Designation<input name="designation"></label><label class="f">Phone<input name="phone"></label><div style="align-self:end"><button class="btn ghost sm">+ Add</button></div></form>
   </div>
   <div class="panel"><h3>Client brief / requirements</h3><div class="row">${upBtn('brief', c.id, 'Upload brief')}${fileChips(c.briefs, isAdmin())}</div><p class="sub" style="margin-bottom:0">PDF, image, Word, Excel or PowerPoint.</p></div>
   <div class="panel"><h3>Business cards</h3><div class="row">${upBtn('card', c.id, 'Add card')}${fileChips(c.cards, isAdmin())}</div></div>
  </div>`;
};

// ---------- PROJECT ----------
const TABS = [['expo', 'Expo'], ['design', 'Design'], ['order', 'Quote & order'], ['vendors', 'Vendor quotes'], ['extras', 'Extras'], ['money', 'Payments & costs']];
routes.project = async ({ id, tab }) => {
  const { project: p } = await api('project', { id });
  C.project = p; tab = TABS.some(t => t[0] === tab) ? tab : 'expo';
  const b = p.bill;
  view().innerHTML = `
  <div class="head"><div><div class="crumb"><a href="#client-${p.client.id}">← ${esc(p.client.company)}</a>${+p.client.dnq ? ' <span class="dnq">⛔ DO NOT QUOTE</span>' : ''}</div>
   <h1>${esc(p.expo)}${p.location ? ', ' + esc(p.location) : ''}</h1><p>${fd(p.start_date)} – ${fd(p.end_date)}${p.area_sqm ? ' · ' + num(p.area_sqm) + ' sqm' : ''}${p.space_type ? ' · ' + esc(p.space_type) : ''}${p.stall_no ? ' · Stall ' + esc(p.stall_no) : ''} · ${esc(p.owner)}</p></div>${pill(p.status)}</div>
  <div class="split"><div class="panel" style="padding-top:4px"><div class="tabs">${TABS.map(([k, l]) => `<a href="#project-${p.id}-${k}" class="${tab === k ? 'on' : ''}">${l}</a>`).join('')}</div><div style="padding-top:16px">${PT[tab](p)}</div></div>
  <aside class="panel sum"><h3>Bill summary</h3>
   <div class="l"><span>Stall fabrication (agreed)</span><span class="num">${inr(b.base)}</span></div>
   <div class="l"><span>Extras (${p.extras.length})</span><span class="num">${inr(b.extras)}</span></div>
   <div class="l"><span>GST ${num(p.gst_rate)}%${p.gst_on !== null && p.gst_on !== '' ? ' on ' + inr(p.gst_on) : ''}</span><span class="num">${inr(b.gst)}</span></div>
   <div class="l tot big"><span>Bill total</span><span class="num">${inr(b.total)}</span></div>
   <div class="l"><span>Received</span><span class="num">${inr(b.received)}</span></div>
   <div class="l tot"><span>Balance</span><span class="num" style="color:${b.balance > 0.5 ? 'var(--bad)' : 'var(--ok)'}">${inr(b.balance)}</span></div>
   <h3 style="margin-top:14px">Profit (excl. GST)</h3>
   <div class="l"><span>Vendor paid</span><span class="num">${inr(b.vendor_paid)}</span></div>
   <div class="l"><span>Project expenses</span><span class="num">${inr(b.expenses)}</span></div>
   <div class="l"><span>Losses</span><span class="num">${inr(b.losses)}</span></div>
   <div class="l tot"><span>Profit so far</span><span class="num" style="color:${b.profit < 0 ? 'var(--bad)' : 'inherit'}">${inr(b.profit)}</span></div>
   <a class="btn ghost sm" style="margin-top:10px;justify-content:center" href="ledger.php?client=${p.client.id}" target="_blank" rel="noopener">Open client ledger</a>
  </aside></div>`;
};
const PF = (p, k, l, t = 'text', extra = '') => `<label class="f">${l}<input data-pf="${k}" type="${t}" value="${esc(t === 'number' ? num(p[k]) : p[k])}" ${extra}></label>`;
const delBtn = (kind, id) => `<button class="btn link" data-delchild="${kind}:${id}" aria-label="Delete entry" title="Delete">✕</button>`;
const acts = (kind, id, canDel = true) => `<span style="white-space:nowrap"><button class="btn link" data-editchild="${kind}:${id}" title="Edit" aria-label="Edit entry">✎ Edit</button>${canDel ? delBtn(kind, id) : ''}</span>`;
// Inline edit form for one entry (opens in place of the row)
const EF = {
  revision: [['rdate', 'Date', 'date'], ['done_by', 'Done by'], ['changes', 'What changed']],
  quote: [['qdate', 'Date', 'date'], ['amount', 'Amount (₹)', 'number'], ['approved_by', 'Approved by (Zest)'], ['notes', 'What changed']],
  vquote: [['vendor_id', 'Vendor', 'vendor'], ['amount', 'Quote (₹)', 'number'], ['note', 'Quality note']],
  extra: [['item', 'Item'], ['rate', 'Unit rate (₹)', 'number'], ['qty', 'Qty', 'number']],
  payment: [['pdate', 'Date', 'date'], ['mode', 'Mode', ['Bank', 'Cash', 'UPI', 'Cheque']], ['reference', 'Reference / UTR'], ['amount', 'Amount (₹)', 'number']],
  vpayment: [['pdate', 'Date', 'date'], ['vendor_id', 'Vendor', 'vendor'], ['mode', 'Mode', ['Bank', 'Cash', 'UPI', 'Cheque']], ['amount', 'Amount (₹)', 'number']],
  expense: [['edate', 'Date', 'date'], ['category', 'Type', ['Flight', 'Train', 'Hotel', 'Commute', 'Food', 'Courier', 'Other']], ['description', 'Details'], ['amount', 'Amount (₹)', 'number']],
  loss: [['ldate', 'Date', 'date'], ['ltype', 'Type', ['Client paid less', 'Vendor overpaid by mistake', 'Damage', 'Other']], ['note', 'Note'], ['amount', 'Amount (₹)', 'number']],
};
function rowOr(kind, r, cols, html) {
  if (C.editing !== kind + ':' + r.id) return html;
  const inp = ([k, l, t]) => {
    const v = r[k] ?? '';
    if (t === 'vendor') return `<label class="f">${l}<select name="${k}">${B.vendors.map(o => `<option value="${o.id}" ${+o.id === +v ? 'selected' : ''}>${esc(o.name)}</option>`).join('')}${B.vendors.some(o => +o.id === +v) ? '' : `<option value="${esc(v)}" selected>${esc(r.vendor || 'Current vendor')}</option>`}</select></label>`;
    if (Array.isArray(t)) return `<label class="f">${l}<select name="${k}">${[...new Set([...t, v].filter(Boolean))].map(o => `<option ${o === v ? 'selected' : ''}>${esc(o)}</option>`).join('')}</select></label>`;
    return `<label class="f">${l}<input name="${k}" type="${t || 'text'}" ${t === 'number' ? 'step="any"' : ''} value="${esc(t === 'number' ? num(v) : v)}"></label>`;
  };
  return `<tr><td colspan="${cols}" style="background:var(--gold-soft)"><form data-edit="${kind}:${r.id}"><div class="grid g3">${EF[kind].map(inp).join('')}</div>
    <div class="row" style="margin-top:8px"><button class="btn sm">Save changes</button><button type="button" class="btn ghost sm" data-canceledit="1">Cancel</button></div></form></td></tr>`;
}
const canDelMoney = () => isAdmin();
const vendorOpts = () => '<option value="">Select vendor</option>' + B.vendors.map(v => `<option value="${v.id}">${esc(v.name)}</option>`).join('') + '<option value="__new">+ Add new vendor…</option>';
const PT = {};
PT.expo = p => `<div class="grid">${PF(p, 'expo', 'Expo name')}${PF(p, 'location', 'Location')}${PF(p, 'start_date', 'Start date', 'date')}${PF(p, 'end_date', 'End date', 'date')}${PF(p, 'area_sqm', 'Area (sqm)', 'number', 'step="0.01"')}<label class="f">Space type<select data-pf="space_type"><option value="">Select</option>${['Bare space', 'Shell scheme'].map(o => `<option ${p.space_type === o ? 'selected' : ''}>${o}</option>`).join('')}</select></label>${PF(p, 'stall_no', 'Stall no.')}
  ${isAdmin() ? `<label class="f">Handled by<select data-pf="owner_id">${B.users.filter(u => +u.active).map(u => `<option value="${u.id}" ${+u.id === +p.owner_id ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select></label>` : ''}</div>
  <h3 style="margin:18px 0 8px">Client brief for this expo</h3><div class="row">${upBtn('pbrief', p.id, 'Upload brief (PDF / image / Word / Excel / PPT)')}${fileChips(p.files.pbrief, isAdmin())}</div>
  <h3 style="margin:18px 0 8px">Floor plan</h3><div class="row">${upBtn('floor', p.id, 'Upload floor plan (JPG / PDF)')}${fileChips(p.files.floor, isAdmin())}</div>`;
PT.design = p => `<div class="grid">${PF(p, 'designed_by', 'Designed by')}${PF(p, 'assigned_on', 'Design assigned on', 'date')}${PF(p, 'design_approved_by', 'Design approved by (Zest)')}</div>
  <h3 style="margin:18px 0 8px">Corrections</h3>
  ${p.revisions.length ? `<div class="tw"><table><tr><th>#</th><th>Date</th><th>Done by</th><th>What changed</th><th>File</th><th></th></tr>${p.revisions.map((r, i) => rowOr('revision', r, 6, `<tr><td>R${i + 1}</td><td class="num">${fd(r.rdate)}</td><td>${esc(r.done_by || '')}</td><td>${esc(r.changes)}</td><td>${fileChips(p.files.revision[r.id], isAdmin())}</td><td class="r">${acts('revision', r.id)}</td></tr>`)).join('')}</table></div>` : '<div class="sub">No corrections yet.</div>'}
  <form class="inset" data-add="revision" style="margin-top:10px"><div class="grid g3"><label class="f">Date<input type="date" name="rdate" value="${B.today}" required></label><label class="f">Done by<input name="done_by"></label><label class="f">What changed *<input name="changes" required></label></div>
   <div class="row" style="margin-top:8px"><label class="up">⬆ Attach file<input type="file" name="file" multiple></label><button class="btn ghost sm">+ Add correction</button></div></form>
  <div class="grid" style="margin-top:18px"><div><h3 style="margin-bottom:8px">Final design</h3><div class="row">${upBtn('final', p.id)}${fileChips(p.files.final, isAdmin())}</div></div>
  <div><h3 style="margin-bottom:8px">Working drawing <span class="sub">(if order executed)</span></h3><div class="row">${upBtn('wd', p.id)}${fileChips(p.files.wd, isAdmin())}</div></div></div>`;
PT.order = p => { const c = calcBill(p); return `<div class="grid">${PF(p, 'offered', 'Offered price (₹)', 'number')}${PF(p, 'agreed', 'Agreed price (₹)', 'number')}
  ${PF(p, 'gst_rate', 'GST rate (%)', 'number')}
  <label class="f">GST amount (auto)<input id="calc-gst" readonly tabindex="-1" value="${inr(c.gst)}"></label>
  <label class="f">Total incl. GST (auto)<input id="calc-total" readonly tabindex="-1" value="${inr(c.total)}" style="font-weight:700"></label>
  <label class="f">GST only on part amount (₹) <span class="sub" style="font-weight:400">optional · blank = GST on full amount</span><input data-pf="gst_on" type="number" value="${num(p.gst_on)}"></label>
  ${PF(p, 'approved_by', 'Quote approved by (Zest)')}
  <label class="f">Order status<select data-pf="status">${['enquiry', 'quoted', 'won', 'rejected', 'completed'].map(s => `<option value="${s}" ${p.status === s ? 'selected' : ''}>${cap(s)}</option>`).join('')}</select></label>
  ${PF(p, 'invoice_no', 'Invoice no.')}${PF(p, 'invoice_date', 'Invoice date', 'date')}</div>
  ${p.status === 'rejected' ? `<div class="grid inset" style="margin-top:12px"><label class="f">Rejected by<select data-pf="rejected_by"><option value="">Select</option><option value="us" ${p.rejected_by === 'us' ? 'selected' : ''}>Zest (we declined)</option><option value="client" ${p.rejected_by === 'client' ? 'selected' : ''}>Client</option></select></label>
   <label class="f">Reason<select data-pf="reason"><option value="">Select reason</option>${B.reasons.map(r => `<option ${p.reason === r.label ? 'selected' : ''}>${esc(r.label)}</option>`).join('')}${p.reason && !B.reasons.some(r => r.label === p.reason) ? `<option selected>${esc(p.reason)}</option>` : ''}</select></label>
   <label class="f wide">Details<input data-pf="rejection_note" value="${esc(p.rejection_note)}"></label>
   ${p.rejected_by === 'us' && !+p.client.dnq && isAdmin() ? `<div class="sub wide">Want to stop future quotes to this client? <a href="#client-${p.client.id}">Mark them “Do not quote”</a>.</div>` : ''}</div>` : ''}
  <h3 style="margin:18px 0 8px">Quote versions sent to client</h3>
  ${p.quotes.length ? `<div class="tw"><table><tr><th>Ver.</th><th>Date</th><th class="r">Amount</th><th>Approved by</th><th>Notes</th><th>PDF</th><th></th></tr>${p.quotes.map(q => rowOr('quote', q, 7, `<tr><td>V${q.version}</td><td class="num">${fd(q.qdate)}</td><td class="r num">${inr(q.amount)}</td><td>${q.approved_by ? esc(q.approved_by) : '<span class="pill p-pending">pending</span>'}</td><td class="sub">${esc(q.notes || '')}</td><td>${fileChips(p.files.quote[q.id], isAdmin())}${upBtn('quote', q.id, 'Add')}</td><td class="r">${acts('quote', q.id)}</td></tr>`)).join('')}</table></div>` : '<div class="sub">No quote sent yet.</div>'}
  <form class="inset" data-add="quote" style="margin-top:10px"><div class="grid g3"><label class="f">Date<input type="date" name="qdate" value="${B.today}" required></label><label class="f">Amount (₹) *<input type="number" name="amount" required></label><label class="f">Approved by (Zest)<input name="approved_by"></label><label class="f">What changed<input name="notes"></label></div>
   <div class="row" style="margin-top:8px"><label class="up">⬆ Attach quote PDF<input type="file" name="file" multiple accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx"></label><button class="btn ghost sm">+ Add quote version</button></div></form>`; };
// Same maths as the server (lib/calc.php), used to update totals while typing
function calcBill(p, over = {}) {
  const v = k => (k in over ? over[k] : p[k]);
  const base = +v('agreed') || 0, ext = p.extras.reduce((s, x) => s + x.rate * x.qty, 0);
  const on = v('gst_on'), taxable = on !== null && on !== '' && on !== undefined ? +on : base + ext;
  const gst = Math.round(taxable * (+v('gst_rate') || 0)) / 100;
  return { gst, total: base + ext + gst, ext };
}
PT.vendors = p => { const min = p.vquotes.length ? Math.min(...p.vquotes.map(v => +v.amount)) : 0; return `<p class="sub" style="margin-top:0">Collect quotes from all vendors. L1 (lowest) is marked automatically; choose the vendor you finally went with.</p>
  ${p.vquotes.length ? `<div class="tw"><table><tr><th>Vendor</th><th class="r">Quote</th><th>Quality note</th><th>File</th><th>Chosen</th><th></th></tr>${p.vquotes.map(v => rowOr('vquote', v, 6, `<tr><td>${esc(v.vendor)}${+v.amount === min ? '<span class="l1">L1</span>' : ''}</td><td class="r num">${inr(v.amount)}</td><td>${esc(v.note || '')}</td><td>${fileChips(p.files.vquote[v.id], isAdmin())}${upBtn('vquote', v.id, 'Add')}</td><td><input type="radio" name="vqsel" data-vqsel="${v.id}" ${+v.selected ? 'checked' : ''} aria-label="Choose ${esc(v.vendor)}"></td><td class="r">${acts('vquote', v.id)}</td></tr>`)).join('')}</table></div>` : '<div class="sub">No vendor quotes yet.</div>'}
  <form class="inset" data-add="vquote" style="margin-top:10px"><div class="grid g3"><label class="f">Vendor *<select name="vendor_id" required>${vendorOpts()}</select></label><label class="f">Quote (₹) *<input type="number" name="amount" required></label><label class="f">Quality note<input name="note"></label></div>
   <div class="row" style="margin-top:8px"><label class="up">⬆ Attach vendor quote<input type="file" name="file" multiple></label><button class="btn ghost sm">+ Add vendor quote</button></div></form>`; };
PT.extras = p => { const tot = p.extras.reduce((s, x) => s + x.rate * x.qty, 0); return `<p class="sub" style="margin-top:0">Items taken during the expo. They are added to the bill total automatically.</p>
  ${p.extras.length ? `<div class="tw"><table><tr><th>Item</th><th class="r">Unit rate</th><th class="r">Qty</th><th class="r">Amount</th><th></th></tr>${p.extras.map(x => rowOr('extra', x, 5, `<tr><td>${esc(x.item)}</td><td class="r num">${inr(x.rate)}</td><td class="r num">${num(x.qty)}</td><td class="r num">${inr(x.rate * x.qty)}</td><td class="r">${acts('extra', x.id)}</td></tr>`)).join('')}<tr><td><b>Total extras</b></td><td></td><td></td><td class="r num"><b>${inr(tot)}</b></td><td></td></tr></table></div>` : '<div class="sub">No extras.</div>'}
  <form class="inset" data-add="extra" style="margin-top:10px"><div class="grid g3"><label class="f">Item name *<input name="item" required placeholder="e.g. Extra spotlight"></label><label class="f">Unit rate (₹) *<input type="number" name="rate" required></label><label class="f">Qty<input type="number" name="qty" value="1" step="0.01"></label></div>
   <div class="row" style="margin-top:8px"><button class="btn ghost sm">+ Add extra item</button></div></form>`; };
PT.money = p => `<h3 style="margin-bottom:8px">Received from client</h3>
  ${p.payments.length ? `<div class="tw"><table><tr><th>Date</th><th>Mode</th><th>Reference</th><th class="r">Amount</th><th></th></tr>${p.payments.map(x => rowOr('payment', x, 5, `<tr><td class="num">${fd(x.pdate)}</td><td>${esc(x.mode)}</td><td class="sub">${esc(x.reference || '')}</td><td class="r num">${inr(x.amount)}</td><td class="r">${acts('payment', x.id, canDelMoney())}</td></tr>`)).join('')}</table></div>` : '<div class="sub">Nothing received yet.</div>'}
  <form class="inset" data-add="payment" style="margin-top:8px"><div class="grid g3"><label class="f">Date *<input type="date" name="pdate" value="${B.today}" required></label><label class="f">Mode<select name="mode"><option>Bank</option><option>Cash</option><option>UPI</option><option>Cheque</option></select></label><label class="f">Reference / UTR<input name="reference"></label><label class="f">Amount (₹) *<input type="number" name="amount" required step="0.01"></label></div><div class="row" style="margin-top:8px"><button class="btn ghost sm">+ Add client payment</button></div></form>
  <h3 style="margin:22px 0 8px">Paid to vendors</h3>
  ${p.vpayments.length ? `<div class="tw"><table><tr><th>Date</th><th>Vendor</th><th>Mode</th><th class="r">Amount</th><th></th></tr>${p.vpayments.map(x => rowOr('vpayment', x, 5, `<tr><td class="num">${fd(x.pdate)}</td><td>${esc(x.vendor)}</td><td>${esc(x.mode)}</td><td class="r num">${inr(x.amount)}</td><td class="r">${acts('vpayment', x.id, canDelMoney())}</td></tr>`)).join('')}</table></div>` : '<div class="sub">No vendor payments yet.</div>'}
  <form class="inset" data-add="vpayment" style="margin-top:8px"><div class="grid g3"><label class="f">Date *<input type="date" name="pdate" value="${B.today}" required></label><label class="f">Vendor *<select name="vendor_id" required>${vendorOpts()}</select></label><label class="f">Mode<select name="mode"><option>Bank</option><option>Cash</option><option>UPI</option><option>Cheque</option></select></label><label class="f">Amount (₹) *<input type="number" name="amount" required step="0.01"></label></div><div class="row" style="margin-top:8px"><button class="btn ghost sm">+ Add vendor payment</button></div></form>
  <h3 style="margin:22px 0 8px">Project expenses <span class="sub">(flights, hotel, commute…)</span></h3>
  ${p.expenses.length ? `<div class="tw"><table><tr><th>Date</th><th>Type</th><th>Details</th><th class="r">Amount</th><th></th></tr>${p.expenses.map(x => rowOr('expense', x, 5, `<tr><td class="num">${fd(x.edate)}</td><td>${esc(x.category)}</td><td>${esc(x.description || '')}</td><td class="r num">${inr(x.amount)}</td><td class="r">${acts('expense', x.id)}</td></tr>`)).join('')}</table></div>` : '<div class="sub">No expenses.</div>'}
  <form class="inset" data-add="expense" style="margin-top:8px"><div class="grid g3"><label class="f">Date *<input type="date" name="edate" value="${B.today}" required></label><label class="f">Type *<select name="category"><option>Flight</option><option>Train</option><option>Hotel</option><option>Commute</option><option>Food</option><option>Courier</option><option>Other</option></select></label><label class="f">Details<input name="description"></label><label class="f">Amount (₹) *<input type="number" name="amount" required step="0.01"></label></div><div class="row" style="margin-top:8px"><button class="btn ghost sm">+ Add expense</button></div></form>
  <h3 style="margin:22px 0 8px">Losses</h3>
  ${p.losses.length ? `<div class="tw"><table><tr><th>Date</th><th>Type</th><th>Note</th><th class="r">Amount</th><th></th></tr>${p.losses.map(x => rowOr('loss', x, 5, `<tr><td class="num">${fd(x.ldate)}</td><td>${esc(x.ltype)}</td><td>${esc(x.note || '')}</td><td class="r num">${inr(x.amount)}</td><td class="r">${acts('loss', x.id)}</td></tr>`)).join('')}</table></div>` : '<div class="sub">No losses recorded.</div>'}
  <form class="inset" data-add="loss" style="margin-top:8px"><div class="grid g3"><label class="f">Date *<input type="date" name="ldate" value="${B.today}" required></label><label class="f">Type *<select name="ltype"><option>Client paid less</option><option>Vendor overpaid by mistake</option><option>Damage</option><option>Other</option></select></label><label class="f">Note<input name="note"></label><label class="f">Amount (₹) *<input type="number" name="amount" required step="0.01"></label></div><div class="row" style="margin-top:8px"><button class="btn ghost sm">+ Add loss</button></div></form>
  ${p.history && p.history.length ? `<details class="inset" style="margin-top:22px"><summary><b>Change history</b> <span class="sub">(${p.history.length} correction${p.history.length === 1 ? '' : 's'})</span></summary><div class="tw" style="margin-top:8px"><table><tr><th>When</th><th>By</th><th>Entry</th><th>Change</th></tr>${p.history.map(h => { const b = JSON.parse(h.before_json || '{}'), a = JSON.parse(h.after_json || 'null'); const fmt = (k, v) => /amount|rate/.test(k) ? inr(v) : esc(v); return `<tr><td class="num">${fd(h.at)} ${h.at.slice(11, 16)}</td><td>${esc(h.by)}</td><td>${esc(KIND_NAMES[h.kind] || h.kind)}</td><td>${h.action === 'delete' ? `<span class="dnq">Deleted</span> ${b.amount ? inr(b.amount) : esc(b.item || b.changes || '')}` : Object.keys(a || {}).map(k => `${esc(k.replace('_id', '').replace('_', ' '))}: <s>${fmt(k, b[k])}</s> → <b>${fmt(k, a[k])}</b>`).join('<br>')}</td></tr>`; }).join('')}</table></div></details>` : ''}`;
const KIND_NAMES = { revision: 'Design correction', quote: 'Quote version', vquote: 'Vendor quote', extra: 'Extra item', payment: 'Client payment', vpayment: 'Vendor payment', expense: 'Expense', loss: 'Loss' };

// ---------- DAILY REPORT ----------
function fieldInput(f, i, v) {
  const a = `data-ri="${i}" data-k="${esc(f.k)}"`;
  if (f.t === 'long') return `<label class="f wide">${esc(f.l)}<textarea ${a}>${esc(v)}</textarea></label>`;
  if (f.t === 'select') return `<label class="f">${esc(f.l)}<select ${a}><option value="">Select</option>${(f.o || []).map(o => `<option ${v === o ? 'selected' : ''}>${esc(o)}</option>`).join('')}</select></label>`;
  if (f.t === 'file') return '';
  return `<label class="f">${esc(f.l)}${f.req ? ' *' : ''}<input ${f.t === 'number' ? 'type="number" step="any"' : f.t === 'date' ? 'type="date"' : ''} ${a} value="${esc(v)}"></label>`;
}
function reportTable(items, F) {
  return `<div class="tw"><table><tr><th>Sl</th>${F.map(f => `<th>${esc(f.l)}</th>`).join('')}</tr>
  ${items.map((x, i) => `<tr><td>${i + 1}</td>${F.map((f, j) => f.t === 'file' ? `<td>${x._files && x._files.length ? fileChips(x._files) : '—'}</td>` : `<td class="${f.t === 'number' || f.t === 'date' ? 'num' : ''}">${j === 0 ? '<b>' : ''}${f.t === 'date' ? (x[f.k] ? fd(x[f.k]) : '') : esc(x[f.k] ?? '')}${j === 0 ? '</b>' : ''}</td>`).join('')}</tr>`).join('')}</table></div>`;
}
routes.report = async () => {
  const d = await api('report_mine'); B.flags = d.missing.length; renderNav('report');
  const F = d.template, ff = F.find(f => f.t === 'file');
  const done = d.reports.find(r => r.rdate === d.today);
  if (!draft || draft.user !== B.me.id) draft = { user: B.me.id, items: [{}], files: [[]] };
  view().innerHTML = `
  <div class="head"><div><h1>Daily report</h1><p>${longDate(d.today)} · ${esc(B.me.department)} format · Once submitted, it cannot be edited.</p></div></div>
  ${d.missing.length ? `<div class="alert bad"><b>Report not submitted</b><span class="m">${d.missing.map(fd).join(', ')}. Admin can see this.</span></div>` : ''}
  ${done ? `<div class="panel"><div class="ph"><h2>Today's report</h2><span class="lock">🔒 Submitted at ${done.submitted_at.slice(11, 16)} · locked</span></div>${reportTable(done.items, done.fields)}</div>`
  : `<div class="panel"><h2>Today's activity</h2>${!d.today_working ? '<div class="alert warn" style="margin-bottom:10px"><span class="m">Today is a holiday / week off. Submitting is optional.</span></div>' : ''}
   ${draft.items.map((it, i) => `<div class="inset" style="margin-bottom:10px"><div class="ph" style="margin-bottom:8px"><b>Entry ${i + 1}</b>${draft.items.length > 1 ? `<button class="btn link" data-rmitem="${i}">Remove</button>` : ''}</div>
    <div class="grid g3">${F.map(f => fieldInput(f, i, it[f.k])).join('')}</div>
    ${ff ? `<div class="row" style="margin-top:8px"><label class="up">⬆ ${esc(ff.l)}<input type="file" multiple data-rfile="${i}"></label>${draft.files[i].map(f => `<span class="chip">📎 <span>${esc(f.name)}</span></span>`).join('')}</div>` : ''}</div>`).join('')}
   <div class="row"><button class="btn ghost" id="ri-add">+ Add another entry</button><button class="btn" id="ri-submit">Submit report</button></div>
   <div id="ri-confirm"></div></div>`}
  <div class="panel"><h2>My past reports</h2>${d.reports.filter(r => r !== done).length ? `<div class="log">${d.reports.filter(r => r !== done).map(r => `<details><summary><b class="num">${fd(r.rdate)}</b><span class="sub">${r.items.length} entr${r.items.length === 1 ? 'y' : 'ies'} · submitted ${r.submitted_at.slice(11, 16)}</span><span class="lock" style="margin-left:auto">🔒 locked</span></summary><div class="in">${reportTable(r.items, r.fields)}</div></details>`).join('')}</div>` : '<div class="empty">No past reports yet.</div>'}</div>`;
  C.tpl = F;
};

routes.reportlog = async () => {
  const f = C.log || (C.log = { from: '', to: '', user_id: '' });
  const d = await api('report_log', f);
  const emps = B.users.filter(u => u.role === 'employee' && +u.active);
  view().innerHTML = `
  <div class="head"><div><h1>Daily reports</h1><p>${fd(d.from)} to ${fd(d.to)} · click a name to open the report</p></div>
   <form class="row" id="logf"><label class="f">Employee<select name="user_id"><option value="">All employees</option>${emps.map(e => `<option value="${e.id}" ${+f.user_id === +e.id ? 'selected' : ''}>${esc(e.name)}</option>`).join('')}</select></label><label class="f">From<input type="date" name="from" value="${d.from}"></label><label class="f">To<input type="date" name="to" value="${d.to}"></label><button class="btn ghost sm" style="align-self:flex-end">Show</button></form></div>
  <div class="log">${d.days.map(day => `<h3 class="num">${fd(day.date)} ${day.off ? `<span class="pill p-off">${esc(day.off)}</span>` : ''}</h3>
   ${day.rows.length ? day.rows.map(r => r.status === 'received'
     ? `<details><summary><b>${esc(r.user.name)}</b><span class="sub">${esc(r.user.department)}</span><span class="pill p-received">received ${r.at}</span><span class="sub" style="margin-left:auto">${r.items.length} entr${r.items.length === 1 ? 'y' : 'ies'}</span></summary><div class="in">${reportTable(r.items, r.fields)}</div></details>`
     : `<details><summary><b>${esc(r.user.name)}</b><span class="sub">${esc(r.user.department)}</span><span class="pill p-${r.status}">${r.status === 'pending' ? 'pending today' : 'report not received'}</span></summary></details>`).join('')
     : `<div class="sub">${day.off ? 'No report expected.' : 'No employees to track on this day.'}</div>`}`).join('')}</div>`;
  $('#logf').onsubmit = e => { e.preventDefault(); const fm = new FormData(e.target); C.log = { from: fm.get('from'), to: fm.get('to'), user_id: fm.get('user_id') }; refresh(); };
};

// ---------- CLIENT LOG (admin) ----------
routes.clientlog = async () => {
  const f = C.clog || (C.clog = { q: '', user_id: '', status: '', from: '', to: '' });
  const d = await api('client_log', f);
  const sales = B.users.filter(u => u.role === 'employee');
  const T = d.clients.reduce((a, c) => (a.b += c.business, a.r += c.received, a.d += c.due, a.m += c.missed, a.p += c.profit, a), { b: 0, r: 0, d: 0, m: 0, p: 0 });
  const qs = new URLSearchParams(Object.entries(f).filter(([, v]) => v)).toString();
  view().innerHTML = `
  <div class="head"><div><h1>Client log</h1><p>Every client and order with its full history. Click a client to see everything.</p></div>
   <a class="btn" href="export.php${qs ? '?' + qs : ''}">⬇ Export to Excel</a></div>
  <form class="panel row" id="clogf" style="align-items:flex-end">
   <label class="f" style="flex:1;min-width:180px">Search client / expo<input name="q" value="${esc(f.q)}"></label>
   <label class="f">Handled by<select name="user_id"><option value="">Everyone</option>${sales.map(u => `<option value="${u.id}" ${+f.user_id === +u.id ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select></label>
   <label class="f">Status<select name="status"><option value="">All</option>${['enquiry', 'quoted', 'won', 'rejected', 'completed'].map(s => `<option value="${s}" ${f.status === s ? 'selected' : ''}>${cap(s)}</option>`).join('')}</select></label>
   <label class="f">Expo from<input type="date" name="from" value="${f.from}"></label><label class="f">to<input type="date" name="to" value="${f.to}"></label>
   <button class="btn ghost">Apply</button>${Object.values(f).some(Boolean) ? '<button type="button" class="btn link" id="clogclear">Clear</button>' : ''}</form>
  <div class="kpis"><div class="kpi"><span>Clients</span><b>${d.clients.length}</b><small>${d.orders_n} orders</small></div><div class="kpi hl"><span>Business won</span><b>${inr(T.b)}</b></div><div class="kpi"><span>Received</span><b>${inr(T.r)}</b></div><div class="kpi"><span>Outstanding</span><b>${inr(T.d)}</b></div><div class="kpi"><span>Missed</span><b>${inr(T.m)}</b></div><div class="kpi"><span>Profit</span><b>${inr(T.p)}</b></div></div>
  <div class="log">${d.clients.length ? d.clients.map(c => `<details data-clog="${c.id}"><summary><b>${esc(c.company)}</b>${c.dnq ? '<span class="dnq">⛔ DO NOT QUOTE</span>' : ''}<span class="sub">${esc(c.city || '')} · ${esc(c.handlers.join(', '))}</span>
     <span style="margin-left:auto" class="row"><span class="sub">${c.won}/${c.orders.length} won</span><span class="num">${inr(c.business)}</span>${c.due > 0.5 ? `<span class="pill p-quoted">${inr(c.due)} due</span>` : c.business ? '<span class="pill p-ok">paid</span>' : ''}</span></summary>
     <div class="in">${c.orders.length ? `<div class="tw"><table><tr><th>Expo</th><th>Date</th><th>Area · type · stall</th><th>Status</th><th>Sales</th><th>Designer</th><th>Fabricator</th><th class="r">Bill</th><th class="r">Received</th><th class="r">Balance</th><th class="r">Profit</th></tr>
      ${c.orders.map(o => `<tr class="click" data-go="project-${o.id}"><td><b>${esc(o.expo)}</b><div class="sub">${esc(o.location || '')}</div></td><td class="num">${fd(o.start_date)}</td><td>${o.area_sqm ? num(o.area_sqm) + ' sqm' : '—'}${o.space_type ? ' · ' + esc(o.space_type) : ''}${o.stall_no ? ' · ' + esc(o.stall_no) : ''}</td><td>${pill(o.status)}${o.reason ? `<div class="sub">${esc(o.reason)}</div>` : ''}</td><td>${esc(o.owner)}</td><td>${esc(o.designed_by || '—')}</td><td>${esc(o.fabricator || '—')}</td><td class="r num">${o.total ? inr(o.total) : '—'}</td><td class="r num">${inr(o.received)}</td><td class="r num">${o.total ? inr(o.balance) : '—'}</td><td class="r num">${o.total ? inr(o.profit) : '—'}</td></tr>`).join('')}</table></div>` : '<div class="sub">No orders yet.</div>'}
     <div class="full" data-full="${c.id}"><div class="loading">Loading full history…</div></div></div></details>`).join('') : blank('No clients match these filters.')}</div>`;
  $('#clogf').onsubmit = e => { e.preventDefault(); C.clog = Object.fromEntries(new FormData(e.target)); refresh(); };
  if ($('#clogclear')) $('#clogclear').onclick = () => { C.clog = null; refresh(); };
  document.querySelectorAll('details[data-clog]').forEach(el => el.addEventListener('toggle', () => { if (el.open && !el.dataset.loaded) { el.dataset.loaded = 1; loadFull(el.dataset.clog); } }));
};
async function loadFull(id) {
  const box = document.querySelector(`[data-full="${id}"]`);
  try {
    const { client: c } = await api('client_full', { id });
    const tag = { 'Payment in': 'p-ok', 'Vendor paid': 'p-quoted', 'Loss': 'p-bad', 'Expense': 'p-off', 'Do not quote': 'p-bad', 'Follow-up': 'p-enquiry' };
    box.innerHTML = `<div class="grid" style="margin-top:14px">
     <div class="inset"><h3 style="margin-bottom:6px">Client</h3><div>${esc(c.person || '—')}${c.designation ? ' · ' + esc(c.designation) : ''}</div><div class="sub">${esc(c.email || '')} ${esc(c.phone || '')}</div><div class="sub">${esc(c.location || '')}</div>${c.gst_no ? `<div class="sub">GSTIN ${esc(c.gst_no)}</div>` : ''}
      <div class="sub" style="margin-top:6px">Handled by <b>${esc(c.owner)}</b> · added ${fd(c.created_at)} by ${esc(c.created_by_name)}</div>
      ${c.contacts.length ? `<div style="margin-top:8px"><b class="sub">Others in the deal</b>${c.contacts.map(o => `<div class="sub">${esc(o.name)}${o.designation ? ' · ' + esc(o.designation) : ''} ${esc(o.phone || '')}</div>`).join('')}</div>` : ''}
      <div style="margin-top:8px">${c.briefs.length ? '<b class="sub">Briefs</b>' + fileChips(c.briefs) : ''}${c.cards.length ? '<b class="sub">Business cards</b>' + fileChips(c.cards) : ''}</div></div>
     <div class="inset"><h3 style="margin-bottom:6px">Who followed up</h3>${c.followups.length ? `<div class="tw"><table>${c.followups.slice(0, 15).map(x => `<tr><td class="num">${fd(x.date)}</td><td><b>${esc(x.by)}</b><div class="sub">${esc(x.dept)}</div></td><td class="sub">${esc(x.what)}</td></tr>`).join('')}</table></div>` : '<div class="sub">No daily-report entries mention this client.</div>'}</div></div>
    ${c.orders.map(o => { const b = o.bill; return `<div class="inset" style="margin-top:12px"><div class="ph"><h3>${esc(o.expo)}${o.location ? ', ' + esc(o.location) : ''} ${pill(o.status)}</h3><a class="btn ghost sm" href="#project-${o.id}">Open order</a></div>
      <div class="grid g3 sub">
       <div><b>Expo</b><br>${fd(o.start_date)} – ${fd(o.end_date)}<br>${o.area_sqm ? num(o.area_sqm) + ' sqm' : '—'} · ${esc(o.space_type || '—')} · Stall ${esc(o.stall_no || '—')}</div>
       <div><b>People</b><br>Sales: ${esc(o.owner)}<br>Designer: ${esc(o.designed_by || '—')} (${o.revisions.length} correction${o.revisions.length === 1 ? '' : 's'})<br>Design approved: ${esc(o.design_approved_by || '—')}</div>
       <div><b>Fabrication</b><br>Vendor: ${esc(o.fabricator || '—')}<br>Vendor quotes: ${o.vquotes.length ? o.vquotes.map(v => `${esc(v.vendor)} ${inr(v.amount)}${+v.selected ? ' ✓' : ''}`).join(', ') : '—'}</div>
       <div><b>Price</b><br>Offered ${inr(o.offered)} · Agreed ${inr(o.agreed)}<br>Quotes: ${o.quotes.map(q => `V${q.version} ${inr(q.amount)}`).join(', ') || '—'}<br>Approved by: ${esc(o.approved_by || '—')}</div>
       <div><b>Money</b><br>Bill ${inr(b.total)} (GST ${inr(b.gst)}, extras ${inr(b.extras)})<br>Received ${inr(b.received)} · <span style="color:${b.balance > 0.5 ? 'var(--bad)' : 'var(--ok)'}">Balance ${inr(b.balance)}</span></div>
       <div><b>Costs & profit</b><br>Vendor ${inr(b.vendor_paid)} · Expenses ${inr(b.expenses)} · Losses ${inr(b.losses)}<br>Profit <b>${inr(b.profit)}</b></div>
      </div>${o.status === 'rejected' ? `<div class="sub" style="margin-top:6px"><b>Rejected by ${o.rejected_by === 'us' ? 'Zest' : 'client'}:</b> ${esc(o.reason || '')} ${esc(o.rejection_note || '')}</div>` : ''}
      <div class="row" style="margin-top:8px">${['pbrief', 'floor', 'final', 'wd'].filter(k => o.files[k].length).map(k => `<span class="sub">${{ pbrief: 'Brief', floor: 'Floor plan', final: 'Final design', wd: 'Working drawing' }[k]}:</span>${fileChips(o.files[k])}`).join('')}</div></div>`; }).join('')}
    <div class="inset" style="margin-top:12px"><h3 style="margin-bottom:6px">Full timeline</h3>${c.timeline.length ? `<div class="tw"><table>${c.timeline.map(x => `<tr><td class="num" style="white-space:nowrap">${fd(x.date)}</td><td><span class="pill ${tag[x.type] || 'p-off'}">${esc(x.type)}</span></td><td>${esc(x.text)}</td></tr>`).join('')}</table></div>` : '<div class="sub">Nothing yet.</div>'}</div>`;
  } catch (e) { box.innerHTML = `<div class="alert bad"><span class="m">${esc(e.message)}</span></div>`; }
}

// ---------- TEAM ----------
routes.team = async () => {
  const d = await api('users'); const ed = C.editUser ? d.users.find(u => +u.id === +C.editUser) : null;
  const deptOpts = sel => B.departments.map(x => `<option ${x === sel ? 'selected' : ''}>${esc(x)}</option>`).join('');
  view().innerHTML = `
  <div class="head"><div><h1>Team</h1><p>Only admin sets role and department. The department decides the daily report format.</p></div></div>
  <div class="panel"><div class="tw"><table><tr><th>Name</th><th>Login email</th><th>Designation</th><th>Department</th><th>Access</th><th>Joined</th><th>Status</th><th></th></tr>
   ${d.users.map(u => `<tr><td><b>${esc(u.name)}</b><div class="sub">${esc(u.phone || '')}</div></td><td>${esc(u.email)}</td><td>${esc(u.designation || '')}</td><td>${esc(u.department)}</td><td>${u.role === 'admin' ? '<span class="pill p-enquiry">admin</span>' : B.record_depts.includes(u.department) ? 'Clients + orders + report' : 'Daily report only'}</td><td class="num">${fd(u.joined_on)}</td><td>${+u.active ? '<span class="pill p-ok">active</span>' : '<span class="pill p-off">disabled</span>'}</td><td class="r"><button class="btn ghost sm" data-edituser="${u.id}">Edit</button></td></tr>`).join('')}</table></div></div>
  <div class="panel"><h2>Who can add clients & orders</h2><p class="sub" style="margin-top:0">Ticked departments get Clients, New client and full expo orders (design, quotes, vendor quotes, extras, payments). Others only see their daily report.</p>
   <div class="row">${B.departments.map(x => `<label class="row" style="gap:5px;margin-right:10px"><input type="checkbox" data-recdept="${esc(x)}" ${B.record_depts.includes(x) ? 'checked' : ''}>${esc(x)}</label>`).join('')}</div></div>
  <form class="panel" id="uform"><h2>${ed ? 'Edit ' + esc(ed.name) : 'Create employee login'}</h2>
   <div class="grid"><label class="f">Full name *<input name="name" required value="${esc(ed?.name)}"></label>
    <label class="f">Email (login ID) *<input name="email" type="email" ${ed ? 'disabled' : 'required'} value="${esc(ed?.email)}"></label>
    <label class="f">Designation<input name="designation" placeholder="e.g. Sales Executive" value="${esc(ed?.designation)}"></label>
    <label class="f">Department *<select name="department">${deptOpts(ed?.department)}</select></label>
    <label class="f">Phone<input name="phone" value="${esc(ed?.phone)}"></label>
    <label class="f">Access<select name="role"><option value="employee">Employee</option><option value="admin" ${ed?.role === 'admin' ? 'selected' : ''}>Admin (sees everything)</option></select></label>
    ${ed ? '' : `<label class="f">Joining date<input type="date" name="joined_on" value="${B.today}"></label>`}
    <label class="f">${ed ? 'New password (leave blank to keep)' : 'Password * (min 8)'}<input name="password" type="text" autocomplete="new-password" ${ed ? '' : 'required minlength="8"'}></label>
    ${ed ? `<label class="f">Status<select name="active"><option value="1">Active</option><option value="0" ${+ed.active ? '' : 'selected'}>Disabled (cannot sign in)</option></select></label>` : ''}</div>
   <div class="row" style="margin-top:12px"><button class="btn">${ed ? 'Save changes' : 'Create login'}</button>${ed ? '<button type="button" class="btn ghost" id="ucancel">Cancel</button>' : ''}</div>
   <p class="sub">Share the email and password with the employee. They can change the password from Account.</p></form>`;
  $('#uform').onsubmit = async e => {
    e.preventDefault(); const o = Object.fromEntries(new FormData(e.target)); if (ed) { o.id = ed.id; o.active = o.active === '1'; }
    await act(() => api('user_save', o), ed ? 'Saved' : 'Login created'); C.editUser = null; await reboot(); refresh();
  };
  if ($('#ucancel')) $('#ucancel').onclick = () => { C.editUser = null; refresh(); };
};

// ---------- REPORT FORMATS ----------
routes.formats = async () => {
  if (!C.tpls) C.tpls = JSON.parse(JSON.stringify(B.templates));
  const dept = C.fmDept && B.departments.includes(C.fmDept) ? C.fmDept : B.departments[0];
  C.fmDept = dept;
  const F = C.tpls[dept] || (C.tpls[dept] = [{ k: 'company', l: 'Company / client', t: 'text', req: 1 }, { k: 'brief', l: 'Work done', t: 'long' }]);
  const types = { text: 'Short text', long: 'Long text', number: 'Number', date: 'Date', select: 'Dropdown', file: 'File upload' };
  const dirty = JSON.stringify(C.tpls) !== JSON.stringify(B.templates);
  view().innerHTML = `
  <div class="head"><div><h1>Report formats</h1><p>Each department fills a different daily report. Saved changes apply from the next report.</p></div>
   <div class="row"><label class="f" style="min-width:200px">Department<select id="fm-dept">${B.departments.map(x => `<option ${x === dept ? 'selected' : ''}>${esc(x)}</option>`).join('')}</select></label></div></div>
  ${dirty ? '<div class="alert warn"><b>You have unsaved changes</b><div class="row"><button class="btn sm" id="fm-save">Save all formats</button><button class="btn ghost sm" id="fm-reset">Discard</button></div></div>' : ''}
  <div class="panel"><h2>${esc(dept)} — daily report fields</h2><div class="tw"><table><tr><th>#</th><th>Field</th><th>Type</th><th>Choices</th><th></th></tr>
   ${F.map((f, i) => `<tr><td>${i + 1}</td><td><b>${esc(f.l)}</b>${i === 0 ? ' <span class="sub">(required)</span>' : ''}</td><td>${types[f.t]}</td><td class="sub">${f.o ? esc(f.o.join(', ')) : ''}</td><td class="r">${i > 1 ? `<button class="btn link" data-fmup="${i}" title="Move up">↑</button>` : ''}${i > 0 ? `<button class="btn link" data-fmdel="${i}">Remove</button>` : ''}</td></tr>`).join('')}</table></div>
   <form class="grid g3 inset" id="fm-add" style="margin-top:12px"><label class="f">New field name<input name="l" required placeholder="e.g. Site visit done?"></label><label class="f">Type<select name="t">${Object.entries(types).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}</select></label><label class="f">Choices (dropdown only, comma separated)<input name="o" placeholder="Yes, No"></label><div style="align-self:end"><button class="btn ghost sm">+ Add field</button></div></form></div>
  <div class="panel"><h2>Preview — what ${esc(dept)} staff will see</h2><div class="grid g3">${F.map(f => fieldInput(f, 'pv', '')).join('')}</div>${F.find(f => f.t === 'file') ? `<div style="margin-top:8px"><span class="up">⬆ ${esc(F.find(f => f.t === 'file').l)}</span></div>` : ''}</div>
  <div class="panel"><h2>Departments</h2><div class="row">${B.departments.map(x => `<span class="chip"><span>${esc(x)}</span></span>`).join('')}</div>
   <form class="row" id="dep-add" style="margin-top:10px"><input name="d" placeholder="New department, e.g. Production" style="max-width:300px" required><button class="btn ghost sm">+ Add department</button></form></div>`;
  $('#fm-add').onsubmit = e => {
    e.preventDefault(); const o = Object.fromEntries(new FormData(e.target)); const l = o.l.trim(); if (!l) return;
    if (o.t === 'file' && F.some(f => f.t === 'file')) return toast('This format already has a file upload', true);
    const fld = { k: 'f' + Date.now().toString(36), l, t: o.t };
    if (o.t === 'select') { fld.o = o.o.split(',').map(x => x.trim()).filter(Boolean); if (!fld.o.length) return toast('Add the dropdown choices', true); }
    const fi = F.findIndex(f => f.t === 'file'); fi < 0 ? F.push(fld) : F.splice(fi, 0, fld); refresh();
  };
  $('#dep-add').onsubmit = async e => { e.preventDefault(); const v = new FormData(e.target).get('d').trim(); if (!v || B.departments.includes(v)) return; await act(() => api('settings_save', { departments: [...B.departments, v] }), 'Department added'); await reboot(); C.fmDept = v; refresh(); };
  if ($('#fm-save')) $('#fm-save').onclick = async () => { await act(() => api('settings_save', { templates: C.tpls }), 'Report formats saved'); await reboot(); C.tpls = null; refresh(); };
  if ($('#fm-reset')) $('#fm-reset').onclick = () => { C.tpls = null; refresh(); };
};

// ---------- SETTINGS ----------
routes.settings = async () => {
  const d = await api('settings');
  view().innerHTML = `
  <div class="head"><div><h1>Holidays & lists</h1><p>Reports are not expected on week-offs and declared holidays.</p></div></div>
  <div class="grid">
   <div class="panel"><h2>Weekly off</h2><div class="row">${['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map((x, i) => `<label class="row" style="gap:4px"><input type="checkbox" data-woff="${i}" ${B.weekly_off.includes(i) ? 'checked' : ''}>${x}</label>`).join('')}</div>
    <h3 style="margin:16px 0 6px">Start tracking reports from</h3><div class="row"><input type="date" id="trackf" value="${esc(d.track_from)}" style="max-width:200px"><button class="btn ghost sm" id="trackf-save">Save</button></div></div>
   <div class="panel"><h2>Declared holidays</h2>${d.holidays.length ? `<div class="tw"><table>${d.holidays.map(h => `<tr><td class="num">${fd(h.hdate)}</td><td>${esc(h.reason)}</td><td class="r"><button class="btn link" data-delhol="${h.id}">✕</button></td></tr>`).join('')}</table></div>` : '<div class="sub">None yet.</div>'}
    <form class="grid g3" id="hol-add" style="margin-top:10px"><label class="f">Date<input type="date" name="hdate" required></label><label class="f">Reason<input name="reason" required placeholder="e.g. Diwali"></label><div style="align-self:end"><button class="btn ghost sm">+ Add holiday</button></div></form></div>
  </div>
  <div class="grid">
   <div class="panel"><h2>Rejection reasons</h2>${B.reasons.map(r => `<div class="row" style="justify-content:space-between;padding:3px 0"><span>${esc(r.label)}</span><button class="btn link" data-delreason="${r.id}">✕</button></div>`).join('')}
    <form class="row" id="rs-add" style="margin-top:8px"><input name="label" placeholder="New reason" required style="flex:1"><button class="btn ghost sm">Add</button></form></div>
   <div class="panel"><h2>Vendors</h2>${B.vendors.length ? `<div class="tw"><table><tr><th>Vendor</th><th>Phone</th><th>City</th><th>Email</th><th></th></tr>${B.vendors.map(v => `<tr><td><b>${esc(v.name)}</b></td><td class="num">${esc(v.phone || '')}</td><td>${esc(v.city || '')}</td><td>${esc(v.email || '')}</td><td class="r"><button class="btn link" data-delvendor="${v.id}">✕</button></td></tr>`).join('')}</table></div>` : '<div class="sub">No vendors yet.</div>'}
    <form class="grid g3 inset" id="vd-add" style="margin-top:10px"><label class="f">Vendor name *<input name="name" required></label><label class="f">Phone *<input name="phone" type="tel" required></label><label class="f">City *<input name="city" required></label><label class="f">Email<input name="email" type="email"></label><div style="align-self:end"><button class="btn ghost sm">+ Add vendor</button></div></form></div>
  </div>`;
  const sub = (id, a, msg) => { $(id).onsubmit = async e => { e.preventDefault(); await act(() => api(a, Object.fromEntries(new FormData(e.target))), msg); await reboot(); refresh(); }; };
  sub('#hol-add', 'holiday_add', 'Holiday added'); sub('#rs-add', 'reason_add', 'Reason added'); sub('#vd-add', 'vendor_add', 'Vendor added');
  $('#trackf-save').onclick = async () => { await act(() => api('settings_save', { track_from: $('#trackf').value }), 'Saved'); };
};

// ---------- ACCOUNT ----------
routes.account = async () => {
  view().innerHTML = `<div class="head"><div><h1>Account</h1><p>${esc(B.me.name)} · ${esc(B.me.email)} · ${esc(B.me.department)}</p></div></div>
  <form class="panel" id="pw" style="max-width:520px"><h2>Change password</h2><div class="grid"><label class="f">Current password<input type="password" name="old" required autocomplete="current-password"></label><label class="f">New password (min 8)<input type="password" name="new" required minlength="8" autocomplete="new-password"></label></div><div style="margin-top:12px"><button class="btn">Update password</button></div></form>`;
  $('#pw').onsubmit = async e => { e.preventDefault(); await act(() => api('password', Object.fromEntries(new FormData(e.target))), 'Password updated'); e.target.reset(); };
};

// ---------- EVENTS ----------
document.addEventListener('click', async e => {
  const t = e.target.closest('[data-go],[data-editchild],[data-canceledit],[data-delchild],[data-delfile],[data-delcontact],[data-rmitem],[data-edituser],[data-fmdel],[data-fmup],[data-delhol],[data-delreason],[data-delvendor],button[id]');
  if (!t) return;
  const ds = t.dataset;
  try {
    if (ds.go) { if (e.target.closest('a,button,input,select,label')) return; location.hash = '#' + ds.go; return; }
    if (ds.editchild) { C.editing = ds.editchild; await refresh(); const f = document.querySelector(`form[data-edit="${ds.editchild}"] input, form[data-edit="${ds.editchild}"] select`); if (f) f.focus(); return; }
    if (ds.canceledit) { C.editing = null; return refresh(); }
    if (ds.delchild) { const [kind, id] = ds.delchild.split(':'); if (!confirm('Delete this entry?')) return; await act(() => api('child_del', { kind, id }), 'Deleted'); return refresh(); }
    if (ds.delfile) { if (!confirm('Remove this file?')) return; await act(() => api('file_del', { id: ds.delfile }), 'File removed'); return refresh(); }
    if (ds.delcontact) { if (!confirm('Remove this person?')) return; await act(() => api('contact_del', { id: ds.delcontact })); return refresh(); }
    if (ds.rmitem !== undefined) { draft.items.splice(+ds.rmitem, 1); draft.files.splice(+ds.rmitem, 1); return refresh(); }
    if (ds.edituser) { C.editUser = +ds.edituser; await refresh(); $('#uform').scrollIntoView({ behavior: 'smooth' }); return; }
    if (ds.fmdel) { C.tpls[C.fmDept].splice(+ds.fmdel, 1); return refresh(); }
    if (ds.fmup) { const F = C.tpls[C.fmDept], i = +ds.fmup; [F[i - 1], F[i]] = [F[i], F[i - 1]]; return refresh(); }
    if (ds.delhol) { await act(() => api('holiday_del', { id: ds.delhol })); return refresh(); }
    if (ds.delreason) { if (!confirm('Remove this reason from the list?')) return; await act(() => api('reason_del', { id: ds.delreason })); await reboot(); return refresh(); }
    if (ds.delvendor) { if (!confirm('Remove this vendor from the list? Past records stay.')) return; await act(() => api('vendor_del', { id: ds.delvendor })); await reboot(); return refresh(); }
    switch (t.id) {
      case 'nc-addc': newClient.contacts.push({ name: '', designation: '', phone: '', email: '' }); return refresh();
      case 'nc-save': {
        const n = newClient; if (!n.company.trim()) return toast('Enter the company name', true);
        if (Object.values(n.expo).some(v => String(v).trim()) && !n.expo.expo.trim()) return toast('Enter the expo name', true);
        t.disabled = true;
        const r = await act(() => api('client_save', { ...n, cards: undefined, briefs: undefined })).catch(() => null);
        if (!r) { t.disabled = false; return; }
        if (n.cards.length) await act(() => uploadAll('card', r.id, n.cards)).catch(() => {});
        if (n.briefs.length) await act(() => uploadAll('brief', r.id, n.briefs)).catch(() => {});
        newClient = null; toast(n.expo.expo.trim() ? 'Client and expo order saved' : 'Client saved'); location.hash = '#client-' + r.id; return;
      }
      case 'dnq-open': $('#dnq-form').innerHTML = `<form class="panel" id="dnqf"><h3>Mark as “Do not quote”</h3><div class="grid"><label class="f">Reason<select name="reason" required>${B.reasons.map(r => `<option>${esc(r.label)}</option>`).join('')}</select></label><label class="f">What happened?<input name="note"></label></div><div class="row" style="margin-top:8px"><button class="btn danger">Confirm flag</button></div></form>`;
        $('#dnqf').onsubmit = async ev => { ev.preventDefault(); await act(() => api('dnq', { id: C.client.id, on: 1, ...Object.fromEntries(new FormData(ev.target)) }), 'Flagged: do not quote'); refresh(); }; return;
      case 'client-del': if (!confirm(`Delete ${C.client.company} with all its orders, payments and files? This cannot be undone.`)) return; await act(() => api('client_del', { id: C.client.id }), 'Client deleted'); location.hash = '#clients'; return;
      case 'dnq-off': if (!confirm('Remove the do-not-quote flag?')) return; await act(() => api('dnq', { id: C.client.id, on: 0 }), 'Flag removed'); return refresh();
      case 'np-open': $('#np-form').hidden = false; $('#np-form [name=expo]').focus(); return;
      case 'np-cancel': $('#np-form').hidden = true; return;
      case 'ce-open': C.editClient = C.client.id; return refresh();
      case 'ce-cancel': C.editClient = null; return refresh();
      case 'ri-add': draft.items.push({}); draft.files.push([]); return refresh();
      case 'ri-submit': {
        const k0 = C.tpl[0]; const bad = draft.items.findIndex(x => !String(x[k0.k] || '').trim());
        if (bad >= 0) return toast(`Entry ${bad + 1}: please fill ${k0.l}`, true);
        $('#ri-confirm').innerHTML = `<div class="alert warn" style="margin-top:10px"><b>Submit ${draft.items.length} entr${draft.items.length > 1 ? 'ies' : 'y'}?</b><span class="m">After this you cannot edit today's report.</span><div class="row"><button class="btn" id="ri-final">Yes, submit</button><button class="btn ghost" id="ri-cancel">Go back</button></div></div>`; return;
      }
      case 'ri-cancel': $('#ri-confirm').innerHTML = ''; return;
      case 'ri-final': {
        t.disabled = true;
        const r = await act(() => api('report_submit', { items: draft.items })).catch(() => null);
        if (!r) { t.disabled = false; return; }
        for (let i = 0; i < r.item_ids.length; i++) if (draft.files[i] && draft.files[i].length) await act(() => uploadAll('ritem', r.item_ids[i], draft.files[i])).catch(() => {});
        draft = null; toast('Report submitted and locked'); return refresh();
      }
    }
  } catch (err) { /* toast already shown */ }
});

document.addEventListener('input', e => {
  const t = e.target, ds = t.dataset;
  if (ds.nc && newClient) { newClient[ds.nc] = t.value; if (['company', 'email', 'phone'].includes(ds.nc)) dupCheck(); }
  if (ds.ncc !== undefined) newClient.contacts[+ds.ncc][ds.k] = t.value;
  if (ds.nce && newClient) newClient.expo[ds.nce] = t.value;
  if (ds.ri !== undefined && ds.ri !== 'pv' && draft) draft.items[+ds.ri][ds.k] = t.value;
  if (['agreed', 'gst_rate', 'gst_on'].includes(ds.pf) && C.project && $('#calc-total')) {
    const over = {}; ['agreed', 'gst_rate', 'gst_on'].forEach(k => { const el = document.querySelector(`[data-pf="${k}"]`); if (el) over[k] = el.value; });
    const c = calcBill(C.project, over); $('#calc-gst').value = inr(c.gst); $('#calc-total').value = inr(c.total);
  }
});

document.addEventListener('change', async e => {
  const t = e.target, ds = t.dataset;
  try {
    if (ds.pf && C.project) {
      if (t.type === 'number' && t.value !== '' && isNaN(+t.value)) return;
      await act(() => api('project_save', { id: C.project.id, fields: { [ds.pf]: t.value } }), 'Saved');
      return refresh();
    }
    if (ds.vqsel) { await act(() => api('vq_select', { id: ds.vqsel }), 'Vendor chosen'); return refresh(); }
    if (ds.upload && t.files.length) { const files = [...t.files]; toast('Uploading…'); await act(() => uploadAll(ds.upload, ds.eid, files), `${files.length} file(s) uploaded`); return refresh(); }
    if (t.id === 'nc-cards') { newClient.cards.push(...t.files); return refresh(); }
    if (t.id === 'nc-brief') { newClient.briefs.push(...t.files); return refresh(); }
    if (ds.rfile !== undefined) { draft.files[+ds.rfile].push(...t.files); return refresh(); }
    if (ds.woff !== undefined) { const i = +ds.woff; const w = t.checked ? [...B.weekly_off, i] : B.weekly_off.filter(x => x !== i); await act(() => api('settings_save', { weekly_off: w }), 'Weekly off saved'); await reboot(); return; }
    if (t.id === 'fm-dept') { C.fmDept = t.value; return refresh(); }
    if (ds.recdept !== undefined) { const set = new Set(B.record_depts); t.checked ? set.add(ds.recdept) : set.delete(ds.recdept); await act(() => api('settings_save', { record_depts: [...set] }), 'Access updated'); await reboot(); return; }
    if (t.tagName === 'SELECT' && t.name === 'vendor_id') {
      // "+ Add new vendor…" shows a name box right under the dropdown
      const form = t.closest('form'), box = form.querySelector('.nv');
      if (t.value === '__new' && !box) {
        t.closest('.grid').insertAdjacentHTML('afterend', `<div class="nv" style="margin-top:10px"><div class="sub" style="margin-bottom:6px"><b>New vendor details</b></div><div class="grid g3">
          <label class="f">Vendor name *<input name="new_vendor" required placeholder="e.g. Metro Exhibits"></label>
          <label class="f">Phone *<input name="new_vendor_phone" type="tel" required></label>
          <label class="f">City *<input name="new_vendor_city" required></label>
          <label class="f">Email<input name="new_vendor_email" type="email"></label></div></div>`);
        form.querySelector('[name=new_vendor]').focus();
      }
      if (t.value !== '__new' && box) box.remove();
      return;
    }
  } catch (err) { /* shown */ }
});

document.addEventListener('submit', async e => {
  const f = e.target;
  if (f.dataset.edit && C.project) {
    e.preventDefault(); const [kind, id] = f.dataset.edit.split(':');
    try { await act(() => api('child_update', { kind, id, data: Object.fromEntries(new FormData(f)) }), 'Updated'); C.editing = null; refresh(); } catch {}
    return;
  }
  if (f.dataset.add && C.project) {
    e.preventDefault();
    const fdta = new FormData(f); const files = fdta.getAll('file').filter(x => x && x.size); fdta.delete('file');
    const data = Object.fromEntries(fdta);
    const btn = f.querySelector('button:not([type=button])'); btn.disabled = true;
    try {
      if (data.vendor_id === '__new') {   // create the vendor first, then use it
        const v = await act(() => api('vendor_add', { name: (data.new_vendor || '').trim(), phone: data.new_vendor_phone, city: data.new_vendor_city, email: data.new_vendor_email }));
        data.vendor_id = v.id; ['new_vendor', 'new_vendor_phone', 'new_vendor_city', 'new_vendor_email'].forEach(k => delete data[k]); await reboot();
      }
      const r = await act(() => api('child_add', { project_id: C.project.id, kind: f.dataset.add, data }), 'Added');
      if (files.length) await act(() => uploadAll(f.dataset.add, r.id, files));
      refresh();
    } catch { btn.disabled = false; }
    return;
  }
  if (f.id === 'np-form') { e.preventDefault(); const r = await act(() => api('project_create', { client_id: C.client.id, ...Object.fromEntries(new FormData(f)) }), 'Order created').catch(() => null); if (r) location.hash = `#project-${r.id}-expo`; return; }
  if (f.id === 'ce-form') { e.preventDefault(); const o = Object.fromEntries(new FormData(f)); o.id = C.client.id; o.move_orders = !!o.move_orders; await act(() => api('client_save', o), 'Saved').catch(() => null); C.editClient = null; return refresh(); }
  if (f.id === 'ct-form') { e.preventDefault(); await act(() => api('contact_add', { client_id: C.client.id, ...Object.fromEntries(new FormData(f)) }), 'Added').catch(() => null); return refresh(); }
});

// ---------- start ----------
async function reboot() { Object.assign(B, await api('boot')); B.weekly_off = (B.weekly_off || []).map(Number); }
(async () => {
  try { await reboot(); } catch (e) { view().innerHTML = `<div class="alert bad"><b>Could not start</b><span class="m">${esc(e.message)}</span></div>`; return; }
  window.addEventListener('hashchange', route);
  route();
})();
})();
