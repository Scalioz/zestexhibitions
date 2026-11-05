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
// Photos are made smaller in the browser before sending (quick uploads on mobile data). PDFs and Office files are compressed on the server.
const mb = n => n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';
async function shrinkPhoto(file, entity) {
  const base = B.file_cfg || { max: 2000, q: 80 }, design = ['final', 'wd', 'appr'].includes(entity);
  const cfg = design ? { max: Math.round(base.max * 1.5), q: Math.min(90, base.q + 5) } : base;
  if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size < 400 * 1024 || !window.createImageBitmap) return file;
  try {
    const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
    const k = Math.min(1, cfg.max / Math.max(bmp.width, bmp.height)), w = Math.round(bmp.width * k), h = Math.round(bmp.height * k);
    const cv = document.createElement('canvas'); cv.width = w; cv.height = h; const g = cv.getContext('2d');
    let alpha = false;
    if (file.type !== 'image/jpeg') {   // keep transparency (logos): check a small copy for see-through pixels
      const t = document.createElement('canvas'); t.width = Math.max(1, Math.round(w / 16)); t.height = Math.max(1, Math.round(h / 16)); const tg = t.getContext('2d'); tg.drawImage(bmp, 0, 0, t.width, t.height);
      const px = tg.getImageData(0, 0, t.width, t.height).data; for (let i = 3; i < px.length; i += 4) if (px[i] < 247) { alpha = true; break; }
    }
    if (!alpha) { g.fillStyle = '#fff'; g.fillRect(0, 0, w, h); }
    g.drawImage(bmp, 0, 0, w, h); bmp.close && bmp.close();
    const type = alpha ? 'image/png' : 'image/jpeg';
    const blob = await new Promise(r => cv.toBlob(r, type, cfg.q / 100));
    if (!blob || blob.size >= file.size * 0.9) return file;
    const name = alpha ? file.name : file.name.replace(/\.(png|webp|jpe?g)$/i, '') + '.jpg';
    return new File([blob], name, { type, lastModified: file.lastModified });
  } catch { return file; }
}
async function upload(entity, id, file) {
  const lim = (B.file_cfg?.limit_mb || 60) * 1048576, orig = file.size;
  file = await shrinkPhoto(file, entity);
  if (file.size > lim) throw new Error(`${file.name}: too large (${mb(file.size)}). Maximum is ${mb(lim)}.`);
  const fdta = new FormData(); fdta.append('entity', entity); fdta.append('entity_id', id); fdta.append('orig_size', orig); fdta.append('file', file);
  const r = await fetch('api.php?a=upload', { method: 'POST', headers: { 'X-CSRF': CSRF }, body: fdta, credentials: 'same-origin' });
  const j = await r.json().catch(() => ({ error: 'Upload failed' }));
  if (!r.ok || j.error) throw new Error(`${file.name}: ${j.error || 'upload failed'}`);
  return j;
}
async function uploadAll(entity, id, files) {   // returns a short note on how much was saved
  let o = 0, n = 0; for (const f of files) { const j = await upload(entity, id, f); o += +j.orig_size || f.size; n += +j.size || f.size; }
  return o && n < o * 0.8 ? ` · ${mb(o)} → ${mb(n)}, compressed automatically` : '';
}
async function act(fn, okMsg) { try { const r = await fn(); if (okMsg) toast(okMsg); return r; } catch (e) { toast(e.message, true); throw e; } }

const fileChips = (list, canDel) => list && list.length
  ? `<div class="files">${list.map(f => `<a class="chip" href="file.php?id=${f.id}" target="_blank" rel="noopener" title="${esc(f.orig_name)}">📎 <span>${esc(f.orig_name)}</span></a>${canDel ? `<button class="btn link" data-delfile="${f.id}" title="Remove file" aria-label="Remove ${esc(f.orig_name)}">✕</button>` : ''}`).join('')}</div>` : '<span class="sub">No file yet</span>';
const upBtn = (entity, id, label = 'Upload', accept) => `<label class="up">⬆ ${esc(label)}<input type="file" multiple data-upload="${entity}" data-eid="${id}" accept="${accept || '.pdf,.jpg,.jpeg,.png,.webp,.heic,.xlsx,.xls,.csv,.doc,.docx,.ppt,.pptx,.dwg,.dxf,.skp,.zip'}"></label>`;

// line drawing of an exhibition stall, used in empty states
const STALL = `<svg viewBox="0 0 160 110" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path d="M20 90 L80 105 L140 90 L80 75 Z" opacity=".5"/><path d="M20 90 V35 L80 50 V105"/><path d="M80 50 L140 35 V90"/><path d="M20 35 L80 20 L140 35"/><rect x="30" y="52" width="38" height="18" transform="skewY(14)" opacity=".7"/><path d="M95 62 h28 v16 h-28z" opacity=".6"/><circle cx="109" cy="55" r="4"/></svg>`;
const blank = (msg, btn = '') => `<div class="blank">${STALL}<div>${msg}</div>${btn}</div>`;
const PLAN = `<svg viewBox="0 0 300 140" aria-hidden="true">${Array.from({ length: 24 }, (_, i) => { const x = (i % 8) * 36 + 4, y = Math.floor(i / 8) * 44 + 6; const f = [2, 5, 9, 12, 19].includes(i); return `<rect x="${x}" y="${y}" width="30" height="36" rx="2" fill="${f ? '#e9b949' : 'none'}" stroke="#fff" stroke-opacity=".6"/>`; }).join('')}</svg>`;

// ---------- routing ----------
const routes = {};
function parseHash() { const h = (location.hash || '#home').slice(1); const [name, id, tab] = h.split('-'); return { name: name || 'home', id: id ? +id : null, tab }; }
async function route() {
  const r = parseHash();
  C.editing = null; C.leditId = null; C.lxu = '';
  if (isAdmin() && ({ leads: 'leadlog', mydesign: 'jobs', myops: 'jobs' })[r.name]) { location.hash = '#' + ({ leads: 'leadlog', mydesign: 'jobs', myops: 'jobs' })[r.name]; return; }
  if (!isAdmin() && MODULE_ONLY[r.name] && !MODULE_ONLY[r.name].includes(B.module)) { location.hash = '#home'; return; }
  if (ACC_ROUTES[r.name] && !B.acc) { location.hash = '#home'; return; }
  renderNav(r.name);
  const fn = routes[r.name] || routes.home;
  if ((r.name in ADMIN_ONLY) && !isAdmin()) { location.hash = '#home'; return; }
  if ((r.name in RECORDS_ONLY) && !B.records) { location.hash = '#home'; return; }
  try { await fn(r); } catch (e) { if (e.message !== 'signed out') view().innerHTML = `<div class="alert bad"><b>Could not load this page</b><span class="m">${esc(e.message)}</span></div>`; }
  window.scrollTo(0, 0);
}
const ADMIN_ONLY = { invappr: 1, clientlog: 1, saleslog: 1, leadlog: 1, jobs: 1, reportlog: 1, team: 1, formats: 1, settings: 1, dnq: 1 };
const RECORDS_ONLY = { refs: 1, clients: 1, client: 1, project: 1, newclient: 1, qgen: 1 };
const ACC_ROUTES = { acc: 1, entry: 1, jv: 1, invoices: 1, invnew: 1, inv: 1, invappr: 1, claims: 1, topost: 1, books: 1, coa: 1, accset: 1 };
const MODULE_ONLY = { myinv: ['records'], myexp: ['records', 'ops'],  leads: ['leads', 'calls'], lexpo: ['leads', 'calls'], mydesign: ['design'], myops: ['ops'], opsjob: ['ops', 'records'] };
async function refresh() { const y = window.scrollY; const r = parseHash(); await (routes[r.name] || routes.home)(r); window.scrollTo(0, y); }

function renderNav(cur) {
  const map = { qgen: 'clients', jv: 'books', inv: 'invoices', invnew: 'invoices', invd: B.acc ? 'invoices' : 'myinv', acc: isAdmin() ? 'acc' : 'home', client: 'clients', project: 'clients', lexpo: isAdmin() ? 'leadlog' : 'leads', opsjob: isAdmin() ? 'jobs' : 'myops' };
  const on = map[cur] || cur;
  const L = (k, l, b) => `<a href="#${k}" class="${on === k ? 'on' : ''}">${l}${b ? `<span class="badge">${b}</span>` : ''}</a>`;
  $('#nav').innerHTML = isAdmin()
    ? `<div class="sec">Overview</div>${L('home', 'Business dashboard')}${L('clientlog', 'Client log')}${L('saleslog', 'Sales log', B.pending_approvals || '')}${L('invappr', 'Invoice approvals', B.inv_pending || '')}${L('leadlog', 'Expo data log')}${L('jobs', 'Design & production')}${L('reportlog', 'Daily reports', B.flags || '')}
       <div class="sec">Records</div>${L('clients', 'All clients')}${L('newclient', '+ New client')}${L('refs', 'Referral leads', B.ref_new || '')}${L('dnq', 'Do-not-quote list')}
       <div class="sec">Accounts</div>${L('acc', 'Accounts dashboard')}${L('claims', 'Expense approvals', B.claims_waiting || '')}${L('entry', '+ New entry')}${L('invoices', 'Invoices', B.inv_todo || '')}${L('topost', 'Post from orders')}${L('books', 'Books & reports')}${L('coa', 'Chart of accounts')}${L('accset', 'Company settings')}
       <div class="sec">Admin</div>${L('team', 'Team')}${L('formats', 'Report formats')}${L('settings', 'Holidays & lists')}`
    : B.module === 'accounts' ? `${L('home', 'Accounts dashboard')}${L('claims', 'Expense approvals', B.claims_waiting || '')}${L('entry', '+ New entry')}${L('invoices', 'Invoices', B.inv_todo || '')}${L('topost', 'Post from orders')}${L('books', 'Books & reports')}${L('coa', 'Chart of accounts')}${L('accset', 'Company settings')}${L('report', 'Daily report', B.flags || '')}`
    : B.records ? `${L('home', 'My dashboard')}${L('clients', 'My clients')}${L('newclient', '+ New client')}${L('refs', 'Referral leads', B.ref_new || '')}${L('myinv', 'My invoices', B.inv_attention || '')}${L('myexp', 'My expenses', B.claims_attention || '')}${L('report', 'Daily report', B.flags || '')}`
    : `${L('home', 'My dashboard')}${['leads', 'calls'].includes(B.module) ? L('leads', 'Expo data') : B.module === 'design' ? L('mydesign', 'My design jobs') : B.module === 'ops' ? L('myops', 'My production jobs') + L('myexp', 'My expenses', B.claims_attention || '') : ''}${L('report', 'Daily report', B.flags || '')}`;
}

// ---------- HOME ----------
routes.home = async () => isAdmin() ? adminHome() : B.module === 'accounts' ? routes.acc() : empHome();

async function empHome() {
  const d = await api('home'); B.flags = d.missing.length; renderNav('home');
  const first = B.me.name.split(' ')[0];
  const hr = new Date().getHours(); const greet = hr < 12 ? 'Good morning' : hr < 17 ? 'Good afternoon' : 'Good evening';
  if (!B.records) {
    const M = B.module, work = M !== 'none';
    const go = ['leads', 'calls'].includes(M) ? ['#leads', 'Open expo data'] : M === 'design' ? ['#mydesign', 'Open my design jobs'] : M === 'ops' ? ['#myops', 'Open my production jobs'] : ['#report', 'Open daily report'];
    const kpi = ['leads', 'calls'].includes(M) ? `<div class="kpi hl"><span>Entries today</span><b>${d.leads_today}</b></div><div class="kpi"><span>Entries this month</span><b>${d.leads_month}</b></div><div class="kpi"><span>Expos worked on</span><b>${d.expos_n}</b></div>`
      : M === 'design' ? `<div class="kpi hl"><span>Jobs pending</span><b>${d.design_pending}</b></div><div class="kpi"><span>Jobs submitted</span><b>${d.design_done}</b></div>`
      : M === 'ops' ? `<div class="kpi hl"><span>Active production jobs</span><b>${d.ops_jobs}</b></div>`
      : `<div class="kpi"><span>Reports this month</span><b>${d.reports_month}</b></div><div class="kpi"><span>Entries this month</span><b>${d.entries_month}</b></div>`;
    const doneToday = d.today_done || d.today_work;
    view().innerHTML = `
    <div class="welcome">${PLAN}<div><h1>${greet}, ${esc(first)}</h1><p>${longDate(B.today)} · ${esc(B.me.department)}</p></div>
     <div class="row"><a class="btn" href="${go[0]}">${go[1]}</a></div></div>
    ${d.missing.length ? `<div class="alert bad"><b>${work ? 'No work or report logged' : 'Daily report not submitted'}</b><span class="m">Missing for: ${d.missing.map(fd).join(', ')}. Admin can see this.</span></div>` : ''}
    ${!doneToday && d.today_working ? `<div class="alert warn"><b>${work ? 'Nothing logged today yet' : "Today's report is pending"}</b><span class="m">${work ? `Add today's work in <a href="${go[0]}">${go[1].replace('Open ', '')}</a> or submit a <a href="#report">daily report</a>.` : 'Submit before you leave for the day. <a href="#report">Open daily report →</a>'}</span></div>` : ''}
    ${doneToday ? `<div class="alert" style="background:var(--ok-bg);color:var(--ok)"><b>${d.today_done ? "Today's report is submitted" : "Today's work is logged"}</b></div>` : ''}
    <div class="kpis">${kpi}<div class="kpi"><span>Missed days</span><b>${d.missing.length}</b></div></div>`;
    claimAlert(); return;
  }
  view().innerHTML = `
  <div class="welcome">${PLAN}<div><h1>${greet}, ${esc(first)}</h1><p>${longDate(B.today)} · ${esc(B.me.department)}</p></div>
   <div class="row"><a class="btn" href="#newclient">+ New client</a></div></div>
  ${d.missing.length ? `<div class="alert bad"><b>Daily report not submitted</b><span class="m">Missing for: ${d.missing.map(fd).join(', ')}. Admin can see this.</span></div>` : ''}
  ${!d.today_done && d.today_working ? `<div class="alert warn"><b>Today's report is pending</b><span class="m">Submit before you leave for the day. <a href="#report">Open daily report →</a></span></div>` : ''}
  ${d.appr && d.appr.length ? `<div class="panel"><h2>My approvals</h2><div class="tw"><table>${d.appr.map(a => `<tr class="click" data-go="project-${a.project_id}-${a.kind === 'quote' ? 'order' : 'design'}"><td><b>${esc(a.company)}</b><div class="sub">${esc(a.expo)}</div></td><td><span class="pill ${a.kind === 'quote' ? 'p-enquiry' : 'p-off'}">${a.kind} V${a.version}</span></td><td>${stagePill(a)}</td><td class="sub">${a.status === 'approved' ? 'Approved — send it to the customer and mark it sent.' : a.status === 'rejected' ? 'Needs changes: ' + esc(a.admin_note || '') : 'Waiting for admin.'}</td></tr>`).join('')}</table></div></div>` : ''}
  <div class="kpis">
    <div class="kpi"><span>My clients</span><b>${d.clients}</b></div>
    <div class="kpi"><span>Orders won</span><b>${d.won}</b><small>${inr(d.won_total)} billed</small></div>
    <div class="kpi"><span>Open enquiries / quotes</span><b>${d.open}</b></div>
    <div class="kpi hl"><span>Balance to collect</span><b>${inr(d.due)}</b></div>
  </div>
  <div class="panel"><h2>My active orders & enquiries</h2>${projTable(d.projects)}</div>`;
  claimAlert(); invAlert(); refAlert();
}
function claimAlert() { if (!B.claims_attention) return; const w = $('.welcome'); if (w) w.insertAdjacentHTML('afterend', `<div class="alert warn"><b>${B.claims_attention} expense claim${B.claims_attention > 1 ? 's need' : ' needs'} your attention</b><span class="m">Accounts rejected it or asked for more details. <a href="#myexp">Open my expenses →</a></span></div>`); }

async function adminHome(r) {
  const f = C.dash || (C.dash = { from: '', to: '' });
  const d = await api('home', f);
  B.flags = d.employees.reduce((s, e) => s + e.missing.length, 0); renderNav('home');
  const flags = d.employees.filter(e => e.missing.length);
  const maxB = Math.max(1, ...d.employees.map(e => e.business));
  view().innerHTML = `
  <div class="head"><div><h1>Business dashboard</h1><p>${f.from || f.to ? `Expos from ${f.from ? fd(f.from) : 'start'} to ${f.to ? fd(f.to) : 'today'}` : 'All time · all employees'}</p></div>
   <form class="row" id="dashf"><label class="f">From<input type="date" name="from" value="${f.from}"></label><label class="f">To<input type="date" name="to" value="${f.to}"></label><button class="btn ghost sm" style="align-self:flex-end">Apply</button>${f.from || f.to ? '<button type="button" class="btn link" id="dashclear" style="align-self:flex-end">Clear</button>' : ''}</form></div>
  ${B.ref_new ? `<div class="alert warn"><b>${B.ref_new} referral lead${B.ref_new > 1 ? 's' : ''} handed to you</b><div><a class="btn sm" href="#refs">Open referral leads</a></div></div>` : ''}
  ${B.inv_pending ? `<div class="alert warn"><b>${B.inv_pending} invoice${B.inv_pending > 1 ? 's' : ''} waiting for your approval</b><div><a class="btn sm" href="#invappr">Review invoices</a></div></div>` : ''}
  ${B.pending_approvals ? `<div class="alert warn"><b>${B.pending_approvals} design / quote approval${B.pending_approvals > 1 ? 's' : ''} waiting</b><div><a class="btn sm" href="#saleslog">Open sales log</a></div></div>` : ''}
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
  const n = newClient || (newClient = { company: '', email: '', phone: '', person: '', designation: '', city: '', location: '', gst_no: '', notes: '', owner_id: '', contacts: [], cards: [], briefs: [], gstcert: [], expo: { expo: '', location: '', start_date: '', area_sqm: '', space_type: '', sides_open: '', stall_no: '' } });
  const F = (k, l, t = 'text') => `<label class="f">${l}<input data-nc="${k}" type="${t}" value="${esc(n[k])}"></label>`;
  view().innerHTML = `
  ${n.ref_id ? '<div class="alert"><b>From a referral lead</b><span class="m">Saving this client marks the referral as “added as client”. Add the expo details if you have them.</span></div>' : ''}
  <div class="head"><div><h1>New client</h1><p>As you type the company, email or phone, the CRM checks every past record, including other employees' clients.</p></div></div>
  <div class="panel"><h2>Client details</h2>
   <div class="grid">${F('company', 'Company name *')}${F('email', 'Email', 'email')}${F('phone', 'Phone', 'tel')}${F('person', 'Contact person')}${F('designation', 'Designation')}${F('city', 'City')}${F('gst_no', 'GSTIN')}
   ${isAdmin() ? `<label class="f">Handled by<select data-nc="owner_id"><option value="">Me</option>${B.users.filter(u => +u.active).map(u => `<option value="${u.id}" ${+n.owner_id === +u.id ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select></label>` : ''}</div>
   <div class="grid" style="margin-top:12px"><label class="f">Company location / address<textarea data-nc="location">${esc(n.location)}</textarea></label><label class="f">Notes<textarea data-nc="notes">${esc(n.notes)}</textarea></label></div>
   <div class="row" style="margin-top:12px"><span class="sub"><b>GST certificate</b></span><label class="up">⬆ Upload GST certificate (PDF / image)<input type="file" id="nc-gst" accept=".pdf,.jpg,.jpeg,.png,.webp"></label>${n.gstcert.map(f => `<span class="chip">📎 <span>${esc(f.name)}</span></span>`).join('')}</div>
   <div id="dup" style="margin-top:12px"></div>
  </div>
  <div class="panel"><h2>Expo enquiry</h2><p class="sub" style="margin-top:0">The expo this client is talking to us about. This creates their first expo order.</p>
   <div class="grid g3">${[['expo', 'Expo name *'], ['location', 'Expo location / city'], ['start_date', 'Expo start date', 'date'], ['area_sqm', 'Area chosen (sqm)', 'number'], ['stall_no', 'Stall no.']].map(([k, l, t]) => `<label class="f">${l}<input data-nce="${k}" type="${t || 'text'}" value="${esc(n.expo[k])}"></label>`).join('')}
   <label class="f">No. of sides open<select data-nce="sides_open"><option value="">Select</option>${[1, 2, 3, 4].map(k => `<option value="${k}" ${+n.expo.sides_open === k ? 'selected' : ''}>${SIDES[k]}</option>`).join('')}</select></label>
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
  <form class="panel" id="np-form" hidden><h3>New expo order</h3><div class="grid g3"><label class="f">Expo name *<input name="expo" required></label><label class="f">Location<input name="location"></label><label class="f">Start date<input type="date" name="start_date"></label><label class="f">Area chosen (sqm)<input type="number" step="0.01" name="area_sqm"></label><label class="f">Space type<select name="space_type"><option value="">Select</option><option>Bare space</option><option>Shell scheme</option></select></label><label class="f">No. of sides open<select name="sides_open"><option value="">Select</option>${[1, 2, 3, 4].map(k => `<option value="${k}">${SIDES[k]}</option>`).join('')}</select></label><label class="f">Stall no.<input name="stall_no"></label></div><div class="row" style="margin-top:10px"><button class="btn">Create order</button><button type="button" class="btn ghost" id="np-cancel">Cancel</button></div></form>
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
   <div class="panel"><h3>GST certificate</h3><div class="row">${upBtn('gstcert', c.id, 'Upload GST certificate')}${fileChips(c.gstcert, isAdmin())}</div>${c.gst_no ? `<p class="sub" style="margin-bottom:0">GSTIN ${esc(c.gst_no)}</p>` : ''}</div>
   <div class="panel"><h3>Client brief / requirements</h3><div class="row">${upBtn('brief', c.id, 'Upload brief')}${fileChips(c.briefs, isAdmin())}</div><p class="sub" style="margin-bottom:0">PDF, image, Word, Excel or PowerPoint.</p></div>
   <div class="panel"><h3>Business cards</h3><div class="row">${upBtn('card', c.id, 'Add card')}${fileChips(c.cards, isAdmin())}</div></div>
  </div>`;
};

// ---------- PROJECT ----------
const TABS = [['expo', 'Expo'], ['design', 'Design'], ['order', 'Quote & order'], ['vendors', 'Vendor quotes'], ['production', 'Production'], ['extras', 'Extras'], ['money', 'Payments & costs']];
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
PT.expo = p => `<div class="grid">${PF(p, 'expo', 'Expo name')}${PF(p, 'location', 'Location')}${PF(p, 'start_date', 'Start date', 'date')}${PF(p, 'end_date', 'End date', 'date')}${PF(p, 'area_sqm', 'Area (sqm)', 'number', 'step="0.01"')}<label class="f">Space type<select data-pf="space_type"><option value="">Select</option>${['Bare space', 'Shell scheme'].map(o => `<option ${p.space_type === o ? 'selected' : ''}>${o}</option>`).join('')}</select></label><label class="f">No. of sides open<select data-pf="sides_open"><option value="">Select</option>${[1, 2, 3, 4].map(n => `<option value="${n}" ${+p.sides_open === n ? 'selected' : ''}>${SIDES[n]}</option>`).join('')}</select></label>${PF(p, 'stall_no', 'Stall no.')}
  ${isAdmin() ? `<label class="f">Handled by<select data-pf="owner_id">${B.users.filter(u => +u.active).map(u => `<option value="${u.id}" ${+u.id === +p.owner_id ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select></label>` : ''}</div>
  <h3 style="margin:18px 0 8px">Client brief for this expo</h3><div class="row">${upBtn('pbrief', p.id, 'Upload brief (PDF / image / Word / Excel / PPT)')}${fileChips(p.files.pbrief, isAdmin())}</div>
  <h3 style="margin:18px 0 8px">Floor plan</h3><div class="row">${upBtn('floor', p.id, 'Upload floor plan (JPG / PDF)')}${fileChips(p.files.floor, isAdmin())}</div>`;
PT.design = p => `<div class="inset" style="margin-bottom:14px"><div class="grid g3">
   ${isAdmin() ? `<label class="f">Assign designer<select data-assign="designer"><option value="0">Not assigned</option>${usersOfMod('design').map(u => `<option value="${u.id}" ${+u.id === +p.designer_id ? 'selected' : ''}>${esc(u.name)} · ${esc(u.department)}</option>`).join('')}</select></label>`
     : `<div class="sub"><b>Designer</b><br>${esc(p.designer || '—')}</div>`}
   <div class="sub"><b>Assigned by</b><br>${esc(p.design_assigned_by || '—')}${p.designer_id ? ' · ' + fd(p.assigned_on) : ''}</div>
   <div class="sub"><b>Design submitted</b><br>${p.design_submitted_on ? fd(p.design_submitted_on) : p.designer_id ? '<span class="pill p-pending">pending</span>' : '—'}</div></div>
   ${p.design_notes ? `<div class="sub" style="margin-top:8px"><b>Designer notes:</b> ${esc(p.design_notes)}</div>` : ''}</div>
  ${designOkBlock(p)}${apprPanel(p, 'design')}
  <div class="grid">${PF(p, 'designed_by', 'Designed by')}${PF(p, 'assigned_on', 'Design assigned on', 'date')}${PF(p, 'design_approved_by', 'Design approved by (Zest)')}</div>
  <h3 style="margin:18px 0 8px">Corrections</h3>
  ${p.revisions.length ? `<div class="tw"><table><tr><th>#</th><th>Date</th><th>Done by</th><th>What changed</th><th>File</th><th></th></tr>${p.revisions.map((r, i) => rowOr('revision', r, 6, `<tr><td>R${i + 1}</td><td class="num">${fd(r.rdate)}</td><td>${esc(r.done_by || '')}</td><td>${esc(r.changes)}</td><td>${fileChips(p.files.revision[r.id], isAdmin())}</td><td class="r">${acts('revision', r.id)}</td></tr>`)).join('')}</table></div>` : '<div class="sub">No corrections yet.</div>'}
  <form class="inset" data-add="revision" style="margin-top:10px"><div class="grid g3"><label class="f">Date<input type="date" name="rdate" value="${B.today}" required></label><label class="f">Done by<input name="done_by"></label><label class="f">What changed *<input name="changes" required></label></div>
   <div class="row" style="margin-top:8px"><label class="up">⬆ Attach file<input type="file" name="file" multiple></label><button class="btn ghost sm">+ Add correction</button></div></form>
  <div class="grid" style="margin-top:18px"><div><h3 style="margin-bottom:8px">Final design</h3><div class="row">${upBtn('final', p.id)}${fileChips(p.files.final, isAdmin())}</div></div>
  <div><h3 style="margin-bottom:8px">Working drawing <span class="sub">(if order executed)</span></h3><div class="row">${upBtn('wd', p.id)}${fileChips(p.files.wd, isAdmin())}</div></div></div>`;
PT.production = p => `<div class="inset" style="margin-bottom:14px"><div class="grid g3">
   ${isAdmin() ? `<label class="f">Assign production person<select data-assign="ops"><option value="0">Not assigned</option>${usersOfMod('ops').map(u => `<option value="${u.id}" ${+u.id === +p.ops_id ? 'selected' : ''}>${esc(u.name)} · ${esc(u.department)}</option>`).join('')}</select></label>`
     : `<div class="sub"><b>Production person</b><br>${esc(p.ops_person || '—')}</div>`}
   <div class="sub"><b>Assigned by</b><br>${esc(p.ops_assigned_by || '—')}${p.ops_id ? ' · ' + fd(p.ops_assigned_on) : ''}</div>
   <div class="sub"><b>Vendor assigned</b><br>${p.chosen_vendor ? `${esc(p.chosen_vendor.name)} · ${inr(p.chosen_vendor.amount)}` : 'Choose one in <a href="#project-' + p.id + '-vendors">Vendor quotes</a>'}</div></div></div>
  ${opsForm({ id: p.id, expo: p.expo, location: p.location, start_date: p.start_date, company: p.client.company, area_sqm: p.area_sqm, sides_open: p.sides_open, stall_no: p.stall_no, status: p.status,
    ops_person: p.ops_person, ops_assigned_by: p.ops_assigned_by, ops_assigned_on: p.ops_assigned_on, ops_updated_at: p.ops_updated_at, vendor: p.chosen_vendor, ops: p.ops })}`;
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
  <div style="margin-top:18px">${apprPanel(p, 'quote')}</div>
  <h3 style="margin:18px 0 8px">Quote versions sent to client</h3>
  ${p.quotes.length ? `<div class="tw"><table><tr><th>Ver.</th><th>Date</th><th class="r">Amount</th><th>Approved by</th><th>Notes</th><th>PDF</th><th></th></tr>${p.quotes.map(q => rowOr('quote', q, 7, `<tr><td>V${q.version}</td><td class="num">${fd(q.qdate)}</td><td class="r num">${inr(q.amount)}</td><td>${q.approved_by ? esc(q.approved_by) : '<span class="pill p-pending">pending</span>'}</td><td class="sub">${esc(q.notes || '')}</td><td>${fileChips(p.files.quote[q.id], isAdmin())}${upBtn('quote', q.id, 'Add')}</td><td class="r">${acts('quote', q.id)}</td></tr>`)).join('')}</table></div>` : '<div class="sub">No quote sent yet.</div>'}
  ${isAdmin() ? `<details class="inset" style="margin-top:10px"><summary class="sub"><b>Admin: record a quote directly</b> (skips approval)</summary><form data-add="quote" style="margin-top:10px"><div class="grid g3"><label class="f">Date<input type="date" name="qdate" value="${B.today}" required></label><label class="f">Amount (₹) *<input type="number" name="amount" required></label><label class="f">Approved by (Zest)<input name="approved_by"></label><label class="f">What changed<input name="notes"></label></div>
   <div class="row" style="margin-top:8px"><label class="up">⬆ Attach quote PDF<input type="file" name="file" multiple accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx"></label><button class="btn ghost sm">+ Add quote version</button></div></form></details>` : ''}`; };
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
  C.repById = {}; d.days.forEach(day => day.rows.forEach(r => { if (r.id) C.repById[r.id] = { id: r.id, name: r.user.name, date: day.date, fields: r.fields, items: r.items.map(x => { const o = { ...x }; delete o._files; return o; }) }; }));
  view().innerHTML = `
  <div class="head"><div><h1>Daily reports</h1><p>${fd(d.from)} to ${fd(d.to)} · click a name to open the report</p></div>
   <form class="row" id="logf"><label class="f">Employee<select name="user_id"><option value="">All employees</option>${emps.map(e => `<option value="${e.id}" ${+f.user_id === +e.id ? 'selected' : ''}>${esc(e.name)}</option>`).join('')}</select></label><label class="f">From<input type="date" name="from" value="${d.from}"></label><label class="f">To<input type="date" name="to" value="${d.to}"></label><button class="btn ghost sm" style="align-self:flex-end">Show</button></form></div>
  ${C.repEdit ? repEditor(C.repEdit) : ''}
  <div class="log">${d.days.map(day => `<h3 class="num">${fd(day.date)} ${day.off ? `<span class="pill p-off">${esc(day.off)}</span>` : ''}</h3>
   ${day.rows.length ? day.rows.map(r => r.status === 'received'
     ? `<details><summary><b>${esc(r.user.name)}</b><span class="sub">${esc(r.user.department)}</span><span class="pill p-received">received ${r.at}</span><span class="sub" style="margin-left:auto">${r.items.length} entr${r.items.length === 1 ? 'y' : 'ies'}</span></summary><div class="in">${reportTable(r.items, r.fields)}<div class="row" style="margin-top:8px"><button class="btn ghost sm" data-repedit="${r.id}">Edit report</button><button class="btn link" data-repdel="${r.id}">Delete report</button></div></div></details>`
     : `<details><summary><b>${esc(r.user.name)}</b><span class="sub">${esc(r.user.department)}</span><span class="pill p-${r.status === 'work' ? 'received' : r.status}">${r.status === 'work' ? 'work logged' : r.status === 'pending' ? 'pending today' : 'report not received'}</span>${r.status === 'work' && r.leads ? `<span class="sub" style="margin-left:auto">${r.leads} expo entr${r.leads === 1 ? 'y' : 'ies'}</span>` : ''}</summary></details>`).join('')
     : `<div class="sub">${day.off ? 'No report expected.' : 'No employees to track on this day.'}</div>`}`).join('')}</div>`;
  $('#logf').onsubmit = e => { e.preventDefault(); const fm = new FormData(e.target); C.log = { from: fm.get('from'), to: fm.get('to'), user_id: fm.get('user_id') }; refresh(); };
};

function repEditor(R) {
  const F = R.fields.filter(f => f.t !== 'file');
  const inp = (f, k, v) => f.t === 'long' ? `<textarea data-rek="${k}" data-k="${esc(f.k)}">${esc(v)}</textarea>` : f.t === 'select' ? `<select data-rek="${k}" data-k="${esc(f.k)}"><option value="">Select</option>${(f.o || []).map(o => `<option ${v === o ? 'selected' : ''}>${esc(o)}</option>`).join('')}${v && !(f.o || []).includes(v) ? `<option selected>${esc(v)}</option>` : ''}</select>` : `<input ${f.t === 'number' ? 'type="number" step="any"' : f.t === 'date' ? 'type="date"' : ''} data-rek="${k}" data-k="${esc(f.k)}" value="${esc(v)}">`;
  return `<form class="panel" id="repeditf" style="border:2px solid var(--warn)"><div class="ph"><h2>Edit report · ${esc(R.name)} · ${fd(R.date)}</h2><button type="button" class="btn link" data-repedit="0">Close</button></div>
   ${R.items.map((x, k) => `<div class="inset" style="margin-top:8px"><div class="ph"><b>Entry ${k + 1}</b>${R.items.length > 1 ? `<button type="button" class="btn link" data-reprm="${k}">Remove</button>` : ''}</div><div class="grid g3">${F.map(f => `<label class="f ${f.t === 'long' ? 'wide' : ''}">${esc(f.l)}${inp(f, k, x[f.k] ?? '')}</label>`).join('')}</div></div>`).join('')}
   <div class="row" style="margin-top:10px"><button type="button" class="btn ghost sm" data-repadd>+ Add entry</button><button class="btn sm">Save report</button></div><div class="sub" style="margin-top:6px">Every change is recorded in the change history.</div></form>`;
}
function repCapture() { document.querySelectorAll('#repeditf [data-rek]').forEach(el => { const it = C.repEdit.items[+el.dataset.rek]; if (it) it[el.dataset.k] = el.value; }); }

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
    const tag = { 'Payment in': 'p-ok', 'Vendor paid': 'p-quoted', 'Loss': 'p-bad', 'Expense': 'p-off', 'Do not quote': 'p-bad', 'Follow-up': 'p-enquiry', 'Approval': 'p-pending', 'Approved': 'p-ok', 'Rejected': 'p-bad', 'Sent to customer': 'p-received' };
    box.innerHTML = `<div class="grid" style="margin-top:14px">
     <div class="inset"><h3 style="margin-bottom:6px">Client</h3><div>${esc(c.person || '—')}${c.designation ? ' · ' + esc(c.designation) : ''}</div><div class="sub">${esc(c.email || '')} ${esc(c.phone || '')}</div><div class="sub">${esc(c.location || '')}</div>${c.gst_no ? `<div class="sub">GSTIN ${esc(c.gst_no)}</div>` : ''}
      <div class="sub" style="margin-top:6px">Handled by <b>${esc(c.owner)}</b> · added ${fd(c.created_at)} by ${esc(c.created_by_name)}</div>
      ${c.contacts.length ? `<div style="margin-top:8px"><b class="sub">Others in the deal</b>${c.contacts.map(o => `<div class="sub">${esc(o.name)}${o.designation ? ' · ' + esc(o.designation) : ''} ${esc(o.phone || '')}</div>`).join('')}</div>` : ''}
      <div style="margin-top:8px">${c.briefs.length ? '<b class="sub">Briefs</b>' + fileChips(c.briefs) : ''}${c.cards.length ? '<b class="sub">Business cards</b>' + fileChips(c.cards) : ''}${c.gstcert.length ? '<b class="sub">GST certificate</b>' + fileChips(c.gstcert) : ''}</div></div>
     <div class="inset"><h3 style="margin-bottom:6px">Who followed up</h3>${c.followups.length ? `<div class="tw"><table>${c.followups.slice(0, 15).map(x => `<tr><td class="num">${fd(x.date)}</td><td><b>${esc(x.by)}</b><div class="sub">${esc(x.dept)}</div></td><td class="sub">${esc(x.what)}</td></tr>`).join('')}</table></div>` : '<div class="sub">No daily-report entries mention this client.</div>'}</div></div>
    ${c.orders.map(o => { const b = o.bill; return `<div class="inset" style="margin-top:12px"><div class="ph"><h3>${esc(o.expo)}${o.location ? ', ' + esc(o.location) : ''} ${pill(o.status)}</h3><a class="btn ghost sm" href="#project-${o.id}">Open order</a></div>
      <div class="grid g3 sub">
       <div><b>Expo</b><br>${fd(o.start_date)} – ${fd(o.end_date)}<br>${o.area_sqm ? num(o.area_sqm) + ' sqm' : '—'} · ${esc(o.space_type || '—')} · Stall ${esc(o.stall_no || '—')}</div>
       <div><b>People</b><br>Sales: ${esc(o.owner)}<br>Designer: ${esc(o.designed_by || '—')} (${o.revisions.length} correction${o.revisions.length === 1 ? '' : 's'})<br>Design approved: ${esc(o.design_approved_by || '—')}${o.design_submitted_on ? '<br>Design submitted: ' + fd(o.design_submitted_on) : ''}<br>Production: ${esc(o.ops_person || '—')}</div>
       <div><b>Fabrication</b><br>Vendor: ${esc(o.fabricator || '—')}<br>Vendor quotes: ${o.vquotes.length ? o.vquotes.map(v => `${esc(v.vendor)} ${inr(v.amount)}${+v.selected ? ' ✓' : ''}`).join(', ') : '—'}</div>
       <div><b>Price</b><br>Offered ${inr(o.offered)} · Agreed ${inr(o.agreed)}<br>Quotes: ${o.quotes.map(q => `V${q.version} ${inr(q.amount)}`).join(', ') || '—'}<br>Approved by: ${esc(o.approved_by || '—')}</div>
       <div><b>Money</b><br>Bill ${inr(b.total)} (GST ${inr(b.gst)}, extras ${inr(b.extras)})<br>Received ${inr(b.received)} · <span style="color:${b.balance > 0.5 ? 'var(--bad)' : 'var(--ok)'}">Balance ${inr(b.balance)}</span></div>
       <div><b>Costs & profit</b><br>Vendor ${inr(b.vendor_paid)} · Expenses ${inr(b.expenses)} · Losses ${inr(b.losses)}<br>Profit <b>${inr(b.profit)}</b></div>
      </div>${o.status === 'rejected' ? `<div class="sub" style="margin-top:6px"><b>Rejected by ${o.rejected_by === 'us' ? 'Zest' : 'client'}:</b> ${esc(o.reason || '')} ${esc(o.rejection_note || '')}</div>` : ''}
      <div class="row" style="margin-top:8px">${['pbrief', 'floor', 'final', 'wd'].filter(k => o.files[k].length).map(k => `<span class="sub">${{ pbrief: 'Brief', floor: 'Floor plan', final: 'Final design', wd: 'Working drawing' }[k]}:</span>${fileChips(o.files[k])}`).join('')}</div></div>`; }).join('')}
    <div class="inset" style="margin-top:12px"><h3 style="margin-bottom:6px">Full timeline</h3>${c.timeline.length ? `<div class="tw"><table>${c.timeline.map(x => `<tr><td class="num" style="white-space:nowrap">${fd(x.date)}</td><td><span class="pill ${tag[x.type] || 'p-off'}">${esc(x.type)}</span></td><td>${esc(x.text)}</td></tr>`).join('')}</table></div>` : '<div class="sub">Nothing yet.</div>'}</div>`;
  } catch (e) { box.innerHTML = `<div class="alert bad"><span class="m">${esc(e.message)}</span></div>`; }
}

// ---------- DEPARTMENT WORK: shared bits ----------
const MOD_LABEL = { records: 'Clients & orders', leads: 'Expo data (research)', calls: 'Expo data (telecalling)', design: 'Design jobs', ops: 'Production jobs', none: 'Daily report only' };
const modOf = dept => (B.dept_modules || {})[dept] || 'none';
const usersOfMod = m => { const u = B.users.filter(x => +x.active && x.role === 'employee' && modOf(x.department) === m); return u.length ? u : B.users.filter(x => +x.active && x.role === 'employee'); };
const SIDES = { 1: '1 side open', 2: '2 sides open (corner)', 3: '3 sides open', 4: '4 sides open (island)' };
const sidesTxt = v => SIDES[+v] || '—';
const UNITS = ['ft', 'm', 'in', 'cm', 'mm'];
const unitSel = (path, u) => `<select data-op="${path}" aria-label="Unit" style="max-width:80px">${UNITS.map(x => `<option ${x === (u || 'ft') ? 'selected' : ''}>${x}</option>`).join('')}</select>`;
const link = u => u ? `<a href="${esc(u)}" target="_blank" rel="noopener">${esc(u.replace(/^https?:\/\//, '').replace(/\/$/, ''))}</a>` : '—';
function setPath(o, path, v) { const k = path.split('.'); let x = o; for (let i = 0; i < k.length - 1; i++) x = x[k[i]] = x[k[i]] ?? (isNaN(k[i + 1]) ? {} : []); x[k[k.length - 1]] = v; }

// ---------- EXPO DATA (research + telecalling) ----------
routes.leads = async () => {
  const d = await api('lexpos', { q: C.lq || '' });
  const calls = B.module === 'calls';
  view().innerHTML = `
  <div class="head"><div><h1>Expo data</h1><p>${calls ? 'Expos you are calling exhibitors for.' : 'Expos you are collecting exhibitor data for.'} Open an expo to add or correct entries.</p></div></div>
  <form class="panel" id="lx-new"><h2>Add an expo</h2><p class="sub" style="margin-top:0">If a colleague already added the same expo, pick it from the suggestions — your entries will sit under the same expo.</p>
   <div class="grid g3"><label class="f">Expo name *<input name="name" required list="expo-list" autocomplete="off"></label><label class="f">Expo date<input type="date" name="edate"></label><label class="f">Expo location / city<input name="city"></label><label class="f">Expo website<input name="website" placeholder="www.example.com"></label></div>
   <datalist id="expo-list">${d.all_expos.map(e => `<option value="${esc(e.name)}">${esc([e.city, e.edate ? fd(e.edate) : ''].filter(Boolean).join(' · '))}</option>`).join('')}</datalist>
   <div style="margin-top:10px"><button class="btn">+ Add expo</button></div></form>
  <div class="panel"><div class="ph"><h2>My expos</h2><input id="lq" placeholder="Search expo or city" value="${esc(C.lq || '')}" style="max-width:260px"></div>
   ${d.expos.length ? `<div class="tw"><table><tr><th>Expo</th><th>Date</th><th>City</th><th>Website</th><th class="r">My entries</th><th>Last entry</th></tr>
   ${d.expos.map(e => `<tr class="click" data-go="lexpo-${e.id}"><td><b>${esc(e.name)}</b></td><td class="num">${fd(e.edate)}</td><td>${esc(e.city || '—')}</td><td>${link(e.website)}</td><td class="r num">${e.entries}</td><td class="num">${fd(e.last)}</td></tr>`).join('')}</table></div>`
   : blank('No expos yet. Add the expo you are working on above.')}</div>`;
  $('#lx-new').onsubmit = async e => { e.preventDefault(); const r = await act(() => api('lexpo_save', Object.fromEntries(new FormData(e.target))), 'Expo ready').catch(() => null); if (r) location.hash = '#lexpo-' + r.id; };
  let tq; $('#lq').oninput = e => { clearTimeout(tq); tq = setTimeout(() => { C.lq = e.target.value.trim(); refresh().then(() => { const i = $('#lq'); i.focus(); i.setSelectionRange(i.value.length, i.value.length); }); }, 350); };
};

routes.lexpo = async ({ id }) => {
  const d = await api('lexpo', { id, user_id: isAdmin() ? (C.lxu || '') : '' });
  C.lexpo = d; const x = d.expo;
  const admin = isAdmin();
  const showNotes = admin ? true : B.module === 'calls';
  const canDel = e => admin || (+e.user_id === +B.me.id && String(e.created_at).slice(0, 10) === B.today);
  const cols = 6 + (showNotes ? 1 : 0) + (admin ? 1 : 0);
  const people = admin ? [...new Map(d.entries.map(e => [e.user_id, e.entered_by])).entries()] : [];
  const row = (e, i) => C.leditId === +e.id
    ? `<tr><td colspan="${cols + 1}" style="background:var(--gold-soft)"><form data-ledit="${e.id}"><div class="grid g3">
        <label class="f">Company name *<input name="company" required value="${esc(e.company)}"></label><label class="f">Contact person<input name="person" value="${esc(e.person || '')}"></label>
        <label class="f">Phone<input name="phone" value="${esc(e.phone || '')}"></label><label class="f">Email<input name="email" type="email" value="${esc(e.email || '')}"></label>
        ${showNotes ? `<label class="f wide">Comments / notes<textarea name="notes">${esc(e.notes || '')}</textarea></label>` : ''}</div>
        <div class="row" style="margin-top:8px"><button class="btn sm">Save changes</button><button type="button" class="btn ghost sm" data-lcancel="1">Cancel</button></div></form></td></tr>`
    : `<tr><td class="num">${i + 1}</td><td><b>${esc(e.company)}</b></td><td>${esc(e.person || '')}</td><td class="num">${esc(e.phone || '')}</td><td>${esc(e.email || '')}</td>${showNotes ? `<td>${esc(e.notes || '')}</td>` : ''}${admin ? `<td>${esc(e.entered_by || '—')}<div class="sub">${esc(e.dept)}</div></td>` : ''}<td class="num">${fd(e.created_at)}${e.updated_at ? '<div class="sub">edited</div>' : ''}</td>
       <td class="r" style="white-space:nowrap">${admin || +e.user_id === +B.me.id ? `<button class="btn link" data-ledit-open="${e.id}">✎ Edit</button>` : ''}${canDel(e) ? `<button class="btn link" data-ldel="${e.id}" aria-label="Delete">✕</button>` : ''}</td></tr>`;
  view().innerHTML = `
  <div class="head"><div><div class="crumb"><a href="#${admin ? 'leadlog' : 'leads'}">← ${admin ? 'Expo data log' : 'Expo data'}</a></div><h1>${esc(x.name)}</h1>
   <p>${fd(x.edate)} · ${esc(x.city || 'city not set')} · ${link(x.website)}${admin ? ` · added by ${esc(x.created_by_name)}` : ''}</p></div>
   <div class="row">${d.can_edit_expo ? '<button class="btn ghost" id="lx-edit-open">Edit expo details</button>' : ''}${admin ? `<a class="btn" href="export_leads.php?expo_id=${x.id}${C.lxu ? '&user_id=' + C.lxu : ''}">⬇ Export to Excel</a>` : ''}</div></div>
  <form class="panel" id="lx-edit" hidden><h3>Expo details</h3><div class="grid g3"><label class="f">Expo name *<input name="name" required value="${esc(x.name)}"></label><label class="f">Expo date<input type="date" name="edate" value="${esc(x.edate || '')}"></label><label class="f">Expo location / city<input name="city" value="${esc(x.city || '')}"></label><label class="f">Expo website<input name="website" value="${esc(x.website || '')}"></label></div>
   <div class="row" style="margin-top:10px"><button class="btn">Save</button><button type="button" class="btn ghost" id="lx-edit-cancel">Cancel</button></div></form>
  <div class="panel"><div class="ph"><h2>${admin ? 'All entries' : 'My entries'} <span class="sub">(${d.entries.length})</span></h2>
   ${admin && people.length > 1 || C.lxu ? `<label class="f" style="min-width:200px">Entered by<select id="lxu"><option value="">Everyone</option>${(people.length ? people : []).map(([uid, nm]) => `<option value="${uid}" ${+C.lxu === +uid ? 'selected' : ''}>${esc(nm)}</option>`).join('')}${C.lxu && !people.some(([u]) => +u === +C.lxu) ? `<option value="${C.lxu}" selected>${esc(userName(C.lxu))}</option>` : ''}</select></label>` : ''}</div>
   ${d.entries.length ? `<div class="tw"><table><tr><th>Sl</th><th>Company</th><th>Contact person</th><th>Phone</th><th>Email</th>${showNotes ? '<th>Comments / notes</th>' : ''}${admin ? '<th>Entered by</th>' : ''}<th>Added</th><th></th></tr>${d.entries.map(row).join('')}</table></div>` : '<div class="empty">No entries yet. Add the first one below.</div>'}
   <form class="inset" id="lead-add" style="margin-top:12px"><div class="grid g3"><label class="f">Company name *<input name="company" required></label><label class="f">Contact person<input name="person"></label><label class="f">Phone number<input name="phone" type="tel"></label><label class="f">Email<input name="email" type="email"></label>
    ${showNotes ? '<label class="f wide">Comments / notes <span class="sub" style="font-weight:400">(after speaking to the person)</span><textarea name="notes"></textarea></label>' : ''}</div>
    <div class="row" style="margin-top:8px"><button class="btn">+ Add entry</button><span class="sub">Tip: press Enter to add and start the next one.</span></div></form>
   <details class="inset" style="margin-top:12px"><summary><b>Paste many rows from Excel</b> <span class="sub">— copy the rows in Excel and paste here</span></summary>
    <p class="sub">Column order: <b>Company | Contact person | Phone | Email${showNotes ? ' | Comments' : ''}</b>. A header row is skipped. Companies already in this expo are skipped.</p>
    <textarea id="lx-paste" style="min-height:120px" placeholder="Paste here"></textarea><div class="row" style="margin-top:8px"><button class="btn ghost sm" id="lx-paste-go">Add pasted rows</button><span class="sub" id="lx-paste-n"></span></div></details>
  </div>`;
};

routes.leadlog = async () => {
  const f = C.llog || (C.llog = { q: '', user_id: '', dept: '', from: '', to: '' });
  const d = await api('lexpos', f);
  const depts = B.departments.filter(x => ['leads', 'calls'].includes(modOf(x)));
  const qs = new URLSearchParams(Object.entries(f).filter(([, v]) => v)).toString();
  const total = d.expos.reduce((s, e) => s + e.entries, 0), today = d.people.reduce((s, p) => s + p.today, 0);
  view().innerHTML = `
  <div class="head"><div><h1>Expo data log</h1><p>Exhibitor data collected by research and telecalling. Open an expo to see every entry.</p></div><a class="btn" href="export_leads.php${qs ? '?' + qs : ''}">⬇ Export to Excel</a></div>
  <form class="panel row" id="llogf" style="align-items:flex-end">
   <label class="f" style="flex:1;min-width:180px">Search expo / city<input name="q" value="${esc(f.q)}"></label>
   <label class="f">Employee<select name="user_id"><option value="">Everyone</option>${d.people.map(p => `<option value="${p.id}" ${+f.user_id === +p.id ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}</select></label>
   <label class="f">Department<select name="dept"><option value="">All</option>${depts.map(x => `<option ${f.dept === x ? 'selected' : ''}>${esc(x)}</option>`).join('')}</select></label>
   <label class="f">Entered from<input type="date" name="from" value="${f.from}"></label><label class="f">to<input type="date" name="to" value="${f.to}"></label>
   <button class="btn ghost">Apply</button>${Object.values(f).some(Boolean) ? '<button type="button" class="btn link" id="llogclear">Clear</button>' : ''}</form>
  <div class="kpis"><div class="kpi"><span>Expos</span><b>${d.expos.length}</b></div><div class="kpi hl"><span>Entries</span><b>${total}</b><small>in this view</small></div><div class="kpi"><span>Entries today</span><b>${today}</b><small>all employees</small></div></div>
  ${d.people.length ? `<div class="panel"><h2>By employee</h2><div class="tw"><table><tr><th>Employee</th><th>Department</th><th class="r">Today</th><th class="r">This month</th><th class="r">All time</th></tr>${d.people.map(p => `<tr><td><b>${esc(p.name)}</b></td><td>${esc(p.dept)}</td><td class="r num">${p.today}</td><td class="r num">${p.month}</td><td class="r num">${p.total}</td></tr>`).join('')}</table></div></div>` : ''}
  <div class="log">${d.expos.length ? d.expos.map(e => `<details data-lx="${e.id}"><summary><b>${esc(e.name)}</b><span class="sub">${fd(e.edate)} · ${esc(e.city || '—')}</span><span class="sub">${esc(e.by.map(b => `${b.name} (${b.n})`).join(', ') || 'no entries yet')}</span>
     <span style="margin-left:auto" class="row"><span class="num">${e.entries} entr${e.entries === 1 ? 'y' : 'ies'}</span><span class="sub">last ${fd(e.last)}</span></span></summary>
     <div class="in"><div class="row" style="margin-bottom:8px"><span class="sub">${link(e.website)}</span><a class="btn ghost sm" href="#lexpo-${e.id}">Open & edit</a><a class="btn ghost sm" href="export_leads.php?expo_id=${e.id}${f.user_id ? '&user_id=' + f.user_id : ''}">⬇ Export this expo</a></div><div data-lxbody="${e.id}"><div class="loading">Loading entries…</div></div></div></details>`).join('') : blank('No expo data in this view.')}</div>`;
  $('#llogf').onsubmit = e => { e.preventDefault(); C.llog = Object.fromEntries(new FormData(e.target)); refresh(); };
  if ($('#llogclear')) $('#llogclear').onclick = () => { C.llog = null; refresh(); };
  document.querySelectorAll('details[data-lx]').forEach(el => el.addEventListener('toggle', async () => {
    if (!el.open || el.dataset.loaded) return; el.dataset.loaded = 1;
    const box = el.querySelector('[data-lxbody]');
    try {
      const r = await api('lexpo', { id: el.dataset.lx, user_id: f.user_id || '' });
      box.innerHTML = r.entries.length ? `<div class="tw"><table><tr><th>Sl</th><th>Company</th><th>Contact person</th><th>Phone</th><th>Email</th><th>Comments / notes</th><th>Entered by</th><th>Added</th></tr>${r.entries.map((x, i) => `<tr><td class="num">${i + 1}</td><td><b>${esc(x.company)}</b></td><td>${esc(x.person || '')}</td><td class="num">${esc(x.phone || '')}</td><td>${esc(x.email || '')}</td><td>${esc(x.notes || '')}</td><td>${esc(x.entered_by || '—')}<div class="sub">${esc(x.dept)}</div></td><td class="num">${fd(x.created_at)}</td></tr>`).join('')}</table></div>` : '<div class="sub">No entries.</div>';
    } catch (err) { box.innerHTML = `<div class="alert bad"><span class="m">${esc(err.message)}</span></div>`; }
  }));
};

// ---------- DESIGN JOBS (designer) ----------
routes.mydesign = async () => {
  const d = await api('design_jobs');
  const pdf = (ent, id, label) => upBtn(ent, id, label, '.pdf');
  view().innerHTML = `
  <div class="head"><div><h1>My design jobs</h1><p>Jobs assigned to you by Zest. Fill the submission date and notes, and upload your design and working drawing as PDF.</p></div></div>
  ${d.jobs.length ? d.jobs.map(j => `<form class="panel" data-dsave="${j.id}">
    <div class="ph"><h2>${esc(j.expo)} · ${esc(j.company)}</h2>${j.design_submitted_on ? '<span class="pill p-ok">submitted</span>' : '<span class="pill p-pending">pending</span>'}</div>
    <div class="grid g3 sub" style="margin-bottom:12px">
     <div><b>Expo name</b><br>${esc(j.expo)}${j.location ? ', ' + esc(j.location) : ''}<br>${fd(j.start_date)}</div>
     <div><b>Company name</b><br>${esc(j.company)}</div>
     <div><b>Area</b><br>${j.area_sqm ? num(j.area_sqm) + ' sqm' : '—'}${j.space_type ? ' · ' + esc(j.space_type) : ''}${j.stall_no ? '<br>Stall ' + esc(j.stall_no) : ''}</div>
     <div><b>No. of sides open</b><br>${sidesTxt(j.sides_open)}</div>
     <div><b>Design assigned by</b><br>${esc(j.design_assigned_by || '—')}</div>
     <div><b>Design assign date</b><br>${fd(j.assigned_on)}</div>
    </div>
    ${j.files.pbrief.length || j.files.floor.length ? `<div class="row" style="margin-bottom:12px">${j.files.pbrief.length ? '<span class="sub">Client brief:</span>' + fileChips(j.files.pbrief) : ''}${j.files.floor.length ? '<span class="sub">Floor plan:</span>' + fileChips(j.files.floor) : ''}</div>` : ''}
    <div class="grid"><label class="f">Design submission date<input type="date" name="submitted_on" value="${esc(j.design_submitted_on || '')}"></label>
     <label class="f wide">Notes / comments <span class="sub" style="font-weight:400">(e.g. need more inputs or details)</span><textarea name="notes">${esc(j.design_notes || '')}</textarea></label></div>
    <div class="grid" style="margin-top:12px"><div><h3 style="margin-bottom:6px">Design (PDF)</h3><div class="row">${pdf('final', j.id, 'Upload design PDF')}${fileChips(j.files.final)}</div></div>
     <div><h3 style="margin-bottom:6px">Working drawing (PDF)</h3><div class="row">${pdf('wd', j.id, 'Upload working drawing PDF')}${fileChips(j.files.wd)}</div></div></div>
    <div class="row" style="margin-top:12px"><button class="btn">Save</button>${j.design_approved_by ? `<span class="sub">Approved by ${esc(j.design_approved_by)}</span>` : ''}</div></form>`).join('')
  : `<div class="alert warn"><b>No design job assigned to you yet</b><span class="m">Your jobs appear here as soon as admin assigns one: admin opens the order → <b>Design</b> tab → <b>Assign designer</b> → picks your name.</span></div>
    <div class="panel" aria-label="Preview of a design job" style="opacity:.6"><div class="ph"><h2>Sample job · how each job will look</h2><span class="pill p-pending">pending</span></div>
     <div class="grid g3 sub" style="margin-bottom:12px">${['Expo name', 'Company name', 'Area', 'No. of sides open', 'Design assigned by', 'Design assign date'].map(l => `<div><b>${l}</b><br>filled in automatically</div>`).join('')}</div>
     <div class="grid"><label class="f">Design submission date<input type="date" disabled></label><label class="f wide">Notes / comments<textarea disabled placeholder="e.g. need more inputs or details"></textarea></label></div>
     <div class="grid" style="margin-top:12px"><div><h3 style="margin-bottom:6px">Design (PDF)</h3><span class="up">⬆ Upload design PDF</span></div><div><h3 style="margin-bottom:6px">Working drawing (PDF)</h3><span class="up">⬆ Upload working drawing PDF</span></div></div></div>`}`;
};

// ---------- PRODUCTION JOBS (operations) ----------
routes.myops = async () => {
  const d = await api('ops_jobs');
  view().innerHTML = `<div class="head"><div><h1>My production jobs</h1><p>Open a job to fill stall dimensions, furniture and artwork details.</p></div></div>
  <div class="panel">${d.jobs.length ? `<div class="tw"><table><tr><th>Expo</th><th>Company</th><th>Expo date</th><th class="r">Area</th><th>Sides open</th><th>Vendor</th><th>Assigned</th><th>Details</th></tr>
   ${d.jobs.map(j => `<tr class="click" data-go="opsjob-${j.id}"><td><b>${esc(j.expo)}</b><div class="sub">${esc(j.location || '')}</div></td><td>${esc(j.company)}</td><td class="num">${fd(j.start_date)}</td><td class="r num">${j.area_sqm ? num(j.area_sqm) + ' sqm' : '—'}</td><td>${sidesTxt(j.sides_open)}</td><td>${j.vendor ? esc(j.vendor.name) : '<span class="sub">not assigned</span>'}</td><td class="sub">${esc(j.ops_assigned_by || '')}<br>${fd(j.ops_assigned_on)}</td><td>${j.ops_updated_at ? `<span class="pill p-ok">filled</span><div class="sub">${fd(j.ops_updated_at)}</div>` : '<span class="pill p-pending">to fill</span>'}</td></tr>`).join('')}</table></div>`
  : `<div class="alert warn" style="margin-bottom:12px"><b>No production job assigned to you yet</b><span class="m">Admin opens the order → <b>Production</b> tab → <b>Assign production person</b> → picks your name. The job then opens here with dimensions, furniture, backlit walls and vinyl artwork fields.</span></div>${blank('Waiting for your first job.')}`}</div>`;
};
routes.opsjob = async ({ id }) => {
  const { job } = await api('ops_job', { id });
  view().innerHTML = `<div class="head"><div><div class="crumb"><a href="#${isAdmin() ? 'jobs' : B.records ? 'project-' + job.id + '-production' : 'myops'}">← Back</a></div><h1>${esc(job.expo)} · ${esc(job.company)}</h1><p>${fd(job.start_date)}${job.location ? ' · ' + esc(job.location) : ''}${job.stall_no ? ' · Stall ' + esc(job.stall_no) : ''}</p></div>${pill(job.status)}</div>
   <div class="panel">${opsForm(job)}</div>`;
};
const OPS_BLANK = () => ({ length: { v: '', u: 'ft' }, width: { v: '', u: 'ft' }, wall_h: { v: '', u: 'ft' }, fascia_h: { v: '', u: 'ft' }, vendor_cost: '', furniture: [], backlit_walls: '', vinyl_total: 0, vinyl: [], notes: '' });
function opsForm(job) {   // used on the production job page and in the order's Production tab
  C.opsJob = job; C.ops = Object.assign(OPS_BLANK(), JSON.parse(JSON.stringify(job.ops || {})));
  if ((C.ops.vendor_cost === '' || C.ops.vendor_cost === null) && job.vendor) C.ops.vendor_cost = job.vendor.amount;
  return `<form id="opsform" data-pid="${job.id}"><div id="opsbox">${opsInner()}</div>
   <div class="row" style="margin-top:14px"><button class="btn">Save production details</button>${job.ops_updated_at ? `<span class="sub">Last saved ${fd(job.ops_updated_at)} ${String(job.ops_updated_at).slice(11, 16)}</span>` : ''}</div></form>`;
}
function opsInner() {
  const j = C.opsJob, o = C.ops;
  const dim = (k, l) => `<label class="f">${l}<span class="row" style="flex-wrap:nowrap;gap:6px"><input type="number" step="any" data-op="${k}.v" value="${esc(o[k]?.v ?? '')}">${unitSel(k + '.u', o[k]?.u)}</span></label>`;
  const items = [...new Set([...(B.furniture_items || []), ...o.furniture.map(f => f.item)])];
  const has = it => o.furniture.find(f => f.item === it);
  return `<div class="grid g3 sub">
    <div><b>Expo name</b><br>${esc(j.expo)}</div><div><b>Company name</b><br>${esc(j.company)}</div><div><b>Area</b><br>${j.area_sqm ? num(j.area_sqm) + ' sqm' : '—'}</div>
    <div><b>No. of sides open</b><br>${sidesTxt(j.sides_open)}</div><div><b>Vendor assigned</b><br>${j.vendor ? esc(j.vendor.name) : 'Not assigned yet (chosen in Vendor quotes)'}</div>
    <div><b>Production person</b><br>${esc(j.ops_person || '—')}${j.ops_assigned_by ? `<br>assigned by ${esc(j.ops_assigned_by)}` : ''}</div></div>
   <div class="grid g3" style="margin-top:12px;max-width:360px"><label class="f">Vendor cost agreed (₹)<input type="number" step="any" data-op="vendor_cost" value="${esc(o.vendor_cost ?? '')}"></label></div>
   <h3 style="margin:18px 0 8px">Stall dimensions</h3>
   <div class="grid">${dim('length', 'Length')}${dim('width', 'Width')}${dim('wall_h', 'Wall height')}${dim('fascia_h', 'Fascia height')}</div>
   <h3 style="margin:18px 0 8px">Furniture & fixtures</h3>
   <div class="grid g3">${items.map(it => { const f = has(it); return `<label class="row inset" style="gap:8px;padding:8px 10px;flex-wrap:nowrap"><input type="checkbox" data-furn="${esc(it)}" ${f ? 'checked' : ''}><span style="flex:1">${esc(it)}</span>${f ? `<input type="number" min="0" data-furnq="${esc(it)}" value="${f.qty}" aria-label="Quantity of ${esc(it)}" style="max-width:70px">` : ''}</label>`; }).join('')}</div>
   <div class="row" style="margin-top:8px"><input id="ops-furn-new" placeholder="Other item, e.g. Podium" style="max-width:260px"><button type="button" class="btn ghost sm" id="ops-furn-add">+ Add item</button></div>
   <h3 style="margin:18px 0 8px">Graphics</h3>
   <div class="grid g3"><label class="f">Total backlit walls<input type="number" min="0" data-op="backlit_walls" value="${esc(o.backlit_walls ?? '')}"></label><label class="f">Total vinyl artworks<input type="number" min="0" max="50" data-op="vinyl_total" value="${esc(o.vinyl_total || '')}"></label></div>
   ${o.vinyl.length ? `<div class="tw" style="margin-top:10px"><table><tr><th>Vinyl artwork</th><th>Width</th><th>Height</th></tr>${o.vinyl.map((a, i) => `<tr><td><b>Artwork ${i + 1}</b></td>
     <td><span class="row" style="flex-wrap:nowrap;gap:6px"><input type="number" step="any" data-op="vinyl.${i}.w" value="${esc(a.w ?? '')}" aria-label="Artwork ${i + 1} width" style="max-width:110px">${unitSel(`vinyl.${i}.wu`, a.wu)}</span></td>
     <td><span class="row" style="flex-wrap:nowrap;gap:6px"><input type="number" step="any" data-op="vinyl.${i}.h" value="${esc(a.h ?? '')}" aria-label="Artwork ${i + 1} height" style="max-width:110px">${unitSel(`vinyl.${i}.hu`, a.hu)}</span></td></tr>`).join('')}</table></div>` : ''}
   <label class="f" style="margin-top:14px">Notes<textarea data-op="notes">${esc(o.notes || '')}</textarea></label>`;
}
function drawOps() { const b = $('#opsbox'); if (b) { const y = window.scrollY; b.innerHTML = opsInner(); window.scrollTo(0, y); } }

// ---------- ADMIN: design & production board ----------
routes.jobs = async () => {
  const f = C.jobsf || (C.jobsf = { q: '', show: 'open' });
  const d = await api('jobs_board', f);
  const needs = d.jobs.filter(j => ['won', 'quoted'].includes(j.status) && !j.designer_id).length;
  view().innerHTML = `
  <div class="head"><div><h1>Design & production</h1><p>Who is designing and producing each order, and how far they are.</p></div>
  </div>
  <form class="panel row" id="jobsf" style="align-items:flex-end"><label class="f" style="flex:1;min-width:200px">Search client or expo<input name="q" value="${esc(f.q)}"></label><label class="f">Show<select name="show"><option value="open" ${f.show === 'open' ? 'selected' : ''}>Open orders</option><option value="all" ${f.show === 'all' ? 'selected' : ''}>Include completed</option></select></label><button class="btn ghost">Apply</button></form>
  ${needs ? `<div class="alert warn"><b>${needs} order${needs > 1 ? 's' : ''} without a designer</b><span class="m">Open the order → Design tab → Assign designer.</span></div>` : ''}
  <div class="panel">${d.jobs.length ? `<p class="sub" style="margin-top:0">Pick a designer or production person in the row to assign the job. Click a row to open the order.</p><div class="tw"><table><tr><th>Client · expo</th><th>Expo date</th><th>Area · sides</th><th>Status</th><th>Sales</th><th>Designer</th><th>Design submitted</th><th>Files</th><th>Production</th><th>Vendor</th></tr>
   ${d.jobs.map(j => `<tr class="click" data-go="project-${j.id}-design"><td><b>${esc(j.company)}</b><div class="sub">${esc(j.expo)}</div></td><td class="num">${fd(j.start_date)}</td><td>${j.area_sqm ? num(j.area_sqm) + ' sqm' : '—'}<div class="sub">${j.sides_open ? sidesTxt(j.sides_open) : ''}</div></td><td>${pill(j.status)}</td><td>${esc(j.owner)}</td>
    <td><select data-jassign="designer:${j.id}" aria-label="Designer for ${esc(j.expo)}"><option value="0">Not assigned</option>${usersOfMod('design').map(u => `<option value="${u.id}" ${+u.id === +j.designer_id ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select>${j.designer ? `<div class="sub">by ${esc(j.design_assigned_by || '—')} · ${fd(j.assigned_on)}</div>` : ''}</td>
    <td>${j.design_submitted_on ? `<span class="pill p-ok">${fd(j.design_submitted_on)}</span>` : j.designer ? '<span class="pill p-pending">pending</span>' : '—'}${j.design_notes ? `<div class="sub" title="${esc(j.design_notes)}">💬 ${esc(j.design_notes.slice(0, 40))}${j.design_notes.length > 40 ? '…' : ''}</div>` : ''}</td>
    <td class="sub">Design ${j.final_n}<br>WD ${j.wd_n}</td>
    <td><select data-jassign="ops:${j.id}" aria-label="Production person for ${esc(j.expo)}"><option value="0">Not assigned</option>${usersOfMod('ops').map(u => `<option value="${u.id}" ${+u.id === +j.ops_id ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select>${j.ops_person ? `<div class="sub">${j.ops_filled ? 'details filled ' + fd(j.ops_updated_at) : 'details pending'}</div>` : ''}</td>
    <td>${j.vendor ? `${esc(j.vendor.name)}<div class="sub">${inr(j.vendor.amount)}</div>` : '—'}</td></tr>`).join('')}</table></div>` : blank('No expo orders yet. Jobs come from orders: add a client with its expo under <a href="#newclient">+ New client</a>, or open a client and click “+ New expo order”. The order then shows here to assign a designer and production person.')}</div>`;
  $('#jobsf').onsubmit = e => { e.preventDefault(); C.jobsf = Object.fromEntries(new FormData(e.target)); refresh(); };
};

// ---------- APPROVALS (design + quote) ----------
const STAGE = { draft: ['p-off', 'draft · not sent'], pending: ['p-pending', 'waiting approval'], approved: ['p-ok', 'approved · to send'], rejected: ['p-bad', 'rejected'], sent: ['p-received', 'sent to customer'] };
const stageOf = a => a.sent_on ? 'sent' : a.status;
const stagePill = a => { const [c, l] = STAGE[stageOf(a)]; return `<span class="pill ${c}">${l}</span>`; };
const dt = v => v ? `${fd(v)} <span class="sub">${String(v).slice(11, 16)}</span>` : '—';
// one version's controls: approve/reject (admin), withdraw (requester), mark sent (sales/admin)
function apprActions(a) {
  const st = stageOf(a), mine = +a.requested_by === +B.me.id;
  if (st === 'draft') return mine ? `<div class="row"><a class="btn sm" href="#qgen-${a.project_id}-${a.id}">Continue</a><button class="btn link" data-acancel="${a.id}">Delete draft</button></div>` : '';
  if (st === 'rejected' && a.has_doc && B.records) return `<a class="btn ghost sm" href="#qgen-${a.project_id}-${a.id}">Correct → new version</a>`;
  if (st === 'pending') return `${isAdmin() ? `<div class="row" style="flex-wrap:nowrap;gap:6px"><input data-anote="${a.id}" placeholder="Note (needed to reject)" style="min-width:160px">
      <button class="btn sm" data-adec="approved:${a.id}">Approve</button><button class="btn sm danger" data-adec="rejected:${a.id}">Reject</button></div>` : ''}
    ${mine || isAdmin() ? `<div class="row" style="margin-top:4px">${upBtn('appr', a.id, 'Add file')}<button class="btn link" data-acancel="${a.id}">Withdraw</button></div>` : ''}`;
  if (st === 'approved' && (B.records || isAdmin())) return `<div class="row" style="flex-wrap:nowrap;gap:6px"><input type="date" data-sentdate="${a.id}" value="${B.today}" aria-label="Date sent to customer" style="max-width:150px"><button class="btn sm" data-asent="${a.id}">Mark sent to customer</button></div>`;
  return '';
}
function apprTable(list, showAmount) {
  return `<div class="tw"><table><tr><th>Ver.</th><th>Sent for approval</th>${showAmount ? '<th class="r">Amount</th>' : ''}<th>Files</th><th>Sales note</th><th>Admin decision</th><th>Sent to customer</th><th>Status</th><th></th></tr>
  ${list.map(a => `<tr><td><b>V${a.version}</b></td><td class="num">${dt(a.requested_at)}<div class="sub">${esc(a.requested_by_name || '')}</div></td>${showAmount ? `<td class="r num">${inr(a.amount)}</td>` : ''}
   <td>${qFiles(a)}${fileChips(a.files, a.status === 'pending' && (isAdmin() || +a.requested_by === +B.me.id))}</td><td class="sub">${esc(a.note || '')}</td>
   <td>${a.decided_at ? `<span class="num">${dt(a.decided_at)}</span><div class="sub">${esc(a.decided_by)}${a.admin_note ? ' — ' + esc(a.admin_note) : ''}</div>` : '—'}</td>
   <td>${a.sent_on ? `<span class="num">${fd(a.sent_on)}</span><div class="sub">${esc(a.sent_by)}</div>` : '—'}</td><td>${stagePill(a)}</td><td>${apprActions(a)}</td></tr>`).join('')}</table></div>`;
}
function apprPanel(p, kind) {
  const list = p.approvals.filter(a => a.kind === kind), q = kind === 'quote';
  const pend = list.find(a => a.status === 'pending');
  const reuse = q ? [] : [...p.files.final, ...p.files.wd];
  return `<div class="inset" style="margin-bottom:16px"><h3 style="margin-bottom:6px">${q ? 'Quote approvals' : 'Design approvals'}</h3>
   <p class="sub" style="margin-top:0">Every ${q ? 'quote' : 'design'} goes to admin for approval before it is sent to the customer. Each new version is numbered V1, V2, V3…</p>
   ${q ? quoteGenBlock(p) : ''}
   ${list.length ? apprTable(list, q) : `<div class="sub">No ${q ? 'quote' : 'design'} sent for approval yet.</div>`}
   ${B.records ? `${q ? '<details style="margin-top:12px"><summary class="btn link">Or attach a quote file made outside the CRM</summary>' : ''}<form data-areq="${kind}" style="margin-top:12px"><b class="sub">Send V${list.length + 1} for approval</b>${pend ? ` <span class="sub">· V${pend.version} is still waiting</span>` : ''}
    <div class="grid g3" style="margin-top:6px">${q ? `<label class="f">Quote amount (₹) *<input type="number" step="any" name="amount" required value="${num(p.agreed || p.offered || '')}"></label>` : ''}
     <label class="f wide">Note for admin <span class="sub" style="font-weight:400">(what changed in this version)</span><input name="note"></label></div>
    ${reuse.length ? `<div class="row" style="margin-top:8px"><span class="sub">Use designer's file:</span>${reuse.map(f => `<label class="row" style="gap:4px"><input type="checkbox" name="reuse" value="${f.id}">${esc(f.orig_name)}</label>`).join('')}</div>` : ''}
    <div class="row" style="margin-top:8px"><label class="up">⬆ ${q ? 'Attach quote (PDF)' : 'Attach design file'}<input type="file" name="file" multiple accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx,.pptx"></label><button class="btn sm">Send for approval</button></div></form>${q ? '</details>' : ''}` : ''}</div>`;
}


// ---------- customer approval of the design + quote generator ----------
function designOkBlock(p) {
  const sent = p.approvals.filter(a => a.kind === 'design' && a.sent_on);
  if (p.design_ok_on) return `<div class="alert" style="background:var(--ok-bg);color:var(--ok)"><b>Customer approved the design${p.design_ok_ver ? ` (V${p.design_ok_ver})` : ''} on ${fd(p.design_ok_on)}</b><span class="m">Marked by ${esc(p.design_ok_by || '')}. The quote can now be generated from the Order tab.${isAdmin() ? ' <button class="btn link" data-dokundo="1">Undo</button>' : ''}</span></div>`;
  if (!B.records) return '';
  return `<form class="inset" id="dokf" style="margin-bottom:16px"><h3 style="margin-bottom:6px">Customer approval</h3>
   ${sent.length ? `<div class="row" style="align-items:flex-end"><label class="f">Design version the customer approved<select name="approval_id">${[...sent].reverse().map(a => `<option value="${a.id}">V${a.version} · sent ${fd(a.sent_on)}</option>`).join('')}</select></label>
    <label class="f">Approved on<input type="date" name="on" value="${B.today}" max="${B.today}"></label><button class="btn sm">✓ Customer approved this design</button></div>`
   : `<div class="sub">Once a design version is approved by admin and marked “sent to customer”, mark here when the customer approves it. That unlocks “Generate quote”.</div>${isAdmin() ? '<div style="margin-top:8px"><button class="btn ghost sm" name="nover" value="1">Mark customer approved (design shared outside the CRM)</button></div>' : ''}`}</form>`;
}
function quoteGenBlock(p) {
  const qs = p.approvals.filter(a => a.kind === 'quote'), draft = qs.find(a => a.status === 'draft' && +a.requested_by === +B.me.id);
  const lastGen = [...qs].reverse().find(a => a.has_doc && a.status !== 'draft');
  if (!B.records) return '';
  if (!p.design_ok_on) return `<div class="alert"><b>Generate quote</b><span class="m">Available once the customer approves the design — mark it on the <a href="#project-${p.id}-design">Design tab</a>.</span></div>`;
  return `<div class="row" style="margin-bottom:12px">${draft ? `<a class="btn" href="#qgen-${p.id}-${draft.id}">Continue draft quote V${draft.version}</a>` : `<a class="btn" href="#qgen-${p.id}${lastGen ? '-' + lastGen.id : ''}">⚡ Generate quote${lastGen ? ` V${qs.length + 1} (from V${lastGen.version})` : ''}</a>`}
   <span class="sub">Filled from this order — check, adjust and send to admin for approval.</span></div>`;
}
const qFiles = a => a.has_doc ? `<a class="btn ghost sm" href="quote.php?id=${a.id}" target="_blank" rel="noopener">${a.status === 'approved' ? 'View / download' : 'View quote'}</a>${a.quote_no ? `<div class="sub num">${esc(a.quote_no)}</div>` : ''}` : '';

const Q_AREA = ['As Per Design', 'L.S', 'm²', 'Sq. ft.', 'Running ft.'];
routes.qgen = async ({ id, tab }) => {
  const d = await api('quote_prefill', { project_id: id, from_id: tab || 0 });
  if (!d.design_ok) { toast('Mark the design as approved by the customer first', true); location.hash = `#project-${id}-design`; return; }
  C.q = { pid: id, draft: d.draft_id, ver: d.version, doc: d.doc, meta: d };
  drawQuote();
};
function qCapture() {
  const f = $('#qgenf'); if (!f) return; const q = C.q.doc, g = k => f.querySelector(`[name="${k}"]`);
  ['date', 'valid_days', 'to_company', 'to_address', 'to_gstin', 'salutation', 'attn', 'expo', 'expo_dates', 'venue', 'total', 'discount', 'gst_rate', 'gst_mode', 'prepared_by', 'prepared_phone'].forEach(k => { if (g(k)) q[k] = g(k).value; });
  q.show_rates = g('show_rates').checked ? 1 : 0;
  ['payment_terms', 'notes', 'client_resp', 'excludes'].forEach(k => q[k] = g(k).value.split('\n').map(x => x.trim()).filter(Boolean));
  q.details = [...f.querySelectorAll('[data-qdrow]')].map(r => ({ l: r.querySelector('[data-k=l]').value, v: r.querySelector('[data-k=v]').value }));
  q.items = [...f.querySelectorAll('[data-qirow]')].map(r => ({ desc: r.querySelector('[data-k=desc]').value, qty: r.querySelector('[data-k=qty]').value, area: r.querySelector('[data-k=area]').value, amount: r.querySelector('[data-k=amount]')?.value ?? '', sub: r.querySelector('[data-k=sub]').checked ? 1 : 0 }));
  C.q.note = g('note').value;
}
function qTotals() {
  const f = $('#qgenf'); if (!f) return; const g = k => f.querySelector(`[name="${k}"]`);
  const rates = g('show_rates').checked;
  const sum = [...f.querySelectorAll('[data-k=amount]')].reduce((s, i) => s + (+String(i.value).replace(/,/g, '') || 0), 0);
  if (rates) g('total').value = sum ? Math.round(sum * 100) / 100 : '';
  const tot = +String(g('total').value).replace(/,/g, '') || 0, disc = +String(g('discount').value).replace(/,/g, '') || 0, r = +g('gst_rate').value || 0;
  const tx = Math.max(0, tot - disc), gst = Math.round(tx * r) / 100, igst = g('gst_mode').value === 'igst';
  $('#q-tot').innerHTML = `<div class="sum"><div class="l"><span>Total</span><span class="num">${inr2(tot)}</span></div>${disc ? `<div class="l"><span>Discount</span><span class="num">− ${inr2(disc)}</span></div>` : ''}
   ${igst ? `<div class="l"><span>IGST ${r}%</span><span class="num">${inr2(gst)}</span></div>` : `<div class="l"><span>CGST ${r / 2}%</span><span class="num">${inr2(Math.round(gst * 50) / 100)}</span></div><div class="l"><span>SGST ${r / 2}%</span><span class="num">${inr2(gst - Math.round(gst * 50) / 100)}</span></div>`}
   <div class="l tot big"><span>Grand total</span><span class="num">${inr2(tx + gst)}</span></div></div>`;
  g('total').readOnly = rates;
}
function drawQuote() {
  const { doc: q, meta, ver } = C.q, p = meta.project;
  const ta = (k, l, hint) => `<label class="f">${l}<textarea name="${k}" rows="${Math.max(3, q[k].length + 1)}" placeholder="${hint || 'One point per line'}">${esc(q[k].join('\n'))}</textarea></label>`;
  const inp = (k, l, t = 'text', extra = '') => `<label class="f">${l}<input name="${k}" type="${t}" value="${esc(q[k] ?? '')}" ${extra}></label>`;
  view().innerHTML = `<div class="head"><div><div class="crumb"><a href="#project-${p.id}-order">← ${esc(p.company)} · ${esc(p.expo)}</a></div><h1>Quotation V${ver}</h1><p>Filled from the order. Check every section, then send it to admin for approval.</p></div><span class="pill p-pending">${C.q.draft ? 'draft' : 'new'}</span></div>
  <form id="qgenf">
   <div class="panel"><h2>Customer</h2><div class="grid g3">${inp('date', 'Quote date *', 'date', 'required')}${inp('valid_days', 'Valid for (days)', 'number', 'min="1"')}${inp('to_company', 'Company (M/S) *', 'text', 'required')}
    <label class="f">Salutation<select name="salutation">${['Mr.', 'Ms.', 'Mrs.', 'Dr.', 'Sir / Madam'].map(x => `<option ${q.salutation === x ? 'selected' : ''}>${x}</option>`).join('')}</select></label>${inp('attn', 'Contact person')}${inp('to_gstin', 'Customer GSTIN')}</div>
    <label class="f" style="margin-top:10px">Customer address<textarea name="to_address" rows="2">${esc(q.to_address)}</textarea></label></div>
   <div class="panel"><h2>Event</h2><div class="grid g3">${inp('expo', 'Expo name *', 'text', 'required')}${inp('expo_dates', 'Dates (as printed)', 'text', 'placeholder="27 – 29 November 2026"')}${inp('venue', 'Venue')}</div></div>
   <div class="panel"><h2>General details</h2><p class="sub" style="margin-top:0">Blank lines are left out of the quote.</p>
    <div id="q-det">${q.details.map((x, k) => `<div class="row" data-qdrow style="margin-top:6px;flex-wrap:nowrap"><input data-k="l" value="${esc(x.l)}" style="max-width:180px"><input data-k="v" value="${esc(x.v)}" style="flex:1;min-width:0"><button type="button" class="btn link" data-qdel="${k}" aria-label="Remove">✕</button></div>`).join('')}</div>
    <button type="button" class="btn ghost sm" id="q-detadd" style="margin-top:8px">+ Add detail</button></div>
   <div class="panel"><div class="ph"><h2>Items</h2><label class="row" style="gap:6px"><input type="checkbox" name="show_rates" ${+q.show_rates ? 'checked' : ''}> Show price against each item</label></div>
    <p class="sub" style="margin-top:0">Tick “Sub” for points under a heading (a, b, c). Area can be “As Per Design”, “L.S” (lumpsum) or a size.</p>
    <div class="tw"><table id="q-items"><tr><th style="width:44px">Sub</th><th>Particulars</th><th style="width:80px">Qty</th><th style="width:150px">Area</th>${+q.show_rates ? '<th style="width:130px">Amount (₹)</th>' : ''}<th style="width:96px"></th></tr>
     ${q.items.map((x, k) => `<tr data-qirow><td style="text-align:center"><input type="checkbox" data-k="sub" ${+x.sub ? 'checked' : ''} aria-label="Sub point"></td><td><input data-k="desc" value="${esc(x.desc)}" ${+x.sub ? 'style="margin-left:18px;width:calc(100% - 18px)"' : ''}></td><td><input data-k="qty" value="${esc(x.qty)}"></td><td><input data-k="area" list="q-area" value="${esc(x.area)}"></td>${+q.show_rates ? `<td><input data-k="amount" type="number" step="any" value="${esc(x.amount)}" data-qcalc></td>` : ''}
      <td class="r" style="white-space:nowrap"><button type="button" class="btn link" data-qup="${k}" aria-label="Move up">↑</button><button type="button" class="btn link" data-qdown="${k}" aria-label="Move down">↓</button><button type="button" class="btn link" data-qidel="${k}" aria-label="Remove">✕</button></td></tr>`).join('')}</table></div>
    <datalist id="q-area">${Q_AREA.map(x => `<option value="${x}">`).join('')}</datalist>
    <button type="button" class="btn ghost sm" id="q-itemadd" style="margin-top:8px">+ Add item</button></div>
   <div class="panel"><h2>Amount</h2><div class="grid"><div class="grid g3">${inp('total', 'Total (₹) *', 'number', 'step="any" data-qcalc')}${inp('discount', 'Discount (₹)', 'number', 'step="any" min="0" data-qcalc')}${inp('gst_rate', 'GST rate (%)', 'number', 'step="any" data-qcalc')}
     <label class="f">GST type<select name="gst_mode" data-qcalc><option value="igst" ${q.gst_mode === 'igst' ? 'selected' : ''}>IGST (customer in another state)</option><option value="cgst_sgst" ${q.gst_mode === 'cgst_sgst' ? 'selected' : ''}>CGST + SGST (same state as Zest)</option></select></label></div><div class="inset" id="q-tot"></div></div>
    <div class="sub">Amount in words is added automatically.</div></div>
   <div class="panel"><h2>Terms</h2><div class="grid">${ta('payment_terms', 'Payment terms')}${ta('notes', 'Important notes & terms', 'One point per line · {valid} = validity days')}${ta('client_resp', 'Client responsibilities & ownership')}${ta('excludes', 'Quotation does not include')}</div>
    <p class="sub">Bank details, GSTIN and contact line come from Company settings.</p></div>
   <div class="panel"><h2>Send</h2><div class="grid g3">${inp('prepared_by', 'Prepared by')}${inp('prepared_phone', 'Phone')}<label class="f wide">Note for admin<input name="note" value="${esc(C.q.note || '')}" placeholder="e.g. Agreed 15k discount on call"></label></div>
    <div class="row" style="margin-top:12px"><button class="btn ghost" data-qsave="draft">Save draft</button><button class="btn ghost" data-qsave="preview">Save & preview</button><button class="btn" data-qsave="submit">Send to admin for approval</button></div></div>
  </form>`;
  qTotals();
  const f = $('#qgenf');
  f.addEventListener('input', e => { if (e.target.matches('[data-qcalc]')) qTotals(); });
  f.addEventListener('change', e => { if (e.target.name === 'show_rates' || e.target.dataset.k === 'sub') { qCapture(); drawQuote(); } else if (e.target.matches('[data-qcalc]')) qTotals(); });
  f.addEventListener('click', e => {
    const b = e.target.closest('button[type=button]'); if (!b) return; qCapture(); const it = C.q.doc.items, ds = b.dataset;
    if (b.id === 'q-itemadd') it.push({ desc: '', qty: '', area: '', amount: '', sub: 0 });
    else if (b.id === 'q-detadd') C.q.doc.details.push({ l: '', v: '' });
    else if (ds.qidel !== undefined) it.splice(+ds.qidel, 1);
    else if (ds.qdel !== undefined) C.q.doc.details.splice(+ds.qdel, 1);
    else if (ds.qup !== undefined && +ds.qup > 0) [it[+ds.qup - 1], it[+ds.qup]] = [it[+ds.qup], it[+ds.qup - 1]];
    else if (ds.qdown !== undefined && +ds.qdown < it.length - 1) [it[+ds.qdown + 1], it[+ds.qdown]] = [it[+ds.qdown], it[+ds.qdown + 1]];
    else return;
    drawQuote();
  });
  f.onsubmit = async e => {
    e.preventDefault(); qCapture(); const how = e.submitter?.dataset.qsave || 'draft';
    if (how === 'submit' && !confirm('Send this quote to admin for approval?')) return;
    const btns = f.querySelectorAll('[data-qsave]'); btns.forEach(x => x.disabled = true);
    const r = await act(() => api('quote_save', { project_id: C.q.pid, id: C.q.draft || 0, doc: C.q.doc, note: C.q.note, submit: how === 'submit' ? 1 : 0 }), how === 'submit' ? `Quote V${C.q.ver} sent to admin for approval` : 'Draft saved').catch(() => null);
    btns.forEach(x => x.disabled = false); if (!r) return;
    C.q.draft = r.status === 'draft' ? r.id : null;
    if (how === 'preview') { window.open('quote.php?id=' + r.id, '_blank', 'noopener'); return; }
    if (how === 'submit') { await reboot(); location.hash = `#project-${C.q.pid}-order`; }
  };
}


// ---------- referral leads (admin hands a lead to a sales person or another admin) ----------
const REF_ST = { new: ['p-pending', 'new · not opened'], seen: ['p-enquiry', 'opened'], converted: ['p-ok', 'added as client'], closed: ['p-bad', 'closed'] };
const refPill = r => `<span class="pill ${REF_ST[r.status][0]}">${REF_ST[r.status][1]}</span>`;
const refLead = r => `<b>${esc(r.company)}</b><div>${esc(r.name)} · <a href="tel:${esc(String(r.phone).replace(/[^\d+]/g, ''))}" class="num">${esc(r.phone)}</a></div>${r.area ? `<div class="sub">Area: ${esc(r.area)}</div>` : ''}${r.note ? `<div class="sub">${esc(r.note)}</div>` : ''}`;
const refHist = r => r.history.length ? `<details style="margin-top:4px"><summary class="sub" style="cursor:pointer">History</summary>${r.history.map(h => `<div class="sub">${fd(h.at)} ${String(h.at).slice(11, 16)} · ${esc(h.text)}</div>`).join('')}</details>` : '';
function refTake(r) {   // the person it was handed to: add as client / cannot take it up
  if (!['new', 'seen'].includes(r.status) || +r.assigned_to !== +B.me.id) return '';
  return `<div class="row" style="margin-top:8px"><button class="btn sm" data-refadd="${r.id}">+ Add as client</button><button class="btn ghost sm" data-refclose="${r.id}">Can't take it up</button></div>
   <form class="inset" data-refclosef="${r.id}" hidden style="margin-top:8px"><label class="f">Why? *<input name="reason" required placeholder="e.g. Not reachable after 3 calls · budget too low"></label><div style="margin-top:6px"><button class="btn danger sm">Close lead</button></div></form>`;
}
routes.refs = async () => {
  const f = C.reff || (C.reff = { status: isAdmin() ? '' : '' });
  const d = await api('ref_list', f); C.refs = d.rows;
  const unseen = d.rows.filter(r => r.status === 'new' && +r.assigned_to === +B.me.id);
  if (unseen.length) { await Promise.all(unseen.map(r => api('ref_seen', { id: r.id }))); B.ref_new = 0; }
  renderNav('refs');
  const open = (d.counts.new || 0) + (d.counts.seen || 0);
  const tabs = [['', 'All'], ['new', 'Not opened'], ['seen', 'Opened'], ['converted', 'Added as client'], ['closed', 'Closed']];
  const T = d.targets, sales = T.filter(u => u.role !== 'admin'), admins = T.filter(u => u.role === 'admin');
  const toOpts = sel => `<option value="">Pick sales person or admin</option><optgroup label="Sales team">${sales.map(u => `<option value="${u.id}" ${+sel === +u.id ? 'selected' : ''}>${esc(u.name)}${u.designation ? ' · ' + esc(u.designation) : ''}</option>`).join('')}</optgroup><optgroup label="Admins">${admins.map(u => `<option value="${u.id}" ${+sel === +u.id ? 'selected' : ''}>${esc(u.name)}${+u.id === +B.me.id ? ' (me)' : ''}</option>`).join('')}</optgroup>`;
  view().innerHTML = `<div class="head"><div><h1>Referral leads</h1><p>${isAdmin() ? 'Leads from your contacts in the market. Hand each one to the sales person or admin best placed to take it up.' : 'Leads admin has handed to you. Call them, then add them as a client — or close with a reason.'}</p></div>${open ? `<span class="pill p-pending">${open} open</span>` : ''}</div>
  ${isAdmin() ? `<form class="panel" id="reff"><h2>Refer a lead</h2><div class="grid g3">
    <label class="f">Name of the person *<input name="name" required autocomplete="off"></label><label class="f">Contact number *<input name="phone" type="tel" required></label><label class="f">Company name *<input name="company" required autocomplete="off"></label>
    <label class="f">Area<input name="area" placeholder="e.g. 36 sqm"></label><label class="f">Hand to *<select name="assigned_to" required>${toOpts('')}</select></label><label class="f">Note<input name="note" placeholder="Optional: expo, city, how you know them"></label></div>
    <div id="refdup" style="margin-top:10px"></div><div style="margin-top:10px"><button class="btn">Refer lead</button></div></form>` : ''}
  <div class="tabs" style="background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:0 8px">${tabs.map(([k, l]) => `<a href="#refs" data-reft="${k}" class="${(f.status || '') === k ? 'on' : ''}">${l}${k && d.counts[k] ? ` <span class="badge" style="background:var(--navy-soft);color:var(--navy)">${d.counts[k]}</span>` : ''}</a>`).join('')}</div>
  <div class="panel">${d.rows.length ? `<div class="tw"><table><tr><th>Referred</th><th>Lead</th>${isAdmin() ? '<th>With</th>' : '<th>From</th>'}<th>Status</th><th></th></tr>
   ${d.rows.map(r => `<tr><td class="num" style="white-space:nowrap">${fd(r.created_at)}<div class="sub">by ${esc(r.by_name)}</div></td><td>${refLead(r)}${refHist(r)}</td>
    <td>${isAdmin() ? `<b>${esc(r.to_name)}</b><div class="sub">since ${fd(r.assigned_at)}</div>` : esc(r.by_name)}</td>
    <td>${refPill(r)}${r.status === 'converted' && r.client_id ? `<div><a href="#client-${r.client_id}">${esc(r.client_company || 'Open client')} →</a></div>` : ''}${r.status === 'closed' ? `<div class="sub">${esc(r.closed_reason || '')}</div>` : ''}</td>
    <td style="min-width:220px">${refTake(r)}
     ${isAdmin() && ['new', 'seen'].includes(r.status) ? `<form class="row" data-refmove="${r.id}" style="margin-top:6px;flex-wrap:nowrap"><select name="assigned_to" aria-label="Hand to someone else" style="min-width:0">${toOpts(r.assigned_to)}</select><button class="btn ghost sm">Reassign</button></form>` : ''}
     ${isAdmin() && r.status === 'closed' ? `<button class="btn link" data-refreopen="${r.id}">Reopen</button>` : ''}</td></tr>`).join('')}</table></div>` : blank(isAdmin() ? 'No referral leads yet. Use the form above to hand one to your team.' : 'No referral leads handed to you.')}</div>`;
  const rf = $('#reff'); if (rf) {
    let tm; rf.addEventListener('input', e => { if (!['company', 'phone'].includes(e.target.name)) return; clearTimeout(tm); tm = setTimeout(async () => {
      const o = Object.fromEntries(new FormData(rf)); if ((o.company || '').trim().length < 3 && (o.phone || '').replace(/\D/g, '').length < 10) { $('#refdup').innerHTML = ''; return; }
      const r = await api('dup', { company: o.company, phone: o.phone }).catch(() => ({ matches: [] }));
      $('#refdup').innerHTML = r.matches.map(m => +m.dnq ? `<div class="alert bad"><b>⛔ Do not quote — ${esc(m.company)}</b><span class="m">Reason: ${esc(m.dnq_reason)}${m.dnq_note ? ' · ' + esc(m.dnq_note) : ''}</span></div>`
        : `<div class="alert warn"><b>Already in the CRM — ${esc(m.company)}</b><span class="m">Handled by ${esc(m.owner)} · ${m.orders} order(s). <a href="#client-${m.id}">Open client →</a> You may want to hand it to ${esc(m.owner)}.</span></div>`).join('');
    }, 350); });
    rf.onsubmit = async e => { e.preventDefault(); const ok = await act(() => api('ref_save', Object.fromEntries(new FormData(rf))), 'Lead referred — they will see it on their dashboard').catch(() => null); if (ok) { await reboot(); refresh(); } };
  }
};
function refAlert() { const n = B.ref_new; if (!n) return; const w = $('.welcome') || $('.head'); if (w) w.insertAdjacentHTML('afterend', `<div class="alert warn"><b>${n} new referral lead${n > 1 ? 's' : ''} for you</b><span class="m">Handed to you by admin. <a href="#refs">Open referral leads →</a></span></div>`); }

routes.saleslog = async () => {
  const f = C.slog || (C.slog = { status: 'pending', kind: '', user_id: '', q: '', from: '', to: '' });
  const d = await api('sales_log', f);
  B.pending_approvals = d.counts.pending; renderNav('saleslog');
  const qs = new URLSearchParams(Object.entries(f).filter(([, v]) => v)).toString();
  const tabs = [['pending', 'Waiting approval', d.counts.pending], ['approved', 'Approved · to send', d.counts.approved], ['rejected', 'Rejected', d.counts.rejected], ['sent', 'Sent to customer', d.counts.sent], ['all', 'All', '']];
  const sales = d.people;
  const ftag = { 'Sent for approval': 'p-pending', 'Approved': 'p-ok', 'Rejected': 'p-bad', 'Sent to customer': 'p-received', 'Client added': 'p-enquiry', 'Enquiry opened': 'p-off' };
  view().innerHTML = `
  <div class="head"><div><h1>Sales log</h1><p>Designs and quotes sent by sales for approval, every version with dates, and what each sales person is doing.</p></div><a class="btn" href="export_sales.php${qs ? '?' + qs : ''}">⬇ Export to Excel</a></div>
  <div class="tabs" style="background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:0 8px">${tabs.map(([k, l, n]) => `<a href="#saleslog" data-slt="${k}" class="${f.status === k ? 'on' : ''}">${l}${n ? ` <span class="badge" style="${k === 'pending' ? '' : 'background:var(--navy-soft);color:var(--navy)'}">${n}</span>` : ''}</a>`).join('')}</div>
  <form class="panel row" id="slogf" style="align-items:flex-end">
   <label class="f" style="flex:1;min-width:180px">Search client / expo<input name="q" value="${esc(f.q)}"></label>
   <label class="f">Type<select name="kind"><option value="">Design & quote</option><option value="design" ${f.kind === 'design' ? 'selected' : ''}>Design</option><option value="quote" ${f.kind === 'quote' ? 'selected' : ''}>Quote</option></select></label>
   <label class="f">Sales person<select name="user_id"><option value="">Everyone</option>${sales.map(u => `<option value="${u.id}" ${+f.user_id === +u.id ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select></label>
   <label class="f">Requested from<input type="date" name="from" value="${f.from}"></label><label class="f">to<input type="date" name="to" value="${f.to}"></label><input type="hidden" name="status" value="${f.status}">
   <button class="btn ghost">Apply</button></form>
  <div class="log">${d.rows.length ? d.rows.map(a => `<details ${f.status === 'pending' ? 'open' : ''}><summary><b>${esc(a.company)}</b><span class="sub">${esc(a.expo)}</span><span class="pill ${a.kind === 'quote' ? 'p-enquiry' : 'p-off'}">${a.kind} V${a.version}</span>${a.amount ? `<span class="num">${inr(a.amount)}</span>` : ''}
      <span style="margin-left:auto" class="row"><span class="sub">${esc(a.requested_by_name)} · ${fd(a.requested_at)} ${String(a.requested_at).slice(11, 16)}</span>${stagePill(a)}</span></summary>
     <div class="in"><div class="row" style="margin-bottom:8px"><a class="btn ghost sm" href="#project-${a.project_id}-${a.kind === 'quote' ? 'order' : 'design'}">Open order</a><span class="sub">All ${a.kind} versions for this order:</span></div>${apprTable(a.versions, a.kind === 'quote')}</div></details>`).join('')
    : blank(f.status === 'pending' ? 'Nothing waiting for approval.' : 'Nothing in this view.')}</div>
  ${sales.length ? `<div class="panel"><h2>Sales team</h2><div class="tw"><table><tr><th>Sales person</th><th class="r">Clients</th><th class="r">Added this month</th><th class="r">Orders</th><th class="r">Won</th><th class="r">Business</th><th class="r">Designs sent</th><th class="r">Quotes sent</th><th class="r">Waiting</th><th class="r">To send</th><th class="r">Rejected</th></tr>
   ${sales.map(u => `<tr><td><b>${esc(u.name)}</b><div class="sub">${esc(u.dept)}</div></td><td class="r num">${u.clients}</td><td class="r num">${u.clients_month}</td><td class="r num">${u.orders}</td><td class="r num">${u.won}</td><td class="r num">${inr(u.business)}</td><td class="r num">${u.designs}</td><td class="r num">${u.quotes}</td><td class="r num">${u.pending || ''}</td><td class="r num">${u.to_send || ''}</td><td class="r num">${u.rejected || ''}</td></tr>`).join('')}</table></div></div>` : ''}
  <div class="panel"><h2>Recent sales activity</h2>${d.feed.length ? `<div class="tw"><table>${d.feed.map(x => `<tr><td class="num" style="white-space:nowrap">${fd(x.at)} <span class="sub">${String(x.at).slice(11, 16)}</span></td><td><span class="pill ${ftag[x.type] || 'p-off'}">${esc(x.type)}</span></td><td><b>${esc(x.who || '—')}</b></td><td>${esc(x.text)}</td></tr>`).join('')}</table></div>` : '<div class="empty">No activity yet.</div>'}</div>`;
  $('#slogf').onsubmit = e => { e.preventDefault(); C.slog = Object.fromEntries(new FormData(e.target)); refresh(); };
};

// ================= ACCOUNTS =================
const STATES = ['Andhra Pradesh', 'Assam', 'Bihar', 'Chhattisgarh', 'Delhi', 'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jammu and Kashmir', 'Jharkhand', 'Karnataka', 'Kerala', 'Madhya Pradesh', 'Maharashtra', 'Odisha', 'Punjab', 'Rajasthan', 'Tamil Nadu', 'Telangana', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal', 'Chandigarh', 'Puducherry'];
const inr2 = n => n === '' || n === null || n === undefined ? '' : inr(Math.round(+n * 100) / 100).replace(/^(−?₹[\d,]+)$/, '$1.00');
async function accMeta(force) { if (!C.am || force) C.am = await api('acc_meta'); return C.am; }
const accOpts = (list, sel, blankLabel) => (blankLabel ? `<option value="">${blankLabel}</option>` : '') + list.map(a => `<option value="${a.id}" ${+sel === +a.id ? 'selected' : ''}>${esc((a.code ? a.code + ' · ' : '') + a.name)}</option>`).join('');
const moneyOf = m => m.accounts.filter(a => ['bank', 'cash', 'petty'].includes(a.sub));
const expOf = m => m.accounts.filter(a => a.type === 'expense');
const projOpts = (m, sel) => '<option value="">— none —</option>' + m.projects.map(p => `<option value="${p.id}" data-client="${p.client_id}" ${+sel === +p.id ? 'selected' : ''}>${esc(p.company + ' · ' + p.expo)}</option>`).join('');

routes.acc = async () => {
  const d = await api('acc_home'); B.claims_waiting = d.claims_waiting; renderNav('acc');
  const vt = { Sales: 'p-ok', Receipt: 'p-ok', Payment: 'p-quoted', Expense: 'p-quoted', Claim: 'p-quoted', Salary: 'p-enquiry', Tax: 'p-bad', Contra: 'p-off', Journal: 'p-off', Reversal: 'p-bad' };
  view().innerHTML = `
  <div class="head"><div><h1>Accounts</h1><p>${longDate(B.today)}</p></div><div class="row"><a class="btn ghost" href="#invnew-proforma">+ Proforma</a><a class="btn ghost" href="#invnew-tax">+ Tax invoice</a><a class="btn" href="#entry">+ New entry</a></div></div>
  ${d.inv.todo + d.inv.changes ? `<div class="alert warn"><b>${d.inv.todo} invoice request${d.inv.todo === 1 ? '' : 's'} to prepare${d.inv.changes ? ` · ${d.inv.changes} sent back by admin` : ''}</b><div><a class="btn sm" href="#invoices">Open invoices</a></div></div>` : ''}${d.inv.pending ? `<div class="alert"><b>${d.inv.pending} invoice${d.inv.pending > 1 ? 's' : ''} with admin for approval</b></div>` : ''}
  ${d.claims_waiting ? `<div class="alert warn"><b>${d.claims_waiting} expense claim${d.claims_waiting > 1 ? 's' : ''} waiting for approval</b><div><a class="btn sm" href="#claims">Review claims</a></div></div>` : ''}
  ${d.to_post ? `<div class="alert warn"><b>${d.to_post} payment${d.to_post > 1 ? 's' : ''} / expense${d.to_post > 1 ? 's' : ''} recorded in orders are not in the books yet</b><div><a class="btn sm" href="#topost">Post them</a></div></div>` : ''}
  <div class="kpis">${d.money.map(a => `<div class="kpi ${a.sub === 'bank' ? 'hl' : ''}"><span>${esc(a.name)}</span><b>${inr2(a.balance)}</b></div>`).join('')}
   <div class="kpi"><span>Receivable from clients</span><b>${inr2(d.receivable)}</b></div><div class="kpi"><span>Payable to vendors</span><b>${inr2(d.payable)}</b></div>
   <div class="kpi"><span>Payable to staff</span><b>${inr2(d.staff)}</b><small>expense reimbursements</small></div>
   <div class="kpi"><span>GST payable</span><b>${inr2(d.gst_net)}</b><small>TDS payable ${inr2(d.tds)}</small></div>
   <div class="kpi"><span>This month</span><b>${inr2(d.income_month - d.expense_month)}</b><small>income ${inr2(d.income_month)} · expenses ${inr2(d.expense_month)}</small></div></div>
  <div class="panel"><div class="ph"><h2>Latest entries</h2><a class="btn ghost sm" href="#books">Books & reports</a></div>${d.recent.length ? `<div class="tw"><table><tr><th>Date</th><th>Voucher</th><th>Type</th><th>Narration</th><th class="r">Amount</th></tr>
   ${d.recent.map(j => `<tr class="click" data-go="jv-${j.id}"><td class="num">${fd(j.jdate)}</td><td class="num">${esc(j.vno)}</td><td><span class="pill ${vt[j.vtype] || 'p-off'}">${esc(j.vtype)}</span>${j.reversed_by ? ' <span class="sub">reversed</span>' : ''}</td><td>${esc(j.narration)}</td><td class="r num">${inr2(j.amount)}</td></tr>`).join('')}</table></div>` : blank('No entries yet. Start with <a href="#coa">opening balances</a>, then <a href="#entry">add entries</a>.')}</div>`;
};

// ---------- new entry ----------
const ENTRY_KINDS = [['expense', 'Expense'], ['vendor_pay', 'Vendor payment'], ['receipt', 'Receipt'], ['salary', 'Salary'], ['reimburse', 'Staff reimbursement'], ['contra', 'Bank / cash transfer'], ['tax_gst', 'GST payment'], ['tax_tds', 'TDS payment'], ['journal', 'Journal']];
routes.entry = async ({ tab }) => {
  const m = await accMeta(); const k = ENTRY_KINDS.some(x => x[0] === tab) ? tab : (C.ek || 'expense'); C.ek = k;
  const money = moneyOf(m), D = `<label class="f">Date *<input type="date" name="date" required value="${B.today}"></label>`;
  const PAY = (l = 'Paid from *') => `<label class="f">${l}<select name="paid_from" required>${accOpts(money)}</select></label>`;
  const NAR = `<label class="f wide">Narration<input name="narration" placeholder="What is this for?"></label>`;
  const AMT = (l = 'Amount (₹) *') => `<label class="f">${l}<input type="number" step="any" name="amount" required></label>`;
  const ORD = `<label class="f">Order (optional)<select name="project_id">${projOpts(m)}</select></label>`;
  const staffSel = `<label class="f">Employee *<select name="user_id" required>${m.staff.map(u => `<option value="${u.id}">${esc(u.name)} · ${esc(u.department)}</option>`).join('')}</select></label>`;
  const F = {
    expense: `${D}<label class="f">Expense head *<select name="account_id" required>${accOpts(expOf(m))}</select></label>${AMT('Amount before GST (₹) *')}<label class="f">GST amount (₹)<input type="number" step="any" name="gst"></label>
      <label class="f">GST type<select name="gst_mode"><option value="cgst_sgst">CGST + SGST</option><option value="igst">IGST</option></select></label><label class="row f" style="align-self:end;gap:6px"><input type="checkbox" name="gst_credit" value="1" checked> Claim GST input credit</label>
      <label class="f">Paid from<select name="paid_from">${accOpts(money)}<option value="">Not paid yet — owed to vendor</option></select></label><label class="f">Vendor (if not paid yet)<select name="vendor_id"><option value="">—</option>${m.vendors.map(v => `<option value="${v.id}">${esc(v.name)}</option>`).join('')}</select></label>${ORD}${NAR}`,
    vendor_pay: `${D}<label class="f">Vendor *<select name="vendor_id" required>${m.vendors.map(v => `<option value="${v.id}">${esc(v.name)}</option>`).join('')}</select></label>${ORD}${AMT('Amount paid (₹) *')}<label class="f">TDS deducted (₹)<input type="number" step="any" name="tds"></label>${PAY()}
      <label class="f">Expense head<select name="account_id">${accOpts(expOf(m), m.accounts.find(a => a.sub === 'fab')?.id)}</select></label><label class="f">Reference / UTR<input name="reference"></label>${NAR}`,
    receipt: `${D}<label class="f">Order (optional)<select name="project_id" id="rcp-proj">${projOpts(m)}</select></label><label class="f">Client<select name="client_id" id="rcp-client"><option value="">— other income —</option>${m.clients.map(c => `<option value="${c.id}">${esc(c.company)}</option>`).join('')}</select></label>
      <label class="f">Income head (if no client)<select name="account_id">${accOpts(m.accounts.filter(a => a.type === 'income'))}</select></label>${AMT('Amount received (₹) *')}<label class="f">TDS deducted by client (₹)<input type="number" step="any" name="tds"></label>${PAY('Received into *')}<label class="f">Reference / UTR<input name="reference"></label>${NAR}`,
    salary: `${D.replace('Date *', 'Paid on *')}${staffSel}<label class="f">Salary month *<input type="month" name="month" required value="${B.today.slice(0, 7)}"></label><label class="f">Gross salary (₹) *<input type="number" step="any" name="gross" required data-sal></label>
      <label class="f">Deductions (₹) <span class="sub" style="font-weight:400">PF / ESI / advance</span><input type="number" step="any" name="deductions" data-sal></label><label class="f">Deduction details<input name="deduction_note" placeholder="e.g. PF 1,800"></label><label class="f">TDS (₹)<input type="number" step="any" name="tds" data-sal></label>
      <label class="f">Net pay<input id="sal-net" readonly tabindex="-1"></label>${PAY()}${NAR}`,
    reimburse: `${D}${staffSel}${AMT()}${PAY()}${NAR}`,
    contra: `${D}<label class="f">From *<select name="from_id" required>${accOpts(money)}</select></label><label class="f">To *<select name="to_id" required>${accOpts(money, money[1]?.id)}</select></label>${AMT()}${NAR}`,
    tax_gst: `${D}<label class="f">Period<input name="period" placeholder="e.g. Oct 2026"></label>${AMT('GST paid in cash (₹)')}${PAY()}${NAR}<div class="sub wide">Input credit is set off against output GST automatically; enter only the amount actually paid to the government. See <a href="#books-gst">GST & TDS report</a> for the payable amount.</div>`,
    tax_tds: `${D}<label class="f">Period<input name="period" placeholder="e.g. Oct 2026"></label>${AMT('TDS deposited (₹) *')}${PAY()}${NAR}`,
    journal: `${D}${NAR}<div class="wide"><div class="tw"><table id="jl"><tr><th>Account</th><th class="r">Debit (₹)</th><th class="r">Credit (₹)</th><th>Memo</th></tr>
      ${[0, 1].map(() => `<tr><td><select data-jl="account_id">${accOpts(m.accounts, '', 'Pick account')}</select></td><td><input type="number" step="any" data-jl="dr"></td><td><input type="number" step="any" data-jl="cr"></td><td><input data-jl="memo"></td></tr>`).join('')}</table></div>
      <div class="row" style="margin-top:6px"><button type="button" class="btn ghost sm" id="jl-add">+ Add line</button><span class="sub" id="jl-tot"></span></div></div>`,
  };
  view().innerHTML = `<div class="head"><div><div class="crumb"><a href="#acc">← Accounts</a></div><h1>New entry</h1><p>Each entry is posted to the books with a voucher number.</p></div></div>
  <div class="panel" style="padding-top:4px"><div class="tabs">${ENTRY_KINDS.map(([x, l]) => `<a href="#entry-0-${x}" class="${k === x ? 'on' : ''}">${l}</a>`).join('')}</div>
   <form id="accentry" data-kind="${k}" style="padding-top:16px"><div class="grid g3">${F[k]}</div>
    <div class="row" style="margin-top:12px"><label class="up">⬆ Attach bill / proof<input type="file" name="file" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.xlsx,.xls,.csv,.doc,.docx"></label><button class="btn">Post entry</button></div></form></div>`;
};

routes.jv = async ({ id }) => {
  const [{ journal: j }] = await Promise.all([api('acc_journal', { id }), isAdmin() ? accMeta() : null]);
  view().innerHTML = `<div class="head"><div><div class="crumb"><a href="#books">← Books</a></div><h1>${esc(j.vno)} · ${esc(j.vtype)}</h1><p>${fd(j.jdate)} · entered by ${esc(j.by)} on ${fd(j.created_at)}</p></div>
   <div class="row">${j.reversed_by ? `<a class="pill p-bad" href="#jv-${j.reversed_by}">reversed — open reversal</a>` : j.vtype !== 'Reversal' && !['invoice', 'claim'].includes(j.ref_type) ? `${isAdmin() ? '<button class="btn" id="jv-edit-open">Edit entry</button>' : ''}<button class="btn ghost" id="jv-rev-open">${isAdmin() ? 'Delete (reverse)' : 'Reverse this entry'}</button>` : ''}
   ${isAdmin() && j.ref_type === 'claim' && !j.reversed_by ? '<a class="btn ghost" href="#claims">Edit it in Expense approvals</a>' : ''}${isAdmin() && j.ref_type === 'invoice' && !j.reversed_by ? '<a class="btn ghost" href="#invoices">Cancel it from Invoices</a>' : ''}</div></div>
  ${j.replaces ? `<div class="alert"><b>Corrected entry</b><span class="m">This replaces an earlier entry that was edited by admin. <a href="#jv-${j.replaces}">Open the old one →</a></span></div>` : ''}
  <div class="panel"><p style="margin-top:0">${esc(j.narration)}</p><div class="tw"><table><tr><th>Account</th><th class="r">Debit (₹)</th><th class="r">Credit (₹)</th><th>Memo</th></tr>
   ${j.lines.map(l => `<tr><td>${esc(l.name)}</td><td class="r num">${+l.dr ? inr2(l.dr) : ''}</td><td class="r num">${+l.cr ? inr2(l.cr) : ''}</td><td class="sub">${esc(l.memo || '')}</td></tr>`).join('')}</table></div>
   <div class="row" style="margin-top:12px"><span class="sub">Bills / proof:</span>${fileChips(j.files)}${upBtn('voucher', j.id, 'Attach')}</div>
   ${isAdmin() ? `<form id="jv-edit" class="inset" hidden style="margin-top:12px"><h3>Edit entry</h3><div class="grid g3"><label class="f">Date *<input type="date" name="date" required value="${j.jdate}"></label><label class="f wide">Narration<input name="narration" value="${esc(j.narration || '')}"></label></div>
    <div class="tw" style="margin-top:10px"><table id="jl"><tr><th>Account</th><th class="r">Debit (₹)</th><th class="r">Credit (₹)</th><th>Memo</th></tr>
    ${j.lines.map(l => `<tr><td><select data-jl="account_id">${accOpts(C.am.accounts, l.account_id, 'Pick account')}</select></td><td><input type="number" step="any" data-jl="dr" value="${+l.dr ? num(l.dr) : ''}"></td><td><input type="number" step="any" data-jl="cr" value="${+l.cr ? num(l.cr) : ''}"></td><td><input data-jl="memo" value="${esc(l.memo || '')}"></td></tr>`).join('')}</table></div>
    <div class="row" style="margin-top:6px"><button type="button" class="btn ghost sm" id="jl-add">+ Add line</button><span class="sub" id="jl-tot"></span></div>
    <div class="sub" style="margin-top:6px">The old entry is reversed on its own date and this corrected one is posted in its place — both stay visible in the books.${['cpay', 'vpay', 'pexp'].includes(j.ref_type) ? ' This entry is linked to an order: if the amount changes, correct it in the order too.' : ''}</div>
    <div style="margin-top:8px"><button class="btn sm">Save corrected entry</button></div></form>` : ''}
   <form id="jv-rev" class="inset" hidden style="margin-top:12px"><div class="grid g3"><label class="f">Reversal date<input type="date" name="date" value="${B.today}"></label><label class="f wide">Reason *<input name="reason" required></label></div><div style="margin-top:8px"><button class="btn danger sm">Post reversal</button></div></form></div>`;
  C.jv = j;
};

// ---------- invoices: sales request → accounts prepare → admin approve ----------
const INV_ST = { requested: ['p-enquiry', 'requested by sales'], draft: ['p-pending', 'being prepared'], pending: ['p-quoted', 'waiting for admin'], on_hold: ['p-quoted', 'on hold · details needed'], rejected: ['p-bad', 'rejected · needs correction'], issued: ['p-ok', 'approved'], cancelled: ['p-bad', 'cancelled'] };
const invPill = i => `<span class="pill ${(INV_ST[i.status] || ['p-off'])[0]}">${(INV_ST[i.status] || [0, i.status])[1]}</span>`;
const invKind = i => i.kind === 'tax' ? 'Tax invoice' : 'Proforma';
const INV_EDIT = ['requested', 'draft', 'on_hold', 'rejected'];
const INV_ACT = { requested: 'requested', prepared: 'started preparing', created: 'created it', submitted: 'sent to admin for approval', approved: 'approved', hold: 'put on hold', rejected: 'rejected', reply: 'added details', correction: 'asked for a correction', cancelled: 'cancelled' };
const invDl = i => `<a class="btn sm" href="invoice.php?id=${i.id}" target="_blank" rel="noopener">View / download</a>`;
function invTimeline(log) {
  return `<div class="tl">${log.map(l => `<div class="inset" style="margin-top:8px;${['hold', 'rejected'].includes(l.action) ? 'border-left:3px solid var(--bad)' : l.action === 'approved' ? 'border-left:3px solid var(--ok)' : ''}">
   <div><b>${esc(l.by)}</b> ${INV_ACT[l.action] || esc(l.action)}${l.version ? ` <span class="pill p-off">V${l.version}</span>` : ''} <span class="sub">· ${fd(l.at)} ${String(l.at).slice(11, 16)}</span>${l.total !== null && l.total !== undefined ? ` <span class="sub">· total ${inr2(l.total)}</span>` : ''}</div>
   ${l.note ? `<div style="margin-top:4px">${esc(l.note)}</div>` : ''}
   ${l.changes && l.changes.length ? `<div class="sub" style="margin-top:4px"><b>Changes from the previous version:</b><br>${l.changes.map(esc).join('<br>')}</div>` : ''}</div>`).join('')}</div>`;
}
function invLinesTable(i) {
  return i.lines.length ? `<div class="tw"><table><tr><th>Description</th><th>SAC</th><th class="r">Qty</th><th class="r">Rate</th><th class="r">Amount</th></tr>${i.lines.map(l => `<tr><td>${esc(l.desc)}${+l.tax ? '' : ' <span class="sub">(no GST)</span>'}</td><td>${esc(l.sac || '')}</td><td class="r num">${num(l.qty)}</td><td class="r num">${inr2(l.rate)}</td><td class="r num">${inr2(l.amount)}</td></tr>`).join('')}
   <tr><td colspan="4">Taxable value</td><td class="r num">${inr2(i.taxable)}</td></tr><tr><td colspan="4">${i.gst_mode === 'igst' ? 'IGST' : 'CGST + SGST'} @ ${num(i.gst_rate)}%</td><td class="r num">${inr2(i.gst)}</td></tr><tr><td colspan="4"><b>Grand total</b></td><td class="r num"><b>${inr2(i.total)}</b></td></tr></table></div>` : blank('Accounts will add the lines.');
}

// accounts: invoice log
routes.invoices = async () => {
  const f = C.invf || (C.invf = { group: isAdmin() ? 'all' : 'todo', kind: '', q: '' });
  const d = await api('inv_list', f); B.inv_todo = (d.counts.todo || 0) + (d.counts.changes || 0); renderNav('invoices');
  const tabs = [['todo', 'To prepare'], ['pending', 'With admin'], ['changes', 'Needs changes'], ['issued', 'Approved'], ['cancelled', 'Cancelled'], ['all', 'All']];
  view().innerHTML = `<div class="head"><div><div class="crumb"><a href="#acc">← Accounts</a></div><h1>Invoices</h1><p>Sales requests come here. Prepare, send to admin, and download once approved.</p></div><div class="row"><a class="btn ghost" href="#invnew-proforma">+ Proforma</a><a class="btn ghost" href="#invnew-tax">+ Tax invoice</a></div></div>
  <div class="tabs" style="background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:0 8px">${tabs.map(([k, l]) => `<a href="#invoices" data-invt="${k}" class="${f.group === k ? 'on' : ''}">${l}${d.counts[k] && k !== 'all' && k !== 'cancelled' ? ` <span class="badge" style="${['todo', 'changes'].includes(k) ? '' : 'background:var(--navy-soft);color:var(--navy)'}">${d.counts[k]}</span>` : ''}</a>`).join('')}</div>
  <form class="panel row" id="invf" style="align-items:flex-end"><label class="f" style="flex:1">Search client / number<input name="q" value="${esc(f.q)}"></label><label class="f">Kind<select name="kind"><option value="">All</option><option value="proforma" ${f.kind === 'proforma' ? 'selected' : ''}>Proforma</option><option value="tax" ${f.kind === 'tax' ? 'selected' : ''}>Tax invoice</option></select></label><button class="btn ghost">Apply</button></form>
  <div class="panel">${d.invoices.length ? `<div class="tw"><table><tr><th>Number</th><th>Kind</th><th>Date</th><th>Client · expo</th><th>Requested by</th><th class="r">Total</th><th>Status</th><th></th></tr>
   ${d.invoices.map(i => `<tr class="click" data-go="invd-${i.id}"><td class="num"><b>${esc(i.inv_no || '—')}</b>${+i.version ? ` <span class="sub">V${i.version}</span>` : ''}</td><td>${invKind(i)}</td><td class="num">${fd(i.inv_date)}</td><td>${esc(i.company)}<div class="sub">${esc(i.expo || '')}</div></td><td>${esc(i.requester || '—')}</td><td class="r num"><b>${inr2(i.total)}</b></td><td>${invPill(i)}</td>
    <td class="r" style="white-space:nowrap">${INV_EDIT.includes(i.status) ? `<a class="btn sm" href="#inv-${i.id}">${i.status === 'requested' ? 'Prepare' : 'Edit'}</a>` : ''}${i.status === 'issued' ? invDl(i) : ''}<a class="btn ghost sm" href="#invd-${i.id}">Details</a></td></tr>`).join('')}</table></div>` : blank('Nothing here.')}</div>`;
  $('#invf').onsubmit = e => { e.preventDefault(); Object.assign(C.invf, Object.fromEntries(new FormData(e.target))); refresh(); };
};
routes.invnew = async () => routes.inv({ id: null, kind: location.hash.split('-')[1] || 'proforma' });
routes.inv = async ({ id, kind }) => {
  const m = await accMeta(true);
  let i, log = [];
  if (id) { const r = await api('inv_get', { id }); i = r.invoice; log = r.log; } else i = { kind: kind === 'tax' ? 'tax' : 'proforma', inv_date: B.today, client_id: '', project_id: '', place_state: m.company.state || 'Delhi', gst_rate: 18, lines: [], notes: '' };
  if (i.status && !INV_EDIT.includes(i.status)) { location.hash = '#invd-' + i.id; return; }
  if (!i.lines.length) i.lines = [{ desc: '', sac: m.company.sac || '998596', qty: 1, rate: '', tax: 1 }];
  C.inv = i; C.invlog = log; drawInv();
};
function drawInv() {
  const m = C.am, i = C.inv, id = i.id, last = [...(C.invlog || [])].reverse().find(l => ['hold', 'rejected'].includes(l.action));
  view().innerHTML = `<div class="head"><div><div class="crumb"><a href="#invoices">← Invoices</a>${id ? ` · <a href="#invd-${id}">Details & history</a>` : ''}</div><h1>${id ? (i.status === 'requested' ? 'Prepare' : 'Edit') : 'New'} ${i.kind === 'tax' ? 'tax invoice' : 'proforma invoice'}${i.inv_no ? ' ' + esc(i.inv_no) : ''}</h1><p>It goes to admin for approval. The number is given when admin approves.</p></div>${id ? invPill(i) : ''}</div>
  ${i.request_note ? `<div class="alert"><b>Request from ${esc(i.requester || 'sales')}</b><span class="m">${esc(i.request_note)}</span></div>` : ''}
  ${last && ['on_hold', 'rejected'].includes(i.status) ? `<div class="alert bad"><b>Admin ${last.action === 'hold' ? 'needs more details' : 'rejected it'} (${esc(last.by)}, ${fd(last.at)})</b><span class="m">${esc(last.note || '')}</span></div>` : ''}
  <form class="panel" id="invform"><div class="grid g3">
   <label class="f">Date *<input type="date" name="inv_date" required value="${esc(i.inv_date)}"></label>
   <label class="f">Order<select name="project_id" id="inv-proj">${projOpts(m, i.project_id)}</select></label>
   <label class="f">Client *<select name="client_id" id="inv-client" required><option value="">Pick client</option>${m.clients.map(c => `<option value="${c.id}" ${+i.client_id === +c.id ? 'selected' : ''}>${esc(c.company)}</option>`).join('')}</select></label>
   <label class="f">Place of supply (state) *<input name="place_state" list="states" required value="${esc(i.place_state)}"></label><datalist id="states">${STATES.map(x => `<option value="${x}">`).join('')}</datalist>
   <label class="f">GST rate (%)<input type="number" step="any" name="gst_rate" value="${num(i.gst_rate)}" data-invcalc></label>
   <div style="align-self:end"><button type="button" class="btn ghost sm" id="inv-fill">Fill lines from order</button></div></div>
   <div class="tw" style="margin-top:14px"><table id="invlines"><tr><th>Description</th><th style="width:100px">SAC</th><th style="width:80px">Qty</th><th style="width:130px">Rate (₹)</th><th style="width:60px">GST</th><th class="r" style="width:130px">Amount</th><th></th></tr>
    ${i.lines.map((l, k) => `<tr><td><input data-il="desc" value="${esc(l.desc)}"></td><td><input data-il="sac" value="${esc(l.sac || '')}"></td><td><input type="number" step="any" data-il="qty" value="${esc(l.qty)}" data-invcalc></td><td><input type="number" step="any" data-il="rate" value="${esc(l.rate)}" data-invcalc></td><td style="text-align:center"><input type="checkbox" data-il="tax" ${+l.tax || l.tax === undefined ? 'checked' : ''} data-invcalc></td><td class="r num" data-ilamt></td><td>${i.lines.length > 1 ? `<button type="button" class="btn link" data-ildel="${k}">✕</button>` : ''}</td></tr>`).join('')}</table></div>
   <div class="row" style="margin-top:6px"><button type="button" class="btn ghost sm" id="inv-addline">+ Add line</button></div>
   <div class="grid" style="margin-top:14px"><label class="f">Notes / terms on this invoice<textarea name="notes">${esc(i.notes || '')}</textarea></label><div class="inset" id="inv-tot"></div></div>
   <label class="f" style="margin-top:12px">Message for admin (optional)<input name="message" placeholder="${['on_hold', 'rejected'].includes(i.status) ? 'e.g. Corrected the rate as asked' : 'e.g. 50% advance as agreed with client'}"></label>
   <div class="row" style="margin-top:12px"><button class="btn ghost" data-invsave="draft">Save draft</button><button class="btn" data-invsave="submit">Send to admin for approval</button></div></form>`;
  invCalc();
}

// all roles: one invoice with its full history
routes.invd = async ({ id }) => {
  const { invoice: i, log, tax_child } = await api('inv_get', { id });
  const acc = B.acc, admin = isAdmin(), seller = B.module === 'records' || admin;
  const back = acc ? '#invoices' : '#myinv';
  const acts = [];
  if (i.status === 'issued') acts.push(invDl(i));
  else if (i.status !== 'cancelled') acts.push(`<a class="btn ghost sm" href="invoice.php?id=${i.id}" target="_blank" rel="noopener">Preview</a>`);
  if (acc && INV_EDIT.includes(i.status)) acts.push(`<a class="btn sm" href="#inv-${i.id}">${i.status === 'requested' ? 'Prepare invoice' : 'Edit & correct'}</a>`);
  if (i.status === 'issued' && i.kind === 'proforma' && !tax_child) acts.push(acc ? `<button class="btn ghost sm" data-invconv="${i.id}">Make tax invoice</button>` : `<button class="btn ghost sm" data-invreqtax="${i.id}">Request tax invoice</button>`);
  if (tax_child) acts.push(`<a class="btn ghost sm" href="#invd-${tax_child}">Open tax invoice</a>`);
  const canReply = i.status !== 'cancelled' && i.status !== 'issued';
  view().innerHTML = `<div class="head"><div><div class="crumb"><a href="${back}">← ${acc ? 'Invoices' : 'My invoices'}</a></div><h1>${invKind(i)} ${esc(i.inv_no || '')} · ${esc(i.company)}</h1><p>${esc(i.expo || 'No order linked')} · dated ${fd(i.inv_date)}${+i.version ? ` · version ${i.version}` : ''}${i.requester ? ` · requested by ${esc(i.requester)}` : ''}</p></div><div class="row" style="align-items:center">${invPill(i)}${acts.join('')}</div></div>
  ${i.status === 'pending' && admin ? `<form class="panel" data-invdec="${i.id}" style="border:2px solid var(--warn)"><h2>Your decision</h2><label class="f">Reason / details needed (needed for hold or reject)<textarea name="reason" placeholder="e.g. Rate should be ₹4,50,000 · Add PO number"></textarea></label>
    <div class="row" style="margin-top:10px"><button class="btn" data-iact="approve">Approve</button><button class="btn ghost" data-iact="hold">Put on hold — ask for details</button><button class="btn danger" data-iact="reject">Reject — send back for correction</button></div></form>` : ''}
  ${['on_hold', 'rejected'].includes(i.status) ? `<div class="alert bad"><b>${i.status === 'on_hold' ? 'Admin needs more details' : 'Admin rejected it'}</b><span class="m">${esc(i.reason || '')}${acc ? ' — correct it and send again.' : ' — add the details below for accounts.'}</span></div>` : ''}
  ${i.status === 'requested' && !acc ? `<div class="alert"><b>Waiting for accounts</b><span class="m">Accounts will prepare it and send it to admin.</span></div>` : ''}
  ${i.status === 'pending' && !admin ? `<div class="alert warn"><b>Waiting for admin approval</b><span class="m">You will be able to download it once approved.</span></div>` : ''}
  ${i.status === 'issued' ? `<div class="alert" style="background:var(--ok-bg);color:var(--ok)"><b>Approved${i.decided_by ? ` by ${esc(i.decided_by)} on ${fd(i.decided_at)}` : ''}</b><span class="m">Download the PDF and send it to the customer on email or WhatsApp.</span></div>` : ''}
  <div class="grid g2">
   <div class="panel"><h2>${+i.version ? 'Version ' + i.version : 'Draft'}</h2>${i.request_note ? `<div class="inset" style="margin-bottom:10px"><b>Sales request:</b> ${esc(i.request_note)}</div>` : ''}${invLinesTable(i)}</div>
   <div class="panel"><h2>History & changes</h2>${invTimeline(log)}
    ${canReply ? `<form class="inset" data-invreply="${i.id}" style="margin-top:12px"><label class="f">Add details / comment<textarea name="text" required placeholder="${acc ? 'Note for sales and admin' : 'Details for accounts and admin'}"></textarea></label><div style="margin-top:8px"><button class="btn sm">Send</button></div></form>` : ''}
    ${i.status === 'issued' && i.kind === 'proforma' && !tax_child && (acc || seller) ? `<details style="margin-top:12px"><summary class="btn link">Needs a correction?</summary><form class="inset" data-invcorr="${i.id}" style="margin-top:8px"><label class="f">What should change? *<textarea name="reason" required></textarea></label><div class="sub">The proforma goes back ${acc ? 'to you to correct' : 'to accounts'} and then to admin again. The number stays the same.</div><div style="margin-top:8px"><button class="btn sm">${acc ? 'Reopen for correction' : 'Ask for correction'}</button></div></form></details>` : ''}
    ${(admin && i.status !== 'cancelled') || (acc && ['requested', 'draft'].includes(i.status)) ? `<details style="margin-top:12px"><summary class="btn link">Cancel this ${i.kind === 'tax' ? 'invoice' : 'proforma'}</summary><form class="inset" data-invcan="${i.id}" style="margin-top:8px"><label class="f">Reason *<input name="reason" required></label>${i.status === 'issued' && i.kind === 'tax' ? '<div class="sub">The sale and GST will be reversed in the books.</div>' : ''}<div style="margin-top:8px"><button class="btn danger sm">Cancel it</button></div></form></details>` : ''}
   </div></div>`;
};

// admin: invoices waiting for approval
routes.invappr = async () => {
  const d = await api('inv_list', { group: 'pending' }); B.inv_pending = d.counts.pending; renderNav('invappr');
  const det = await Promise.all(d.invoices.map(i => api('inv_get', { id: i.id })));
  view().innerHTML = `<div class="head"><div><h1>Invoice approvals</h1><p>Prepared by accounts. Approve to give the number — then accounts and sales can download it.</p></div><a class="btn ghost" href="#invoices">All invoices</a></div>
  ${det.length ? det.map(({ invoice: i, log }) => { const sub = [...log].reverse().find(l => l.action === 'submitted') || {}; return `<div class="panel"><div class="ph"><h3>${invKind(i)} · ${esc(i.company)} <span class="sub">· ${esc(i.expo || '')} · V${i.version}</span></h3><b class="num">${inr2(i.total)}</b></div>
    <div class="sub">Requested by ${esc(i.requester || '—')} · sent by ${esc(sub.by || '—')} on ${sub.at ? fd(sub.at) : '—'}</div>
    ${i.request_note ? `<div class="inset" style="margin-top:8px"><b>Sales request:</b> ${esc(i.request_note)}</div>` : ''}
    ${sub.note ? `<div class="inset" style="margin-top:8px"><b>Accounts says:</b> ${esc(sub.note)}</div>` : ''}
    ${sub.changes && sub.changes.length ? `<div class="inset" style="margin-top:8px"><b>Changed since last version:</b><br>${sub.changes.map(esc).join('<br>')}</div>` : ''}
    <div style="margin-top:10px">${invLinesTable(i)}</div>
    <form data-invdec="${i.id}" style="margin-top:10px"><label class="f">Reason / details needed (for hold or reject)<input name="reason"></label>
     <div class="row" style="margin-top:8px"><button class="btn" data-iact="approve">Approve</button><button class="btn ghost" data-iact="hold">Hold</button><button class="btn danger" data-iact="reject">Reject</button><a class="btn ghost sm" href="invoice.php?id=${i.id}" target="_blank" rel="noopener">Preview</a><a class="btn link" href="#invd-${i.id}">Full history</a></div></form></div>`; }).join('') : `<div class="panel">${blank('Nothing waiting for approval.')}</div>`}`;
};

// sales: request invoices and download approved ones
routes.myinv = async () => {
  const d = await api('inv_mine'); B.inv_attention = d.invoices.filter(i => ['on_hold', 'rejected'].includes(i.status)).length; renderNav('myinv');
  const pre = C.invreq || {};
  view().innerHTML = `<div class="head"><div><h1>My invoices</h1><p>Ask accounts for a proforma or tax invoice. Once admin approves, download it here and send it to the customer.</p></div></div>
  <form class="panel" id="invreqf"><h2>Request an invoice</h2><div class="grid g3">
   <label class="f">Type *<select name="kind"><option value="proforma">Proforma invoice</option><option value="tax">Tax invoice</option></select></label>
   <label class="f">Order<select name="project_id"><option value="">— pick order —</option>${d.orders.map(o => `<option value="${o.id}" ${+pre.project_id === +o.id ? 'selected' : ''}>${esc(o.company + ' · ' + o.expo)}</option>`).join('')}</select></label>
   <label class="f">or Client (if no order)<select name="client_id"><option value="">—</option>${d.clients.map(c => `<option value="${c.id}">${esc(c.company)}</option>`).join('')}</select></label></div>
   <label class="f" style="margin-top:10px">What should be billed? *<textarea name="note" required placeholder="e.g. 50% advance of agreed amount · client GST no. · PO reference · any special terms"></textarea></label>
   <div style="margin-top:10px"><button class="btn">Send to accounts</button></div></form>
  <div class="panel"><h2>My requests & invoices</h2>${d.invoices.length ? `<div class="tw"><table><tr><th>Number</th><th>Type</th><th>Client · expo</th><th class="r">Total</th><th>Status</th><th></th></tr>
   ${d.invoices.map(i => `<tr class="click" data-go="invd-${i.id}"><td class="num"><b>${esc(i.inv_no || '—')}</b></td><td>${invKind(i)}</td><td>${esc(i.company)}<div class="sub">${esc(i.expo || '')}</div></td><td class="r num">${+i.total ? inr2(i.total) : '—'}</td><td>${invPill(i)}</td>
    <td class="r" style="white-space:nowrap">${i.status === 'issued' ? invDl(i) : ''}<a class="btn ghost sm" href="#invd-${i.id}">Details</a></td></tr>`).join('')}</table></div>` : blank('No invoice requests yet.')}</div>`;
};
function invAlert() { const n = B.inv_attention; if (!n) return; const w = $('.welcome'); if (w) w.insertAdjacentHTML('afterend', `<div class="alert warn"><b>${n} invoice${n > 1 ? 's need' : ' needs'} your attention</b><span class="m">Admin put it on hold or rejected it. <a href="#myinv">Open my invoices →</a></span></div>`); }

function invCapture() { const f = $('#invform'); if (!f) return; const g = k => f.querySelector(`[name=${k}]`).value; Object.assign(C.inv, { inv_date: g('inv_date'), project_id: g('project_id'), client_id: g('client_id'), place_state: g('place_state'), gst_rate: g('gst_rate'), notes: g('notes'), lines: invLines() }); }
function invLines() { return [...document.querySelectorAll('#invlines tr')].slice(1).map(tr => { const g = k => tr.querySelector(`[data-il="${k}"]`); return { desc: g('desc').value, sac: g('sac').value, qty: g('qty').value, rate: g('rate').value, tax: g('tax').checked ? 1 : 0 }; }); }
function invCalc() {
  const box = $('#inv-tot'); if (!box) return;
  const rate = +document.querySelector('#invform [name=gst_rate]').value || 0, st = (document.querySelector('#invform [name=place_state]').value || '').trim().toLowerCase();
  const igst = st && st !== String((C.am?.company?.state) || '').trim().toLowerCase();
  let tx = 0, non = 0; [...document.querySelectorAll('#invlines tr')].slice(1).forEach((tr, k) => { const l = invLines()[k]; const a = (+l.qty || 0) * (+l.rate || 0); tr.querySelector('[data-ilamt]').textContent = inr2(a); l.tax ? tx += a : non += a; });
  const g = Math.round(tx * rate) / 100;
  box.innerHTML = `<div class="sum"><div class="l"><span>Taxable value</span><span class="num">${inr2(tx)}</span></div>${non ? `<div class="l"><span>Without GST</span><span class="num">${inr2(non)}</span></div>` : ''}
   ${igst ? `<div class="l"><span>IGST ${rate}%</span><span class="num">${inr2(g)}</span></div>` : `<div class="l"><span>CGST ${rate / 2}%</span><span class="num">${inr2(Math.round(g * 50) / 100)}</span></div><div class="l"><span>SGST ${rate / 2}%</span><span class="num">${inr2(g - Math.round(g * 50) / 100)}</span></div>`}
   <div class="l tot big"><span>Grand total</span><span class="num">${inr2(tx + non + g)}</span></div><div class="sub">${igst ? 'Other state → IGST' : 'Same state as Zest → CGST + SGST'}</div></div>`;
}

// ---------- expense approvals (accounts) ----------
const CLAIM_ST = { submitted: ['p-pending', 'waiting for accounts'], on_hold: ['p-quoted', 'more details needed'], approved: ['p-ok', 'approved'], rejected: ['p-bad', 'rejected'] };
const claimPill = c => `<span class="pill ${CLAIM_ST[c.status][0]}">${CLAIM_ST[c.status][1]}</span>`;
const claimThread = c => c.notes.filter(n => n.text).map(n => `<div class="sub" style="margin-top:4px"><b>${esc(n.by)}</b> · ${fd(n.at)} ${String(n.at).slice(11, 16)} · ${esc({ hold: 'asked for details', reply: 'replied', rejected: 'rejected', approved: 'approved', resubmitted: 'sent again' }[n.kind] || n.kind)}: ${esc(n.text)}</div>`).join('');
routes.claims = async () => {
  const f = C.clf || (C.clf = { status: 'submitted', q: '' });
  const [d, m] = await Promise.all([api('acc_claims', f), accMeta()]); B.claims_waiting = d.counts.submitted; renderNav('claims');
  const money = moneyOf(m);
  view().innerHTML = `<div class="head"><div><div class="crumb"><a href="#acc">← Accounts</a></div><h1>Expense approvals</h1><p>Claims from sales and production. Approving posts the expense to the books.</p></div><a class="btn ghost" href="acc_export.php?r=claims&from=${m.company.books_from || ''}&to=${B.today}">⬇ Export claims</a></div>
  <div class="tabs" style="background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:0 8px">${[['submitted', 'Waiting'], ['on_hold', 'On hold'], ['approved', 'Approved'], ['rejected', 'Rejected'], ['all', 'All']].map(([k, l]) => `<a href="#claims" data-clt="${k}" class="${f.status === k ? 'on' : ''}">${l}${d.counts[k] ? ` <span class="badge" style="${k === 'submitted' ? '' : 'background:var(--navy-soft);color:var(--navy)'}">${d.counts[k]}</span>` : ''}</a>`).join('')}</div>
  ${d.claims.length ? d.claims.map(c => `<div class="panel"><div class="ph"><h3>${esc(c.particular)} <span class="sub">· ${esc(c.category || '')}</span></h3>${claimPill(c)}</div>
    <div class="grid g3 sub"><div><b>Employee</b><br>${esc(c.user_name)} · ${esc(c.department)}</div><div><b>Date of expense</b><br>${fd(c.edate)}</div><div><b>Order</b><br>${c.expo ? esc(c.company + ' · ' + c.expo) : '—'}</div>
     <div><b>Unit rate × qty</b><br>${inr2(c.rate)} × ${num(c.qty)} = ${inr2(c.total)}</div><div><b>GST</b><br>${inr2(c.gst)}</div><div><b>Grand total</b><br><span style="font-size:15px;color:var(--ink)"><b>${inr2(c.grand)}</b></span></div></div>
    ${c.note ? `<p class="sub"><b>Note:</b> ${esc(c.note)}</p>` : ''}<div class="row" style="margin-top:8px"><span class="sub">Bill / proof:</span>${fileChips(c.files)}</div>${claimThread(c)}
    ${['submitted', 'on_hold'].includes(c.status) ? `<form class="inset" data-cdec="${c.id}" style="margin-top:12px"><div class="grid g3">
      <label class="f">Book under (expense head)<select name="account_id">${accOpts(expOf(m), d.cat_acc[c.category] || d.cat_acc.Other)}</select></label>
      <label class="f">Pay / reimburse<select name="pay_from"><option value="staff">Owe to ${esc(c.user_name)} (reimburse later)</option>${money.map(a => `<option value="${a.id}">Already paid from ${esc(a.name)}</option>`).join('')}</select></label>
      ${+c.gst ? `<label class="row f" style="align-self:end;gap:6px"><input type="checkbox" name="gst_credit" value="1"> Claim GST credit <select name="gst_mode" style="max-width:130px"><option value="cgst_sgst">CGST+SGST</option><option value="igst">IGST</option></select></label>` : ''}
      <label class="f wide">Reason / note <span class="sub" style="font-weight:400">(needed to reject or hold)</span><input name="reason"></label></div>
      <div class="row" style="margin-top:8px"><button class="btn sm" data-cact="approve">Approve & post</button><button class="btn ghost sm" data-cact="hold">Hold — ask for details</button><button class="btn danger sm" data-cact="reject">Reject</button></div></form>` : ''}
    ${c.status === 'approved' && c.journal_id ? `<div class="sub" style="margin-top:8px">Posted: <a href="#jv-${c.journal_id}">open voucher</a> · approved by ${esc(c.decided_by)} on ${fd(c.decided_at)}</div>` : ''}
    ${isAdmin() ? `<div class="row" style="margin-top:10px"><button class="btn ghost sm" data-caedit="${c.id}">Edit</button><button class="btn link" data-cadel="${c.id}" data-st="${c.status}">Delete</button></div>
     ${+C.caEdit === +c.id ? `<form class="inset" data-casave="${c.id}" style="margin-top:8px"><div class="grid g3">
      <label class="f wide">Particular *<input name="particular" required value="${esc(c.particular)}"></label><label class="f">Date *<input type="date" name="edate" required value="${c.edate}"></label>
      <label class="f">Category<select name="category">${Object.keys(m.claim_cats).map(k => `<option ${k === c.category ? 'selected' : ''}>${esc(k)}</option>`).join('')}</select></label>
      <label class="f">Unit rate *<input type="number" step="any" name="rate" required value="${num(c.rate)}"></label><label class="f">Qty<input type="number" step="any" name="qty" value="${num(c.qty)}"></label><label class="f">GST (₹)<input type="number" step="any" name="gst" value="${num(c.gst)}"></label>
      <label class="f">Order<select name="project_id">${projOpts(m, c.project_id)}</select></label><label class="f wide">Note<input name="note" value="${esc(c.note || '')}"></label></div>
      ${c.status === 'approved' ? '<div class="sub" style="margin-top:6px">Already in the books — the old voucher is reversed and a corrected one is posted automatically.</div>' : ''}
      <div class="row" style="margin-top:8px"><button class="btn sm">Save changes</button><button type="button" class="btn link" data-caedit="0">Close</button></div></form>` : ''}` : ''}</div>`).join('')
  : `<div class="panel">${blank('Nothing here.')}</div>`}`;
};

// ---------- post CRM entries ----------
routes.topost = async () => {
  const [d, m] = await Promise.all([api('acc_inbox'), accMeta()]); const money = moneyOf(m);
  const def = mode => (money.find(a => a.sub === (mode === 'Bank' || mode === 'UPI' || mode === 'Cheque' ? 'bank' : 'cash')) || money[0] || {}).id;
  const row = (t, x, who, cat) => `<tr><td class="num">${fd(x.pdate || x.edate)}</td><td>${esc(x.company)}<div class="sub">${esc(x.expo)}</div></td><td>${esc(who)}</td><td class="r num">${inr2(x.amount)}</td><td>${esc(x.mode || x.category || '')}</td>
    <td><select data-pm="${t}:${x.id}">${accOpts(money, def(x.mode))}</select></td><td>${cat ? `<select data-pa="${t}:${x.id}">${accOpts(expOf(m), cat)}</select>` : ''}</td>
    <td class="r" style="white-space:nowrap"><button class="btn sm" data-post="${t}:${x.id}">Post</button><button class="btn link" data-postskip="${t}:${x.id}" title="Already entered in the books another way">Skip</button></td></tr>`;
  const sec = (title, list, head, fn) => `<div class="panel"><h2>${title} <span class="sub">(${list.length})</span></h2>${list.length ? `<div class="tw"><table><tr><th>Date</th><th>Client · expo</th><th>${head}</th><th class="r">Amount</th><th>Mode</th><th>Bank / cash</th><th>Expense head</th><th></th></tr>${list.map(fn).join('')}</table></div>` : '<div class="sub">Nothing to post.</div>'}</div>`;
  const fab = m.accounts.find(a => a.sub === 'fab')?.id, sub2 = { Flight: 'exp_travel', Train: 'exp_travel', Hotel: 'exp_hotel', Commute: 'exp_local', Food: 'exp_food', Courier: 'exp_courier' };
  view().innerHTML = `<div class="head"><div><div class="crumb"><a href="#acc">← Accounts</a></div><h1>Post from orders</h1><p>Payments and expenses that sales recorded inside orders (from ${fd(d.books_from)}). Check each one and post it to the books.</p></div></div>
   ${sec('Received from clients', d.cpay, 'From', x => row('cpay', x, x.company, null))}
   ${sec('Paid to vendors', d.vpay, 'Vendor', x => row('vpay', x, x.vendor || '—', fab))}
   ${sec('Order expenses', d.pexp, 'Details', x => row('pexp', x, x.description || x.category, m.accounts.find(a => a.sub === (sub2[x.category] || 'exp_misc'))?.id))}`;
};

// ---------- books & reports ----------
const REPORTS = [['daybook', 'Day book'], ['ledger', 'Ledger'], ['cash', 'Cash book'], ['bank', 'Bank book'], ['petty', 'Petty cash'], ['trial', 'Trial balance'], ['pl', 'Profit & loss'], ['bs', 'Balance sheet'], ['gst', 'GST & TDS'], ['expenses', 'Expenses'], ['salary', 'Salary register'], ['parties', 'Outstanding'], ['claims', 'Expense claims']];
routes.books = async ({ id, tab }) => {
  const m = await accMeta(); const hashTab = location.hash.split('-')[1];
  const r = REPORTS.some(x => x[0] === hashTab) ? hashTab : (C.br || 'daybook'); C.br = r;
  const f = C.bf = Object.assign({ from: m.company.books_from || B.today.slice(0, 4) + '-04-01', to: B.today, account_id: '' }, C.bf || {});
  const asOf = ['trial', 'bs', 'parties'].includes(r), pick = r === 'ledger' ? m.accounts : ['bank', 'cash', 'petty'].includes(r) ? m.accounts.filter(a => a.sub === r) : null;
  if (pick && !pick.some(a => +a.id === +f.account_id)) f.account_id = pick[0]?.id || '';
  let rep, err = '';
  try { rep = (await api('acc_report', { r, ...f })).report; } catch (e) { err = e.message; }
  const qs = new URLSearchParams({ r, ...f }).toString();
  view().innerHTML = `<div class="head"><div><div class="crumb"><a href="#acc">← Accounts</a></div><h1>Books & reports</h1></div>${rep ? `<a class="btn" href="acc_export.php?${qs}">⬇ Export to Excel</a>` : ''}</div>
  <div class="panel" style="padding-top:4px"><div class="tabs">${REPORTS.map(([k, l]) => `<a href="#books-${k}" class="${r === k ? 'on' : ''}">${l}</a>`).join('')}</div>
   <form class="row" id="bookf" style="align-items:flex-end;padding-top:14px">${pick ? `<label class="f" style="min-width:260px">${r === 'bank' ? 'Bank account' : r === 'ledger' ? 'Account' : 'Cash account'}<select name="account_id">${accOpts(pick, f.account_id)}</select></label>` : ''}
    ${asOf ? '' : `<label class="f">From<input type="date" name="from" value="${f.from}"></label>`}<label class="f">${asOf ? 'As on' : 'To'}<input type="date" name="to" value="${f.to}"></label><button class="btn ghost">Show</button></form></div>
  ${err ? `<div class="alert bad"><span class="m">${esc(err)}</span></div>` : `<div class="panel"><h2>${esc(rep.title)}</h2><div class="tw"><table class="rep"><tr>${rep.cols.map((c, i) => `<th class="${rep.num.includes(i) ? 'r' : ''}">${esc(c)}</th>`).join('')}</tr>
   ${rep.rows.map(x => `<tr class="${x.k.startsWith('j') ? 'click' : ''} rk-${x.k}" ${x.k.startsWith('j') ? `data-go="jv-${x.k.slice(1)}"` : ''}>${x.c.map((v, i) => `<td class="${rep.num.includes(i) ? 'r num' : /Dr$|Cr$|^0\.00$/.test(String(v)) ? 'r num' : ''}">${rep.num.includes(i) && v !== '' ? inr2(v) : esc(/^\d{4}-\d{2}-\d{2}$/.test(v) ? fd(v) : v)}</td>`).join('')}</tr>`).join('')}</table></div></div>`}`;
  $('#bookf').onsubmit = e => { e.preventDefault(); Object.assign(C.bf, Object.fromEntries(new FormData(e.target))); refresh(); };
};

// ---------- chart of accounts ----------
const SUBL = { bank: 'Bank', cash: 'Cash', petty: 'Petty cash', debtor: 'Client', creditor: 'Vendor', employee: 'Staff', gst_in_c: 'Input GST', gst_in_s: 'Input GST', gst_in_i: 'Input GST', gst_out_c: 'Output GST', gst_out_s: 'Output GST', gst_out_i: 'Output GST', tds_in: 'TDS', tds_out: 'TDS' };
routes.coa = async () => {
  const d = await api('acc_accounts'); const ed = C.coaEdit ? d.accounts.find(a => +a.id === +C.coaEdit) : null;
  const grp = { asset: 'Assets', liability: 'Liabilities', equity: 'Capital', income: 'Income', expense: 'Expenses' };
  view().innerHTML = `<div class="head"><div><div class="crumb"><a href="#acc">← Accounts</a></div><h1>Chart of accounts</h1><p>Set opening balances here when you start. Client, vendor and staff accounts are created automatically.</p></div></div>
  ${ed ? `<form class="panel" id="coaform"><h3>Edit ${esc(ed.name)}</h3><div class="grid g3"><label class="f">Name *<input name="name" required value="${esc(ed.name)}"></label><label class="f">Code<input name="code" value="${esc(ed.code || '')}"></label>
    <label class="f">Opening balance (₹)<input type="number" step="any" name="opening" value="${Math.abs(+ed.opening) || ''}"></label><label class="f">Side<select name="opening_side"><option value="dr" ${+ed.opening >= 0 ? 'selected' : ''}>Debit</option><option value="cr" ${+ed.opening < 0 ? 'selected' : ''}>Credit</option></select></label>
    <label class="row f" style="align-self:end;gap:6px"><input type="checkbox" name="active" value="1" ${+ed.active ? 'checked' : ''}> Active</label></div><div class="row" style="margin-top:10px"><button class="btn">Save</button><button type="button" class="btn ghost" id="coa-cancel">Cancel</button></div></form>` : ''}
  <form class="panel" id="coanew"><h3>Add account</h3><div class="grid g3"><label class="f">Name *<input name="name" required placeholder="e.g. HDFC Bank, Office cleaning"></label><label class="f">Code<input name="code"></label>
   <label class="f">Type *<select name="type">${Object.entries(grp).map(([k, l]) => `<option value="${k}" ${k === 'expense' ? 'selected' : ''}>${l}</option>`).join('')}</select></label><label class="f">If asset: kind<select name="sub"><option value="">Normal</option><option value="bank">Bank account</option><option value="cash">Cash</option><option value="petty">Petty cash</option></select></label>
   <label class="f">Opening balance (₹)<input type="number" step="any" name="opening"></label><label class="f">Side<select name="opening_side"><option value="dr">Debit</option><option value="cr">Credit</option></select></label></div><div style="margin-top:10px"><button class="btn ghost">+ Add account</button></div></form>
  ${Object.entries(grp).map(([t, l]) => { const list = d.accounts.filter(a => a.type === t); return list.length ? `<div class="panel"><h2>${l}</h2><div class="tw"><table><tr><th>Code</th><th>Account</th><th>Kind</th><th class="r">Opening</th><th class="r">Balance today</th><th></th></tr>
   ${list.map(a => `<tr class="click" data-go="books-ledger" data-acc="${a.id}"><td class="num">${esc(a.code || '')}</td><td><b>${esc(a.name)}</b>${+a.active ? '' : ' <span class="sub">(inactive)</span>'}</td><td class="sub">${esc(SUBL[a.sub] || '')}</td><td class="r num">${+a.opening ? inr2(Math.abs(a.opening)) + (a.opening > 0 ? ' Dr' : ' Cr') : ''}</td><td class="r num">${+a.balance ? inr2(Math.abs(a.balance)) + (a.balance > 0 ? ' Dr' : ' Cr') : '—'}</td><td class="r"><button class="btn link" data-coaedit="${a.id}">Edit</button></td></tr>`).join('')}</table></div></div>` : ''; }).join('')}`;
};
routes.accset = async () => {
  const m = await accMeta(true); const c = m.company;
  const F = (k, l, t = 'text') => `<label class="f">${l}<input name="${k}" type="${t}" value="${esc(c[k] || '')}"></label>`;
  view().innerHTML = `<div class="head"><div><div class="crumb"><a href="#acc">← Accounts</a></div><h1>Company & invoice settings</h1><p>Printed on every invoice and quotation.</p></div></div>
  <form class="panel" id="accsetf"><div class="grid g3">${F('name', 'Company name')}${F('gstin', 'GSTIN')}${F('pan', 'PAN')}${F('phone', 'Phone')}${F('email', 'Email')}${F('website', 'Website')}
   <label class="f">State (for CGST/SGST vs IGST)<input name="state" list="states" value="${esc(c.state || '')}"></label><datalist id="states">${STATES.map(x => `<option value="${x}">`).join('')}</datalist>${F('sac', 'Default SAC code')}
   ${F('bank_benef', 'Beneficiary name')}${F('bank_name', 'Bank name')}${F('bank_branch', 'Branch address')}${F('bank_acno', 'Bank account no.')}${F('bank_ifsc', 'IFSC')}${F('bank_swift', 'SWIFT code')}${F('bank_micr', 'MICR code')}${F('books_from', 'Books start date', 'date')}</div>
   <div class="grid" style="margin-top:12px"><label class="f">Address<textarea name="address">${esc(c.address || '')}</textarea></label><label class="f">Invoice terms<textarea name="terms">${esc(c.terms || '')}</textarea></label></div>
   <div style="margin-top:12px"><button class="btn">Save settings</button></div></form>`;
};

// ---------- my expenses (sales + production) ----------
routes.myexp = async () => {
  const d = await api('claim_mine'); B.claims_attention = d.claims.filter(c => ['on_hold', 'rejected'].includes(c.status)).length; renderNav('myexp');
  const ed = C.cledit ? d.claims.find(c => +c.id === +C.cledit) : null, x = ed || { edate: B.today, particular: '', category: d.cats[0], rate: '', qty: 1, gst: '', project_id: '', note: '' };
  const form = `<form class="panel" id="clform" ${ed ? `data-id="${ed.id}"` : ''}><h2>${ed ? 'Correct and send again' : 'Add an expense'}</h2>${ed && ed.reason ? `<div class="alert ${ed.status === 'rejected' ? 'bad' : 'warn'}" style="margin-bottom:10px"><span class="m"><b>Accounts:</b> ${esc(ed.reason)}</span></div>` : ''}
   <div class="grid g3"><label class="f">Date of expense *<input type="date" name="edate" required max="${B.today}" value="${esc(x.edate)}"></label><label class="f wide">Particular of expense *<input name="particular" required value="${esc(x.particular)}" placeholder="e.g. Flight Delhi–Bengaluru for site visit"></label>
    <label class="f">Category<select name="category">${d.cats.map(k => `<option ${k === x.category ? 'selected' : ''}>${esc(k)}</option>`).join('')}</select></label>
    <label class="f">Unit rate (₹) *<input type="number" step="any" name="rate" required value="${esc(x.rate)}" data-clc></label><label class="f">Qty<input type="number" step="any" name="qty" value="${esc(x.qty)}" data-clc></label>
    <label class="f">Total amount<input id="cl-total" readonly tabindex="-1"></label><label class="f">GST (₹, if any)<input type="number" step="any" name="gst" value="${esc(+x.gst ? x.gst : '')}" data-clc></label><label class="f">Grand total<input id="cl-grand" readonly tabindex="-1" style="font-weight:700"></label>
    <label class="f">Order (optional)<select name="project_id"><option value="">— not for an order —</option>${d.projects.map(p => `<option value="${p.id}" ${+p.id === +x.project_id ? 'selected' : ''}>${esc(p.company + ' · ' + p.expo)}</option>`).join('')}</select></label>
    <label class="f wide">Note<input name="note" value="${esc(x.note || '')}"></label>${ed ? '<label class="f wide">What did you change?<input name="reply"></label>' : ''}</div>
   <div class="row" style="margin-top:12px"><label class="up">⬆ Upload bill / proof (JPG, PNG, PDF, Excel)<input type="file" name="file" multiple accept=".jpg,.jpeg,.png,.webp,.heic,.pdf,.xlsx,.xls,.csv"></label>${ed ? fileChips(ed.files) : ''}<button class="btn">${ed ? 'Send again for approval' : 'Submit for approval'}</button>${ed ? '<button type="button" class="btn ghost" id="cl-cancel">Cancel</button>' : ''}</div></form>`;
  view().innerHTML = `<div class="head"><div><h1>My expenses</h1><p>Submit expenses with the bill. Accounts approves, rejects with a reason, or asks for more details.</p></div></div>${form}
  <div class="panel"><h2>My claims</h2>${d.claims.length ? d.claims.map(c => `<div class="inset" style="margin-bottom:10px"><div class="ph" style="margin-bottom:4px"><b>${esc(c.particular)}</b>${claimPill(c)}</div>
    <div class="sub">${fd(c.edate)} · ${esc(c.category || '')}${c.expo ? ' · ' + esc(c.company + ' · ' + c.expo) : ''} · ${inr2(c.rate)} × ${num(c.qty)}${+c.gst ? ' + GST ' + inr2(c.gst) : ''} = <b style="color:var(--ink)">${inr2(c.grand)}</b></div>
    <div class="row" style="margin-top:6px">${fileChips(c.files)}${c.status !== 'approved' ? upBtn('claim', c.id, 'Add bill') : ''}</div>${claimThread(c)}
    ${c.status === 'on_hold' ? `<form class="row" data-clreply="${c.id}" style="margin-top:8px"><input name="text" required placeholder="Give the details accounts asked for" style="flex:1"><button class="btn sm">Send details</button></form>` : ''}
    <div class="row" style="margin-top:6px">${['rejected', 'on_hold'].includes(c.status) ? `<button class="btn ghost sm" data-cledit="${c.id}">Edit & send again</button>` : ''}${c.status !== 'approved' ? `<button class="btn link" data-cldel="${c.id}">Delete</button>` : ''}</div></div>`).join('') : blank('No expenses submitted yet.')}</div>`;
  clCalc();
};
function clCalc() { const f = $('#clform'); if (!f) return; const v = k => +(f.querySelector(`[name=${k}]`).value) || 0; const t = v('rate') * (v('qty') || 1); $('#cl-total').value = inr2(t); $('#cl-grand').value = inr2(t + v('gst')); }

// ---------- TEAM ----------
routes.team = async () => {
  const d = await api('users'); const ed = C.editUser ? d.users.find(u => +u.id === +C.editUser) : null;
  const deptOpts = sel => B.departments.map(x => `<option ${x === sel ? 'selected' : ''}>${esc(x)}</option>`).join('');
  view().innerHTML = `
  <div class="head"><div><h1>Team</h1><p>Only admin sets role and department. The department decides the daily report format.</p></div></div>
  <div class="panel"><div class="tw"><table><tr><th>Name</th><th>Login email</th><th>Designation</th><th>Department</th><th>Access</th><th>Joined</th><th>Status</th><th></th></tr>
   ${d.users.map(u => `<tr><td><b>${esc(u.name)}</b><div class="sub">${esc(u.phone || '')}</div></td><td>${esc(u.email)}</td><td>${esc(u.designation || '')}</td><td>${esc(u.department)}</td><td>${u.role === 'admin' ? '<span class="pill p-enquiry">admin</span>' : MOD_LABEL[modOf(u.department)]}</td><td class="num">${fd(u.joined_on)}</td><td>${+u.active ? '<span class="pill p-ok">active</span>' : '<span class="pill p-off">disabled</span>'}</td><td class="r"><button class="btn ghost sm" data-edituser="${u.id}">Edit</button></td></tr>`).join('')}</table></div></div>
  <div class="panel"><h2>What each department works on</h2><p class="sub" style="margin-top:0">Decides the screens an employee gets as soon as their login is created. Everyone also has the daily report.</p>
   <div class="tw"><table><tr><th>Department</th><th>Works on</th><th class="r">People</th></tr>${B.departments.map(x => `<tr><td><b>${esc(x)}</b></td><td><select data-deptmod="${esc(x)}" aria-label="${esc(x)} works on">${Object.entries(MOD_LABEL).map(([k, l]) => `<option value="${k}" ${modOf(x) === k ? 'selected' : ''}>${l}</option>`).join('')}</select></td><td class="r num">${d.users.filter(u => u.department === x && +u.active && u.role === 'employee').length}</td></tr>`).join('')}</table></div></div>
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
  const [d, st] = await Promise.all([api('settings'), api('storage')]);
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
  </div>
  <form class="panel" id="qdefs"><h2>Quote template</h2><p class="sub" style="margin-top:0">Every generated quote starts with these. Sales can still change them on each quote. One point per line.</p>
   <div class="grid g3"><label class="f">Valid for (days)<input type="number" name="valid_days" min="1" value="${d.quote_defaults.valid_days}"></label><label class="f wide">Default scope of work<input name="scope" value="${esc(d.quote_defaults.scope)}"></label></div>
   <div class="grid" style="margin-top:10px">${[['payment_terms', 'Payment terms'], ['notes', 'Important notes & terms ({valid} = validity days)'], ['client_resp', 'Client responsibilities & ownership'], ['excludes', 'Quotation does not include']].map(([k, l]) => `<label class="f">${l}<textarea name="${k}" rows="${d.quote_defaults[k].length + 1}">${esc(d.quote_defaults[k].join('\n'))}</textarea></label>`).join('')}</div>
   <div style="margin-top:10px"><button class="btn sm">Save quote template</button> <span class="sub">Bank details & GSTIN: <a href="#accset">Company settings</a></span></div></form>
  <div class="panel" id="storage"><h2>Files & storage</h2><p class="sub" style="margin-top:0">Every upload is compressed automatically: photos, screenshots and the pictures inside PDF, Word, Excel and PowerPoint files. Text and drawings are not touched.</p>
   ${!st.tools.gd || !st.tools.zip ? `<div class="alert bad"><b>Compression tools missing on this server</b><span class="m">Ask hosting to enable the PHP "${!st.tools.gd ? 'gd' : ''}${!st.tools.gd && !st.tools.zip ? '" and "' : ''}${!st.tools.zip ? 'zip' : ''}" extension. Uploads still work, but are not compressed.</span></div>` : ''}
   <div class="kpis"><div class="kpi"><span>Files stored</span><b>${st.files}</b></div><div class="kpi"><span>Space used</span><b>${mb(st.size)}</b></div><div class="kpi hl"><span>Space saved by compression</span><b>${mb(st.saved)}</b></div><div class="kpi"><span>Largest upload allowed</span><b>${st.limit_mb} MB</b>${st.limit_mb < 50 ? '<small>hosting limit — can be raised in hPanel → PHP settings</small>' : ''}</div></div>
   <div class="row" style="align-items:flex-end;margin-top:12px"><label class="f">Picture quality<select id="fq">${Object.entries(st.levels).map(([k, v]) => `<option value="${k}" ${st.level === k ? 'selected' : ''}>${esc(v.label)} (up to ${v.max} px)</option>`).join('')}</select></label><button class="btn ghost sm" id="fq-save">Save</button>
    ${st.pending ? `<button class="btn sm" id="shr-run">Compress ${st.pending} older file${st.pending > 1 ? 's' : ''} now</button>` : '<span class="sub">All files are compressed.</span>'}</div>
   <div class="sub" id="shr-prog" style="margin-top:8px"></div></div>
  <div class="panel"><h2>Furniture & fixtures list</h2><p class="sub" style="margin-top:0">Shown as tick boxes on every production job.</p>
   <div class="row">${(B.furniture_items || []).map((x, i) => `<span class="chip"><span>${esc(x)}</span><button class="btn link" data-delfurn="${i}" aria-label="Remove ${esc(x)}">✕</button></span>`).join('')}</div>
   <form class="row" id="furn-add" style="margin-top:10px"><input name="item" placeholder="e.g. Podium" required style="max-width:260px"><button class="btn ghost sm">+ Add item</button></form></div>`;
  $('#furn-add').onsubmit = async e => { e.preventDefault(); const v = new FormData(e.target).get('item').trim(); if (!v) return; await act(() => api('settings_save', { furniture_items: [...B.furniture_items, v] }), 'Added'); await reboot(); refresh(); };
  const sub = (id, a, msg) => { $(id).onsubmit = async e => { e.preventDefault(); await act(() => api(a, Object.fromEntries(new FormData(e.target))), msg); await reboot(); refresh(); }; };
  sub('#hol-add', 'holiday_add', 'Holiday added'); sub('#rs-add', 'reason_add', 'Reason added'); sub('#vd-add', 'vendor_add', 'Vendor added');
  $('#trackf-save').onclick = async () => { await act(() => api('settings_save', { track_from: $('#trackf').value }), 'Saved'); };
  $('#qdefs').onsubmit = async e => { e.preventDefault(); await act(() => api('settings_save', { quote_defaults: Object.fromEntries(new FormData(e.target)) }), 'Quote template saved'); };
  $('#fq-save').onclick = async () => { await act(() => api('settings_save', { file_quality: $('#fq').value }), 'Saved — applies to new uploads'); await reboot(); };
  const run = $('#shr-run'); if (run) run.onclick = async () => {
    run.disabled = true; let saved = 0, left = st.pending;
    try { while (left > 0) { const r = await api('storage_shrink'); saved += r.saved; left = r.pending; $('#shr-prog').textContent = `Working… ${st.pending - left} of ${st.pending} done · ${mb(saved)} saved so far`; if (!r.done) break; } toast(`Done — ${mb(saved)} saved`); }
    catch (e) { toast(e.message, true); }
    await refresh(); $('#storage').scrollIntoView();
  };
};

// ---------- ACCOUNT ----------
routes.account = async () => {
  view().innerHTML = `<div class="head"><div><h1>Account</h1><p>${esc(B.me.name)} · ${esc(B.me.email)} · ${esc(B.me.department)}</p></div></div>
  <form class="panel" id="pw" style="max-width:520px"><h2>Change password</h2><div class="grid"><label class="f">Current password<input type="password" name="old" required autocomplete="current-password"></label><label class="f">New password (min 8)<input type="password" name="new" required minlength="8" autocomplete="new-password"></label></div><div style="margin-top:12px"><button class="btn">Update password</button></div></form>`;
  $('#pw').onsubmit = async e => { e.preventDefault(); await act(() => api('password', Object.fromEntries(new FormData(e.target))), 'Password updated'); e.target.reset(); };
};

// ---------- EVENTS ----------
document.addEventListener('click', async e => {
  const t = e.target.closest('[data-reft],[data-refadd],[data-refclose],[data-refreopen],[data-dokundo],[data-caedit],[data-cadel],[data-repedit],[data-repdel],[data-repadd],[data-reprm],[data-clt],[data-invt],[data-invreqtax],[data-invconv],[data-ildel],[data-post],[data-postskip],[data-coaedit],[data-cledit],[data-cldel],[data-adec],[data-acancel],[data-asent],[data-slt],[data-go],[data-ledit-open],[data-lcancel],[data-ldel],[data-delfurn],[data-editchild],[data-canceledit],[data-delchild],[data-delfile],[data-delcontact],[data-rmitem],[data-edituser],[data-fmdel],[data-fmup],[data-delhol],[data-delreason],[data-delvendor],button[id]');
  if (!t) return;
  const ds = t.dataset;
  try {
    if (ds.go && ds.acc && !e.target.closest('a,button,input,select,label')) { C.bf = Object.assign(C.bf || {}, { account_id: ds.acc }); C.br = 'ledger'; }
    if (ds.go) { if (e.target.closest('a,button,input,select,label')) return; location.hash = '#' + ds.go; return; }
    if (ds.coaedit) { e.stopPropagation(); C.coaEdit = +ds.coaedit; await refresh(); window.scrollTo(0, 0); return; }
    if (ds.reft !== undefined) { e.preventDefault(); C.reff = { status: ds.reft }; return refresh(); }
    if (ds.refadd) { const r = C.refs.find(x => +x.id === +ds.refadd);
      newClient = { company: r.company, email: '', phone: r.phone, person: r.name, designation: '', city: '', location: '', gst_no: '', notes: `Referral lead from ${r.by_name}${r.area ? ' · Area: ' + r.area : ''}${r.note ? ' — ' + r.note : ''}`, owner_id: '', contacts: [], cards: [], briefs: [], gstcert: [],
        expo: { expo: '', location: '', start_date: '', area_sqm: '', space_type: '', sides_open: '', stall_no: '' }, ref_id: r.id };
      location.hash = '#newclient'; return; }
    if (ds.refclose) { const fm = document.querySelector(`[data-refclosef="${ds.refclose}"]`); fm.hidden = !fm.hidden; if (!fm.hidden) fm.querySelector('input').focus(); return; }
    if (ds.refreopen) { await act(() => api('ref_reopen', { id: ds.refreopen }), 'Reopened'); return refresh(); }
    if (ds.dokundo) { if (!confirm('Undo the customer approval of the design?')) return; await act(() => api('design_client_ok', { project_id: C.project.id, undo: 1 }), 'Undone'); return refresh(); }
    if (ds.caedit !== undefined) { C.caEdit = +ds.caedit; return refresh(); }
    if (ds.cadel) { if (!confirm(ds.st === 'approved' ? 'Delete this expense? It will also be removed from the books (reversed) and from the order.' : 'Delete this expense claim?')) return; await act(() => api('claim_admin_del', { id: ds.cadel }), 'Expense deleted'); await reboot(); return refresh(); }
    if (ds.repedit !== undefined) { C.repEdit = ds.repedit === '0' ? null : JSON.parse(JSON.stringify(C.repById[ds.repedit])); await refresh(); if (C.repEdit) window.scrollTo(0, 0); return; }
    if (ds.repdel) { if (!confirm('Delete this daily report? The employee will have to submit it again.')) return; await act(() => api('report_delete', { id: ds.repdel }), 'Report deleted'); C.repEdit = null; return refresh(); }
    if (ds.repadd !== undefined) { repCapture(); C.repEdit.items.push({}); return refresh(); }
    if (ds.reprm !== undefined) { repCapture(); C.repEdit.items.splice(+ds.reprm, 1); return refresh(); }
    if (ds.clt) { e.preventDefault(); C.clf = { ...(C.clf || {}), status: ds.clt }; return refresh(); }
    if (ds.invt) { e.preventDefault(); C.invf = { ...(C.invf || {}), group: ds.invt }; return refresh(); }
    if (ds.invreqtax) { const note = prompt('Anything accounts should know for the tax invoice?', 'Tax invoice as per approved proforma'); if (note === null) return; const r = await act(() => api('inv_request', { from_id: ds.invreqtax, note: note || 'Tax invoice as per approved proforma' }), 'Request sent to accounts'); location.hash = '#invd-' + r.id; return; }
    if (ds.invconv) { const r = await act(() => api('inv_convert', { id: ds.invconv }), 'Tax invoice draft created — send it to admin when ready'); location.hash = '#inv-' + r.id; return; }
    if (ds.ildel !== undefined) { invCapture(); C.inv.lines.splice(+ds.ildel, 1); return drawInv(); }
    if (ds.post || ds.postskip) { const key = ds.post || ds.postskip, [rt, rid] = key.split(':');
      const pm = document.querySelector(`[data-pm="${key}"]`), pa = document.querySelector(`[data-pa="${key}"]`);
      await act(() => api('acc_post_crm', { ref_type: rt, ref_id: rid, money_id: pm?.value, account_id: pa?.value, skip: ds.postskip ? 1 : 0 }), ds.post ? 'Posted to books' : 'Skipped'); return refresh(); }
    if (ds.cledit) { C.cledit = +ds.cledit; await refresh(); window.scrollTo(0, 0); return; }
    if (ds.cldel) { if (!confirm('Delete this expense claim?')) return; await act(() => api('claim_del', { id: ds.cldel }), 'Deleted'); return refresh(); }
    if (ds.slt) { e.preventDefault(); C.slog = { ...(C.slog || {}), status: ds.slt }; return refresh(); }
    if (ds.adec) { const [dec, id] = ds.adec.split(':'); const note = (document.querySelector(`[data-anote="${id}"]`) || {}).value || '';
      if (dec === 'rejected' && !note.trim()) { toast('Write what needs to change in the note box, then click Reject', true); const n = document.querySelector(`[data-anote="${id}"]`); if (n) n.focus(); return; }
      await act(() => api('appr_decide', { id, decision: dec, note }), dec === 'approved' ? 'Approved' : 'Rejected — sales will see your note'); await reboot(); return refresh(); }
    if (ds.acancel) { if (!confirm('Withdraw this approval request?')) return; await act(() => api('appr_cancel', { id: ds.acancel }), 'Withdrawn'); return refresh(); }
    if (ds.asent) { const on = (document.querySelector(`[data-sentdate="${ds.asent}"]`) || {}).value; await act(() => api('appr_sent', { id: ds.asent, sent_on: on }), 'Marked as sent to customer'); return refresh(); }
    if (ds.leditOpen) { C.leditId = +ds.leditOpen; await refresh(); const i = document.querySelector('form[data-ledit] [name=company]'); if (i) i.focus(); return; }
    if (ds.lcancel) { C.leditId = null; return refresh(); }
    if (ds.ldel) { if (!confirm('Delete this entry?')) return; await act(() => api('lead_del', { id: ds.ldel }), 'Deleted'); return refresh(); }
    if (ds.delfurn !== undefined) { const l = B.furniture_items.filter((_, i) => i !== +ds.delfurn); await act(() => api('settings_save', { furniture_items: l }), 'Removed'); await reboot(); return refresh(); }
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
        const r = await act(() => api('client_save', { ...n, cards: undefined, briefs: undefined, gstcert: undefined })).catch(() => null);
        if (!r) { t.disabled = false; return; }
        if (n.cards.length) await act(() => uploadAll('card', r.id, n.cards)).catch(() => {});
        if (n.briefs.length) await act(() => uploadAll('brief', r.id, n.briefs)).catch(() => {});
        if (n.gstcert.length) await act(() => uploadAll('gstcert', r.id, n.gstcert)).catch(() => {});
        newClient = null; toast(n.expo.expo.trim() ? 'Client and expo order saved' : 'Client saved'); if (n.ref_id) await reboot(); location.hash = '#client-' + r.id; return;
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
      case 'jl-add': { const tb = $('#jl'); const tr = tb.rows[1].cloneNode(true); tr.querySelectorAll('input').forEach(i => i.value = ''); tr.querySelector('select').selectedIndex = 0; tb.tBodies[0].appendChild(tr); return; }
      case 'inv-addline': invCapture(); C.inv.lines.push({ desc: '', sac: C.am.company.sac || '998596', qty: 1, rate: '', tax: 1 }); return drawInv();
      case 'inv-fill': { invCapture(); const pid = C.inv.project_id; if (!pid) return toast('Pick the order first', true);
        const r = await act(() => api('inv_prefill', { project_id: pid })); Object.assign(C.inv, { client_id: r.client_id, gst_rate: r.gst_rate, lines: r.lines }); return drawInv(); }
      case 'jv-rev-open': $('#jv-rev').hidden = false; $('#jv-edit') && ($('#jv-edit').hidden = true); return;
      case 'jv-edit-open': $('#jv-edit').hidden = false; $('#jv-rev').hidden = true; return;
      case 'coa-cancel': C.coaEdit = null; return refresh();
      case 'cl-cancel': C.cledit = null; return refresh();
      case 'lx-edit-open': $('#lx-edit').hidden = false; return;
      case 'lx-edit-cancel': $('#lx-edit').hidden = true; return;
      case 'lx-paste-go': {
        const lines = $('#lx-paste').value.split(/\r?\n/).map(l => l.split('\t').map(c => c.trim())).filter(r => r.some(Boolean));
        if (lines.length && /company/i.test(lines[0][0] || '')) lines.shift();
        if (!lines.length) return toast('Paste rows from Excel first', true);
        t.disabled = true;
        const r = await act(() => api('lead_bulk', { expo_id: C.lexpo.expo.id, rows: lines })).catch(() => null); t.disabled = false;
        if (r) { toast(`${r.added} added${r.skipped.length ? ` · ${r.skipped.length} already in list, skipped` : ''}`); refresh(); }
        return;
      }
      case 'ops-furn-add': { const v = ($('#ops-furn-new').value || '').trim(); if (!v) return; if (!C.ops.furniture.some(f => f.item.toLowerCase() === v.toLowerCase())) C.ops.furniture.push({ item: v, qty: 1 }); return drawOps(); }
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
  if (ds.op && C.ops && ds.op !== 'vinyl_total') setPath(C.ops, ds.op, t.value);
  if (ds.sal !== undefined) { const f = t.form, v = k => +f.querySelector(`[name=${k}]`).value || 0; $('#sal-net').value = inr2(v('gross') - v('deductions') - v('tds')); }
  if (t.closest && t.closest('#invform')) invCalc();
  if (ds.clc !== undefined) clCalc();
  if (ds.jl) { let dr = 0, cr = 0; document.querySelectorAll('#jl [data-jl=dr]').forEach(i => dr += +i.value || 0); document.querySelectorAll('#jl [data-jl=cr]').forEach(i => cr += +i.value || 0); $('#jl-tot').textContent = `Debit ${inr2(dr)} · Credit ${inr2(cr)}${Math.abs(dr - cr) > 0.009 ? ' · not equal yet' : ' · balanced'}`; }
  if (ds.furnq !== undefined && C.ops) { const f = C.ops.furniture.find(x => x.item === ds.furnq); if (f) f.qty = t.value; }
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
    if (ds.upload && t.files.length) { const files = [...t.files]; toast('Uploading…'); const note = await act(() => uploadAll(ds.upload, ds.eid, files)); toast(`${files.length} file(s) uploaded${note || ''}`); return refresh(); }
    if (t.id === 'nc-cards') { newClient.cards.push(...t.files); return refresh(); }
    if (t.id === 'nc-brief') { newClient.briefs.push(...t.files); return refresh(); }
    if (t.id === 'nc-gst') { newClient.gstcert = [...t.files]; return refresh(); }
    if (ds.rfile !== undefined) { draft.files[+ds.rfile].push(...t.files); return refresh(); }
    if (ds.woff !== undefined) { const i = +ds.woff; const w = t.checked ? [...B.weekly_off, i] : B.weekly_off.filter(x => x !== i); await act(() => api('settings_save', { weekly_off: w }), 'Weekly off saved'); await reboot(); return; }
    if (t.id === 'fm-dept') { C.fmDept = t.value; return refresh(); }
    if (t.id === 'rcp-proj') { const c = t.selectedOptions[0]?.dataset.client; if (c) $('#rcp-client').value = c; return; }
    if (t.id === 'inv-proj') { const c = t.selectedOptions[0]?.dataset.client; if (c) $('#inv-client').value = c; return; }
    if (ds.deptmod !== undefined) { await act(() => api('settings_save', { dept_modules: { [ds.deptmod]: t.value } }), `${ds.deptmod}: ${MOD_LABEL[t.value]}`); await reboot(); return refresh(); }
    if (ds.jassign) { const [kind, pid] = ds.jassign.split(':'); await act(() => api(kind === 'designer' ? 'assign_designer' : 'assign_ops', { project_id: pid, user_id: t.value }), +t.value ? 'Assigned' : 'Unassigned'); return refresh(); }
    if (ds.assign && C.project) { await act(() => api(ds.assign === 'designer' ? 'assign_designer' : 'assign_ops', { project_id: C.project.id, user_id: t.value }), +t.value ? 'Assigned' : 'Unassigned'); return refresh(); }
    if (t.id === 'lxu') { C.lxu = t.value; return refresh(); }
    if (ds.furn !== undefined && C.ops) { const it = ds.furn; C.ops.furniture = t.checked ? [...C.ops.furniture.filter(f => f.item !== it), { item: it, qty: 1 }] : C.ops.furniture.filter(f => f.item !== it); return drawOps(); }
    if (ds.op === 'vinyl_total' && C.ops) { const nn = Math.max(0, Math.min(50, parseInt(t.value) || 0)); C.ops.vinyl_total = nn; const v = C.ops.vinyl.slice(0, nn); while (v.length < nn) v.push({ w: '', wu: 'ft', h: '', hu: 'ft' }); C.ops.vinyl = v; return drawOps(); }
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
  if (f.id === 'accentry') {
    e.preventDefault(); const fdta = new FormData(f); const files = fdta.getAll('file').filter(x => x && x.size); fdta.delete('file');
    const data = { kind: f.dataset.kind, ...Object.fromEntries(fdta) };
    if (data.kind === 'expense' && !data.paid_from && !data.vendor_id) return toast('Pick where it was paid from, or the vendor it is owed to', true);
    if (data.kind === 'journal') data.lines = [...f.querySelectorAll('#jl tr')].slice(1).map(tr => ({ account_id: tr.querySelector('[data-jl=account_id]').value, dr: tr.querySelector('[data-jl=dr]').value, cr: tr.querySelector('[data-jl=cr]').value, memo: tr.querySelector('[data-jl=memo]').value }));
    const btn = f.querySelector('button:not([type=button])'); btn.disabled = true;
    try { const r = await act(() => api('acc_entry', data)); if (files.length) await act(() => uploadAll('voucher', r.id, files)); toast('Posted ' + r.vno); location.hash = '#jv-' + r.id; } catch { btn.disabled = false; }
    return;
  }
  if (f.id === 'jv-edit') {
    e.preventDefault(); const o = Object.fromEntries(new FormData(f));
    o.lines = [...f.querySelectorAll('#jl tr')].slice(1).map(tr => ({ account_id: tr.querySelector('[data-jl=account_id]').value, dr: tr.querySelector('[data-jl=dr]').value, cr: tr.querySelector('[data-jl=cr]').value, memo: tr.querySelector('[data-jl=memo]').value }));
    const r = await act(() => api('acc_edit', { id: C.jv.id, ...o }), 'Entry corrected').catch(() => null); if (r) location.hash = '#jv-' + r.id; return;
  }
  if (f.id === 'jv-rev') { e.preventDefault(); const r = await act(() => api('acc_reverse', { id: C.jv.id, ...Object.fromEntries(new FormData(f)) }), 'Reversed').catch(() => null); if (r) location.hash = '#jv-' + r.id; return; }
  if (f.id === 'invform') {
    e.preventDefault(); invCapture(); const how = e.submitter?.dataset.invsave || 'draft';
    const r = await act(() => api('inv_save', { ...C.inv, submit: how === 'submit' ? 1 : 0, message: f.querySelector('[name=message]').value }), how === 'submit' ? 'Sent to admin for approval' : 'Draft saved').catch(() => null); if (!r) return;
    await reboot(); location.hash = '#invd-' + r.id; return;
  }
  if (f.id === 'invreqf') {
    e.preventDefault(); const o = Object.fromEntries(new FormData(f));
    if (!o.project_id && !o.client_id) return toast('Pick the order or the client', true);
    const r = await act(() => api('inv_request', o), 'Request sent to accounts').catch(() => null); if (r) { C.invreq = null; location.hash = '#invd-' + r.id; } return;
  }
  if (f.dataset.invdec) {
    e.preventDefault(); const action = e.submitter?.dataset.iact; const reason = f.querySelector('[name=reason]').value;
    if (action !== 'approve' && !reason.trim()) return toast(action === 'hold' ? 'Write what details are needed' : 'Write why it is rejected', true);
    const r = await act(() => api('inv_decide', { id: f.dataset.invdec, action, reason }), action === 'approve' ? 'Approved' : action === 'hold' ? 'On hold — sent to accounts and sales' : 'Rejected — sent to accounts and sales').catch(() => null);
    if (r) { if (r.inv_no) toast('Approved · ' + r.inv_no); await reboot(); refresh(); } return;
  }
  if (f.dataset.invreply) { e.preventDefault(); const ok = await act(() => api('inv_reply', { id: f.dataset.invreply, text: f.querySelector('[name=text]').value }), 'Sent').catch(() => null); if (ok) refresh(); return; }
  if (f.dataset.invcorr) { e.preventDefault(); const ok = await act(() => api('inv_correct', { id: f.dataset.invcorr, reason: f.querySelector('[name=reason]').value }), 'Reopened for correction').catch(() => null); if (ok) { await reboot(); refresh(); } return; }
  if (f.dataset.invcan) { e.preventDefault(); const ok = await act(() => api('inv_cancel', { id: f.dataset.invcan, reason: f.querySelector('[name=reason]').value }), 'Cancelled').catch(() => null); if (ok) { await reboot(); refresh(); } return; }
  if (f.dataset.refclosef) { e.preventDefault(); const ok = await act(() => api('ref_close', { id: f.dataset.refclosef, reason: f.querySelector('[name=reason]').value }), 'Lead closed').catch(() => null); if (ok) { await reboot(); refresh(); } return; }
  if (f.dataset.refmove) { e.preventDefault(); const ok = await act(() => api('ref_save', { id: f.dataset.refmove, assigned_to: f.querySelector('[name=assigned_to]').value }), 'Lead handed over').catch(() => null); if (ok) { await reboot(); refresh(); } return; }
  if (f.id === 'dokf') { e.preventDefault(); const o = Object.fromEntries(new FormData(f)); const ok = await act(() => api('design_client_ok', { project_id: C.project.id, approval_id: e.submitter?.name === 'nover' ? 0 : o.approval_id, on: o.on }), 'Marked: customer approved the design — Generate quote is now open').catch(() => null); if (ok) refresh(); return; }
  if (f.dataset.casave) { e.preventDefault(); const ok = await act(() => api('claim_admin_save', { id: f.dataset.casave, ...Object.fromEntries(new FormData(f)) }), 'Expense updated').catch(() => null); if (ok) { C.caEdit = null; refresh(); } return; }
  if (f.id === 'repeditf') { e.preventDefault(); repCapture(); const ok = await act(() => api('report_admin_save', { id: C.repEdit.id, items: C.repEdit.items }), 'Report updated').catch(() => null); if (ok) { C.repEdit = null; refresh(); } return; }
  if (f.dataset.cdec) {
    e.preventDefault(); const action = e.submitter?.dataset.cact; const o = Object.fromEntries(new FormData(f));
    if (action !== 'approve' && !(o.reason || '').trim()) return toast(action === 'reject' ? 'Write the reason for rejecting' : 'Write what details you need', true);
    const ok = await act(() => api('claim_decide', { id: f.dataset.cdec, action, ...o }), action === 'approve' ? 'Approved and posted to books' : action === 'hold' ? 'On hold — sent back for details' : 'Rejected — sent back with reason').catch(() => null); if (ok) { await reboot(); refresh(); } return;
  }
  if (f.id === 'coaform' || f.id === 'coanew') { e.preventDefault(); const o = Object.fromEntries(new FormData(f)); if (f.id === 'coaform') { o.id = C.coaEdit; o.active = o.active ? 1 : 0; }
    const ok = await act(() => api('acc_account_save', o), 'Saved').catch(() => null); if (ok) { C.coaEdit = null; C.am = null; refresh(); } return; }
  if (f.id === 'accsetf') { e.preventDefault(); const ok = await act(() => api('acc_company_save', Object.fromEntries(new FormData(f))), 'Settings saved').catch(() => null); if (ok) C.am = null; return; }
  if (f.id === 'clform') {
    e.preventDefault(); const fdta = new FormData(f); const files = fdta.getAll('file').filter(x => x && x.size); fdta.delete('file');
    const o = Object.fromEntries(fdta); if (f.dataset.id) o.id = f.dataset.id;
    if (!f.dataset.id && !files.length && !confirm('No bill attached. Submit without proof?')) return;
    const btn = f.querySelector('button:not([type=button])'); btn.disabled = true;
    try { const r = await act(() => api('claim_save', o)); if (files.length) await act(() => uploadAll('claim', r.id, files)); toast('Sent to accounts for approval'); C.cledit = null; await reboot(); refresh(); } catch { btn.disabled = false; }
    return;
  }
  if (f.dataset.clreply) { e.preventDefault(); const ok = await act(() => api('claim_reply', { id: f.dataset.clreply, ...Object.fromEntries(new FormData(f)) }), 'Details sent to accounts').catch(() => null); if (ok) { await reboot(); refresh(); } return; }
  if (f.dataset.areq && C.project) {
    e.preventDefault(); const fdta = new FormData(f); const files = fdta.getAll('file').filter(x => x && x.size); const reuse = fdta.getAll('reuse');
    if (!files.length && !reuse.length) return toast('Attach the ' + (f.dataset.areq === 'quote' ? 'quote' : 'design') + ' file first', true);
    const btn = f.querySelector('button:not([type=button])'); btn.disabled = true;
    try {
      const r = await act(() => api('appr_request', { project_id: C.project.id, kind: f.dataset.areq, amount: fdta.get('amount'), note: fdta.get('note'), reuse_file_ids: reuse }));
      if (files.length) await act(() => uploadAll('appr', r.id, files));
      toast(`V${r.version} sent to admin for approval`); refresh();
    } catch { btn.disabled = false; }
    return;
  }
  if (f.id === 'lead-add') {
    e.preventDefault(); const btn = f.querySelector('button'); btn.disabled = true;
    const r = await act(() => api('lead_add', { expo_id: C.lexpo.expo.id, ...Object.fromEntries(new FormData(f)) }), 'Added').catch(() => null);
    btn.disabled = false; if (!r) return;
    await refresh(); const i = $('#lead-add [name=company]'); if (i) { i.focus(); i.scrollIntoView({ block: 'center' }); } return;
  }
  if (f.dataset.ledit) { e.preventDefault(); const ok = await act(() => api('lead_update', { id: f.dataset.ledit, ...Object.fromEntries(new FormData(f)) }), 'Updated').catch(() => null); if (ok) { C.leditId = null; refresh(); } return; }
  if (f.id === 'lx-edit') { e.preventDefault(); const ok = await act(() => api('lexpo_save', { id: C.lexpo.expo.id, ...Object.fromEntries(new FormData(f)) }), 'Saved').catch(() => null); if (ok) refresh(); return; }
  if (f.dataset.dsave) { e.preventDefault(); const ok = await act(() => api('design_save', { id: f.dataset.dsave, ...Object.fromEntries(new FormData(f)) }), 'Saved').catch(() => null); if (ok) refresh(); return; }
  if (f.id === 'opsform') { e.preventDefault(); const ok = await act(() => api('ops_save', { id: f.dataset.pid, ops: C.ops }), 'Production details saved').catch(() => null); if (ok) refresh(); return; }
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
