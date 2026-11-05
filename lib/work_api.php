<?php
// Department work: expo data (research + telecalling), design jobs, production jobs.
// Included from api.php after login, CSRF and helpers. Every case ends with out() or fail().
if (!defined('ZEST_API')) exit;

const UNITS = ['ft', 'm', 'in', 'cm', 'mm'];

function lead_dept_ok() { global $ADMIN, $MODULE; if (!$ADMIN && !in_array($MODULE, ['leads', 'calls'], true)) fail('Your department does not have expo data.', 403); }
function expo_row($id) { $e = one('SELECT * FROM ' . T('expos') . ' WHERE id = ?', [(int) $id]); if (!$e) fail('Expo not found.', 404); return $e; }
function clean_web($v) { $v = s($v, 255); if ($v && !preg_match('#^https?://#i', $v)) $v = 'https://' . $v; return $v; }
function clean_email($v) { $v = strtolower(s($v, 160) ?? ''); if ($v && !filter_var($v, FILTER_VALIDATE_EMAIL)) fail("Email looks wrong: $v"); return $v ?: null; }
function lead_dup($expoId, $dept, $company, $skipId = 0) {
  return one('SELECT l.id, l.created_at, u.name FROM ' . T('leads') . ' l LEFT JOIN ' . T('users') . ' u ON u.id = l.user_id WHERE l.expo_id = ? AND l.dept = ? AND LOWER(l.company) = LOWER(?) AND l.id <> ?',
    [(int) $expoId, $dept, $company, (int) $skipId]);
}
// project access for design / production work
function work_project($id, $who) {
  global $ADMIN, $MID, $RECORDS;
  $p = one('SELECT p.*, c.company FROM ' . T('projects') . ' p JOIN ' . T('clients') . ' c ON c.id = p.client_id WHERE p.id = ?', [(int) $id]);
  if (!$p) fail('Job not found.', 404);
  $ok = $ADMIN || ($RECORDS && (int) $p['owner_id'] === $MID) || ($who === 'design' && (int) $p['designer_id'] === $MID) || ($who === 'ops' && (int) $p['ops_id'] === $MID);
  if (!$ok) fail('This job is not assigned to you.', 403);
  return $p;
}
function chosen_vendor($pid) {
  return one('SELECT v.name, q.amount FROM ' . T('vendor_quotes') . ' q JOIN ' . T('vendors') . ' v ON v.id = q.vendor_id WHERE q.project_id = ? AND q.selected = 1', [(int) $pid]);
}
function ops_clean($d) {
  $d = (array) $d; $o = [];
  $dim = function ($x) { $x = (array) $x; $v = ($x['v'] ?? '') === '' ? null : n($x['v']); $u = in_array($x['u'] ?? '', UNITS, true) ? $x['u'] : 'ft'; return ['v' => $v, 'u' => $u]; };
  foreach (['length', 'width', 'wall_h', 'fascia_h'] as $k) $o[$k] = $dim($d[$k] ?? []);
  $o['vendor_cost'] = ($d['vendor_cost'] ?? '') === '' ? null : n($d['vendor_cost']);
  $o['furniture'] = [];
  foreach (array_slice((array) ($d['furniture'] ?? []), 0, 60) as $f) { $it = s(((array) $f)['item'] ?? null, 80); if ($it) $o['furniture'][] = ['item' => $it, 'qty' => max(0, (int) (((array) $f)['qty'] ?? 1))]; }
  $o['backlit_walls'] = max(0, (int) ($d['backlit_walls'] ?? 0));
  $o['vinyl_total'] = min(50, max(0, (int) ($d['vinyl_total'] ?? 0)));
  $o['vinyl'] = [];
  foreach (array_slice((array) ($d['vinyl'] ?? []), 0, 50) as $a) { $a = (array) $a;
    $o['vinyl'][] = ['w' => ($a['w'] ?? '') === '' ? null : n($a['w']), 'wu' => in_array($a['wu'] ?? '', UNITS, true) ? $a['wu'] : 'ft',
                     'h' => ($a['h'] ?? '') === '' ? null : n($a['h']), 'hu' => in_array($a['hu'] ?? '', UNITS, true) ? $a['hu'] : 'ft'];
  }
  $o['notes'] = s($d['notes'] ?? null, 4000);
  return $o;
}

switch ($A) {

// ================= EXPO DATA (research + telecalling) =================
case 'lexpos':
  lead_dept_ok();
  $w = '1=1'; $p = [];
  if (!$ADMIN) { $w .= ' AND l.user_id = ?'; $p[] = $MID; }
  if ($ADMIN && !empty($in['user_id'])) { $w .= ' AND l.user_id = ?'; $p[] = (int) $in['user_id']; }
  if ($ADMIN && s($in['dept'] ?? null)) { $w .= ' AND l.dept = ?'; $p[] = s($in['dept']); }
  if ($fr = d($in['from'] ?? null)) { $w .= ' AND l.created_at >= ?'; $p[] = "$fr 00:00:00"; }
  if ($to = d($in['to'] ?? null)) { $w .= ' AND l.created_at <= ?'; $p[] = "$to 23:59:59"; }
  $agg = all('SELECT l.expo_id, l.user_id, COUNT(*) AS n, MAX(l.created_at) AS last FROM ' . T('leads') . " l WHERE $w GROUP BY l.expo_id, l.user_id", $p);
  $um = users_map(); $by = [];
  foreach ($agg as $r) { $e = &$by[$r['expo_id']]; $e = $e ?? ['n' => 0, 'last' => '', 'by' => []]; $e['n'] += $r['n']; $e['last'] = max($e['last'], $r['last']); $e['by'][] = ['name' => $um[$r['user_id']] ?? '—', 'n' => (int) $r['n']]; unset($e); }
  $filtered = !empty($in['user_id']) || s($in['dept'] ?? null) || d($in['from'] ?? null) || d($in['to'] ?? null);
  $ew = '1=0'; $ep = [];
  if ($by) $ew = 'id IN (' . implode(',', array_map('intval', array_keys($by))) . ')';
  if (!$ADMIN) { $ew .= ' OR created_by = ?'; $ep[] = $MID; } elseif (!$filtered) $ew = '1=1';
  $qs = s($in['q'] ?? null, 100);
  $sql = 'SELECT * FROM ' . T('expos') . " WHERE ($ew)" . ($qs ? ' AND (name LIKE ? OR city LIKE ?)' : '');
  if ($qs) { $ep[] = "%$qs%"; $ep[] = "%$qs%"; }
  $ex = all($sql, $ep);
  foreach ($ex as &$e) { $a = $by[$e['id']] ?? ['n' => 0, 'last' => $e['created_at'], 'by' => []]; $e += ['entries' => $a['n'], 'last' => $a['last'] ?: $e['created_at'], 'by' => $a['by'], 'created_by_name' => $um[$e['created_by']] ?? '—']; } unset($e);
  usort($ex, fn($a, $b) => strcmp($b['last'], $a['last']));
  $people = [];
  if ($ADMIN) {
    $mods = setting('dept_modules', []);
    foreach (all('SELECT id, name, department FROM ' . T('users') . " WHERE active = 1 AND role = 'employee' ORDER BY name") as $u) {
      if (!in_array($mods[$u['department']] ?? 'none', ['leads', 'calls'], true)) continue;
      $people[] = ['id' => $u['id'], 'name' => $u['name'], 'dept' => $u['department'],
        'today' => (int) val('SELECT COUNT(*) FROM ' . T('leads') . ' WHERE user_id = ? AND created_at >= ?', [$u['id'], today() . ' 00:00:00']),
        'month' => (int) val('SELECT COUNT(*) FROM ' . T('leads') . ' WHERE user_id = ? AND created_at >= ?', [$u['id'], date('Y-m-01') . ' 00:00:00']),
        'total' => (int) val('SELECT COUNT(*) FROM ' . T('leads') . ' WHERE user_id = ?', [$u['id']])];
    }
  }
  out(['expos' => $ex, 'people' => $people, 'all_expos' => all('SELECT id, name, edate, city FROM ' . T('expos') . ' ORDER BY name LIMIT 1000')]);

case 'lexpo_save':
  lead_dept_ok();
  $data = ['name' => s($in['name'] ?? null, 200), 'edate' => d($in['edate'] ?? null), 'city' => s($in['city'] ?? null, 80), 'website' => clean_web($in['website'] ?? null)];
  if (!$data['name']) fail('Enter the expo name.');
  if (!empty($in['id'])) {
    $e = expo_row($in['id']);
    if (!$ADMIN && (int) $e['created_by'] !== $MID) fail('Only the person who added this expo, or admin, can change it.', 403);
    upd('expos', $e['id'], $data); touch_activity(); out(['id' => (int) $e['id']]);
  }
  // same expo already added by someone? reuse it so everyone's entries sit together
  $ex = $data['edate']
    ? one('SELECT * FROM ' . T('expos') . ' WHERE LOWER(name) = LOWER(?) AND (edate = ? OR edate IS NULL)', [$data['name'], $data['edate']])
    : one('SELECT * FROM ' . T('expos') . ' WHERE LOWER(name) = LOWER(?) ORDER BY edate DESC', [$data['name']]);
  if ($ex) { upd('expos', $ex['id'], array_filter(['edate' => $ex['edate'] ? null : $data['edate'], 'city' => $ex['city'] ? null : $data['city'], 'website' => $ex['website'] ? null : $data['website']])); out(['id' => (int) $ex['id'], 'existing' => true]); }
  $id = ins('expos', $data + ['created_by' => $MID, 'created_at' => now()]); touch_activity();
  out(['id' => $id]);

case 'lexpo':
  lead_dept_ok();
  $e = expo_row($in['id'] ?? 0);
  $w = 'l.expo_id = ?'; $p = [$e['id']];
  if (!$ADMIN) { $w .= ' AND l.user_id = ?'; $p[] = $MID; }
  elseif (!empty($in['user_id'])) { $w .= ' AND l.user_id = ?'; $p[] = (int) $in['user_id']; }
  $rows = all('SELECT l.*, u.name AS entered_by FROM ' . T('leads') . ' l LEFT JOIN ' . T('users') . " u ON u.id = l.user_id WHERE $w ORDER BY l.id", $p);
  $e['created_by_name'] = users_map()[$e['created_by']] ?? '—';
  out(['expo' => $e, 'entries' => $rows, 'can_edit_expo' => $ADMIN || (int) $e['created_by'] === $MID]);

case 'lead_add':
  lead_dept_ok();
  $e = expo_row($in['expo_id'] ?? 0);
  $row = ['company' => s($in['company'] ?? null, 200), 'person' => s($in['person'] ?? null, 120), 'phone' => s($in['phone'] ?? null, 60), 'email' => clean_email($in['email'] ?? null), 'notes' => s($in['notes'] ?? null, 4000)];
  if (!$row['company']) fail('Enter the company name.');
  if ($dup = lead_dup($e['id'], $me['department'], $row['company'])) fail("{$row['company']} is already in this expo list (added by {$dup['name']} on " . date('d M', strtotime($dup['created_at'])) . ').');
  $id = ins('leads', $row + ['expo_id' => $e['id'], 'user_id' => $MID, 'dept' => $me['department'], 'created_at' => now()]);
  touch_activity(); out(['id' => $id]);

case 'lead_bulk':   // rows pasted from Excel: company, contact, phone, email, notes
  lead_dept_ok();
  $e = expo_row($in['expo_id'] ?? 0);
  $rows = array_slice((array) ($in['rows'] ?? []), 0, 2000);
  $added = 0; $skipped = []; $seen = [];
  tx();
  foreach ($rows as $r) {
    $r = array_values((array) $r); $co = s($r[0] ?? null, 200); if (!$co) continue;
    $key = mb_strtolower($co);
    if (isset($seen[$key]) || lead_dup($e['id'], $me['department'], $co)) { $skipped[] = $co; continue; }
    $em = strtolower(s($r[3] ?? null, 160) ?? ''); if ($em && !filter_var($em, FILTER_VALIDATE_EMAIL)) $em = '';
    ins('leads', ['expo_id' => $e['id'], 'user_id' => $MID, 'dept' => $me['department'], 'company' => $co, 'person' => s($r[1] ?? null, 120),
      'phone' => s($r[2] ?? null, 60), 'email' => $em ?: null, 'notes' => s($r[4] ?? null, 4000), 'created_at' => now()]);
    $seen[$key] = 1; $added++;
  }
  db()->commit();
  if ($added) touch_activity();
  out(['added' => $added, 'skipped' => $skipped]);

case 'lead_update':
  lead_dept_ok();
  $l = one('SELECT * FROM ' . T('leads') . ' WHERE id = ?', [(int) ($in['id'] ?? 0)]); if (!$l) fail('Entry not found.', 404);
  if (!$ADMIN && (int) $l['user_id'] !== $MID) fail('You can only change your own entries.', 403);
  $row = ['company' => s($in['company'] ?? null, 200), 'person' => s($in['person'] ?? null, 120), 'phone' => s($in['phone'] ?? null, 60), 'email' => clean_email($in['email'] ?? null), 'notes' => s($in['notes'] ?? null, 4000), 'updated_at' => now()];
  if (!$row['company']) fail('Company name cannot be empty.');
  if ($dup = lead_dup($l['expo_id'], $l['dept'], $row['company'], $l['id'])) fail("{$row['company']} is already in this expo list (added by {$dup['name']}).");
  upd('leads', $l['id'], $row);
  touch_activity(); out(['ok' => true]);

case 'lead_del':
  lead_dept_ok();
  $l = one('SELECT * FROM ' . T('leads') . ' WHERE id = ?', [(int) ($in['id'] ?? 0)]); if (!$l) fail('Entry not found.', 404);
  if (!$ADMIN && !((int) $l['user_id'] === $MID && substr($l['created_at'], 0, 10) === today())) fail('You can delete only entries added today. Ask admin for older ones.', 403);
  q('DELETE FROM ' . T('leads') . ' WHERE id = ?', [$l['id']]);
  out(['ok' => true]);

// ================= DESIGN JOBS =================
case 'design_jobs':
  if (!$ADMIN && $MODULE !== 'design') fail('Your department does not have design jobs.', 403);
  $rows = all('SELECT p.id, p.expo, p.location, p.start_date, p.end_date, p.area_sqm, p.space_type, p.sides_open, p.stall_no, p.status, p.designer_id, p.design_assigned_by, p.assigned_on, p.design_submitted_on, p.design_notes, p.design_approved_by, c.company
    FROM ' . T('projects') . ' p JOIN ' . T('clients') . ' c ON c.id = p.client_id WHERE p.designer_id = ? ORDER BY (p.design_submitted_on IS NOT NULL), p.assigned_on DESC, p.id DESC', [$MID]);
  $ids = array_column($rows, 'id');
  $F = []; foreach (['final', 'wd', 'floor', 'pbrief'] as $k) $F[$k] = files_of($k, $ids);
  foreach ($rows as &$r) foreach ($F as $k => $g) $r['files'][$k] = $g[$r['id']] ?? []; unset($r);
  out(['jobs' => $rows]);

case 'design_save':
  $p = work_project($in['id'] ?? 0, 'design');
  if (!$ADMIN && (int) $p['designer_id'] !== $MID) fail('This job is not assigned to you.', 403);
  upd('projects', $p['id'], ['design_submitted_on' => d($in['submitted_on'] ?? null), 'design_notes' => s($in['notes'] ?? null, 4000)]);
  touch_activity(); out(['ok' => true]);

case 'assign_designer':
case 'assign_ops':
  need_admin();
  $p = one('SELECT * FROM ' . T('projects') . ' WHERE id = ?', [(int) ($in['project_id'] ?? 0)]); if (!$p) fail('Order not found.', 404);
  $uid = (int) ($in['user_id'] ?? 0);
  $u = $uid ? one('SELECT id, name FROM ' . T('users') . ' WHERE id = ? AND active = 1', [$uid]) : null;
  if ($uid && !$u) fail('Pick an active employee.');
  if ($A === 'assign_designer') {
    upd('projects', $p['id'], $u ? ['designer_id' => $u['id'], 'designed_by' => $u['name'], 'assigned_on' => (int) $p['designer_id'] === (int) $u['id'] && $p['assigned_on'] ? $p['assigned_on'] : today(), 'design_assigned_by' => $me['name']]
                                 : ['designer_id' => null, 'design_assigned_by' => null]);
  } else {
    upd('projects', $p['id'], $u ? ['ops_id' => $u['id'], 'ops_assigned_by' => $me['name'], 'ops_assigned_on' => (int) $p['ops_id'] === (int) $u['id'] && $p['ops_assigned_on'] ? $p['ops_assigned_on'] : today()]
                                 : ['ops_id' => null, 'ops_assigned_by' => null, 'ops_assigned_on' => null]);
  }
  out(['ok' => true]);

// ================= PRODUCTION JOBS =================
case 'ops_jobs':
  if (!$ADMIN && $MODULE !== 'ops') fail('Your department does not have production jobs.', 403);
  $rows = all('SELECT p.id, p.expo, p.location, p.start_date, p.area_sqm, p.sides_open, p.stall_no, p.status, p.ops_assigned_by, p.ops_assigned_on, p.ops_updated_at, c.company
    FROM ' . T('projects') . ' p JOIN ' . T('clients') . ' c ON c.id = p.client_id WHERE p.ops_id = ? ORDER BY COALESCE(p.start_date, p.created_at) DESC', [$MID]);
  foreach ($rows as &$r) $r['vendor'] = chosen_vendor($r['id']); unset($r);
  out(['jobs' => $rows]);

case 'ops_job':
  $p = work_project($in['id'] ?? 0, 'ops');
  $um = users_map();
  out(['job' => ['id' => $p['id'], 'expo' => $p['expo'], 'location' => $p['location'], 'start_date' => $p['start_date'], 'company' => $p['company'], 'area_sqm' => $p['area_sqm'],
    'sides_open' => $p['sides_open'], 'stall_no' => $p['stall_no'], 'space_type' => $p['space_type'], 'status' => $p['status'],
    'ops_person' => $um[$p['ops_id']] ?? null, 'ops_assigned_by' => $p['ops_assigned_by'], 'ops_assigned_on' => $p['ops_assigned_on'], 'ops_updated_at' => $p['ops_updated_at'],
    'vendor' => chosen_vendor($p['id']), 'ops' => json_decode($p['ops_json'] ?? '', true) ?: null,
    'files' => ['final' => files_of('final', [$p['id']])[$p['id']] ?? [], 'wd' => files_of('wd', [$p['id']])[$p['id']] ?? []]]]);

case 'ops_save':
  $p = work_project($in['id'] ?? 0, 'ops');
  upd('projects', $p['id'], ['ops_json' => json_encode(ops_clean($in['ops'] ?? []), JSON_UNESCAPED_UNICODE), 'ops_updated_at' => now()]);
  touch_activity(); out(['ok' => true]);

// ================= ADMIN: design & production board =================
case 'jobs_board':
  need_admin();
  $w = "p.status <> 'rejected'"; $p = [];
  if ($qs = s($in['q'] ?? null, 100)) { $w .= ' AND (c.company LIKE ? OR p.expo LIKE ?)'; $p[] = "%$qs%"; $p[] = "%$qs%"; }
  if (($in['show'] ?? '') === 'open') $w .= " AND p.status <> 'completed'";
  $rows = all('SELECT p.id, p.expo, p.location, p.start_date, p.area_sqm, p.sides_open, p.status, p.owner_id, p.designer_id, p.designed_by, p.design_assigned_by, p.assigned_on, p.design_submitted_on, p.design_notes, p.design_approved_by,
     p.ops_id, p.ops_assigned_on, p.ops_updated_at, p.ops_json, c.company FROM ' . T('projects') . ' p JOIN ' . T('clients') . " c ON c.id = p.client_id WHERE $w ORDER BY COALESCE(p.start_date, p.created_at) DESC LIMIT 300", $p);
  $ids = array_column($rows, 'id'); $fin = files_of('final', $ids); $wd = files_of('wd', $ids); $um = users_map();
  foreach ($rows as &$r) {
    $r['owner'] = $um[$r['owner_id']] ?? '—'; $r['designer'] = $um[$r['designer_id']] ?? null; $r['ops_person'] = $um[$r['ops_id']] ?? null;
    $r['final_n'] = count($fin[$r['id']] ?? []); $r['wd_n'] = count($wd[$r['id']] ?? []); $r['vendor'] = chosen_vendor($r['id']);
    $o = json_decode($r['ops_json'] ?? '', true); $r['ops_filled'] = (bool) $o; unset($r['ops_json']);
  }
  unset($r);
  out(['jobs' => $rows]);
}
fail('Unknown action', 404);
