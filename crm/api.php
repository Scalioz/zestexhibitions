<?php
// JSON API for the Zest CRM app. Every call: POST, logged-in, CSRF header.
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/calc.php';
require_once __DIR__ . '/lib/schema.php';
require_once __DIR__ . '/lib/report.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($d) { $p = $GLOBALS['ZEST_PDO'] ?? null; if ($p && $p->inTransaction()) $p->commit(); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }   // success: save everything
function fail($msg, $code = 400) { $p = $GLOBALS['ZEST_PDO'] ?? null; if ($p && $p->inTransaction()) $p->rollBack(); http_response_code($code); out(['error' => $msg]); }   // error: save nothing

set_exception_handler(function ($e) { error_log('[zestcrm] ' . $e->getMessage()); fail('Something went wrong on the server. Please try again.', 500); });

if (!cfg()) fail('Not installed', 503);
$me = current_user();
if (!$me) fail('Please sign in again.', 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST only', 405);
if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF'] ?? '')) fail('Page expired. Reload the page.', 419);
session_write_close();   // release the session so one person's parallel requests don't queue behind each other
migrate();
try { require_once __DIR__ . '/lib/att.php'; att_tick(); } catch (Throwable $e) { error_log('[zestcrm] attendance tick: ' . $e->getMessage()); }
try { require_once __DIR__ . '/lib/alerts.php'; alerts_tick(); } catch (Throwable $e) { error_log('[zestcrm] alerts tick: ' . $e->getMessage()); }

$isUpload = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');
if ($isUpload && empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) fail('File is too large for the server (max ' . upload_limit_mb() . ' MB).');
$in = $isUpload ? $_POST : (json_decode(file_get_contents('php://input'), true) ?: []);
$A = $_GET['a'] ?? '';
$ADMIN = $me['role'] === 'admin';
$MID = (int) $me['id'];
// What this person works on, set per department by admin (Team page):
// records = clients & orders, leads = expo data research, calls = telecalling, design = design jobs, ops = production jobs, none = daily report only
$MODULE = $ADMIN ? 'admin' : (setting('dept_modules', default_dept_modules())[$me['department']] ?? 'none');
$MODS = $ADMIN ? [] : user_mods($me);   // main department + extra departments
$RECORDS = $ADMIN || in_array('records', $MODS, true);
$ACC = $ADMIN || in_array('accounts', $MODS, true);
$RECORD_ACTIONS = ['clients', 'client', 'client_save', 'dup', 'dnq', 'contact_add', 'contact_del', 'project_create', 'project', 'project_save',
  'child_add', 'child_update', 'child_del', 'vq_select'];
$HANDOVER = !$RECORDS && array_intersect(['leads', 'calls'], $MODS) ? true : false;   // research / telecalling can add a new client and hand it to a sales person
if (in_array($A, $RECORD_ACTIONS, true) && !$RECORDS && !($HANDOVER && ($A === 'dup' || ($A === 'client_save' && empty($in['id']))))) fail('Your department does not have access to client records.', 403);
if ($A === 'upload' && !$RECORDS && !in_array($in['entity'] ?? '', ['ritem', 'final', 'wd', 'claim', 'voucher'], true)) fail('Your department does not have access to client records.', 403);

const UPLOAD_MAX_MB = 60;
function ini_mb($k) { $v = trim((string) ini_get($k)); if ($v === '' || $v === '0' || $v === '-1') return PHP_INT_MAX; $n = (float) $v; $u = strtolower(substr($v, -1)); return (int) floor($u === 'g' ? $n * 1024 : ($u === 'm' ? $n : ($u === 'k' ? $n / 1024 : $n / 1048576))); }
function upload_limit_mb() { return max(1, min(UPLOAD_MAX_MB, ini_mb('upload_max_filesize'), ini_mb('post_max_size') - 1)); }   // what this server really accepts

// ---------- helpers ----------
function ref_hist($r, $text) { $h = json_decode($r['history'] ?? '', true) ?: []; $h[] = ['at' => now(), 'text' => $text]; return json_encode($h, JSON_UNESCAPED_UNICODE); }
function ref_targets() {   // who a referral can go to: sales people and admins
  $mods = setting('dept_modules', default_dept_modules());
  return array_values(array_map(function ($u) { unset($u['extra_depts']); return $u; }, array_filter(all('SELECT id, name, role, department, designation, extra_depts FROM ' . T('users') . ' WHERE active = 1 ORDER BY role, name'), fn($u) => $u['role'] === 'admin' || has_mod($u, 'records'))));
}
function touch_activity() {   // marks today as a working day with work logged
  global $ADMIN, $MID; if ($ADMIN) return;
  if (!val('SELECT COUNT(*) FROM ' . T('activity') . ' WHERE user_id = ? AND adate = ?', [$MID, today()])) { try { ins('activity', ['user_id' => $MID, 'adate' => today()]); } catch (PDOException $e) { /* already marked */ } }
}
function need_admin() { global $ADMIN; if (!$ADMIN) fail('Only admin can do this.', 403); }
function s($v, $max = 2000) { $v = trim((string) ($v ?? '')); return $v === '' ? null : mb_substr($v, 0, $max); }
function d($v) { $v = trim((string) ($v ?? '')); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null; }
function n($v) { if ($v === null || $v === '') return null; if (!is_numeric($v)) fail('Please enter a valid number.'); return round((float) $v, 2); }
function client_row($id) {
  global $ADMIN, $MID;
  $c = one('SELECT * FROM ' . T('clients') . ' WHERE id = ?', [(int) $id]);
  if (!$c) fail('Client not found.', 404);
  if (!$ADMIN && (int) $c['owner_id'] !== $MID) fail('This client is handled by ' . (users_map()[$c['owner_id']] ?? 'another sales person') . '. Only they and admin can see the details.', 403);
  return $c;
}
function project_row($id) {
  global $ADMIN, $MID;
  $p = one('SELECT * FROM ' . T('projects') . ' WHERE id = ?', [(int) $id]);
  if (!$p || (!$ADMIN && (int) $p['owner_id'] !== $MID)) fail('Order not found or not yours.', 404);
  return $p;
}
function files_of($entity, $ids) {
  $ids = array_filter(array_map('intval', (array) $ids));
  if (!$ids) return [];
  $g = [];
  foreach (all('SELECT id, entity_id, orig_name, size, created_at FROM ' . T('files') . ' WHERE entity = ? AND entity_id IN (' . implode(',', $ids) . ') ORDER BY id', [$entity]) as $f) $g[$f['entity_id']][] = $f;
  return $g;
}
function visible_projects($where = '1=1', $p = []) {
  global $ADMIN, $MID;
  if (!$ADMIN) { $where .= ' AND p.owner_id = ?'; $p[] = $MID; }
  return all('SELECT p.*, c.company FROM ' . T('projects') . ' p JOIN ' . T('clients') . " c ON c.id = p.client_id WHERE $where ORDER BY COALESCE(p.start_date, p.created_at) DESC", $p);
}
function create_project($clientId, $ownerId, $in) {
  $st = s($in['space_type'] ?? null); if (!in_array($st, ['Bare space', 'Shell scheme'], true)) $st = null;
  try { expo_remember($in['expo'] ?? null, $in['location'] ?? null, $in['start_date'] ?? null, $in['end_date'] ?? null); } catch (Throwable $e) {}
  return ins('projects', ['client_id' => $clientId, 'owner_id' => (int) $ownerId, 'expo' => s($in['expo'] ?? null, 200), 'location' => s($in['location'] ?? null),
    'start_date' => d($in['start_date'] ?? null), 'area_sqm' => n($in['area_sqm'] ?? null), 'space_type' => $st, 'sides_open' => in_array((int) ($in['sides_open'] ?? 0), [1, 2, 3, 4], true) ? (int) $in['sides_open'] : null, 'stall_no' => s($in['stall_no'] ?? null, 40),
    'status' => 'enquiry', 'gst_rate' => 18, 'created_at' => now()]);
}
// shared expo list: remember an expo typed on an order so next time it can be picked
function expo_remember($name, $loc = null, $start = null, $end = null) {
  global $MID;
  $name = s($name, 200); if (!$name) return;
  $ex = one('SELECT * FROM ' . T('expos') . ' WHERE LOWER(name) = LOWER(?) ORDER BY edate DESC', [$name]);
  if (!$ex) { ins('expos', ['name' => $name, 'edate' => d($start), 'end_date' => d($end), 'city' => s($loc, 80), 'created_by' => $MID, 'created_at' => now()]); return; }
  $fill = array_filter(['edate' => $ex['edate'] ? null : d($start), 'end_date' => $ex['end_date'] ? null : d($end), 'city' => $ex['city'] ? null : s($loc, 80)]);
  if ($fill) upd('expos', $ex['id'], $fill);
}
// company profile sent to new clients (WhatsApp / email) — PDF uploaded by admin, messages editable
function profile_msgs() {
  $msg = "Hello {name} Sir - Greetings!!! It was lovely connecting with you today for the {expo}.\nI am reaching out to you regarding your participation in the {expo} at {place}.\n\nI represent Zest Exhibitions - a company based out of Noida with its branches across major cities in India like Mumbai | Chennai | Hyderabad | Bengaluru | Ahmedabad and Pune. I’m sharing the Zest Exhibitions company profile for your immediate reference. I’d be grateful if you / your team could take a few minutes to have a look at our work, capabilities and approach to exhibition stall design and fabrication.\n\nAt Zest Exhibitions, our focus is not just on building a stall, but on creating a professional, impactful brand space that attracts visitors, communicates your offering clearly and supports your business objectives at the exhibition.\n\nOnce you’ve reviewed our profile, please feel free to share your stall requirements, stall size or floor plan with us. We would be happy to prepare suitable concepts and a proposal for your consideration.\n\nAlternatively, if it is convenient, please allow us 15–20 minutes with your team to understand your requirements, expectations and budget, and we can take it forward from there.\n\nLooking forward to the opportunity to work with you at the {expo}.\n\nWarm regards,\n{me}\n{my_designation} - Zest Exhibitions\n🌐 www.zestexhibitions.com";
  $d = ['wa' => $msg, 'subj' => 'Zest Exhibitions — Company Profile | {expo}', 'body' => $msg];
  return array_merge($d, array_filter((array) setting('profile_msgs', []), 'strlen'));
}
function profile_info() {
  $f = one('SELECT id, orig_name, size, created_at FROM ' . T('files') . " WHERE entity = 'profile' ORDER BY id DESC");
  global $MID;
  require_once __DIR__ . '/lib/mailer.php'; require_once __DIR__ . '/lib/att.php'; $w = att_cfg()['wa'];
  return ['file' => $f ?: null, 'msgs' => profile_msgs(), 'mail_server' => mail_server_ready(), 'mail_ready' => mail_user_ready($MID), 'wa_gateway' => (bool) ($w['token'] ?? '')];
}
function profile_pdf() {
  $f = one('SELECT * FROM ' . T('files') . " WHERE entity = 'profile' ORDER BY id DESC");
  $path = $f ? __DIR__ . '/uploads/' . $f['stored_name'] : '';
  if (!$f || !is_file($path)) fail('Admin has not uploaded the company profile yet (Holidays & lists → Company profile).');
  $name = (preg_replace('/[^\w .()-]/u', '', pathinfo($f['orig_name'], PATHINFO_FILENAME)) ?: 'Zest-Exhibitions-Profile') . '.pdf';
  return [$name, file_get_contents($path)];
}
function expo_list() { return all('SELECT id, name, edate, end_date, city, venue FROM ' . T('expos') . ' ORDER BY edate DESC, name LIMIT 1500'); }
function users_map() { $m = []; foreach (all('SELECT id, name FROM ' . T('users')) as $u) $m[$u['id']] = $u['name']; return $m; }

// child tables that hang off a project: kind => [table, allowed fields, required fields]
$KINDS = [
  'revision' => ['revisions', ['rdate' => 'd', 'done_by' => 's', 'changes' => 's'], ['rdate', 'changes']],
  'quote' => ['quotes', ['qdate' => 'd', 'amount' => 'n', 'approved_by' => 's', 'notes' => 's'], ['qdate', 'amount']],
  'vquote' => ['vendor_quotes', ['vendor_id' => 'i', 'amount' => 'n', 'note' => 's', 'qdate' => 'd'], ['vendor_id', 'amount']],
  'extra' => ['extras', ['item' => 's', 'rate' => 'n', 'qty' => 'n', 'added_on' => 'd'], ['item', 'rate']],
  'payment' => ['client_payments', ['pdate' => 'd', 'amount' => 'n', 'mode' => 's', 'reference' => 's'], ['pdate', 'amount']],
  'vpayment' => ['vendor_payments', ['vendor_id' => 'i', 'pdate' => 'd', 'amount' => 'n', 'mode' => 's', 'reference' => 's'], ['vendor_id', 'pdate', 'amount']],
  'expense' => ['expenses', ['edate' => 'd', 'category' => 's', 'description' => 's', 'amount' => 'n'], ['edate', 'category', 'amount']],
  'loss' => ['losses', ['ldate' => 'd', 'ltype' => 's', 'note' => 's', 'amount' => 'n'], ['ldate', 'ltype', 'amount']],
];
$ADMIN_DELETE_ONLY = ['payment', 'vpayment'];
$PROJECT_FIELDS = ['expo' => 's', 'location' => 's', 'start_date' => 'd', 'end_date' => 'd', 'area_sqm' => 'n', 'space_type' => 's', 'sides_open' => 'i', 'stall_no' => 's',
  'designed_by' => 's', 'assigned_on' => 'd', 'design_approved_by' => 's', 'status' => 's', 'offered' => 'n', 'agreed' => 'n', 'disc_val' => 'n', 'disc_type' => 's', 'round_to' => 'i',
  'gst_rate' => 'n', 'gst_on' => 'n', 'approved_by' => 's', 'invoice_no' => 's', 'invoice_date' => 'd', 'rejected_by' => 's', 'reason' => 's', 'rejection_note' => 's', 'vendor_settled' => 'i'];
$CLIENT_FIELDS = ['company', 'email', 'phone', 'person', 'designation', 'location', 'city', 'gst_no', 'notes'];
function clean($type, $v) { return match ($type) { 'd' => d($v), 'n' => n($v), 'i' => ($v === null || $v === '') ? null : (int) $v, default => s($v) }; }

define('ZEST_API', 1);
require_once __DIR__ . '/lib/dup.php';
if (in_array($A, ['lexpos', 'lexpo', 'lexpo_save', 'lead_add', 'lead_bulk', 'lead_import', 'lead_heat', 'lead_assign', 'my_leads', 'lead_follow', 'lead_update', 'lead_del', 'design_jobs', 'design_save', 'assign_designer', 'assign_ops',
  'ops_jobs', 'ops_job', 'ops_save', 'jobs_board'], true)) require __DIR__ . '/lib/work_api.php';
if (str_starts_with($A, 'acc_') || str_starts_with($A, 'inv_') || str_starts_with($A, 'claim_')) require __DIR__ . '/lib/acc_api.php';
if (str_starts_with($A, 'att_')) require __DIR__ . '/lib/att_api.php';
if (str_starts_with($A, 'dev_')) require __DIR__ . '/lib/dev_api.php';
if (str_starts_with($A, 'xp_')) require __DIR__ . '/lib/expo_api.php';
if (in_array($A, ['appr_request', 'appr_cancel', 'appr_decide', 'appr_sent', 'sales_log', 'design_client_ok', 'design_source', 'quote_prefill', 'quote_save'], true)) require __DIR__ . '/lib/appr_api.php';

// ---------- actions ----------
switch ($A) {

case 'boot':
  require_once __DIR__ . '/lib/shrink.php';
  out([
    'file_cfg' => shrink_cfg() + ['limit_mb' => upload_limit_mb()],
    'me' => $me,
    'departments' => setting('departments', []),
    'weekly_off' => setting('weekly_off', [0]),
    'templates' => setting('templates', []),
    'reasons' => all('SELECT id, label FROM ' . T('reasons') . ' WHERE active = 1 ORDER BY id'),
    'vendors' => all('SELECT id, name, phone, email, city, notes FROM ' . T('vendors') . ' WHERE active = 1 ORDER BY name'),
    'expos' => expo_list(),
    'my_leads_n' => (function () use ($MID) { try { return (int) val('SELECT COUNT(*) FROM ' . T('leads') . " WHERE assigned_to = ? AND (heat IS NULL OR heat NOT IN ('no', 'cold')) AND follow IS NULL", [$MID]); } catch (Throwable $e) { return 0; } })(),
    'my_expos_n' => (function () use ($MID) { try { return (int) val('SELECT COUNT(*) FROM ' . T('expo_assign') . ' WHERE user_id = ? AND done_at IS NULL', [$MID]); } catch (Throwable $e) { return 0; } })(),
    'my_expos_all' => (function () use ($MID) { try { return (int) val('SELECT COUNT(*) FROM ' . T('expo_assign') . ' WHERE user_id = ?', [$MID]); } catch (Throwable $e) { return 0; } })(),
    'my_leads_all' => (function () use ($MID) { try { return (int) val('SELECT COUNT(*) FROM ' . T('leads') . ' WHERE assigned_to = ?', [$MID]); } catch (Throwable $e) { return 0; } })(),
    'partners' => ($ADMIN || $ACC) ? (function () { try { return all('SELECT id, name, rev_pct, exp_pct FROM ' . T('partners') . ' WHERE active = 1 ORDER BY name'); } catch (Throwable $e) { return []; } })() : [],
    'profile' => profile_info(),
    'users' => all('SELECT id, name, role, designation, department, active, phone FROM ' . T('users') . ' ORDER BY name'),
    'today' => today(),
    'records' => $RECORDS,
    'module' => $MODULE,
    'modules' => $MODS,
    'dept_modules' => setting('dept_modules', default_dept_modules()),
    'furniture_items' => setting('furniture_items', default_furniture()),
    'acc' => $ACC,
    'dev_mine' => $ADMIN ? 0 : (int) val('SELECT COUNT(*) FROM ' . T('device_issues') . ' WHERE user_id = ? AND returned_on IS NULL', [$MID]),
    'dev_ack' => $ADMIN ? (int) val('SELECT COUNT(*) FROM ' . T('device_issues') . ' WHERE ack_at IS NULL AND returned_on IS NULL') : (int) val('SELECT COUNT(*) FROM ' . T('device_issues') . ' WHERE user_id = ? AND ack_at IS NULL AND returned_on IS NULL', [$MID]),
    'att_pending' => $ADMIN ? (int) val('SELECT COUNT(*) FROM ' . T('att_requests') . " WHERE status = 'pending'") : 0,
    'att_news' => $ADMIN ? 0 : (int) val('SELECT COUNT(*) FROM ' . T('att_requests') . " WHERE user_id = ? AND status IN ('approved','rejected') AND seen = 0", [$MID]),
    'att_today' => $ADMIN ? null : (function () use ($me, $MID) { $d = today(); if (!att_tracked_users($d) || ($me['joined_on'] ?: '2000-01-01') > $d) return null; return att_status($me, $d, one('SELECT * FROM ' . T('attendance') . ' WHERE user_id = ? AND adate = ?', [$MID, $d]), att_reqs($d, $d, ['approved', 'pending'], $MID)[$MID] ?? []); })(),
    'handover' => $HANDOVER ? ref_targets() : [],
    'ref_new' => (int) val('SELECT COUNT(*) FROM ' . T('referrals') . " WHERE assigned_to = ? AND status = 'new'", [$MID]),
    'ref_open' => (int) val('SELECT COUNT(*) FROM ' . T('referrals') . " WHERE assigned_to = ? AND status IN ('new','seen')", [$MID]),
    'claims_attention' => (int) val('SELECT COUNT(*) FROM ' . T('claims') . " WHERE user_id = ? AND status IN ('on_hold','rejected')", [$MID]),
    'claims_waiting' => $ACC ? (int) val('SELECT COUNT(*) FROM ' . T('claims') . " WHERE status = 'submitted'") : 0,
    'inv_todo' => $ACC ? (int) val('SELECT COUNT(*) FROM ' . T('invoices') . " WHERE status IN ('requested','on_hold','rejected')") : 0,
    'inv_pending' => $ADMIN ? (int) val('SELECT COUNT(*) FROM ' . T('invoices') . " WHERE status = 'pending'") : 0,
    'inv_attention' => in_array('records', $MODS, true) ? (int) val('SELECT COUNT(*) FROM ' . T('invoices') . ' i LEFT JOIN ' . T('projects') . " p ON p.id = i.project_id WHERE i.status IN ('on_hold','rejected') AND (i.requested_by = ? OR p.owner_id = ?)", [$MID, $MID]) : 0,
    'pending_approvals' => $ADMIN ? (int) val('SELECT COUNT(*) FROM ' . T('approvals') . " WHERE status = 'pending'") : 0,
  ]);

// ----- HOME / DASHBOARD -----
case 'home':
  $yday = date('Y-m-d', strtotime('-1 day'));
  if (!$ADMIN) {
    if (!$RECORDS) out([
      'missing' => missing_days($me, $yday),
      'today_done' => (bool) val('SELECT COUNT(*) FROM ' . T('reports') . ' WHERE user_id = ? AND rdate = ?', [$MID, today()]),
      'today_working' => is_working_day(today()),
      'reports_month' => (int) val('SELECT COUNT(*) FROM ' . T('reports') . ' WHERE user_id = ? AND rdate >= ?', [$MID, date('Y-m-01')]),
      'entries_month' => (int) val('SELECT COUNT(*) FROM ' . T('report_items') . ' i JOIN ' . T('reports') . ' r ON r.id = i.report_id WHERE r.user_id = ? AND r.rdate >= ?', [$MID, date('Y-m-01')]),
      'today_work' => (bool) val('SELECT COUNT(*) FROM ' . T('activity') . ' WHERE user_id = ? AND adate = ?', [$MID, today()]),
      'leads_today' => (int) val('SELECT COUNT(*) FROM ' . T('leads') . ' WHERE user_id = ? AND created_at >= ?', [$MID, today() . ' 00:00:00']),
      'leads_month' => (int) val('SELECT COUNT(*) FROM ' . T('leads') . ' WHERE user_id = ? AND created_at >= ?', [$MID, date('Y-m-01') . ' 00:00:00']),
      'expos_n' => (int) val('SELECT COUNT(DISTINCT expo_id) FROM ' . T('leads') . ' WHERE user_id = ?', [$MID]),
      'design_pending' => (int) val('SELECT COUNT(*) FROM ' . T('projects') . ' WHERE designer_id = ? AND design_submitted_on IS NULL', [$MID]),
      'design_done' => (int) val('SELECT COUNT(*) FROM ' . T('projects') . ' WHERE designer_id = ? AND design_submitted_on IS NOT NULL', [$MID]),
      'ops_jobs' => (int) val('SELECT COUNT(*) FROM ' . T('projects') . " WHERE ops_id = ? AND status <> 'completed'", [$MID]),
    ]);
    $ps = visible_projects(); $b = bills_for($ps);
    $won = array_filter($ps, fn($p) => is_won($p['status']));
    foreach ($ps as &$p) $p['bill'] = $b[$p['id']]; unset($p);
    out([
      'missing' => missing_days($me, $yday),
      'today_done' => (bool) val('SELECT COUNT(*) FROM ' . T('reports') . ' WHERE user_id = ? AND rdate = ?', [$MID, today()]),
      'today_working' => is_working_day(today()),
      'clients' => (int) val('SELECT COUNT(*) FROM ' . T('clients') . ' WHERE owner_id = ?', [$MID]),
      'won' => count($won), 'won_total' => array_sum(array_map(fn($p) => $b[$p['id']]['total'], $won)),
      'open' => count(array_filter($ps, fn($p) => in_array($p['status'], ['enquiry', 'quoted']))),
      'due' => array_sum(array_map(fn($p) => $b[$p['id']]['balance'], $won)),
      'appr' => all('SELECT a.id, a.kind, a.version, a.status, a.admin_note, a.decided_at, a.project_id, p.expo, c.company FROM ' . T('approvals') . ' a JOIN ' . T('projects') . ' p ON p.id = a.project_id JOIN ' . T('clients') . " c ON c.id = p.client_id
        WHERE a.requested_by = ? AND a.sent_on IS NULL AND (a.status = 'pending' OR a.status = 'approved' OR (a.status = 'rejected' AND a.version = (SELECT MAX(b.version) FROM " . T('approvals') . ' b WHERE b.project_id = a.project_id AND b.kind = a.kind))) ORDER BY a.requested_at DESC', [$MID]),
      'projects' => array_values(array_filter($ps, fn($p) => $p['status'] !== 'completed' || $b[$p['id']]['balance'] > 0)),
    ]);
  }
  $from = d($in['from'] ?? null); $to = d($in['to'] ?? null);
  $w = '1=1'; $prm = [];
  if ($from) { $w .= ' AND COALESCE(p.start_date, DATE(p.created_at)) >= ?'; $prm[] = $from; }
  if ($to) { $w .= ' AND COALESCE(p.start_date, DATE(p.created_at)) <= ?'; $prm[] = $to; }
  $who = (int) ($in['owner_id'] ?? 0);   // one sales person's business only
  if ($who) { $w .= ' AND p.owner_id = ?'; $prm[] = $who; }
  $ps = visible_projects($w, $prm); $b = bills_for($ps);
  $won = array_values(array_filter($ps, fn($p) => is_won($p['status'])));
  $lost = array_values(array_filter($ps, fn($p) => $p['status'] === 'rejected'));
  $sumk = fn($list, $k) => array_sum(array_map(fn($p) => $b[$p['id']][$k], $list));
  // vendors
  $vend = [];
  if ($ps) {
    $in_ids = implode(',', array_map('intval', array_column($ps, 'id')));
    $vend = all('SELECT v.name, SUM(vp.amount) AS paid, COUNT(DISTINCT vp.project_id) AS jobs FROM ' . T('vendor_payments') . ' vp JOIN ' . T('vendors') . " v ON v.id = vp.vendor_id WHERE vp.project_id IN ($in_ids) GROUP BY v.name ORDER BY paid DESC");
  }
  // employees
  $emps = [];
  foreach (all('SELECT * FROM ' . T('users') . " WHERE active = 1 ORDER BY (role = 'admin') DESC, name") as $u) {   // admins too: their own orders count
    if ($who && (int) $u['id'] !== $who) continue;
    $isAdm = $u['role'] === 'admin';
    $mine = array_filter($ps, fn($p) => (int) $p['owner_id'] === (int) $u['id']);
    $mw = array_filter($mine, fn($p) => is_won($p['status']));
    $ml = array_filter($mine, fn($p) => $p['status'] === 'rejected');
    $emps[] = ['id' => $u['id'], 'name' => $u['name'], 'designation' => $u['designation'], 'department' => $isAdm ? 'Admin' : $u['department'], 'admin' => $isAdm,
      'clients' => (int) val('SELECT COUNT(*) FROM ' . T('clients') . ' WHERE owner_id = ?', [$u['id']]),
      'orders' => count($mine), 'won' => count($mw), 'business' => $sumk($mw, 'total'),
      'missed' => array_sum(array_map(fn($p) => (float) $p['offered'], $ml)), 'collected' => $sumk($mine, 'received'),
      'reports_month' => (int) val('SELECT COUNT(*) FROM ' . T('reports') . ' WHERE user_id = ? AND rdate >= ?', [$u['id'], date('Y-m-01')]),
      'missing' => $isAdm ? [] : missing_days($u, $yday)];
  }
  // customers
  $cust = [];
  foreach ($won as $p) {
    $k = $p['client_id'];
    $cust[$k] = $cust[$k] ?? ['id' => $k, 'company' => $p['company'], 'orders' => 0, 'total' => 0, 'received' => 0];
    $cust[$k]['orders']++; $cust[$k]['total'] += $b[$p['id']]['total']; $cust[$k]['received'] += $b[$p['id']]['received'];
  }
  usort($cust, fn($a, $c) => $c['total'] <=> $a['total']);
  foreach ($lost as &$p) $p['bill'] = $b[$p['id']]; unset($p);
  $recent = array_slice($ps, 0, 25); foreach ($recent as &$p) $p['bill'] = $b[$p['id']]; unset($p);
  $um = users_map(); foreach ($recent as &$p) $p['owner'] = $um[$p['owner_id']] ?? '—'; unset($p);
  // details behind each box (click a box on the dashboard)
  $det = ['orders' => [], 'pays' => [], 'vpays' => [], 'exps' => []];
  foreach ($ps as $p) { $x = $b[$p['id']]; $det['orders'][] = ['id' => (int) $p['id'], 'company' => $p['company'], 'expo' => $p['expo'], 'start_date' => $p['start_date'], 'status' => $p['status'], 'owner' => $um[$p['owner_id']] ?? '—',
    'total' => $x['total'], 'received' => $x['received'], 'balance' => $x['balance'], 'gst' => $x['gst'], 'gst_received' => $x['gst_received'], 'vendor_paid' => $x['vendor_paid'], 'expenses' => $x['expenses'], 'losses' => $x['losses'], 'profit' => $x['profit'],
    'offered' => (float) $p['offered'], 'reason' => $p['reason'], 'rejected_by' => $p['rejected_by'], 'note' => $p['rejection_note'] ?? '']; }
  if ($ps) {
    $in_ids = implode(',', array_map('intval', array_column($ps, 'id'))); $pn = array_column($det['orders'], null, 'id');
    $lab = fn($r) => ['project_id' => (int) $r['project_id'], 'company' => $pn[$r['project_id']]['company'] ?? '', 'expo' => $pn[$r['project_id']]['expo'] ?? '', 'owner' => $pn[$r['project_id']]['owner'] ?? ''];
    foreach (all('SELECT * FROM ' . T('client_payments') . " WHERE project_id IN ($in_ids) ORDER BY pdate DESC, id DESC") as $r) $det['pays'][] = $lab($r) + ['date' => $r['pdate'], 'amount' => (float) $r['amount'], 'mode' => $r['mode'], 'ref' => $r['reference']];
    foreach (all('SELECT vp.*, v.name AS vendor FROM ' . T('vendor_payments') . ' vp JOIN ' . T('vendors') . " v ON v.id = vp.vendor_id WHERE vp.project_id IN ($in_ids) ORDER BY vp.pdate DESC, vp.id DESC") as $r) $det['vpays'][] = $lab($r) + ['date' => $r['pdate'], 'amount' => (float) $r['amount'], 'vendor' => $r['vendor'], 'mode' => $r['mode']];
    foreach (all('SELECT * FROM ' . T('expenses') . " WHERE project_id IN ($in_ids)") as $r) $det['exps'][] = $lab($r) + ['date' => $r['edate'], 'amount' => (float) $r['amount'], 'kind' => 'Expense', 'what' => trim($r['category'] . ' — ' . ($r['description'] ?? ''), ' —')];
    foreach (all('SELECT * FROM ' . T('losses') . " WHERE project_id IN ($in_ids)") as $r) $det['exps'][] = $lab($r) + ['date' => $r['ldate'], 'amount' => (float) $r['amount'], 'kind' => 'Loss', 'what' => trim($r['ltype'] . ' — ' . ($r['note'] ?? ''), ' —')];
    usort($det['exps'], fn($a, $c) => strcmp($c['date'], $a['date']));
  }
  out([
    'captured' => $sumk($won, 'total'), 'captured_n' => count($won),
    'missed' => array_sum(array_map(fn($p) => (float) $p['offered'], $lost)), 'missed_n' => count($lost),
    'received' => $sumk($ps, 'received'), 'outstanding' => $sumk($won, 'balance'),
    'gst_billed' => $sumk($won, 'gst'), 'gst_received' => $sumk($ps, 'gst_received'),
    'vendor_paid' => $sumk($ps, 'vendor_paid'), 'expenses' => $sumk($ps, 'expenses'), 'losses' => $sumk($ps, 'losses'),
    'profit' => $sumk($won, 'profit'),
    'vendors' => $vend, 'employees' => $emps, 'customers' => array_slice($cust, 0, 15),
    'lost' => $lost, 'recent' => $recent, 'detail' => $det,
  ]);

// ----- CLIENT LOG (admin reports) -----
case 'client_log':
  need_admin();
  $f = ['from' => d($in['from'] ?? null), 'to' => d($in['to'] ?? null), 'status' => s($in['status'] ?? null, 12), 'user_id' => (int) ($in['user_id'] ?? 0), 'q' => s($in['q'] ?? null, 100)];
  $orders = report_orders($f);
  $cl = [];
  foreach ($orders as $o) {
    $k = $o['client_id'];
    $c = &$cl[$k];
    $c = $c ?? ['id' => $k, 'company' => $o['company'], 'city' => $o['client_city'], 'dnq' => (int) $o['dnq'], 'orders' => [], 'won' => 0, 'business' => 0, 'received' => 0, 'due' => 0, 'missed' => 0, 'profit' => 0, 'handlers' => []];
    $c['orders'][] = ['id' => $o['id'], 'expo' => $o['expo'], 'location' => $o['location'], 'start_date' => $o['start_date'], 'area_sqm' => $o['area_sqm'], 'space_type' => $o['space_type'], 'stall_no' => $o['stall_no'],
      'status' => $o['status'], 'owner' => $o['owner'], 'designed_by' => $o['designed_by'], 'fabricator' => $o['fabricator'], 'total' => $o['bill']['total'], 'received' => $o['bill']['received'],
      'balance' => $o['bill']['balance'], 'profit' => $o['bill']['profit'], 'offered' => $o['offered'], 'reason' => $o['reason']];
    $c['handlers'][$o['owner']] = 1;
    if (is_won($o['status'])) { $c['won']++; $c['business'] += $o['bill']['total']; $c['received'] += $o['bill']['received']; $c['due'] += $o['bill']['balance']; $c['profit'] += $o['bill']['profit']; }
    if ($o['status'] === 'rejected') $c['missed'] += (float) $o['offered'];
    unset($c);
  }
  // clients with no orders yet (only when not filtering by order status / dates)
  if (!$f['status'] && !$f['from'] && !$f['to']) {
    $w = '1=1'; $pp = [];
    if ($f['user_id']) { $w .= ' AND owner_id = ?'; $pp[] = $f['user_id']; }
    if ($f['q']) { $w .= ' AND company LIKE ?'; $pp[] = '%' . $f['q'] . '%'; }
    $um = users_map();
    foreach (all('SELECT id, company, city, dnq, owner_id FROM ' . T('clients') . " WHERE $w", $pp) as $r) if (!isset($cl[$r['id']]))
      $cl[$r['id']] = ['id' => $r['id'], 'company' => $r['company'], 'city' => $r['city'], 'dnq' => (int) $r['dnq'], 'orders' => [], 'won' => 0, 'business' => 0, 'received' => 0, 'due' => 0, 'missed' => 0, 'profit' => 0, 'handlers' => [$um[$r['owner_id']] ?? '—' => 1]];
  }
  foreach ($cl as &$c) $c['handlers'] = array_keys($c['handlers']); unset($c);
  usort($cl, fn($a, $b) => strcasecmp($a['company'], $b['company']));
  out(['clients' => array_values($cl), 'orders_n' => count($orders)]);

case 'client_full':
  need_admin();
  $c = client_row($in['id'] ?? 0);
  $um = users_map();
  $c['owner'] = $um[$c['owner_id']] ?? '—'; $c['created_by_name'] = $um[$c['created_by']] ?? '—';
  $c['contacts'] = all('SELECT * FROM ' . T('contacts') . ' WHERE client_id = ?', [$c['id']]);
  $c['cards'] = files_of('card', [$c['id']])[$c['id']] ?? []; $c['briefs'] = files_of('brief', [$c['id']])[$c['id']] ?? []; $c['gstcert'] = files_of('gstcert', [$c['id']])[$c['id']] ?? [];
  $orders = report_orders(['client_id' => $c['id']]);
  $vn = []; foreach (all('SELECT id, name FROM ' . T('vendors')) as $v) $vn[$v['id']] = $v['name'];
  $ev = [];
  $ev[] = ['date' => substr($c['created_at'], 0, 10), 'type' => 'Client', 'text' => 'Client added by ' . $c['created_by_name']];
  if ($c['dnq']) $ev[] = ['date' => $c['dnq_on'], 'type' => 'Do not quote', 'text' => 'Marked do-not-quote by ' . $c['dnq_by'] . ' — ' . $c['dnq_reason']];
  foreach ($orders as &$o) {
    $id = $o['id']; $tb = fn($t, $ord) => all('SELECT * FROM ' . T($t) . " WHERE project_id = ? ORDER BY $ord", [$id]);
    $o['quotes'] = $tb('quotes', 'version'); $o['revisions'] = $tb('revisions', 'rdate'); $o['vquotes'] = $tb('vendor_quotes', 'amount'); $o['extras'] = $tb('extras', 'id');
    $o['payments'] = $tb('client_payments', 'pdate'); $o['vpayments'] = $tb('vendor_payments', 'pdate'); $o['expenses'] = $tb('expenses', 'edate'); $o['losses'] = $tb('losses', 'ldate');
    foreach (['vquotes', 'vpayments'] as $k) foreach ($o[$k] as &$r) { $r['vendor'] = $vn[$r['vendor_id']] ?? '—'; } unset($r);
    $o['ops_person'] = $um[$o['ops_id']] ?? null;
    $o['files'] = ['pbrief' => files_of('pbrief', [$id])[$id] ?? [], 'floor' => files_of('floor', [$id])[$id] ?? [], 'final' => files_of('final', [$id])[$id] ?? [], 'wd' => files_of('wd', [$id])[$id] ?? [],
      'quote' => files_of('quote', array_column($o['quotes'], 'id'))];
    $X = $o['expo'];
    $ev[] = ['date' => substr($o['created_at'], 0, 10), 'type' => 'Order', 'text' => "Enquiry opened for $X, handled by {$o['owner']}"];
    foreach ($o['quotes'] as $q) $ev[] = ['date' => $q['qdate'], 'type' => 'Quote', 'text' => "$X: quote V{$q['version']} ₹" . inr_txt($q['amount']) . ($q['approved_by'] ? ", approved by {$q['approved_by']}" : ', approval pending')];
    if ($o['assigned_on']) $ev[] = ['date' => $o['assigned_on'], 'type' => 'Design', 'text' => "$X: design assigned to " . ($o['designed_by'] ?: '—')];
    foreach ($o['revisions'] as $r) $ev[] = ['date' => $r['rdate'], 'type' => 'Design', 'text' => "$X: correction by " . ($r['done_by'] ?: '—') . ' — ' . $r['changes']];
    foreach ($o['payments'] as $r) $ev[] = ['date' => $r['pdate'], 'type' => 'Payment in', 'text' => "$X: received ₹" . inr_txt($r['amount']) . " ({$r['mode']})"];
    foreach ($o['vpayments'] as $r) $ev[] = ['date' => $r['pdate'], 'type' => 'Vendor paid', 'text' => "$X: paid {$r['vendor']} ₹" . inr_txt($r['amount']) . " ({$r['mode']})"];
    foreach ($o['expenses'] as $r) $ev[] = ['date' => $r['edate'], 'type' => 'Expense', 'text' => "$X: {$r['category']} ₹" . inr_txt($r['amount']) . ($r['description'] ? " — {$r['description']}" : '')];
    foreach ($o['losses'] as $r) $ev[] = ['date' => $r['ldate'], 'type' => 'Loss', 'text' => "$X: {$r['ltype']} ₹" . inr_txt($r['amount']) . ($r['note'] ? " — {$r['note']}" : '')];
    foreach ($o['extras'] as $r) $ev[] = ['date' => $r['added_on'], 'type' => 'Extra', 'text' => "$X: {$r['item']} " . (float) $r['qty'] . ' × ₹' . inr_txt($r['rate'])];
  }
  unset($o);
  foreach ($orders as $o) foreach (all('SELECT a.*, u.name AS rb FROM ' . T('approvals') . ' a LEFT JOIN ' . T('users') . ' u ON u.id = a.requested_by WHERE a.project_id = ?', [$o['id']]) as $a) {
    $t = "{$o['expo']}: " . ($a['kind'] === 'quote' ? 'quote' : 'design') . " V{$a['version']}" . ($a['amount'] ? ' ₹' . inr_txt($a['amount']) : '');
    $ev[] = ['date' => substr($a['requested_at'], 0, 10), 'type' => 'Approval', 'text' => "$t sent for approval by {$a['rb']}"];
    if ($a['decided_at']) $ev[] = ['date' => substr($a['decided_at'], 0, 10), 'type' => $a['status'] === 'approved' ? 'Approved' : 'Rejected', 'text' => "$t {$a['status']} by {$a['decided_by']}" . ($a['admin_note'] ? " — {$a['admin_note']}" : '')];
    if ($a['sent_on']) $ev[] = ['date' => $a['sent_on'], 'type' => 'Sent to customer', 'text' => "$t sent to customer by {$a['sent_by']}"];
  }
  $c['followups'] = followups_for($c['company']);
  foreach ($c['followups'] as $fu) $ev[] = ['date' => $fu['date'], 'type' => 'Follow-up', 'text' => "{$fu['by']} ({$fu['dept']})" . ($fu['what'] ? ': ' . $fu['what'] : '')];
  usort($ev, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));
  $c['orders'] = $orders; $c['timeline'] = $ev;
  out(['client' => $c]);

// ----- CLIENTS -----
case 'clients':
  $qs = s($in['q'] ?? null, 100);
  $w = '1=1'; $p = [];
  $everyone = !$ADMIN && !empty($in['all']) && empty($in['dnq']);   // sales: every company client's name, but details only for their own
  if (!$ADMIN && !$everyone) { $w .= ' AND owner_id = ?'; $p[] = $MID; }
  elseif (!empty($in['owner_id'])) { $w .= ' AND owner_id = ?'; $p[] = (int) $in['owner_id']; }   // one person's clients
  if (!empty($in['dnq'])) $w .= ' AND dnq = 1';
  if ($qs && $everyone) { $w .= ' AND (company LIKE ? OR person LIKE ? OR city LIKE ? OR (owner_id = ? AND (email LIKE ? OR phone LIKE ?)))'; array_push($p, "%$qs%", "%$qs%", "%$qs%", $MID, "%$qs%", "%$qs%"); }
  elseif ($qs) { $w .= ' AND (company LIKE ? OR person LIKE ? OR city LIKE ? OR email LIKE ? OR phone LIKE ?)'; array_push($p, "%$qs%", "%$qs%", "%$qs%", "%$qs%", "%$qs%"); }
  // which tab: won = converted (an order won or completed), open = orders but none won yet, none = no orders (just a contact), all
  $P = T('projects'); $WON = "(SELECT COUNT(*) FROM $P p WHERE p.client_id = c.id AND p.status IN ('won','completed'))"; $ORD = "(SELECT COUNT(*) FROM $P p WHERE p.client_id = c.id)";
  $LIVE = "(SELECT COUNT(*) FROM $P p WHERE p.client_id = c.id AND p.status NOT IN ('won','completed','rejected'))";   // enquiry / quoted still open
  // lost = had orders, every one of them rejected
  $kinds = ['won' => "$WON > 0", 'open' => "$WON = 0 AND $LIVE > 0", 'lost' => "$ORD > 0 AND $WON = 0 AND $LIVE = 0", 'none' => "$ORD = 0"];
  $counts = ['all' => 0, 'won' => 0, 'open' => 0, 'lost' => 0, 'none' => 0];
  if (empty($in['dnq'])) foreach (all("SELECT CASE WHEN $WON > 0 THEN 'won' WHEN $LIVE > 0 THEN 'open' WHEN $ORD > 0 THEN 'lost' ELSE 'none' END AS k, COUNT(*) AS n FROM " . T('clients') . " c WHERE $w GROUP BY 1", $p) as $r) { $counts[$r['k']] = (int) $r['n']; $counts['all'] += (int) $r['n']; }
  $kind = $in['kind'] ?? 'all'; $kw = isset($kinds[$kind]) && empty($in['dnq']) ? " AND {$kinds[$kind]}" : '';
  $LIM = 1500;
  $cs = all('SELECT c.id, c.company, c.person, c.designation, c.city, c.email, c.phone, c.owner_id, c.dnq, c.dnq_reason, c.dnq_note, c.dnq_on, c.dnq_by FROM ' . T('clients') . " c WHERE $w$kw ORDER BY c.company LIMIT $LIM", $p);
  if ($cs) {
    $ids = implode(',', array_map('intval', array_column($cs, 'id')));
    $ps = all('SELECT * FROM ' . T('projects') . " WHERE client_id IN ($ids)"); $b = bills_for($ps);
    $agg = [];
    foreach ($ps as $pr) {
      $a = &$agg[$pr['client_id']]; $a = $a ?? ['orders' => 0, 'won' => 0, 'business' => 0, 'due' => 0];
      $a['orders']++;
      if (is_won($pr['status'])) { $a['won']++; $a['business'] += $b[$pr['id']]['total']; $a['due'] += $b[$pr['id']]['balance']; }
      unset($a);
    }
    $um = users_map();
    foreach ($cs as &$c) { $c += $agg[$c['id']] ?? ['orders' => 0, 'won' => 0, 'business' => 0, 'due' => 0]; $c['owner'] = $um[$c['owner_id']] ?? '—';
      if (!$ADMIN && (int) $c['owner_id'] !== $MID)   // someone else's client: name only
        $c = ['id' => (int) $c['id'], 'company' => $c['company'], 'person' => $c['person'], 'designation' => $c['designation'], 'city' => $c['city'], 'owner' => $c['owner'], 'owner_id' => (int) $c['owner_id'], 'dnq' => $c['dnq'], 'locked' => 1]; }
    unset($c);
  }
  out(['clients' => $cs, 'counts' => $counts, 'kind' => isset($kinds[$kind]) ? $kind : 'all', 'capped' => count($cs) >= $LIM ? $LIM : 0]);

case 'client':
  $c = client_row($in['id'] ?? 0);
  $c['contacts'] = all('SELECT * FROM ' . T('contacts') . ' WHERE client_id = ? ORDER BY id', [$c['id']]);
  $c['cards'] = files_of('card', [$c['id']])[$c['id']] ?? [];
  $c['briefs'] = files_of('brief', [$c['id']])[$c['id']] ?? [];
  $c['gstcert'] = files_of('gstcert', [$c['id']])[$c['id']] ?? [];
  $ps = all('SELECT * FROM ' . T('projects') . ' WHERE client_id = ? ORDER BY COALESCE(start_date, created_at) DESC', [$c['id']]);
  if (!$ADMIN) $ps = array_values(array_filter($ps, fn($p) => (int) $p['owner_id'] === $MID));
  $b = bills_for($ps); $um = users_map();
  foreach ($ps as &$p) { $p['bill'] = $b[$p['id']]; $p['owner'] = $um[$p['owner_id']] ?? '—'; } unset($p);
  $c['projects'] = $ps; $c['owner'] = $um[$c['owner_id']] ?? '—';
  $c['gsts'] = all('SELECT * FROM ' . T('client_gst') . ' WHERE client_id = ? ORDER BY id', [$c['id']]);
  $c['gst_state'] = gstin_state($c['gst_no']);
  out(['client' => $c]);

case 'client_gst_save':   // add / edit another GST registration or billing name of a client (owner, admin or accounts)
case 'client_gst_del':
  if ($A === 'client_gst_del') { $g = one('SELECT * FROM ' . T('client_gst') . ' WHERE id = ?', [(int) ($in['id'] ?? 0)]) ?? fail('Not found.', 404); $cid = (int) $g['client_id']; }
  else { $g = !empty($in['id']) ? (one('SELECT * FROM ' . T('client_gst') . ' WHERE id = ?', [(int) $in['id']]) ?? fail('Not found.', 404)) : null; $cid = $g ? (int) $g['client_id'] : (int) ($in['client_id'] ?? 0); }
  $cl = one('SELECT * FROM ' . T('clients') . ' WHERE id = ?', [$cid]) ?? fail('Client not found.', 404);
  if (!$ADMIN && !$ACC && (int) $cl['owner_id'] !== $MID) fail('Only the person handling this client, accounts or admin can change its GST details.', 403);
  if ($A === 'client_gst_del') { q('DELETE FROM ' . T('client_gst') . ' WHERE id = ?', [$g['id']]); out(['ok' => true]); }   // invoices already made keep their own copy
  $gstin = gstin_clean($in['gstin'] ?? ''); $name = s($in['legal_name'] ?? null, 200);
  if (!$name) fail('Enter the registered firm name.');
  if ($gstin !== '' && (strlen($gstin) !== 15 || !gstin_state($gstin))) fail('This GSTIN does not look right — it must be 15 characters and start with a state code.');
  if ($gstin !== '' && ($gstin === gstin_clean($cl['gst_no']) || val('SELECT COUNT(*) FROM ' . T('client_gst') . ' WHERE client_id = ? AND gstin = ? AND id <> ?', [$cid, $gstin, (int) ($g['id'] ?? 0)]))) fail('This client already has that GSTIN.');
  $row = ['gstin' => $gstin ?: null, 'legal_name' => $name, 'address' => s($in['address'] ?? null, 1000), 'state' => gstin_state($gstin) ?? s($in['state'] ?? null, 60)];
  if ($g) upd('client_gst', $g['id'], $row); else $g = ['id' => ins('client_gst', $row + ['client_id' => $cid, 'added_by' => $MID, 'created_at' => now()])];
  // same GSTIN on another client is allowed (it only shows a note) — e.g. before those two entries are merged
  $also = $gstin ? all('SELECT id, company FROM ' . T('clients') . ' WHERE id <> ? AND UPPER(REPLACE(gst_no, \' \', \'\')) = ? UNION SELECT c.id, c.company FROM ' . T('client_gst') . ' g JOIN ' . T('clients') . ' c ON c.id = g.client_id WHERE g.client_id <> ? AND g.gstin = ?', [$cid, $gstin, $cid, $gstin]) : [];
  out(['id' => (int) $g['id'], 'also_on' => $also]);

case 'client_save':
  $data = [];
  foreach ($CLIENT_FIELDS as $f) if (array_key_exists($f, $in)) $data[$f] = s($in[$f]);
  if (isset($data['email']) && $data['email']) $data['email'] = strtolower($data['email']);
  if (empty($in['id'])) {
    if (empty($data['company'])) fail('Enter the company name.');
    $data['owner_id'] = ($ADMIN && !empty($in['owner_id'])) ? (int) $in['owner_id'] : $MID;
    $hand = null;
    if (!$RECORDS) {   // research / telecalling: the client goes to the sales person they pick
      foreach (ref_targets() as $u) if ((int) $u['id'] === (int) ($in['owner_id'] ?? 0)) $hand = $u;
      if (!$hand) fail('Pick the sales person who should take this client.');
      $data['owner_id'] = (int) $hand['id']; unset($in['ref_id']);
    }
    $data['created_by'] = $MID; $data['created_at'] = now();
    $ex = (array) ($in['expo'] ?? []);
    $hasExpo = array_filter(array_map(fn($x) => trim((string) $x), $ex));
    if ($hasExpo && !s($ex['expo'] ?? null)) fail('Enter the expo name (or clear the expo details).');
    tx();   // all-or-nothing: no half-saved client if something fails
    $id = ins('clients', $data);
    if ($hasExpo) create_project($id, $data['owner_id'], $ex);
    if (!empty($in['ref_id'])) {   // this client came from a referral lead
      $rf = lock_row('referrals', $in['ref_id']);
      if ($rf && ((int) $rf['assigned_to'] === $MID || $ADMIN) && in_array($rf['status'], ['new', 'seen'], true))
        upd('referrals', $rf['id'], ['status' => 'converted', 'client_id' => $id, 'converted_at' => now(), 'seen_at' => $rf['seen_at'] ?: now(), 'history' => ref_hist($rf, 'Added as client by ' . $me['name'])]);
    }
    foreach ((array) ($in['contacts'] ?? []) as $ct) if (s($ct['name'] ?? null)) ins('contacts', ['client_id' => $id, 'name' => s($ct['name']), 'designation' => s($ct['designation'] ?? null), 'phone' => s($ct['phone'] ?? null), 'email' => s($ct['email'] ?? null)]);
    if ($hand) {   // tell the sales person: it shows as a new referral lead on their dashboard, already added as a client
      $who = $me['name'] . ($me['department'] ? ' (' . $me['department'] . ')' : '');
      ins('referrals', ['name' => s($data['person'] ?? null, 120) ?? $data['company'], 'phone' => s($data['phone'] ?? null, 40) ?? '—', 'company' => mb_substr($data['company'], 0, 200), 'area' => s($ex['area_sqm'] ?? null, 20) ? s($ex['area_sqm'], 20) . ' sqm' : null,
        'note' => trim(($hasExpo ? 'Expo: ' . s($ex['expo'] ?? null, 200) . '. ' : '') . (s($data['notes'] ?? null, 1500) ?? '')) ?: null, 'referred_by' => $MID, 'assigned_to' => $data['owner_id'], 'assigned_at' => now(),
        'status' => 'new', 'client_id' => $id, 'created_at' => now(), 'history' => json_encode([['at' => now(), 'text' => 'Added as a client by ' . $who . ' and handed to ' . $hand['name']]], JSON_UNESCAPED_UNICODE)]);
    }
    db()->commit();
    out(['id' => $id]);
  }
  $c = client_row($in['id']);
  if (array_key_exists('company', $data) && !$data['company']) fail('Company name cannot be empty.');
  if ($ADMIN && !empty($in['owner_id'])) {
    $data['owner_id'] = (int) $in['owner_id'];
    if (!empty($in['move_orders'])) q('UPDATE ' . T('projects') . ' SET owner_id = ? WHERE client_id = ?', [$data['owner_id'], $c['id']]);
  }
  if ((array_key_exists('city', $data) && $data['city'] !== $c['city']) || (array_key_exists('location', $data) && $data['location'] !== $c['location'])) $data += ['lat' => null, 'lng' => null, 'geo_q' => null, 'geo_prec' => null];   // address changed: find it on the map again
  upd('clients', $c['id'], $data);
  out(['ok' => true]);

case 'client_import':   // sales / admin: many clients from an Excel / CSV sheet (sent in batches by the browser); duplicates are skipped
  if (!$RECORDS) fail('Only sales and admin can import clients.', 403);
  $owner = $ADMIN && !empty($in['owner_id']) ? (int) $in['owner_id'] : $MID;
  if (!val('SELECT id FROM ' . T('users') . ' WHERE id = ? AND active = 1', [$owner])) fail('Pick who handles these clients.');
  $um = users_map(); $added = 0; $orders = 0; $skipped = [];
  $date = function ($v) {   // 10-12-2026 · 10/12/2026 · 2026-12-10 · 10 Dec 2026 · Excel date number
    $v = trim((string) $v); if ($v === '') return null;
    if (preg_match('/^\d{5}(\.\d+)?$/', $v)) return date('Y-m-d', ((int) $v - 25569) * 86400);
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m)) return checkdate($m[2], $m[3], $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
    if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})$/', $v, $m)) { $y = strlen($m[3]) === 2 ? 2000 + $m[3] : (int) $m[3]; return checkdate($m[2], $m[1], $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]) : null; }
    $t = strtotime($v); return $t ? date('Y-m-d', $t) : null;
  };
  tx();
  foreach (array_slice((array) ($in['rows'] ?? []), 0, 300) as $x) {
    $x = (array) $x; $n = (int) ($x['_row'] ?? 0);
    $co = s($x['company'] ?? null, 200); if (!$co) { $skipped[] = ['row' => $n, 'why' => 'no company name']; continue; }
    $em = strtolower(s($x['email'] ?? null, 160) ?? ''); $note = s($x['notes'] ?? null, 3000);
    if ($em && !filter_var($em, FILTER_VALIDATE_EMAIL)) { $note = trim(($note ?? '') . " Email in sheet: $em"); $em = ''; }
    $ph = s($x['phone'] ?? null, 60); $p10 = substr(preg_replace('/\D/', '', (string) $ph), -10);
    $dup = one('SELECT id, company, owner_id FROM ' . T('clients') . ' WHERE LOWER(TRIM(company)) = LOWER(?)' . ($em ? ' OR LOWER(email) = ?' : '') . (strlen($p10) === 10 ? ' OR phone LIKE ?' : ''),
      array_merge([$co], $em ? [$em] : [], strlen($p10) === 10 ? ['%' . substr($p10, -6)] : []));
    if ($dup && strlen($p10) === 10 && strcasecmp(trim($dup['company']), $co) !== 0 && (!$em || strtolower((string) val('SELECT email FROM ' . T('clients') . ' WHERE id = ?', [$dup['id']])) !== $em)
        && substr(preg_replace('/\D/', '', (string) val('SELECT phone FROM ' . T('clients') . ' WHERE id = ?', [$dup['id']])), -10) !== $p10) $dup = null;   // last-6-digit hit but not the same number
    $ex = s($x['expo'] ?? null, 200);
    if ($dup && !$ex) { $skipped[] = ['row' => $n, 'why' => "$co — already a client" . ($dup['company'] !== $co ? " ({$dup['company']})" : '') . ', handled by ' . ($um[$dup['owner_id']] ?? '—')]; continue; }
    if ($dup) {   // same client again with an expo: add that expo as another order for the client (one row per expo)
      if (!$ADMIN && (int) $dup['owner_id'] !== $MID) { $skipped[] = ['row' => $n, 'why' => "$co — client of " . ($um[$dup['owner_id']] ?? '—') . ', expo not added']; continue; }
      if (val('SELECT id FROM ' . T('projects') . ' WHERE client_id = ? AND LOWER(TRIM(expo)) = LOWER(?)', [$dup['id'], $ex])) { $skipped[] = ['row' => $n, 'why' => "$co — already has an order for $ex"]; continue; }
      $id = (int) $dup['id']; $ownerHere = (int) $dup['owner_id'];
    } else {
    $ownerHere = $owner;
    $id = ins('clients', ['company' => $co, 'person' => s($x['person'] ?? null, 120), 'designation' => s($x['designation'] ?? null, 120), 'phone' => $ph, 'email' => $em ?: null,
      'city' => s($x['city'] ?? null, 80), 'location' => s($x['location'] ?? null, 1000), 'gst_no' => strtoupper(preg_replace('/\s/', '', s($x['gst_no'] ?? null, 20) ?? '')) ?: null, 'notes' => $note ?: null,
      'owner_id' => $owner, 'created_by' => $MID, 'created_at' => now()]);
    $added++;
    }
    if ($ex) {
      $st = strtolower(trim((string) ($x['space_type'] ?? ''))); $st = str_contains($st, 'shell') ? 'Shell scheme' : (str_contains($st, 'bare') || str_contains($st, 'raw') ? 'Bare space' : null);
      create_project($id, $ownerHere, ['expo' => $ex, 'location' => s($x['expo_city'] ?? null, 200), 'start_date' => $date($x['start_date'] ?? ''), 'area_sqm' => preg_replace('/[^\d.]/', '', (string) ($x['area_sqm'] ?? '')), 'stall_no' => $x['stall_no'] ?? null, 'space_type' => $st, 'sides_open' => (int) preg_replace('/\D/', '', (string) ($x['sides_open'] ?? ''))]);
      $orders++;
    }
  }
  db()->commit();
  out(['added' => $added, 'orders' => $orders, 'skipped' => $skipped]);

case 'dup':
  $name = s($in['company'] ?? null, 200); $email = strtolower(s($in['email'] ?? null, 160) ?? ''); $ph = substr(preg_replace('/\D/', '', $in['phone'] ?? ''), -10);
  $w = []; $p = [];
  if ($name && mb_strlen($name) >= 3) {
    // match on the first meaningful word too, so "Alucraft LLP" finds "Alucraft Manufacturing"
    $w[] = 'company LIKE ?'; $p[] = '%' . $name . '%';
    $word = preg_split('/\s+/', preg_replace('/\b(pvt|private|ltd|limited|llp|inc|co|company|the|and|&)\b/i', ' ', $name), -1, PREG_SPLIT_NO_EMPTY)[0] ?? '';
    if (mb_strlen($word) >= 4) { $w[] = 'company LIKE ?'; $p[] = '%' . $word . '%'; }
  }
  if ($email) { $w[] = 'LOWER(email) = ?'; $p[] = $email; }
  if (strlen($ph) === 10) { $w[] = 'phone LIKE ?'; $p[] = '%' . substr($ph, -6); }
  if (!$w) out(['matches' => []]);
  $rows = all('SELECT id, company, phone, owner_id, dnq, dnq_reason, dnq_note, dnq_on, dnq_by FROM ' . T('clients') . ' WHERE ' . implode(' OR ', $w) . ' ORDER BY dnq DESC LIMIT 8', $p);
  $um = users_map(); $res = [];
  foreach ($rows as $r) {
    $nameHit = $name && mb_strlen($name) >= 3 && (stripos($r['company'], $name) !== false || (isset($word) && mb_strlen($word) >= 4 && stripos($r['company'], $word) !== false));
    $emailHit = $email && strtolower((string) one('SELECT email FROM ' . T('clients') . ' WHERE id = ?', [$r['id']])['email']) === $email;
    $phoneHit = strlen($ph) === 10 && substr(preg_replace('/\D/', '', $r['phone'] ?? ''), -10) === $ph;
    if (!$nameHit && !$emailHit && !$phoneHit) continue;
    $last = one('SELECT expo, status FROM ' . T('projects') . ' WHERE client_id = ? ORDER BY id DESC', [$r['id']]);
    $res[] = ['id' => $r['id'], 'company' => $r['company'], 'owner' => $um[$r['owner_id']] ?? '—', 'mine' => $ADMIN || (int) $r['owner_id'] === $MID,
      'dnq' => (int) $r['dnq'], 'dnq_reason' => $r['dnq_reason'], 'dnq_note' => $r['dnq_note'], 'dnq_on' => $r['dnq_on'], 'dnq_by' => $r['dnq_by'],
      'orders' => (int) val('SELECT COUNT(*) FROM ' . T('projects') . ' WHERE client_id = ?', [$r['id']]), 'last' => $last];
  }
  out(['matches' => $res]);

case 'client_dupes':   // admin: groups of clients that look like the same company
  need_admin();
  $rows = all('SELECT id, company, person, phone, email, gst_no, city, owner_id, dnq, created_at FROM ' . T('clients') . ' ORDER BY id');
  $norm = function ($n) { $n = strtolower(preg_replace('/[^a-z0-9 ]+/i', ' ', (string) $n));
    $n = preg_replace('/\b(pvt|private|ltd|limited|llp|inc|co|company|corp|corporation|the|m s|ms)\b/', ' ', $n); return preg_replace('/\s+/', '', $n); };
  $par = []; $find = function ($x) use (&$par, &$find) { return $par[$x] === $x ? $x : ($par[$x] = $find($par[$x])); };
  $seen = []; $W = []; $XG = []; foreach (all('SELECT client_id, gstin FROM ' . T('client_gst') . ' WHERE gstin IS NOT NULL') as $x) $XG[(int) $x['client_id']][] = strtoupper($x['gstin']);
  foreach ($rows as $r) { $id = (int) $r['id']; $par[$id] = $id;
    $keys = ['n:' . $norm($r['company'])];
    $ph = substr(preg_replace('/\D/', '', (string) $r['phone']), -10); if (strlen($ph) === 10) $keys[] = 'p:' . $ph;
    if ($r['email']) $keys[] = 'e:' . strtolower(trim($r['email']));
    if ($r['gst_no'] && strlen(trim($r['gst_no'])) >= 10) $keys[] = 'g:' . strtoupper(trim($r['gst_no']));
    foreach ($XG[$id] ?? [] as $xg) $keys[] = 'g:' . $xg;
    // remember the words of the name for the "one name inside the other" check below
    $W[$id] = array_values(array_filter(preg_split('/\s+/', trim(preg_replace('/\b(pvt|private|ltd|limited|llp|inc|co|company|corp|corporation|the|m s|ms|and|of)\b/', ' ', strtolower(preg_replace('/[^a-z0-9 ]+/i', ' ', (string) $r['company'])))))));
    foreach ($keys as $k) { if ($k === 'n:') continue; if (isset($seen[$k])) $par[$find($id)] = $find($seen[$k]); else $seen[$k] = $id; } }
  // same sales person, and every word of one name is in the other (e.g. "Alucraft Manufacturing LLP" inside "Alucraft Manufacturing and Marketing LLP")
  $bk = []; foreach ($rows as $r) { $w0 = $W[(int) $r['id']] ?? []; if (count($w0) >= 2 && strlen(implode('', $w0)) >= 8) $bk[(int) $r['owner_id'] . '|' . $w0[0]][] = (int) $r['id']; }
  foreach ($bk as $ids_) { $n_ = count($ids_); if ($n_ < 2 || $n_ > 200) continue;
    for ($i = 0; $i < $n_; $i++) for ($j = $i + 1; $j < $n_; $j++) { $a = $W[$ids_[$i]]; $b = $W[$ids_[$j]]; [$s1, $l1] = count($a) <= count($b) ? [$a, $b] : [$b, $a];
      if ($s1 !== $l1 && !array_diff($s1, $l1)) $par[$find($ids_[$i])] = $find($ids_[$j]); } }
  $g = []; foreach ($rows as $r) $g[$find((int) $r['id'])][] = $r;
  $g = array_values(array_filter($g, fn($x) => count($x) > 1));
  $ids = array_merge([], ...array_map(fn($x) => array_column($x, 'id'), $g)); $st = [];
  if ($ids) { $in_c = implode(',', array_map('intval', $ids));
    foreach (all('SELECT client_id, COUNT(*) AS n, SUM(CASE WHEN status IN (\'won\',\'completed\') THEN 1 ELSE 0 END) AS w FROM ' . T('projects') . " WHERE client_id IN ($in_c) GROUP BY client_id") as $x) $st[(int) $x['client_id']] = ['orders' => (int) $x['n'], 'won' => (int) $x['w']];
    foreach (all('SELECT p.client_id, SUM(cp.amount) AS v FROM ' . T('client_payments') . ' cp JOIN ' . T('projects') . " p ON p.id = cp.project_id WHERE p.client_id IN ($in_c) GROUP BY p.client_id") as $x) $st[(int) $x['client_id']]['received'] = (float) $x['v'];
    foreach (all('SELECT client_id, COUNT(*) AS n FROM ' . T('contacts') . " WHERE client_id IN ($in_c) GROUP BY client_id") as $x) $st[(int) $x['client_id']]['contacts'] = (int) $x['n']; }
  $um = users_map();
  out(['groups' => array_map(fn($x) => array_map(fn($r) => $r + ['owner' => $um[$r['owner_id']] ?? '—', 'orders' => $st[$r['id']]['orders'] ?? 0, 'won' => $st[$r['id']]['won'] ?? 0, 'received' => $st[$r['id']]['received'] ?? 0, 'contacts' => $st[$r['id']]['contacts'] ?? 0], $x), $g)]);

case 'client_merge':   // admin: move everything of a duplicate client into the one to keep, then remove the duplicate
  need_admin();
  $keep = client_row($in['keep'] ?? 0); $dup = client_row($in['dup'] ?? 0);
  if ((int) $keep['id'] === (int) $dup['id']) fail('Pick two different clients.');
  require_once __DIR__ . '/lib/acc.php';
  tx();
  // the duplicate's name / GSTIN stays as another billing name of the kept client when it is a different registration or firm name
  // (a matching GSTIN never blocks a merge — it simply means it is the same registration)
  $kg = gstin_clean($keep['gst_no']); $dg = gstin_clean($dup['gst_no']);
  $known = array_map('strtoupper', array_column(all('SELECT gstin FROM ' . T('client_gst') . ' WHERE client_id = ? AND gstin IS NOT NULL', [$keep['id']]), 'gstin'));
  $asBilling = isset($in['billing']) ? !empty($in['billing']) : ($dg !== '' && $kg !== '' && $dg !== $kg);
  if ($asBilling && !($dg !== '' && ($dg === $kg || in_array($dg, $known, true))) && !($dg === '' && strcasecmp(trim($dup['company']), trim($keep['company'])) === 0))
    ins('client_gst', ['client_id' => $keep['id'], 'gstin' => $dg ?: null, 'legal_name' => $dup['company'], 'address' => $dup['location'] ?: $dup['city'], 'state' => gstin_state($dg), 'added_by' => $MID, 'created_at' => now()]);
  foreach (all('SELECT * FROM ' . T('client_gst') . ' WHERE client_id = ?', [$dup['id']]) as $xg) {   // the duplicate's own extra GSTINs come along
    if ($xg['gstin'] && (strtoupper($xg['gstin']) === $kg || in_array(strtoupper($xg['gstin']), $known, true))) q('DELETE FROM ' . T('client_gst') . ' WHERE id = ?', [$xg['id']]);
    else upd('client_gst', $xg['id'], ['client_id' => $keep['id']]); }
  $fill = []; foreach (['email', 'phone', 'person', 'designation', 'location', 'city', 'gst_no'] as $f) if (trim((string) $keep[$f]) === '' && trim((string) $dup[$f]) !== '') $fill[$f] = $dup[$f];
  if (isset($fill['gst_no']) && $asBilling) unset($fill['gst_no']);   // kept as its own billing entry instead
  if (trim((string) $dup['notes']) !== '') $fill['notes'] = trim(trim((string) $keep['notes']) . "\n" . $dup['notes']);
  if ((int) $dup['dnq'] && !(int) $keep['dnq']) foreach (['dnq', 'dnq_reason', 'dnq_note', 'dnq_on', 'dnq_by'] as $f) $fill[$f] = $dup[$f];
  if ($fill) upd('clients', $keep['id'], $fill);
  // the duplicate's main person becomes a contact of the kept client (if different)
  if (trim((string) $dup['person']) !== '' && strcasecmp(trim($dup['person']), trim((string) ($fill['person'] ?? $keep['person']))) !== 0)
    ins('contacts', ['client_id' => $keep['id'], 'name' => $dup['person'], 'designation' => $dup['designation'], 'phone' => $dup['phone'], 'email' => $dup['email']]);
  $moved = ['orders' => (int) val('SELECT COUNT(*) FROM ' . T('projects') . ' WHERE client_id = ?', [$dup['id']])];
  q('UPDATE ' . T('projects') . ' SET client_id = ? WHERE client_id = ?', [$keep['id'], $dup['id']]);
  // contacts: move, skipping ones the kept client already has (same name + phone)
  $have = []; foreach (all('SELECT name, phone FROM ' . T('contacts') . ' WHERE client_id = ?', [$keep['id']]) as $ct) $have[strtolower(trim($ct['name'])) . '|' . preg_replace('/\D/', '', (string) $ct['phone'])] = 1;
  foreach (all('SELECT * FROM ' . T('contacts') . ' WHERE client_id = ?', [$dup['id']]) as $ct) {
    $k = strtolower(trim($ct['name'])) . '|' . preg_replace('/\D/', '', (string) $ct['phone']);
    if (isset($have[$k])) q('DELETE FROM ' . T('contacts') . ' WHERE id = ?', [$ct['id']]); else { upd('contacts', $ct['id'], ['client_id' => $keep['id']]); $have[$k] = 1; } }
  // invoices move but keep the name & GSTIN they were made out to; link them to the matching billing entry of the kept client
  try { foreach (all('SELECT id, bill_name, bill_gstin FROM ' . T('invoices') . ' WHERE client_id = ?', [$dup['id']]) as $iv) {
      $gid = $iv['bill_gstin'] ? val('SELECT id FROM ' . T('client_gst') . ' WHERE client_id = ? AND gstin = ?', [$keep['id'], strtoupper($iv['bill_gstin'])]) : val('SELECT id FROM ' . T('client_gst') . ' WHERE client_id = ? AND legal_name = ?', [$keep['id'], $iv['bill_name']]);
      upd('invoices', $iv['id'], ['client_id' => $keep['id'], 'bill_gst_id' => $gid ?: null]); } } catch (PDOException $e) {}
  foreach (['referrals'] as $tb) { try { q('UPDATE ' . T($tb) . ' SET client_id = ? WHERE client_id = ?', [$keep['id'], $dup['id']]); } catch (PDOException $e) {} }
  q('UPDATE ' . T('files') . " SET entity_id = ? WHERE entity IN ('card','brief','gstcert') AND entity_id = ?", [$keep['id'], $dup['id']]);
  // books: the duplicate's ledger joins the kept client's ledger
  try {
    $da = val('SELECT id FROM ' . T('accounts') . " WHERE party_type = 'client' AND party_id = ?", [$dup['id']]);
    if ($da) {
      $ka = val('SELECT id FROM ' . T('accounts') . " WHERE party_type = 'client' AND party_id = ?", [$keep['id']]);
      if (!$ka) upd('accounts', $da, ['party_id' => $keep['id'], 'name' => 'Client: ' . $keep['company']]);
      else { q('UPDATE ' . T('jlines') . ' SET account_id = ? WHERE account_id = ?', [$ka, $da]);
        $op = (float) val('SELECT opening FROM ' . T('accounts') . ' WHERE id = ?', [$da]); if ($op) q('UPDATE ' . T('accounts') . ' SET opening = opening + ? WHERE id = ?', [$op, $ka]);
        q('DELETE FROM ' . T('accounts') . ' WHERE id = ?', [$da]); }
    }
  } catch (PDOException $e) { db()->rollBack(); fail('Could not join the two ledgers: ' . $e->getMessage()); }
  q('DELETE FROM ' . T('clients') . ' WHERE id = ?', [$dup['id']]);
  db()->commit();
  out(['ok' => true, 'keep' => (int) $keep['id'], 'moved' => $moved]);

case 'client_del':  // admin only: removes a client with all its orders, payments and files
  need_admin();
  $c = client_row($in['id'] ?? 0);
  $pids = array_map('intval', array_column(all('SELECT id FROM ' . T('projects') . ' WHERE client_id = ?', [$c['id']]), 'id'));
  $inv = 0; try { $inv = (int) val('SELECT COUNT(*) FROM ' . T('invoices') . ' WHERE client_id = ?', [$c['id']]); } catch (PDOException $e) {}
  $jl = 0; try { $jl = (int) val('SELECT COUNT(*) FROM ' . T('jlines') . ' l JOIN ' . T('accounts') . " a ON a.id = l.account_id WHERE a.party_type = 'client' AND a.party_id = ?", [$c['id']]); } catch (PDOException $e) {}
  if ($inv || $jl) fail('This client has invoices or entries in the books, so it cannot be deleted. If it is a duplicate, use “Merge duplicate” instead — everything moves to the other entry.');
  $files = all('SELECT * FROM ' . T('files') . " WHERE entity IN ('card','brief','gstcert') AND entity_id = ?", [$c['id']]);
  tx();
  if ($pids) {
    $in_p = implode(',', $pids);
    foreach (['revision' => 'revisions', 'quote' => 'quotes', 'vquote' => 'vendor_quotes', 'appr' => 'approvals'] as $ent => $tbl) {
      $ids = array_map('intval', array_column(all('SELECT id FROM ' . T($tbl) . " WHERE project_id IN ($in_p)"), 'id'));
      if ($ids) $files = array_merge($files, all('SELECT * FROM ' . T('files') . ' WHERE entity = ? AND entity_id IN (' . implode(',', $ids) . ')', [$ent]));
    }
    $files = array_merge($files, all('SELECT * FROM ' . T('files') . " WHERE entity IN ('floor','final','wd','pbrief') AND entity_id IN ($in_p)"));
    foreach (['approvals', 'revisions', 'quotes', 'vendor_quotes', 'extras', 'client_payments', 'vendor_payments', 'expenses', 'losses'] as $tbl) q('DELETE FROM ' . T($tbl) . " WHERE project_id IN ($in_p)");
    q('DELETE FROM ' . T('projects') . " WHERE id IN ($in_p)");
  }
  q('DELETE FROM ' . T('contacts') . ' WHERE client_id = ?', [$c['id']]);
  foreach ($files as $f) q('DELETE FROM ' . T('files') . ' WHERE id = ?', [$f['id']]);
  q('DELETE FROM ' . T('clients') . ' WHERE id = ?', [$c['id']]);
  db()->commit();
  foreach ($files as $f) if (!val('SELECT COUNT(*) FROM ' . T('files') . ' WHERE stored_name = ?', [$f['stored_name']])) @unlink(__DIR__ . '/uploads/' . $f['stored_name']);
  out(['ok' => true]);

case 'dnq':  // strategic call: admin only (sales see the flag but cannot set or remove it)
  need_admin();
  $c = client_row($in['id'] ?? 0);
  if (!empty($in['on'])) {
    if (!s($in['reason'] ?? null)) fail('Pick a reason.');
    upd('clients', $c['id'], ['dnq' => 1, 'dnq_reason' => s($in['reason']), 'dnq_note' => s($in['note'] ?? null), 'dnq_on' => today(), 'dnq_by' => $me['name']]);
  } else { upd('clients', $c['id'], ['dnq' => 0, 'dnq_reason' => null, 'dnq_note' => null, 'dnq_on' => null, 'dnq_by' => null]); }
  out(['ok' => true]);

case 'contact_add':
  $c = client_row($in['client_id'] ?? 0);
  if (!s($in['name'] ?? null)) fail('Enter the name.');
  ins('contacts', ['client_id' => $c['id'], 'name' => s($in['name']), 'designation' => s($in['designation'] ?? null), 'phone' => s($in['phone'] ?? null), 'email' => s($in['email'] ?? null)]);
  out(['ok' => true]);

case 'contact_del':
  $ct = one('SELECT * FROM ' . T('contacts') . ' WHERE id = ?', [(int) ($in['id'] ?? 0)]);
  if (!$ct) fail('Not found', 404);
  client_row($ct['client_id']);
  q('DELETE FROM ' . T('contacts') . ' WHERE id = ?', [$ct['id']]);
  out(['ok' => true]);

// ----- PROJECTS (expo orders) -----
case 'project_create':
  $c = client_row($in['client_id'] ?? 0);
  if (!s($in['expo'] ?? null)) fail('Enter the expo name.');
  out(['id' => create_project($c['id'], (int) $c['owner_id'], $in)]);

case 'project':
  $p = project_row($in['id'] ?? 0);
  $id = $p['id']; $tb = fn($t, $o = 'id') => all('SELECT * FROM ' . T($t) . " WHERE project_id = ? ORDER BY $o", [$id]);
  $vn = []; foreach (all('SELECT id, name FROM ' . T('vendors')) as $v) $vn[$v['id']] = $v['name'];
  $p['revisions'] = $tb('revisions', 'rdate, id');
  $p['quotes'] = $tb('quotes', 'version');
  $p['vquotes'] = $tb('vendor_quotes', 'amount');
  $p['extras'] = $tb('extras');
  $p['payments'] = $tb('client_payments', 'pdate, id');
  $p['vpayments'] = $tb('vendor_payments', 'pdate, id');
  $p['expenses'] = $tb('expenses', 'edate, id');
  $p['losses'] = $tb('losses', 'ldate, id');
  foreach (['vquotes', 'vpayments'] as $k) foreach ($p[$k] as &$r) { $r['vendor'] = $vn[$r['vendor_id']] ?? '—'; } unset($r);
  $p['files'] = [
    'floor' => files_of('floor', [$id])[$id] ?? [], 'pbrief' => files_of('pbrief', [$id])[$id] ?? [], 'final' => files_of('final', [$id])[$id] ?? [], 'wd' => files_of('wd', [$id])[$id] ?? [],
    'quote' => files_of('quote', array_column($p['quotes'], 'id')), 'vquote' => files_of('vquote', array_column($p['vquotes'], 'id')),
    'revision' => files_of('revision', array_column($p['revisions'], 'id')),
  ];
  $p['bill'] = project_bill($p, $p['extras'], $p['payments'], $p['vpayments'], $p['expenses'], $p['losses']);
  $um = users_map();
  $p['designer'] = $um[$p['designer_id']] ?? null; $p['ops_person'] = $um[$p['ops_id']] ?? null;
  $p['ops'] = json_decode($p['ops_json'] ?? '', true) ?: null; unset($p['ops_json']);
  $p['vq_agreed'] = (float) val('SELECT COALESCE(SUM(amount),0) FROM ' . T('vendor_quotes') . ' WHERE project_id = ? AND selected = 1', [$id]);
  $p['chosen_vendor'] = one('SELECT v.name, q.amount FROM ' . T('vendor_quotes') . ' q JOIN ' . T('vendors') . ' v ON v.id = q.vendor_id WHERE q.project_id = ? AND q.selected = 1', [$id]);
  $p['approvals'] = all('SELECT a.*, u.name AS requested_by_name FROM ' . T('approvals') . ' a LEFT JOIN ' . T('users') . ' u ON u.id = a.requested_by WHERE a.project_id = ? ORDER BY a.kind, a.version', [$id]);
  $p['approvals'] = array_values(array_filter($p['approvals'], fn($a) => $a['status'] !== 'draft' || $ADMIN || (int) $a['requested_by'] === $MID));   // drafts: only their maker
  $af = files_of('appr', array_column($p['approvals'], 'id')); foreach ($p['approvals'] as &$ap) { $ap['files'] = $af[$ap['id']] ?? []; $ap['has_doc'] = $ap['doc'] ? 1 : 0; unset($ap['doc']); } unset($ap);
  $p['history'] = array_map(fn($h) => $h + ['by' => $um[$h['user_id']] ?? '—'], all('SELECT * FROM ' . T('audit') . ' WHERE project_id = ? ORDER BY id DESC LIMIT 50', [$id]));
  $c = one('SELECT id, company, dnq FROM ' . T('clients') . ' WHERE id = ?', [$p['client_id']]);
  $p['client'] = $c; $p['owner'] = users_map()[$p['owner_id']] ?? '—';
  out(['project' => $p]);

case 'project_save':
  $p = project_row($in['id'] ?? 0);
  $data = [];
  foreach ((array) ($in['fields'] ?? []) as $k => $v) if (isset($PROJECT_FIELDS[$k])) $data[$k] = clean($PROJECT_FIELDS[$k], $v);
  if (array_key_exists('expo', $data) && !$data['expo']) fail('Expo name cannot be empty.');
  if (isset($data['status']) && !in_array($data['status'], ['enquiry', 'quoted', 'won', 'rejected', 'completed'], true)) fail('Unknown status.');
  if (isset($data['rejected_by']) && !in_array($data['rejected_by'], ['us', 'client'], true)) $data['rejected_by'] = null;
  if (array_key_exists('disc_type', $data) && !in_array($data['disc_type'], ['rs', 'pct'], true)) $data['disc_type'] = 'rs';
  if (array_key_exists('round_to', $data) && !in_array($data['round_to'], [0, 1, 10, 100, 1000], true)) fail('Round off can be ₹1, ₹10, ₹100, ₹1,000 or off.');
  if (array_intersect(['disc_val', 'disc_type', 'agreed'], array_keys($data))) { $np = $data + $p;
    if ((float) ($np['disc_val'] ?? 0) < 0) fail('Discount cannot be negative.');
    if (($np['disc_type'] ?? '') === 'pct' && (float) $np['disc_val'] >= 100) fail('Discount must be less than 100%.');
    if (($np['disc_type'] ?? '') !== 'pct' && (float) ($np['disc_val'] ?? 0) > 0 && (float) $np['disc_val'] >= (float) ($np['agreed'] ?? 0)) fail('Discount must be less than the agreed price.'); }
  if ($ADMIN && !empty($in['fields']['owner_id'])) $data['owner_id'] = (int) $in['fields']['owner_id'];
  if (isset($data['status']) && is_won($data['status']) && !is_won($p['status']) && empty($p['won_on'])) $data['won_on'] = today();   // for the daily sales alert
  try { upd('projects', $p['id'], $data); }
  catch (PDOException $e) {   // older database without the won_on column: add it and try again
    if (!isset($data['won_on'])) throw $e;
    try { db()->exec('ALTER TABLE ' . T('projects') . ' ADD COLUMN won_on DATE'); } catch (PDOException $e2) {}
    try { upd('projects', $p['id'], $data); } catch (PDOException $e3) { unset($data['won_on']); upd('projects', $p['id'], $data); }
  }
  if (array_intersect(['expo', 'location', 'start_date', 'end_date'], array_keys($data))) { $np = $data + $p; try { expo_remember($np['expo'], $np['location'], $np['start_date'], $np['end_date']); } catch (Throwable $e) {} }
  out(['ok' => true]);

case 'child_add':
  $p = project_row($in['project_id'] ?? 0);
  $k = $in['kind'] ?? ''; if (!isset($KINDS[$k])) fail('Unknown entry type.');
  [$table, $fields, $req] = $KINDS[$k];
  $row = ['project_id' => $p['id']];
  foreach ($fields as $f => $type) $row[$f] = clean($type, $in['data'][$f] ?? null);
  foreach ($req as $f) if ($row[$f] === null || $row[$f] === '') fail('Please fill: ' . str_replace('_', ' ', $f));
  if ($k === 'quote') $row['version'] = (int) val('SELECT COALESCE(MAX(version),0)+1 FROM ' . T('quotes') . ' WHERE project_id = ?', [$p['id']]);
  if ($k === 'extra' && !$row['qty']) $row['qty'] = 1;
  if ($k === 'extra' && !$row['added_on']) $row['added_on'] = today();
  if ($k === 'vquote' && !$row['qdate']) $row['qdate'] = today();
  if (in_array($k, ['payment', 'vpayment']) && !$row['mode']) $row['mode'] = 'Bank';
  if ($k === 'expense') { dup_guard($p['id'], [$row['amount']]); $row['created_by'] = $MID; }
  $id = ins($table, $row);
  if ($k === 'quote') { // latest quote = offered price; enquiry becomes quoted
    $upd = ['offered' => $row['amount']]; if ($p['status'] === 'enquiry') $upd['status'] = 'quoted';
    upd('projects', $p['id'], $upd);
  }
  out(['id' => $id]);

case 'child_update':  // correct a mistake in any entry; money changes are logged
  $k = $in['kind'] ?? ''; if (!isset($KINDS[$k])) fail('Unknown entry type.');
  [$table, $fields, $req] = $KINDS[$k];
  $r = one('SELECT * FROM ' . T($table) . ' WHERE id = ?', [(int) ($in['id'] ?? 0)]); if (!$r) fail('Not found', 404);
  $p = project_row($r['project_id']);
  $data = [];
  foreach ($fields as $f => $type) if (array_key_exists($f, (array) ($in['data'] ?? []))) $data[$f] = clean($type, $in['data'][$f]);
  foreach ($req as $f) if (array_key_exists($f, $data) && ($data[$f] === null || $data[$f] === '')) fail('Please fill: ' . str_replace('_', ' ', $f));
  if ($k === 'extra' && array_key_exists('qty', $data) && !$data['qty']) $data['qty'] = 1;
  $changed = array_filter($data, fn($v, $f) => (string) $v !== (string) $r[$f] && !(is_numeric($v) && is_numeric($r[$f]) && (float) $v === (float) $r[$f]), ARRAY_FILTER_USE_BOTH);
  if (!$changed) out(['ok' => true]);
  upd($table, $r['id'], $changed);
  ins('audit', ['user_id' => $MID, 'at' => now(), 'project_id' => $p['id'], 'kind' => $k, 'entry_id' => $r['id'], 'action' => 'edit',
    'before_json' => json_encode(array_intersect_key($r, $changed)), 'after_json' => json_encode($changed)]);
  if ($k === 'quote' && isset($changed['amount']) && (int) $r['version'] === (int) val('SELECT MAX(version) FROM ' . T('quotes') . ' WHERE project_id = ?', [$p['id']]))
    upd('projects', $p['id'], ['offered' => $changed['amount']]);
  out(['ok' => true]);

case 'child_del':
  $k = $in['kind'] ?? ''; if (!isset($KINDS[$k])) fail('Unknown entry type.');
  if (in_array($k, $ADMIN_DELETE_ONLY, true)) need_admin();
  $table = $KINDS[$k][0];
  $r = one('SELECT * FROM ' . T($table) . ' WHERE id = ?', [(int) ($in['id'] ?? 0)]); if (!$r) fail('Not found', 404);
  project_row($r['project_id']);
  q('DELETE FROM ' . T($table) . ' WHERE id = ?', [$r['id']]);
  ins('audit', ['user_id' => $MID, 'at' => now(), 'project_id' => $r['project_id'], 'kind' => $k, 'entry_id' => $r['id'], 'action' => 'delete', 'before_json' => json_encode($r), 'after_json' => null]);
  out(['ok' => true]);

case 'vq_select':   // only admin decides which vendor gets the job
  need_admin();
  $r = one('SELECT * FROM ' . T('vendor_quotes') . ' WHERE id = ?', [(int) ($in['id'] ?? 0)]); if (!$r) fail('Not found', 404);
  project_row($r['project_id']);
  q('UPDATE ' . T('vendor_quotes') . ' SET selected = CASE WHEN id = ? THEN 1 ELSE 0 END WHERE project_id = ?', [$r['id'], $r['project_id']]);
  out(['ok' => true]);

// ----- FILES -----
case 'upload':
  $ent = $in['entity'] ?? ''; $eid = (int) ($in['entity_id'] ?? 0);
  switch ($ent) {
    case 'card': case 'brief': case 'gstcert': client_row($eid); break;
    case 'floor': case 'pbrief': project_row($eid); break;
    case 'claim':   // bill / proof for an expense claim: owner while not yet approved
      $cl = one('SELECT user_id, status FROM ' . T('claims') . ' WHERE id = ?', [$eid]);
      if (!$cl || ((int) $cl['user_id'] !== $MID && !$ACC)) fail('Not found', 404);
      if ($cl['status'] === 'approved' && !$ACC) fail('This expense is already approved.', 403); break;
    case 'voucher':
      if (!$ACC || !val('SELECT COUNT(*) FROM ' . T('journal') . ' WHERE id = ?', [$eid])) fail('Not found', 404); break;
    case 'appr':
      $ap = one('SELECT project_id, status FROM ' . T('approvals') . ' WHERE id = ?', [$eid]); if (!$ap) fail('Not found', 404); project_row($ap['project_id']);
      if ($ap['status'] !== 'pending' && !$ADMIN) fail('This version is already decided. Send a new version instead.', 403); break;
    case 'final': case 'wd':   // sales owner, admin, or the designer assigned to this order
      $pp = one('SELECT owner_id, designer_id FROM ' . T('projects') . ' WHERE id = ?', [$eid]);
      if (!$pp || !($ADMIN || ($RECORDS && (int) $pp['owner_id'] === $MID) || (int) $pp['designer_id'] === $MID)) fail('Order not found or not yours.', 404);
      if (!$RECORDS && strtolower(pathinfo($_FILES['file']['name'] ?? '', PATHINFO_EXTENSION)) !== 'pdf') fail('Please upload the design as a PDF.');
      touch_activity(); break;
    case 'quote': case 'vquote': case 'revision':
      $tbl = ['quote' => 'quotes', 'vquote' => 'vendor_quotes', 'revision' => 'revisions'][$ent];
      $r = one('SELECT project_id FROM ' . T($tbl) . ' WHERE id = ?', [$eid]); if (!$r) fail('Not found', 404); project_row($r['project_id']); break;
    case 'profile':   // admin: company profile PDF sent to new clients
      need_admin(); $eid = 0;
      if (strtolower(pathinfo($_FILES['file']['name'] ?? '', PATHINFO_EXTENSION)) !== 'pdf') fail('Please upload the company profile as a PDF.'); break;
    case 'expo':   // exhibitor list for an expo: admin, or someone the expo is assigned to
      if (!val('SELECT COUNT(*) FROM ' . T('expos') . ' WHERE id = ?', [$eid])) fail('Expo not found.', 404);
      if (!$ADMIN && !val('SELECT COUNT(*) FROM ' . T('expo_assign') . ' WHERE expo_id = ? AND user_id = ?', [$eid, $MID])) fail('This expo is not assigned to you.', 403); break;
    case 'ritem':
      $r = one('SELECT r.user_id, r.submitted_at FROM ' . T('report_items') . ' i JOIN ' . T('reports') . ' r ON r.id = i.report_id WHERE i.id = ?', [$eid]);
      if (!$r || (int) $r['user_id'] !== $MID || strtotime($r['submitted_at']) < time() - 900) fail('This report is locked.', 403); break;
    default: fail('Unknown upload type.');
  }
  $f = $_FILES['file'] ?? null;
  if (!$f || $f['error'] !== UPLOAD_ERR_OK) fail($f && in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE]) ? 'File is too large.' : 'Upload failed. Try again.');
  if ($f['size'] > upload_limit_mb() * 1024 * 1024) fail('File is too large (max ' . upload_limit_mb() . ' MB).');
  $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
  $ok = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'gif', 'xlsx', 'xls', 'csv', 'doc', 'docx', 'ppt', 'pptx', 'dwg', 'dxf', 'skp', 'zip', 'txt'];
  if (!in_array($ext, $ok, true)) fail('This file type is not allowed.');
  $dir = __DIR__ . '/uploads/' . date('Y/m');
  if (!is_dir($dir)) mkdir($dir, 0755, true);
  $stored = date('Y/m/') . bin2hex(random_bytes(12)) . '.' . $ext;
  if (!move_uploaded_file($f['tmp_name'], __DIR__ . '/uploads/' . $stored)) fail('Could not save the file.');
  // automatic compression (photos, pictures inside PDF / Word / Excel / PowerPoint) — the original is kept if anything is unusual
  require_once __DIR__ . '/lib/shrink.php';
  $origSize = (int) ($_POST['orig_size'] ?? 0) ?: (int) $f['size']; $origName = mb_substr(basename($f['name']), 0, 250);
  $sr = $ent === 'profile' ? ['ext' => $ext, 'size' => filesize(__DIR__ . '/uploads/' . $stored)] : shrink_file(__DIR__ . '/uploads/' . $stored, $ext, $ent);   // the profile is sent to clients — keep full quality
  if ($sr['ext'] !== $ext) {   // e.g. a PNG screenshot saved as JPEG
    $ns = preg_replace('~\.\w+$~', '.' . $sr['ext'], $stored); rename(__DIR__ . '/uploads/' . $stored, __DIR__ . '/uploads/' . $ns); $stored = $ns;
    $origName = preg_replace('~\.\w+$~', '', $origName) . '.' . $sr['ext'];
  }
  $mime = (new finfo(FILEINFO_MIME_TYPE))->file(__DIR__ . '/uploads/' . $stored) ?: 'application/octet-stream';
  $id = ins('files', ['entity' => $ent, 'entity_id' => $eid, 'orig_name' => $origName, 'stored_name' => $stored,
    'mime' => $mime, 'size' => (int) $sr['size'], 'orig_size' => max($origSize, (int) $sr['size']), 'shrunk' => 1, 'uploaded_by' => $MID, 'created_at' => now()]);
  out(['id' => $id, 'name' => $origName, 'size' => (int) $sr['size'], 'orig_size' => max($origSize, (int) $sr['size'])]);

case 'file_del':
  $f = one('SELECT * FROM ' . T('files') . ' WHERE id = ?', [(int) ($in['id'] ?? 0)]); if (!$f) fail('Not found', 404);
  if ($f['entity'] === 'ritem') fail('Report files cannot be removed.', 403);
  if (!$ADMIN && !((int) $f['uploaded_by'] === $MID && substr($f['created_at'], 0, 10) === today())) fail('Only admin can remove older files.', 403);
  if ($f['entity'] === 'appr' && !$ADMIN && val('SELECT status FROM ' . T('approvals') . ' WHERE id = ?', [$f['entity_id']]) !== 'pending') fail('This version is already decided. Its files are kept for the record.', 403);
  q('DELETE FROM ' . T('files') . ' WHERE id = ?', [$f['id']]);
  if (!val('SELECT COUNT(*) FROM ' . T('files') . ' WHERE stored_name = ?', [$f['stored_name']])) @unlink(__DIR__ . '/uploads/' . $f['stored_name']);
  out(['ok' => true]);

// ----- DAILY REPORTS -----
case 'report_mine':
  $tpl = setting('templates', [])[$me['department']] ?? (setting('templates', [])['Operations'] ?? []);
  $rs = all('SELECT * FROM ' . T('reports') . ' WHERE user_id = ? ORDER BY rdate DESC LIMIT 30', [$MID]);
  $items = []; if ($rs) foreach (all('SELECT * FROM ' . T('report_items') . ' WHERE report_id IN (' . implode(',', array_map('intval', array_column($rs, 'id'))) . ') ORDER BY sl') as $it) $items[$it['report_id']][] = $it;
  $allItems = array_merge([], ...array_values($items)); $fl = files_of('ritem', array_column($allItems, 'id'));
  foreach ($rs as &$r) { $r['fields'] = json_decode($r['fields_json'], true); unset($r['fields_json']);
    $r['items'] = array_map(fn($it) => json_decode($it['data_json'], true) + ['_files' => $fl[$it['id']] ?? []], $items[$r['id']] ?? []); } unset($r);
  out(['template' => $tpl, 'reports' => $rs, 'missing' => missing_days($me, date('Y-m-d', strtotime('-1 day'))), 'today' => today(), 'today_working' => is_working_day(today())]);

case 'report_submit':
  if (val('SELECT COUNT(*) FROM ' . T('reports') . ' WHERE user_id = ? AND rdate = ?', [$MID, today()])) fail("Today's report is already submitted.");
  $tpl = setting('templates', [])[$me['department']] ?? (setting('templates', [])['Operations'] ?? []);
  $items = (array) ($in['items'] ?? []);
  if (!$items) fail('Add at least one entry.');
  if (count($items) > 200) fail('Too many entries.');
  $first = $tpl[0]['k'] ?? 'company';
  $clean = [];
  foreach ($items as $i => $it) {
    $row = [];
    foreach ($tpl as $f) { if ($f['t'] === 'file') continue; $row[$f['k']] = $f['t'] === 'date' ? d($it[$f['k']] ?? null) : s($it[$f['k']] ?? null, 4000); }
    if (!$row[$first]) fail('Entry ' . ($i + 1) . ': please fill ' . ($tpl[0]['l'] ?? 'the first field') . '.');
    $clean[] = $row;
  }
  tx();
  try { $rid = ins('reports', ['user_id' => $MID, 'rdate' => today(), 'dept' => $me['department'], 'fields_json' => json_encode($tpl), 'submitted_at' => now()]); }
  catch (PDOException $e) { db()->rollBack(); fail("Today's report is already submitted."); }
  $ids = [];
  foreach ($clean as $i => $row) $ids[] = ins('report_items', ['report_id' => $rid, 'sl' => $i + 1, 'data_json' => json_encode($row, JSON_UNESCAPED_UNICODE)]);
  db()->commit();
  out(['id' => $rid, 'item_ids' => $ids]);

case 'report_log':
  need_admin();
  $to = d($in['to'] ?? null) ?? today(); $from = d($in['from'] ?? null) ?? date('Y-m-d', strtotime($to . ' -13 days'));
  if ($from > $to) [$from, $to] = [$to, $from];
  if ((strtotime($to) - strtotime($from)) / 86400 > 92) $from = date('Y-m-d', strtotime($to . ' -92 days'));
  $uw = "active = 1 AND role = 'employee'"; $up = [];
  if (!empty($in['user_id'])) { $uw .= ' AND id = ?'; $up[] = (int) $in['user_id']; }
  $users = all('SELECT id, name, department, designation, joined_on FROM ' . T('users') . " WHERE $uw ORDER BY name", $up);
  $rs = all('SELECT * FROM ' . T('reports') . ' WHERE rdate BETWEEN ? AND ?', [$from, $to]);
  $items = []; if ($rs) foreach (all('SELECT * FROM ' . T('report_items') . ' WHERE report_id IN (' . implode(',', array_map('intval', array_column($rs, 'id'))) . ') ORDER BY sl') as $it) $items[$it['report_id']][] = $it;
  $allItems = $items ? array_merge(...array_values($items)) : []; $fl = files_of('ritem', array_column($allItems, 'id'));
  $byKey = []; foreach ($rs as $r) $byKey[$r['user_id'] . '|' . $r['rdate']] = $r;
  $off = setting('weekly_off', [0]); $hol = []; foreach (all('SELECT hdate, reason FROM ' . T('holidays') . ' WHERE hdate BETWEEN ? AND ?', [$from, $to]) as $h) $hol[$h['hdate']] = $h['reason'];
  $track = setting('track_from', today());
  $act = []; foreach (all('SELECT user_id, adate FROM ' . T('activity') . ' WHERE adate BETWEEN ? AND ?', [$from, $to]) as $a) $act[$a['user_id'] . '|' . $a['adate']] = 1;
  $leadN = []; foreach (all('SELECT user_id, created_at FROM ' . T('leads') . ' WHERE created_at BETWEEN ? AND ?', ["$from 00:00:00", "$to 23:59:59"]) as $l) { $k = $l['user_id'] . '|' . substr($l['created_at'], 0, 10); $leadN[$k] = ($leadN[$k] ?? 0) + 1; }
  $lvd = []; foreach (all('SELECT user_id, from_date, to_date, leave_type FROM ' . T('att_requests') . " WHERE kind = 'leave' AND status = 'approved' AND (half IS NULL OR half = '') AND to_date >= ? AND from_date <= ?", [$from, $to]) as $lv)
    for ($x = $lv['from_date']; $x <= $lv['to_date']; $x = date('Y-m-d', strtotime($x . ' +1 day'))) $lvd[$lv['user_id'] . '|' . $x] = ucfirst($lv['leave_type']) . ' leave';
  $days = [];
  for ($dd = $to; $dd >= $from; $dd = date('Y-m-d', strtotime($dd . ' -1 day'))) {
    $isOff = in_array((int) date('w', strtotime($dd)), $off, true);
    $day = ['date' => $dd, 'off' => $isOff ? 'Week off' : ($hol[$dd] ?? null), 'rows' => []];
    foreach ($users as $u) {
      $r = $byKey[$u['id'] . '|' . $dd] ?? null;
      if ($r) {
        $day['rows'][] = ['user' => $u, 'status' => 'received', 'id' => (int) $r['id'], 'at' => substr($r['submitted_at'], 11, 5), 'fields' => json_decode($r['fields_json'], true),
          'items' => array_map(fn($it) => json_decode($it['data_json'], true) + ['_id' => (int) $it['id'], '_files' => $fl[$it['id']] ?? []], $items[$r['id']] ?? [])];
      } elseif (isset($act[$u['id'] . '|' . $dd])) {
        $day['rows'][] = ['user' => $u, 'status' => 'work', 'leads' => $leadN[$u['id'] . '|' . $dd] ?? 0];
      } elseif (!$day['off'] && isset($lvd[$u['id'] . '|' . $dd])) {
        $day['rows'][] = ['user' => $u, 'status' => 'leave', 'leave' => $lvd[$u['id'] . '|' . $dd]];
      } elseif (!$day['off'] && $dd >= $u['joined_on'] && $dd >= $track) {
        $day['rows'][] = ['user' => $u, 'status' => $dd === today() ? 'pending' : 'missing'];
      }
    }
    $days[] = $day;
  }
  out(['from' => $from, 'to' => $to, 'days' => $days]);

case 'report_admin_save':   // admin: correct a submitted daily report (change, add or remove entries)
  need_admin();
  $r = one('SELECT * FROM ' . T('reports') . ' WHERE id = ?', [(int) ($in['id'] ?? 0)]) ?? fail('Report not found.', 404);
  $tpl = json_decode($r['fields_json'], true) ?: []; $first = $tpl[0]['k'] ?? 'company';
  $old = all('SELECT * FROM ' . T('report_items') . ' WHERE report_id = ? ORDER BY sl', [$r['id']]); $oldById = array_column($old, null, 'id');
  $keep = []; $rows = [];
  foreach (array_slice((array) ($in['items'] ?? []), 0, 200) as $i => $it) { $it = (array) $it; $row = [];
    foreach ($tpl as $f) { if ($f['t'] === 'file') continue; $row[$f['k']] = $f['t'] === 'date' ? d($it[$f['k']] ?? null) : s($it[$f['k']] ?? null, 4000); }
    if (!array_filter($row)) continue;
    if (!$row[$first]) fail('Entry ' . ($i + 1) . ': please fill ' . ($tpl[0]['l'] ?? 'the first field') . '.');
    $rows[] = [(int) ($it['_id'] ?? 0), $row];
  }
  if (!$rows) fail('A report needs at least one entry. Use Delete report to remove it fully.');
  tx();
  foreach ($rows as $k => [$iid, $row]) {
    $js = json_encode($row, JSON_UNESCAPED_UNICODE);
    if ($iid && isset($oldById[$iid])) { $keep[] = $iid; if ($oldById[$iid]['data_json'] !== $js || (int) $oldById[$iid]['sl'] !== $k + 1) upd('report_items', $iid, ['sl' => $k + 1, 'data_json' => $js]); }
    else $keep[] = ins('report_items', ['report_id' => $r['id'], 'sl' => $k + 1, 'data_json' => $js]);
  }
  foreach ($old as $o) if (!in_array((int) $o['id'], $keep, true)) q('DELETE FROM ' . T('report_items') . ' WHERE id = ?', [$o['id']]);
  ins('audit', ['user_id' => $MID, 'at' => now(), 'project_id' => null, 'kind' => 'report', 'entry_id' => $r['id'], 'action' => 'edit',
    'before_json' => json_encode(array_map(fn($o) => json_decode($o['data_json'], true), $old), JSON_UNESCAPED_UNICODE), 'after_json' => json_encode(array_column($rows, 1), JSON_UNESCAPED_UNICODE)]);
  db()->commit(); out(['ok' => true]);

case 'report_delete':  // admin only: in case an employee submitted wrong data and must redo
  need_admin();
  $r = one('SELECT * FROM ' . T('reports') . ' WHERE id = ?', [(int) ($in['id'] ?? 0)]); if (!$r) fail('Not found', 404);
  ins('audit', ['user_id' => $MID, 'at' => now(), 'project_id' => null, 'kind' => 'report', 'entry_id' => $r['id'], 'action' => 'delete', 'before_json' => json_encode(['user_id' => $r['user_id'], 'rdate' => $r['rdate'], 'items' => array_map(fn($o) => json_decode($o['data_json'], true), all('SELECT data_json FROM ' . T('report_items') . ' WHERE report_id = ?', [$r['id']]))], JSON_UNESCAPED_UNICODE), 'after_json' => null]);
  q('DELETE FROM ' . T('report_items') . ' WHERE report_id = ?', [$r['id']]);
  q('DELETE FROM ' . T('reports') . ' WHERE id = ?', [$r['id']]);
  out(['ok' => true]);

// ----- TEAM -----
case 'users':
  need_admin();
  out(['users' => array_map(fn($u) => $u + ['extra' => json_decode($u['extra_depts'] ?? '', true) ?: []], all('SELECT id, name, email, role, designation, department, extra_depts, phone, active, joined_on, shift_start, shift_end FROM ' . T('users') . ' ORDER BY active DESC, name'))]);

case 'user_save':
  need_admin();
  $data = ['name' => s($in['name'] ?? null, 120), 'designation' => s($in['designation'] ?? null, 120), 'department' => s($in['department'] ?? null, 60), 'phone' => s($in['phone'] ?? null, 30)];
  if (array_key_exists('shift_start', $in)) { require_once __DIR__ . '/lib/att.php'; $data['shift_start'] = att_hm($in['shift_start'] ?? '') ?: null; $data['shift_end'] = att_hm($in['shift_end'] ?? '') ?: null; }
  $role = ($in['role'] ?? 'employee') === 'admin' ? 'admin' : 'employee';
  if (!$data['name']) fail('Enter the name.');
  if (!$data['department']) fail('Pick a department.');
  $data['extra_depts'] = json_encode(array_values(array_filter(array_unique((array) ($in['extra_depts'] ?? [])), fn($d) => $d !== $data['department'] && in_array($d, setting('departments', []), true))), JSON_UNESCAPED_UNICODE);
  if (empty($in['id'])) {
    $email = strtolower(s($in['email'] ?? null, 160) ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Enter a valid email.');
    if (val('SELECT COUNT(*) FROM ' . T('users') . ' WHERE email = ?', [$email])) fail('This email already has a login.');
    if (strlen($in['password'] ?? '') < 8) fail('Password must be at least 8 characters.');
    $id = ins('users', $data + ['email' => $email, 'role' => $role, 'pass_hash' => password_hash($in['password'], PASSWORD_DEFAULT), 'active' => 1,
      'joined_on' => d($in['joined_on'] ?? null) ?? today(), 'created_at' => now()]);
    out(['id' => $id]);
  }
  $id = (int) $in['id'];
  if ($id === $MID && ($role !== 'admin' || isset($in['active']) && !$in['active'])) fail('You cannot remove your own admin access.');
  $data['role'] = $role;
  if ($jo = d($in['joined_on'] ?? null)) $data['joined_on'] = $jo;
  if (isset($in['active'])) $data['active'] = $in['active'] ? 1 : 0;
  if (!empty($in['password'])) { if (strlen($in['password']) < 8) fail('Password must be at least 8 characters.'); $data['pass_hash'] = password_hash($in['password'], PASSWORD_DEFAULT); }
  upd('users', $id, $data);
  out(['ok' => true]);

case 'password':
  $u = one('SELECT pass_hash FROM ' . T('users') . ' WHERE id = ?', [$MID]);
  if (!password_verify($in['old'] ?? '', $u['pass_hash'])) fail('Current password is wrong.');
  if (strlen($in['new'] ?? '') < 8) fail('New password must be at least 8 characters.');
  upd('users', $MID, ['pass_hash' => password_hash($in['new'], PASSWORD_DEFAULT)]);
  out(['ok' => true]);

// ----- SETTINGS -----
// ----- business cards & contacts directory -----
case 'card_scan':   // photo of one or many business cards → contacts
  require_once __DIR__ . '/lib/cards.php'; require_once __DIR__ . '/lib/shrink.php';
  $f = $_FILES['file'] ?? null;
  if (!$f || $f['error'] !== UPLOAD_ERR_OK) fail($f && in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE]) ? 'Photo is too large.' : 'Upload failed. Try again.');
  $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)); if ($ext === 'jpeg') $ext = 'jpg';
  if (!in_array($ext, ['jpg', 'png', 'webp'], true)) fail('Please upload a photo (JPG or PNG).');
  $tag = s($in['tag'] ?? null, 160);
  $sid = ins('card_scans', ['user_id' => $MID, 'tag' => $tag, 'status' => 'reading', 'created_at' => now()]);
  $dir = __DIR__ . '/uploads/' . date('Y/m'); if (!is_dir($dir)) mkdir($dir, 0755, true);
  $stored = date('Y/m/') . bin2hex(random_bytes(12)) . '.' . $ext; $abs = __DIR__ . '/uploads/' . $stored;
  if (!move_uploaded_file($f['tmp_name'], $abs)) fail('Could not save the photo.');
  session_write_close();
  $r = card_read($abs);   // read from the full-quality photo, then keep a compressed copy
  $before = filesize($abs); $sr = shrink_file($abs, $ext, 'appr');
  if ($sr['ext'] !== $ext) { $ns = preg_replace('~\.\w+$~', '.' . $sr['ext'], $stored); rename($abs, __DIR__ . '/uploads/' . $ns); $stored = $ns; }
  $fid = ins('files', ['entity' => 'cardscan', 'entity_id' => $sid, 'orig_name' => mb_substr(basename($f['name']), 0, 250), 'stored_name' => $stored, 'mime' => 'image/jpeg', 'size' => (int) $sr['size'], 'orig_size' => $before, 'shrunk' => 1, 'uploaded_by' => $MID, 'created_at' => now()]);
  $out = [];
  if (!$r['error']) {
    tx();
    foreach ($r['cards'] as $c) { $sv = card_save($c, $MID, $me['name'], $tag, $sid); $out[] = $c + ['id' => $sv['id'], 'dup' => $sv['dup'], 'known' => $sv['dup'] ? null : card_known($c)]; }
    db()->commit();
  }
  $added = count(array_filter($out, fn($x) => !$x['dup']));
  upd('card_scans', $sid, ['status' => $r['error'] ? ($r['error'] === 'nokey' ? 'manual' : 'failed') : 'done', 'found' => count($out), 'added' => $added, 'error' => $r['error'] === 'nokey' ? null : $r['error']]);
  touch_activity();
  out(['scan_id' => $sid, 'file_id' => $fid, 'cards' => $out, 'added' => $added, 'error' => $r['error'] === 'nokey' ? null : $r['error'], 'manual' => $r['error'] === 'nokey']);

case 'card_scans':   // my recent photos (admin: everyone's)
  require_once __DIR__ . '/lib/cards.php';
  $rows = all('SELECT * FROM ' . T('card_scans') . ($ADMIN ? '' : ' WHERE user_id = ' . $MID) . ' ORDER BY id DESC LIMIT 60');
  $fl = files_of('cardscan', array_column($rows, 'id')); $um = users_map();
  foreach ($rows as &$r) { $r['files'] = $fl[$r['id']] ?? []; $r['by'] = $um[$r['user_id']] ?? '—'; } unset($r);
  out(['scans' => $rows, 'ai' => cards_ai()['key'] !== '']);

case 'dir_list':   // one directory: cards, clients, client contacts, expo data, referrals
  require_once __DIR__ . '/lib/cards.php';
  $rows = directory_rows($in, $ADMIN, $MID); $total = count($rows);
  $tags = array_column(all('SELECT DISTINCT tag FROM ' . T('cards') . ' WHERE tag IS NOT NULL AND tag <> \'\'' . ($ADMIN ? '' : ' AND user_id = ' . $MID) . ' ORDER BY tag'), 'tag');
  $expos = array_values(array_unique(array_merge(array_column(all('SELECT name FROM ' . T('expos') . ' ORDER BY id DESC LIMIT 200'), 'name'), array_column(all('SELECT DISTINCT expo FROM ' . T('projects') . ' ORDER BY id DESC LIMIT 200'), 'expo'))));
  $by = []; foreach ($rows as $r) $by[$r['source']] = ($by[$r['source']] ?? 0) + 1;
  $per = max(25, min(500, (int) ($in['per'] ?? 100))); $pages = max(1, (int) ceil($total / $per)); $page = max(1, min($pages, (int) ($in['page'] ?? 1)));
  $cities = []; foreach ($rows as $r) { $c = trim((string) ($r['city'] ?? '')); if ($c !== '') $cities[mb_convert_case($c, MB_CASE_TITLE)] = 1; } ksort($cities);
  $slice = array_slice($rows, ($page - 1) * $per, $per); foreach ($slice as &$r) unset($r['raw']['phone_key']); unset($r);
  out(['rows' => $slice, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $per, 'counts' => $by, 'tags' => $tags, 'expos' => $expos, 'cities' => array_keys($cities)]);

case 'dir_save':   // correct a contact's details, whichever list it lives in
  $k = $in['kind'] ?? ''; $id = (int) ($in['id'] ?? 0);
  $v = fn($f, $n) => s($in[$f] ?? null, $n);
  $em = strtolower(trim((string) ($in['email'] ?? ''))); if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) fail('The email address looks wrong.');
  if ($k === 'client') {
    $c = one('SELECT * FROM ' . T('clients') . ' WHERE id = ?', [$id]) ?? fail('Client not found.', 404);
    if (!$ADMIN && (int) $c['owner_id'] !== $MID) fail('Only the sales person handling this client (or admin) can change it.', 403);
    $nd = ['person' => $v('name', 120), 'designation' => $v('designation', 120), 'company' => $v('company', 200) ?? $c['company'], 'phone' => $v('phone', 60), 'email' => $em ?: null, 'city' => $v('city', 80), 'location' => $v('address', 1000)];
    if ($nd['city'] !== $c['city'] || $nd['location'] !== $c['location']) $nd += ['lat' => null, 'lng' => null, 'geo_q' => null, 'geo_prec' => null];   // address changed: find it on the map again
    upd('clients', $id, $nd);
  } elseif ($k === 'ccontact') {
    $c = one('SELECT k.*, c.owner_id FROM ' . T('contacts') . ' k JOIN ' . T('clients') . ' c ON c.id = k.client_id WHERE k.id = ?', [$id]) ?? fail('Contact not found.', 404);
    if (!$ADMIN && (int) $c['owner_id'] !== $MID) fail('Only the sales person handling this client (or admin) can change it.', 403);
    upd('contacts', $id, ['name' => $v('name', 120) ?? $c['name'], 'designation' => $v('designation', 120), 'phone' => $v('phone', 60), 'email' => $em ?: null]);
  } elseif ($k === 'expo') {
    $l = one('SELECT * FROM ' . T('leads') . ' WHERE id = ?', [$id]) ?? fail('Entry not found.', 404);
    if (!$ADMIN && (int) $l['user_id'] !== $MID && (int) $l['assigned_to'] !== $MID) fail('You can only change entries you added or that are assigned to you.', 403);
    upd('leads', $id, ['person' => $v('name', 120), 'company' => $v('company', 200) ?? $l['company'], 'phone' => $v('phone', 60), 'email' => $em ?: null, 'notes' => $v('note', 4000), 'updated_at' => now()]);
  } elseif ($k === 'referral') {
    $r = one('SELECT * FROM ' . T('referrals') . ' WHERE id = ?', [$id]) ?? fail('Lead not found.', 404);
    if (!$ADMIN && (int) $r['assigned_to'] !== $MID && (int) $r['referred_by'] !== $MID) fail('Not your referral lead.', 403);
    upd('referrals', $id, ['name' => $v('name', 120) ?? $r['name'], 'company' => $v('company', 200), 'phone' => $v('phone', 60) ?? $r['phone']]);
  } else fail('Business cards are edited with their own form.');
  out(['ok' => true]);

case 'dir_detail':   // one contact: full details + their business with us (orders, payments, due)
  require_once __DIR__ . '/lib/cards.php'; require_once __DIR__ . '/lib/calc.php';
  $k = $in['kind'] ?? ''; $id = (int) ($in['id'] ?? 0); $c = null;
  if ($k === 'card') { $r = one('SELECT * FROM ' . T('cards') . ' WHERE id = ?', [$id]); if ($r && ($ADMIN || (int) $r['user_id'] === $MID)) $c = $r + ['note' => $r['note']]; }
  elseif ($k === 'client') { $r = one('SELECT * FROM ' . T('clients') . ' WHERE id = ?', [$id]); if ($r) $c = ['name' => $r['person'], 'designation' => $r['designation'], 'company' => $r['company'], 'phone' => $r['phone'], 'email' => $r['email'], 'city' => $r['city'], 'address' => $r['location'], 'gst_no' => $r['gst_no'], 'note' => $r['notes'], 'client_id' => $r['id']]; }
  elseif ($k === 'ccontact') { $r = one('SELECT k.*, c.company, c.city, c.location FROM ' . T('contacts') . ' k JOIN ' . T('clients') . ' c ON c.id = k.client_id WHERE k.id = ?', [$id]); if ($r) $c = ['name' => $r['name'], 'designation' => $r['designation'], 'company' => $r['company'], 'phone' => $r['phone'], 'email' => $r['email'], 'city' => $r['city'], 'address' => $r['location'], 'client_id' => $r['client_id']]; }
  elseif ($k === 'expo') { $r = one('SELECT l.*, e.name AS expo, e.city FROM ' . T('leads') . ' l JOIN ' . T('expos') . ' e ON e.id = l.expo_id WHERE l.id = ?', [$id]); if ($r) $c = ['name' => $r['person'], 'company' => $r['company'], 'phone' => $r['phone'], 'email' => $r['email'], 'city' => $r['city'], 'note' => $r['notes'], 'expo' => $r['expo'], 'follow' => $r['follow']]; }
  elseif ($k === 'referral') { $r = one('SELECT * FROM ' . T('referrals') . ' WHERE id = ?', [$id]); if ($r) $c = ['name' => $r['name'], 'company' => $r['company'], 'phone' => $r['phone'], 'note' => $r['note']]; }
  if (!$c) fail('Contact not found.', 404);
  $cid = contact_client($c); $biz = null;
  if ($cid) {
    $cl = one('SELECT * FROM ' . T('clients') . ' WHERE id = ?', [$cid]); $um = users_map();
    $pr = all('SELECT * FROM ' . T('projects') . ' WHERE client_id = ? ORDER BY start_date DESC, id DESC', [$cid]); $bl = bills_for($pr); $orders = []; $T = ['total' => 0, 'received' => 0, 'balance' => 0];
    foreach ($pr as $p) { $b = $bl[$p['id']]; $won = is_won($p['status']);
      $orders[] = ['id' => (int) $p['id'], 'expo' => $p['expo'], 'location' => $p['location'], 'start_date' => $p['start_date'], 'status' => $p['status'], 'area_sqm' => $p['area_sqm'], 'total' => $b['total'], 'received' => $b['received'], 'balance' => $won ? $b['balance'] : 0, 'won' => $won];
      if ($won) { $T['total'] += $b['total']; $T['received'] += $b['received']; $T['balance'] += $b['balance']; } }
    $last = val('SELECT MAX(pdate) FROM ' . T('client_payments') . ' WHERE project_id IN (SELECT id FROM ' . T('projects') . ' WHERE client_id = ?)', [$cid]);
    $mine = $ADMIN || $ACC || (int) $cl['owner_id'] === $MID;
    $biz = $mine ? ['client_id' => $cid, 'company' => $cl['company'], 'address' => $cl['location'], 'city' => $cl['city'], 'gst_no' => $cl['gst_no'], 'owner' => $um[$cl['owner_id']] ?? '—', 'since' => $cl['created_at'], 'can_open' => $ADMIN || (int) $cl['owner_id'] === $MID,
      'orders' => $orders, 'totals' => array_map(fn($x) => round($x, 2), $T), 'last_payment' => $last]
      : ['client_id' => $cid, 'company' => $cl['company'], 'owner' => $um[$cl['owner_id']] ?? '—', 'locked' => 1];   // another sales person's client: name only
  }
  out(['contact' => $c, 'business' => $biz]);

// ---------- customers near me: addresses → map points (OpenStreetMap), then the nearest to where you stand ----------
case 'geo_batch':   // find map points for a few more client addresses (called repeatedly while the page is open)
  require_once __DIR__ . '/lib/geo.php';
  $done = 0; $t0 = microtime(true);
  foreach (all('SELECT id, location, city FROM ' . T('clients') . " WHERE (geo_q IS NULL OR geo_q = '') AND ((location IS NOT NULL AND location <> '') OR (city IS NOT NULL AND city <> '')) ORDER BY id DESC LIMIT 6") as $c) {
    if (microtime(true) - $t0 > 20) break;
    $g = geo_client($c); upd('clients', $c['id'], $g); $done++;
  }
  $left = (int) val('SELECT COUNT(*) FROM ' . T('clients') . " WHERE (geo_q IS NULL OR geo_q = '') AND ((location IS NOT NULL AND location <> '') OR (city IS NOT NULL AND city <> ''))");
  out(['done' => $done, 'left' => $left, 'mapped' => (int) val('SELECT COUNT(*) FROM ' . T('clients') . ' WHERE lat IS NOT NULL')]);

case 'geo_place':   // a typed place (when the phone's location is off)
  require_once __DIR__ . '/lib/geo.php';
  $q = s($in['q'] ?? null, 200) ?? fail('Type a place, area or pincode.');
  $p = geo_lookup($q . ', India') ?? geo_lookup($q) ?? fail('Could not find that place on the map. Try an area name or pincode.');
  out($p);

case 'near_me':
  require_once __DIR__ . '/lib/calc.php';
  $lat = (float) ($in['lat'] ?? 0); $lng = (float) ($in['lng'] ?? 0); if (!$lat && !$lng) fail('Location missing.');
  $rad = max(1, min(3000, (int) ($in['km'] ?? 50)));
  $w = 'lat IS NOT NULL'; $p = []; if (!$ADMIN && !$ACC) { $w .= ' AND owner_id = ?'; $p[] = $MID; }
  $um = users_map(); $rows = [];
  foreach (all('SELECT id, company, person, phone, email, city, location, lat, lng, geo_prec, owner_id FROM ' . T('clients') . " WHERE $w", $p) as $c) {
    $d = 6371 * 2 * asin(sqrt(pow(sin(deg2rad($c['lat'] - $lat) / 2), 2) + cos(deg2rad($lat)) * cos(deg2rad($c['lat'])) * pow(sin(deg2rad($c['lng'] - $lng) / 2), 2)));
    if ($d <= $rad) $rows[] = $c + ['km' => round($d, 1), 'owner' => $um[$c['owner_id']] ?? '—'];
  }
  usort($rows, fn($a, $b) => $a['km'] <=> $b['km']); $rows = array_slice($rows, 0, 100);
  if ($rows) { $ids = implode(',', array_map(fn($r) => (int) $r['id'], $rows)); $pr = all('SELECT * FROM ' . T('projects') . " WHERE client_id IN ($ids)"); $bl = bills_for($pr); $agg = [];
    foreach ($pr as $x) { $a = &$agg[$x['client_id']]; $a = $a ?? ['orders' => 0, 'due' => 0, 'last' => null]; $a['orders']++; if (is_won($x['status'])) $a['due'] += $bl[$x['id']]['balance']; if (!$a['last'] || $x['start_date'] > $a['last']['date']) $a['last'] = ['date' => $x['start_date'], 'expo' => $x['expo']]; unset($a); }
    foreach ($rows as &$r) $r += $agg[$r['id']] ?? ['orders' => 0, 'due' => 0, 'last' => null]; unset($r); }
  $wv = !$ADMIN && !$ACC ? ' AND owner_id = ' . $MID : '';
  out(['rows' => $rows, 'mapped' => (int) val('SELECT COUNT(*) FROM ' . T('clients') . " WHERE lat IS NOT NULL$wv"), 'unmapped' => (int) val('SELECT COUNT(*) FROM ' . T('clients') . " WHERE lat IS NULL AND (geo_q IS NULL OR geo_q = '') AND ((location IS NOT NULL AND location <> '') OR (city IS NOT NULL AND city <> ''))$wv"),
    'noaddr' => (int) val('SELECT COUNT(*) FROM ' . T('clients') . " WHERE (location IS NULL OR location = '') AND (city IS NULL OR city = '')$wv"), 'notfound' => (int) val('SELECT COUNT(*) FROM ' . T('clients') . " WHERE lat IS NULL AND geo_q IS NOT NULL AND geo_q <> ''$wv")]);

case 'my_handovers':   // research / telecalling: clients I added and handed to sales
  $um = users_map();
  $rows = all('SELECT id, company, person, phone, city, owner_id, created_at FROM ' . T('clients') . ' WHERE created_by = ? AND owner_id <> ? ORDER BY id DESC LIMIT 100', [$MID, $MID]);
  foreach ($rows as &$r) $r['owner'] = $um[$r['owner_id']] ?? '—'; unset($r);
  out(['rows' => $rows]);

case 'share_link':   // secure link for a client: quote / invoice / file
  require_once __DIR__ . '/lib/share.php'; require_once __DIR__ . '/lib/fileauth.php';
  $kind = $in['kind'] ?? ''; $id = (int) ($in['id'] ?? 0);
  if ($kind === 'quote') { $a = one('SELECT a.status, p.owner_id FROM ' . T('approvals') . ' a JOIN ' . T('projects') . ' p ON p.id = a.project_id WHERE a.id = ?', [$id]);
    if (!$a || $a['status'] !== 'approved') fail('Only an approved quote can be sent.'); if (!$ADMIN && !$ACC && (int) $a['owner_id'] !== $MID) fail('Not your quote.', 403); }
  elseif ($kind === 'invoice') { $i = one('SELECT i.status, i.requested_by, p.owner_id FROM ' . T('invoices') . ' i LEFT JOIN ' . T('projects') . ' p ON p.id = i.project_id WHERE i.id = ?', [$id]);
    if (!$i || $i['status'] !== 'issued') fail('Only an approved invoice can be sent.'); if (!$ACC && (int) $i['requested_by'] !== $MID && (int) ($i['owner_id'] ?? 0) !== $MID) fail('Not your invoice.', 403); }
  elseif ($kind === 'file') { $f = one('SELECT * FROM ' . T('files') . ' WHERE id = ?', [$id]); if (!$f || !file_allowed($me, $f)) fail('You do not have access to this file.', 403); }
  else fail('Unknown item.');
  $page = ['quote' => 'quote.php', 'invoice' => 'invoice.php', 'file' => 'file.php'][$kind];
  $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\') . '/';
  out(['url' => $base . $page . '?t=' . share_make($kind, $id, $MID), 'days' => 30]);

case 'gst_known':   // is this GSTIN already in the CRM? (fills the form from the saved client)
  $g = strtoupper(preg_replace('/\s/', '', (string) ($in['gst'] ?? '')));
  if (strlen($g) !== 15) out(['found' => null]);
  $c = one('SELECT id, company, location, city, owner_id FROM ' . T('clients') . " WHERE UPPER(REPLACE(gst_no, ' ', '')) = ? ORDER BY id DESC", [$g])
    ?: one('SELECT c.id, c.company, g.address AS location, c.city, c.owner_id FROM ' . T('client_gst') . ' g JOIN ' . T('clients') . ' c ON c.id = g.client_id WHERE g.gstin = ? ORDER BY g.id DESC', [$g]);
  if ($c) { $c['owner'] = users_map()[$c['owner_id']] ?? '—'; unset($c['owner_id']); }
  out(['found' => $c ?: null]);

case 'gst_read_image':   // read a screenshot of the GST portal result with the card-reading AI key
  require_once __DIR__ . '/lib/cards.php';
  $f = $_FILES['file'] ?? null;
  if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) fail('The screenshot did not upload. Please try again.');
  $ai = cards_ai(); if ($ai['key'] === '') fail('Screenshot reading needs the AI key (Settings → Business card reading). You can paste the copied text instead.');
  $imgs = card_images($f['tmp_name']); if (!$imgs) fail('Could not open this image. Paste a PNG or JPG screenshot.');
  $content = [['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $imgs[0]]],
    ['type' => 'text', 'text' => 'This is a screenshot of the Indian GST portal "Search Taxpayer" result. Copy exactly what is shown. Reply with JSON only: {"gstin":"","legal_name":"","trade_name":"","status":"","constitution":"","address":"","city":"","state":"","pincode":""}. "address" is the Principal Place of Business. Use "" for anything not visible. Do not guess.']];
  $ch = curl_init($ai['url']);
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['model' => $ai['model'], 'max_tokens' => 1500, 'messages' => [['role' => 'user', 'content' => $content]]]),
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . $ai['key'], 'anthropic-version: 2023-06-01']]);
  $res = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  $j = $res ? json_decode($res, true) : null; if ($code !== 200) fail('Could not read the screenshot: ' . ($j['error']['message'] ?? 'service not reachable'));
  $text = ''; foreach ((array) ($j['content'] ?? []) as $b) if (($b['type'] ?? '') === 'text') $text .= $b['text'];
  $a = strpos($text, '{'); $z = strrpos($text, '}'); $d = $a !== false ? json_decode(substr($text, $a, $z - $a + 1), true) : null;
  if (!is_array($d) || empty($d['legal_name'])) fail('No GST details found in this image. Take the screenshot of the result area on the GST portal.');
  $o = []; foreach (['gstin', 'legal_name', 'trade_name', 'status', 'constitution', 'address', 'city', 'state', 'pincode'] as $k) $o[$k] = mb_substr(trim((string) ($d[$k] ?? '')), 0, 400);
  out($o);

case 'sheet_read':   // read an uploaded Excel / CSV so the person can match its columns (the file itself is not kept)
  require_once __DIR__ . '/lib/sheet.php';
  $f = $_FILES['file'] ?? null;
  if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) fail('The file did not upload. Please try again.');
  if ($f['size'] > 15 * 1048576) fail('This file is too large (over 15 MB). Split it into smaller files.');
  try { $d = sheet_read($f['tmp_name'], $f['name']); } catch (Exception $e) { fail($e->getMessage()); }
  if (!$d['rows']) fail('This sheet is empty.');
  out($d + ['name' => mb_substr($f['name'], 0, 120)]);

case 'cards_import':   // contacts from an Excel / CSV sheet, sent in batches by the browser
  require_once __DIR__ . '/lib/cards.php';
  $tag = s($in['tag'] ?? null, 160) ?? fail('Enter a tag — where this list came from.');
  $uid = $ADMIN && !empty($in['user_id']) ? (int) $in['user_id'] : $MID;
  $rows = array_slice((array) ($in['rows'] ?? []), 0, 500);
  $added = 0; $merged = 0; $skipped = [];
  tx();
  foreach ($rows as $x) {
    $x = (array) $x; $n = (int) ($x['_row'] ?? 0); $row = [];
    foreach (['name' => 120, 'designation' => 120, 'company' => 200, 'phone' => 40, 'phone2' => 120, 'email' => 160, 'email2' => 255, 'website' => 160, 'city' => 80, 'address' => 2000, 'note' => 4000] as $k => $len) $row[$k] = s(isset($x[$k]) ? (string) $x[$k] : null, $len) ?? '';
    if ($row['email'] !== '' && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) { $row['note'] = trim($row['note'] . ' Email in sheet: ' . $row['email']); $row['email'] = ''; }
    $row['email'] = strtolower($row['email']);
    if ($row['name'] === '' && $row['company'] === '') { $skipped[] = ['row' => $n, 'why' => 'no name or company']; continue; }
    if ($row['phone'] === '' && $row['email'] === '') { $skipped[] = ['row' => $n, 'why' => 'no phone or email']; continue; }
    $sv = card_save($row, $uid, $me['name'], $tag, null, 'import');
    $sv['dup'] ? $merged++ : $added++;
  }
  db()->commit();
  out(['added' => $added, 'merged' => $merged, 'skipped' => $skipped]);

case 'card_save':   // add a contact by hand, or correct one
  require_once __DIR__ . '/lib/cards.php';
  $row = []; foreach (['name' => 120, 'designation' => 120, 'company' => 200, 'phone' => 40, 'phone2' => 120, 'email' => 160, 'email2' => 255, 'website' => 160, 'city' => 80, 'address' => 2000, 'tag' => 160, 'note' => 4000] as $k => $n) $row[$k] = s($in[$k] ?? null, $n) ?? '';
  if ($row['email'] !== '' && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) fail('The email address looks wrong.');
  if ($row['name'] === '' && $row['company'] === '') fail('Enter at least the name or the company.');
  if ($row['phone'] === '' && $row['email'] === '') fail('Enter a phone number or an email.');
  $row['email'] = strtolower($row['email']);
  if (!empty($in['id'])) {
    $c = lock_row('cards', $in['id']) ?? fail('Contact not found.', 404);
    if (!$ADMIN && (int) $c['user_id'] !== $MID) fail('You can only change contacts you added.', 403);
    $ph = card_digits($row['phone']);
    upd('cards', $c['id'], $row + ['phone_key' => $ph && strlen($ph) === 10 ? $ph : null, 'updated_at' => now()] + ($ADMIN && !empty($in['user_id']) ? ['user_id' => (int) $in['user_id']] : []));
    out(['id' => (int) $c['id']]);
  }
  tx(); $sv = card_save($row, $ADMIN && !empty($in['user_id']) ? (int) $in['user_id'] : $MID, $me['name'], $row['tag'], null, 'manual'); db()->commit();
  out($sv);

case 'card_del':
  $c = one('SELECT * FROM ' . T('cards') . ' WHERE id = ?', [(int) ($in['id'] ?? 0)]) ?? fail('Contact not found.', 404);
  if (!$ADMIN && (int) $c['user_id'] !== $MID) fail('You can only delete contacts you added.', 403);
  q('DELETE FROM ' . T('cards') . ' WHERE id = ?', [$c['id']]); out(['ok' => true]);

case 'cards_ai_save':   // admin: the key for the card-reading service
  need_admin(); require_once __DIR__ . '/lib/cards.php';
  $c = (array) setting('cards_ai', []);
  if (array_key_exists('key', $in) && trim((string) $in['key']) !== '' && !str_contains((string) $in['key'], '•')) $c['key'] = trim((string) $in['key']);
  if (!empty($in['remove'])) $c['key'] = '';
  if (isset($in['model'])) $c['model'] = s($in['model'], 80) ?? '';
  set_setting('cards_ai', $c); out(['ok' => true]);

case 'cards_ai_test':
  need_admin(); require_once __DIR__ . '/lib/cards.php';
  $ai = cards_ai(); if ($ai['key'] === '') fail('Enter the key first.');
  $ch = curl_init($ai['url']);
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_POSTFIELDS => json_encode(['model' => $ai['model'], 'max_tokens' => 5, 'messages' => [['role' => 'user', 'content' => 'Reply OK']]]),
    CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . $ai['key'], 'anthropic-version: 2023-06-01']]);
  $res = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
  if ($res === false) fail('Could not reach the service: ' . $err);
  $j = json_decode($res, true); if ($code !== 200) fail('The service said: ' . ($j['error']['message'] ?? 'error ' . $code));
  out(['ok' => true]);

// ----- referral leads -----
case 'ref_list':   // admin: everything; others: what was handed to me
  $um = users_map(); $w = $ADMIN && ($in['scope'] ?? '') !== 'mine' ? '1=1' : 'r.assigned_to = ' . $MID;
  if (in_array($in['status'] ?? '', ['new', 'seen', 'converted', 'closed'], true)) { $w .= ' AND r.status = ?'; $pp = [$in['status']]; } else $pp = [];
  $rows = all('SELECT r.*, c.company AS client_company FROM ' . T('referrals') . " r LEFT JOIN " . T('clients') . " c ON c.id = r.client_id WHERE $w ORDER BY (r.status IN ('new','seen')) DESC, r.id DESC LIMIT 500", $pp);
  foreach ($rows as &$r) { $r['to_name'] = $um[$r['assigned_to']] ?? '—'; $r['by_name'] = $um[$r['referred_by']] ?? '—'; $r['history'] = json_decode($r['history'] ?? '', true) ?: []; } unset($r);
  $cnt = []; foreach (all('SELECT status, COUNT(*) AS n FROM ' . T('referrals') . ($ADMIN ? '' : ' WHERE assigned_to = ' . $MID) . ' GROUP BY status') as $x) $cnt[$x['status']] = (int) $x['n'];
  out(['rows' => $rows, 'counts' => $cnt, 'targets' => $ADMIN ? ref_targets() : []]);

case 'ref_save':   // admin: refer a new lead, or hand an open one to someone else
  need_admin();
  $to = (int) ($in['assigned_to'] ?? 0); $tu = null; foreach (ref_targets() as $u) if ((int) $u['id'] === $to) $tu = $u;
  if (!$tu) fail('Pick the sales person or admin who should handle this lead.');
  if (!empty($in['id'])) {
    $r = lock_row('referrals', $in['id']) ?? fail('Not found', 404);
    if (!in_array($r['status'], ['new', 'seen'], true)) fail('This lead is already ' . $r['status'] . '.');
    if ((int) $r['assigned_to'] === $to) fail('It is already with ' . $tu['name'] . '.');
    upd('referrals', $r['id'], ['assigned_to' => $to, 'assigned_at' => now(), 'status' => 'new', 'seen_at' => null, 'history' => ref_hist($r, 'Handed to ' . $tu['name'] . ' by ' . $me['name'] . (s($in['note'] ?? null) ? ' — ' . s($in['note'], 500) : ''))]);
    out(['ok' => true]);
  }
  $row = ['name' => s($in['name'] ?? null, 120) ?? fail('Enter the name of the person.'), 'phone' => s($in['phone'] ?? null, 40) ?? fail('Enter the contact number.'),
    'company' => s($in['company'] ?? null, 200) ?? fail('Enter the company name.'), 'area' => s($in['area'] ?? null, 60), 'note' => s($in['note'] ?? null, 2000)];
  if (strlen(preg_replace('/\D/', '', $row['phone'])) < 8) fail('The contact number looks too short.');
  $id = ins('referrals', $row + ['referred_by' => $MID, 'assigned_to' => $to, 'assigned_at' => now(), 'status' => 'new', 'created_at' => now(), 'history' => json_encode([['at' => now(), 'text' => 'Referred by ' . $me['name'] . ' to ' . $tu['name']]], JSON_UNESCAPED_UNICODE)]);
  out(['id' => $id]);

case 'ref_seen':
  $r = one('SELECT * FROM ' . T('referrals') . ' WHERE id = ?', [(int) ($in['id'] ?? 0)]);
  if ($r && (int) $r['assigned_to'] === $MID && $r['status'] === 'new') upd('referrals', $r['id'], $r['client_id'] ? ['status' => 'converted', 'seen_at' => now(), 'converted_at' => now()] : ['status' => 'seen', 'seen_at' => now()]);
  out(['ok' => true]);

case 'ref_close':   // the person handling it: not workable (with reason) · admin: can close any
  $r = lock_row('referrals', $in['id'] ?? 0) ?? fail('Not found', 404);
  if ((int) $r['assigned_to'] !== $MID && !$ADMIN) fail('This lead is not with you.', 403);
  if (!in_array($r['status'], ['new', 'seen'], true)) fail('This lead is already ' . $r['status'] . '.');
  $why = s($in['reason'] ?? null, 1000) ?? fail('Write why it cannot be taken up.');
  upd('referrals', $r['id'], ['status' => 'closed', 'closed_reason' => $why, 'closed_at' => now(), 'history' => ref_hist($r, 'Closed by ' . $me['name'] . ' — ' . $why)]);
  out(['ok' => true]);

case 'ref_reopen':
  need_admin();
  $r = lock_row('referrals', $in['id'] ?? 0) ?? fail('Not found', 404);
  if ($r['status'] !== 'closed') fail('Only a closed lead can be reopened.');
  upd('referrals', $r['id'], ['status' => 'new', 'seen_at' => null, 'closed_reason' => null, 'closed_at' => null, 'assigned_at' => now(), 'history' => ref_hist($r, 'Reopened by ' . $me['name'])]);
  out(['ok' => true]);

case 'settings':
  need_admin();
  require_once __DIR__ . '/lib/quote.php';
  out(['holidays' => all('SELECT * FROM ' . T('holidays') . ' ORDER BY hdate DESC'), 'track_from' => setting('track_from'), 'quote_defaults' => quote_defaults(), 'cards_ai' => (function () { require_once __DIR__ . '/lib/cards.php'; $a = cards_ai(); return ['has_key' => $a['key'] !== '', 'hint' => $a['key'] ? substr($a['key'], 0, 7) . '••••' . substr($a['key'], -4) : '', 'model' => $a['model']]; })(),
    'smtp' => (function () { require_once __DIR__ . '/lib/mailer.php'; return mail_cfg() + ['people' => array_map(fn($u) => ['name' => $u['name'], 'email' => $u['email'], 'ready' => (bool) $u['mail_pass']], array_values(array_filter(all('SELECT id, name, email, role, department, extra_depts, mail_pass FROM ' . T('users') . ' WHERE active = 1 ORDER BY name'), fn($u) => $u['role'] === 'admin' || in_array('records', user_mods($u), true))))]; })()]);

case 'storage':   // admin: how much space files use, and compress older files
  need_admin(); require_once __DIR__ . '/lib/shrink.php';
  $t = one('SELECT COUNT(*) AS n, COALESCE(SUM(size),0) AS size, COALESCE(SUM(CASE WHEN orig_size > size THEN orig_size - size ELSE 0 END),0) AS saved, COALESCE(SUM(CASE WHEN shrunk = 0 THEN 1 ELSE 0 END),0) AS pending FROM ' . T('files'));
  out(['files' => (int) $t['n'], 'size' => (int) $t['size'], 'saved' => (int) $t['saved'], 'pending' => (int) $t['pending'], 'level' => setting('file_quality', 'balanced'), 'levels' => SHRINK_LEVELS, 'tools' => ['gd' => gd_ok(), 'zip' => class_exists('ZipArchive')], 'limit_mb' => upload_limit_mb()]);

case 'storage_shrink':   // admin: compress older files, a few at a time (the page calls this repeatedly)
  need_admin(); require_once __DIR__ . '/lib/shrink.php';
  $t0 = microtime(true); $done = 0; $saved = 0;
  foreach (all('SELECT * FROM ' . T('files') . ' WHERE shrunk = 0 ORDER BY size DESC LIMIT 25') as $f) {
    if (microtime(true) - $t0 > 20) break;
    $abs = __DIR__ . '/uploads/' . $f['stored_name']; $ext = strtolower(pathinfo($f['stored_name'], PATHINFO_EXTENSION));
    $upd = ['shrunk' => 1];
    if (is_file($abs) && !val('SELECT COUNT(*) FROM ' . T('files') . ' WHERE stored_name = ? AND id <> ?', [$f['stored_name'], $f['id']])) {   // shared copies are left alone
      $r = shrink_file($abs, $ext, $f['entity']);
      if ($r['changed']) {
        if ($r['ext'] !== $ext) { $ns = preg_replace('~\.\w+$~', '.' . $r['ext'], $f['stored_name']); rename($abs, __DIR__ . '/uploads/' . $ns); $upd += ['stored_name' => $ns, 'orig_name' => preg_replace('~\.\w+$~', '', $f['orig_name']) . '.' . $r['ext'], 'mime' => 'image/jpeg']; }
        $upd += ['size' => $r['size'], 'orig_size' => max((int) ($f['orig_size'] ?: $f['size']), $r['before'])]; $saved += $r['before'] - $r['size'];
      }
    }
    if (!isset($upd['orig_size'])) $upd['orig_size'] = $f['orig_size'] ?: $f['size'];
    upd('files', $f['id'], $upd); $done++;
  }
  out(['done' => $done, 'saved' => $saved, 'pending' => (int) val('SELECT COUNT(*) FROM ' . T('files') . ' WHERE shrunk = 0')]);

case 'settings_save':
  need_admin();
  if (isset($in['quote_defaults'])) {   // default text for generated quotes
    require_once __DIR__ . '/lib/quote.php'; $q = (array) $in['quote_defaults']; $cur = (array) setting('quote_defaults', []);
    $ln = fn($v) => array_values(array_filter(array_map(fn($x) => mb_substr(trim((string) $x), 0, 400), is_array($v) ? $v : preg_split('~\r?\n~', (string) $v)), 'strlen'));
    foreach (['payment_terms', 'notes', 'client_resp', 'excludes'] as $k) if (isset($q[$k])) $cur[$k] = $ln($q[$k]);
    if (isset($q['valid_days'])) $cur['valid_days'] = max(1, min(365, (int) $q['valid_days']));
    if (isset($q['scope'])) $cur['scope'] = s($q['scope'], 120) ?? QUOTE_DEFAULTS['scope'];
    set_setting('quote_defaults', $cur);
  }
  if (isset($in['smtp'])) { $m = (array) $in['smtp'];
    set_setting('smtp', ['host' => s($m['host'] ?? null, 120) ?? '', 'port' => max(1, min(65535, (int) ($m['port'] ?? 465))), 'secure' => in_array($m['secure'] ?? '', ['ssl', 'tls', 'none'], true) ? $m['secure'] : 'ssl',
      'imap_host' => s($m['imap_host'] ?? null, 120) ?? '', 'imap_port' => max(1, min(65535, (int) ($m['imap_port'] ?? 993)))]); }
  if (isset($in['profile_msgs'])) { $m = (array) $in['profile_msgs']; $cur = (array) setting('profile_msgs', []);
    foreach (['wa' => 3000, 'subj' => 200, 'body' => 5000] as $k => $mx) if (array_key_exists($k, $m)) $cur[$k] = mb_substr(str_replace("\r", '', trim((string) $m[$k])), 0, $mx);
    set_setting('profile_msgs', $cur); }
  if (isset($in['file_quality'])) { require_once __DIR__ . '/lib/shrink.php'; if (!isset(SHRINK_LEVELS[$in['file_quality']])) fail('Pick a quality level.'); set_setting('file_quality', $in['file_quality']); }
  if (isset($in['weekly_off'])) set_setting('weekly_off', array_values(array_unique(array_map('intval', array_filter((array) $in['weekly_off'], fn($x) => $x >= 0 && $x <= 6)))));
  if (isset($in['departments'])) { $dp = array_values(array_unique(array_filter(array_map(fn($x) => s($x, 60), (array) $in['departments'])))); if (!$dp) fail('Keep at least one department.'); set_setting('departments', $dp); }
  if (isset($in['templates'])) {
    $clean = [];
    foreach ((array) $in['templates'] as $dept => $fields) {
      $fs = [];
      foreach ((array) $fields as $f) {
        $t = in_array($f['t'] ?? '', ['text', 'long', 'number', 'date', 'select', 'file'], true) ? $f['t'] : 'text';
        $x = ['k' => preg_replace('/[^a-z0-9_]/i', '', $f['k'] ?? '') ?: 'f' . bin2hex(random_bytes(3)), 'l' => s($f['l'] ?? null, 80) ?? 'Field', 't' => $t];
        if (!empty($f['req'])) $x['req'] = 1;
        if ($t === 'select') $x['o'] = array_values(array_filter(array_map(fn($o) => s($o, 60), (array) ($f['o'] ?? []))));
        $fs[] = $x;
      }
      if ($fs) { $fs[0]['req'] = 1; $clean[s($dept, 60)] = $fs; }
    }
    set_setting('templates', $clean);
  }
  if (isset($in['dept_modules'])) {
    $m = setting('dept_modules', default_dept_modules());
    foreach ((array) $in['dept_modules'] as $dname => $mod) if (in_array($dname, setting('departments', []), true) && in_array($mod, ['records', 'leads', 'calls', 'design', 'ops', 'accounts', 'none'], true)) $m[$dname] = $mod;
    set_setting('dept_modules', $m);
  }
  if (isset($in['furniture_items'])) set_setting('furniture_items', array_values(array_unique(array_filter(array_map(fn($x) => s($x, 80), (array) $in['furniture_items'])))));
  if (isset($in['track_from']) && d($in['track_from'])) set_setting('track_from', d($in['track_from']));
  out(['ok' => true]);

case 'holiday_add':
  need_admin();
  $hd = d($in['hdate'] ?? null); $rs = s($in['reason'] ?? null, 160);
  if (!$hd || !$rs) fail('Enter date and reason.');
  if (val('SELECT COUNT(*) FROM ' . T('holidays') . ' WHERE hdate = ?', [$hd])) fail('That date is already a holiday.');
  ins('holidays', ['hdate' => $hd, 'reason' => $rs]); out(['ok' => true]);

case 'holiday_del':
  need_admin(); q('DELETE FROM ' . T('holidays') . ' WHERE id = ?', [(int) ($in['id'] ?? 0)]); out(['ok' => true]);

case 'reason_add':
  need_admin(); $l = s($in['label'] ?? null, 160); if (!$l) fail('Enter the reason.'); ins('reasons', ['label' => $l]); out(['ok' => true]);

case 'reason_del':
  need_admin(); q('UPDATE ' . T('reasons') . ' SET active = 0 WHERE id = ?', [(int) ($in['id'] ?? 0)]); out(['ok' => true]);

case 'vendor_add':
case 'vendor_save':   // sales, operations, accounts and admin keep the vendor list
  if (!$RECORDS && !$ACC && !in_array('ops', $MODS, true)) fail('Your department cannot add vendors.', 403);
  $nm = s($in['name'] ?? null, 160); if (!$nm) fail('Enter the vendor name.');
  $vemail = strtolower(s($in['email'] ?? null, 160) ?? '');
  if ($vemail && !filter_var($vemail, FILTER_VALIDATE_EMAIL)) fail('Vendor email looks wrong.');
  $vd = ['phone' => s($in['phone'] ?? null, 40), 'email' => $vemail ?: null, 'city' => s($in['city'] ?? null, 80)];
  if (array_key_exists('notes', $in)) $vd['notes'] = s($in['notes'] ?? null, 1000);
  if ($A === 'vendor_save' && !empty($in['id'])) {
    $vid = (int) $in['id']; if (!val('SELECT id FROM ' . T('vendors') . ' WHERE id = ?', [$vid])) fail('Vendor not found.', 404);
    if (val('SELECT id FROM ' . T('vendors') . ' WHERE LOWER(name) = LOWER(?) AND id <> ? AND active = 1', [$nm, $vid])) fail('Another vendor already has this name.');
    upd('vendors', $vid, ['name' => $nm] + $vd); out(['id' => $vid]);
  }
  if ($ex = val('SELECT id FROM ' . T('vendors') . ' WHERE LOWER(name) = LOWER(?)', [$nm])) {
    upd('vendors', (int) $ex, array_filter($vd) + ['active' => 1]); out(['id' => (int) $ex]);
  }
  out(['id' => ins('vendors', ['name' => $nm] + $vd)]);

case 'expo_save':   // admin: Holidays & lists → Expo list
  need_admin();
  $data = ['name' => s($in['name'] ?? null, 200), 'edate' => d($in['edate'] ?? null), 'end_date' => d($in['end_date'] ?? null), 'city' => s($in['city'] ?? null, 80), 'venue' => s($in['venue'] ?? null, 200)];
  if (!$data['name']) fail('Enter the expo name.');
  if ($data['edate'] && $data['end_date'] && $data['end_date'] < $data['edate']) fail('End date is before the start date.');
  $id = (int) ($in['id'] ?? 0);
  $same = val('SELECT id FROM ' . T('expos') . ' WHERE LOWER(name) = LOWER(?) AND id <> ?' . ($data['edate'] ? ' AND (edate = ? OR edate IS NULL)' : ''), array_merge([$data['name'], $id], $data['edate'] ? [$data['edate']] : []));
  if ($same) fail('This expo is already in the list.');
  if ($id) { if (!val('SELECT id FROM ' . T('expos') . ' WHERE id = ?', [$id])) fail('Expo not found.', 404); upd('expos', $id, $data); out(['id' => $id]); }
  out(['id' => ins('expos', $data + ['created_by' => $MID, 'created_at' => now()])]);

case 'profile_email':   // sends the company profile PDF as an attachment, from the person's own mailbox (their login email)
  if (!$RECORDS) fail('Only sales and admin send the company profile.', 403);
  require_once __DIR__ . '/lib/mailer.php';
  $to = array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) ($in['to'] ?? ''))));
  foreach ($to as $e) if (!filter_var($e, FILTER_VALIDATE_EMAIL)) fail("Check the email address: $e");
  if (!$to) fail('Enter the client\'s email.');
  $subj = s($in['subject'] ?? null, 200) ?? fail('Enter the subject.'); $body = s($in['body'] ?? null, 8000) ?? fail('Write the message.');
  [$pn, $pb] = profile_pdf();
  $newPw = (string) ($in['pass'] ?? '');   // first time: password typed right in the send box, saved once the email goes through
  try { $r = mail_send_as($me, ['to' => $to, 'cc' => !empty($in['cc_me']) ? [$me['email']] : [], 'subject' => $subj, 'text' => $body, 'files' => [[$pn, $pb, 'application/pdf']], 'save_sent' => true], $newPw !== '' ? $newPw : null); }
  catch (UserError $e) { fail($e->getMessage()); }
  if ($newPw !== '') upd('users', $MID, ['mail_pass' => mail_lock($newPw)]);
  out(['ok' => true, 'from' => $me['email']] + $r);

case 'mail_me_save':   // Account: my mailbox password (stored encrypted)
  if (!$RECORDS) fail('Only sales and admin send emails from the CRM.', 403);
  require_once __DIR__ . '/lib/mailer.php';
  if (!empty($in['remove'])) { upd('users', $MID, ['mail_pass' => null]); out(['ok' => true]); }
  $pw = (string) ($in['pass'] ?? ''); if ($pw === '') fail('Enter your email password.');
  if (!empty($in['check'])) { try { mail_send_as($me, ['to' => [$me['email']], 'subject' => 'Zest CRM — your email is connected', 'text' => "This test was sent from the Zest CRM through your mailbox {$me['email']}.\nCompany profiles you send from the CRM will go out the same way and appear in your Sent folder.", 'save_sent' => false], $pw); }
    catch (UserError $e) { fail($e->getMessage()); } }
  upd('users', $MID, ['mail_pass' => mail_lock($pw)]);
  out(['ok' => true]);

case 'profile_wa':   // company WhatsApp gateway (if set up): sends the PDF with the message straight to the client
  if (!$RECORDS) fail('Only sales and admin send the company profile.', 403);
  require_once __DIR__ . '/lib/att.php'; $w = att_cfg()['wa'];
  if (empty($w['token']) || !function_exists('curl_init')) fail('The company WhatsApp sender is not set up.');
  $d = preg_replace('/\D/', '', (string) ($in['phone'] ?? '')); if (strlen($d) === 10) $d = '91' . $d; $d = ltrim($d, '0');
  if (strlen($d) < 11) fail('Enter the client\'s WhatsApp number.');
  $text = s($in['text'] ?? null, 3000) ?? ''; [$pn, $pb] = profile_pdf();
  $base = preg_replace('~/messages/\w+/?$~', '', $w['url'] ?: 'https://gate.whapi.cloud/messages/text');
  $ch = curl_init($base . '/messages/document');
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_HTTPHEADER => ['content-type: application/json', 'authorization: Bearer ' . $w['token']],
    CURLOPT_POSTFIELDS => json_encode(['to' => $d, 'media' => 'data:application/pdf;name=' . rawurlencode($pn) . ';base64,' . base64_encode($pb), 'filename' => $pn, 'caption' => $text])]);
  $r = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  if ($code < 200 || $code >= 300) fail('WhatsApp sender error (' . $code . '): ' . mb_substr(trim(strip_tags((string) $r)), 0, 160));
  out(['ok' => true]);

case 'expo_merge':   // admin: two names for the same expo — keep one, move everything from the other to it
  need_admin();
  $a = one('SELECT * FROM ' . T('expos') . ' WHERE id = ?', [(int) ($in['id'] ?? 0)]) ?? fail('Expo not found.', 404);
  $b = one('SELECT * FROM ' . T('expos') . ' WHERE id = ?', [(int) ($in['into'] ?? 0)]) ?? fail('Pick the expo to keep.');
  if ($a['id'] === $b['id']) fail('Pick a different expo to keep.');
  tx();
  q('UPDATE ' . T('projects') . ' SET expo = ? WHERE LOWER(TRIM(expo)) = LOWER(TRIM(?))', [$b['name'], $a['name']]);
  q('UPDATE ' . T('leads') . ' SET expo_id = ? WHERE expo_id = ?', [$b['id'], $a['id']]);
  $fill = array_filter(['edate' => $b['edate'] ? null : $a['edate'], 'end_date' => $b['end_date'] ? null : $a['end_date'], 'city' => $b['city'] ? null : $a['city'], 'venue' => $b['venue'] ? null : $a['venue'], 'website' => $b['website'] ? null : $a['website']]);
  if ($fill) upd('expos', $b['id'], $fill);
  q('DELETE FROM ' . T('expos') . ' WHERE id = ?', [$a['id']]);
  db()->commit(); out(['ok' => true]);

case 'expo_del':
  need_admin(); $id = (int) ($in['id'] ?? 0);
  if ($n = (int) val('SELECT COUNT(*) FROM ' . T('leads') . ' WHERE expo_id = ?', [$id])) fail("Can't remove — this expo has $n expo data entries. Use Merge to move them to the right expo.");
  q('DELETE FROM ' . T('expos') . ' WHERE id = ?', [$id]); out(['ok' => true]);

case 'vendor_del':
  need_admin(); q('UPDATE ' . T('vendors') . ' SET active = 0 WHERE id = ?', [(int) ($in['id'] ?? 0)]); out(['ok' => true]);

default:
  fail('Unknown action', 404);
}
