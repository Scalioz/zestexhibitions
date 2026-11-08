<?php
// Attendance API actions. Included from api.php for att_* actions.
if (!defined('ZEST_API')) exit;
require_once __DIR__ . '/att.php';

function att_req_row($id) { $r = one('SELECT * FROM ' . T('att_requests') . ' WHERE id = ?', [(int) $id]); if (!$r) fail('Request not found.', 404); return $r; }
function att_req_view($r, $um) {
  $u = one('SELECT * FROM ' . T('users') . ' WHERE id = ?', [$r['user_id']]);
  $r['name'] = $um[$r['user_id']] ?? '—'; $r['department'] = $u['department'] ?? ''; $r['label'] = att_kind_label($r);
  if ($r['kind'] === 'leave') $r['split'] = $r['status'] === 'approved' ? ['paid' => (float) $r['paid_days'], 'unpaid' => (float) $r['unpaid_days'], 'days' => (float) $r['paid_days'] + (float) $r['unpaid_days'], 'why' => []] : att_split($u, $r, $r['id']);
  $r['probation'] = $u && today() < att_probation_end($u) ? att_probation_end($u) : null;
  return $r;
}
function att_ip() { return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }

switch ($A) {

// ----- employee -----
case 'att_me':
  if ($ADMIN) out(['admin' => true]);
  $d = today(); $rec = one('SELECT * FROM ' . T('attendance') . ' WHERE user_id = ? AND adate = ?', [$MID, $d]);
  $reqs = att_reqs($d, $d, ['approved', 'pending'], $MID)[$MID] ?? [];
  $ym = preg_match('/^\d{4}-\d{2}$/', $in['month'] ?? '') ? $in['month'] : date('Y-m');
  $um = users_map(); $mine = array_map(fn($r) => att_req_view($r, $um), all('SELECT * FROM ' . T('att_requests') . ' WHERE user_id = ? ORDER BY id DESC LIMIT 60', [$MID]));
  q('UPDATE ' . T('att_requests') . " SET seen = 1 WHERE user_id = ? AND status IN ('approved','rejected') AND seen = 0", [$MID]);
  $usedPaid = (float) val('SELECT COALESCE(SUM(paid_days),0) FROM ' . T('att_requests') . " WHERE user_id = ? AND kind = 'leave' AND status = 'approved' AND from_date BETWEEN ? AND ?", [$MID, date('Y-m-01'), date('Y-m-t')]);
  out(['today' => att_status($me, $d, $rec, $reqs), 'tracked' => (bool) att_tracked_users($d) && ($me['joined_on'] ?: '2000-01-01') <= $d,
    'month' => att_month($me, $ym), 'ym' => $ym, 'requests' => $mine, 'probation_end' => today() < att_probation_end($me) ? att_probation_end($me) : null,
    'paid_used' => $usedPaid, 'paid_allow' => (float) att_cfg()['paid_per_month']]);

case 'att_in':
case 'att_out':
  if ($ADMIN) fail('Admin attendance is not tracked.');
  $d = today(); $c = att_cfg(); $ip = att_ip(); $off = $c['office_ips'] && !in_array($ip, $c['office_ips'], true) ? 1 : 0;
  $rec = one('SELECT * FROM ' . T('attendance') . ' WHERE user_id = ? AND adate = ?', [$MID, $d]);
  if ($A === 'att_in') {
    if ($rec && $rec['check_in']) fail('You already checked in today at ' . substr($rec['check_in'], 11, 5) . '.');
    try { ins('attendance', ['user_id' => $MID, 'adate' => $d, 'check_in' => now(), 'in_ip' => $ip, 'off_net' => $off, 'created_at' => now()]); }
    catch (PDOException $e) { if (!is_dup($e)) throw $e; fail('You already checked in today.'); }
    $al = (array) setting('att_alerted', []);
    if (($al['date'] ?? '') === $d && in_array($MID, $al['late'] ?? [], true)) {
      $st = att_status($me, $d, one('SELECT * FROM ' . T('attendance') . ' WHERE user_id = ? AND adate = ?', [$MID, $d]), att_reqs($d, $d, ['approved'], $MID)[$MID] ?? []);
      att_wa('✅ ' . $me['name'] . ' checked in at ' . date('h:i A') . ($st['late_min'] ? ' (' . $st['late_min'] . ' min late)' : ''));
    }
    out(['ok' => true, 'at' => date('H:i')]);
  }
  if (!$rec || !$rec['check_in']) fail('Check in first.');
  upd('attendance', $rec['id'], ['check_out' => now(), 'out_ip' => $ip]);
  out(['ok' => true, 'at' => date('H:i')]);

case 'att_apply':   // employee (or admin on someone's behalf, approved at once)
  $kind = in_array($in['kind'] ?? '', ['leave', 'permission', 'wfh'], true) ? $in['kind'] : fail('Pick leave, permission or work from home.');
  $uid = $MID; $byAdmin = false;
  if ($ADMIN) { $uid = (int) ($in['user_id'] ?? 0); $byAdmin = true; if (!$uid || $uid === $MID) fail('Pick the employee.'); }
  $u = one('SELECT * FROM ' . T('users') . ' WHERE id = ? AND active = 1', [$uid]) ?? fail('Employee not found.');
  $from = d($in['from_date'] ?? null) ?? fail('Pick the date.'); $to = $kind === 'permission' ? $from : (d($in['to_date'] ?? null) ?? $from);
  if ($to < $from) fail('The end date is before the start date.');
  if ((strtotime($to) - strtotime($from)) / 86400 > 60) fail('Please split requests longer than two months.');
  $half = $kind === 'leave' && in_array($in['half'] ?? '', ['first', 'second'], true) ? $in['half'] : '';
  if ($half && $to !== $from) fail('A half day is for one date only.');
  $type = $kind === 'leave' ? (in_array($in['leave_type'] ?? '', ['casual', 'sick', 'unpaid'], true) ? $in['leave_type'] : fail('Pick the leave type.')) : null;
  $ft = $kind === 'permission' ? (att_hm($in['from_time'] ?? '') ?? fail('Enter the time from.')) : null; $tt = $kind === 'permission' ? (att_hm($in['to_time'] ?? '') ?? fail('Enter the time to.')) : null;
  if ($ft && att_min($tt) <= att_min($ft)) fail('"To" time must be after "from" time.');
  $reason = s($in['reason'] ?? null, 1000) ?? ($byAdmin ? 'Marked by admin' : fail('Write the reason.'));
  if ($kind !== 'permission' && !att_days($from, $to)) fail('These dates are holidays / week offs — no leave needed.');
  if (val('SELECT COUNT(*) FROM ' . T('att_requests') . " WHERE user_id = ? AND kind = ? AND status IN ('pending','approved') AND from_date <= ? AND to_date >= ?", [$uid, $kind, $to, $from])) fail('There is already a request for these dates.');
  $row = ['user_id' => $uid, 'kind' => $kind, 'leave_type' => $type, 'from_date' => $from, 'to_date' => $to, 'half' => $half ?: null, 'from_time' => $ft, 'to_time' => $tt, 'reason' => $reason,
    'status' => 'pending', 'paid_days' => 0, 'unpaid_days' => 0, 'seen' => 0, 'created_at' => now()];
  tx();
  $id = ins('att_requests', $row);
  if ($byAdmin) { $sp = att_split($u, $row + ['id' => $id], $id); upd('att_requests', $id, ['status' => 'approved', 'paid_days' => $sp['paid'], 'unpaid_days' => $sp['unpaid'], 'decided_by' => $me['name'], 'decided_at' => now(), 'admin_note' => s($in['note'] ?? null, 500)]); }
  db()->commit();
  if (!$byAdmin) att_alert('Request: ' . $me['name'] . ' — ' . att_kind_label($row), $me['name'] . ' requested ' . att_kind_label($row) . ': ' . date('d M', strtotime($from)) . ($to !== $from ? ' to ' . date('d M', strtotime($to)) : '') . ($half ? " ($half half)" : '') . ($ft ? " $ft–$tt" : '') . "\nReason: $reason\nApprove or reject in the CRM → Attendance → Requests.");
  out(['id' => $id]);

case 'att_cancel':
  $r = att_req_row($in['id'] ?? 0);
  if ($ADMIN) { upd('att_requests', $r['id'], ['status' => 'cancelled', 'decided_by' => $me['name'], 'decided_at' => now(), 'admin_note' => s($in['note'] ?? null, 500) ?? 'Cancelled by admin']); out(['ok' => true]); }
  if ((int) $r['user_id'] !== $MID) fail('Not your request.', 403);
  if ($r['status'] !== 'pending') fail('Only admin can cancel a request after it is decided.');
  upd('att_requests', $r['id'], ['status' => 'cancelled']); out(['ok' => true]);

// ----- admin -----
case 'att_board':
  need_admin(); $d = d($in['date'] ?? null) ?? today();
  $cf = att_cfg(); $cf['wa']['token'] = $cf['wa']['token'] ? '••••' . substr($cf['wa']['token'], -4) : '';
  out(['date' => $d, 'rows' => att_board($d), 'pending' => (int) val('SELECT COUNT(*) FROM ' . T('att_requests') . " WHERE status = 'pending'"), 'cfg' => $cf, 'ip' => att_ip(), 'working' => is_working_day($d), 'from' => setting('att_from')]);

case 'att_requests':
  need_admin(); $st = in_array($in['status'] ?? '', ['pending', 'approved', 'rejected', 'cancelled'], true) ? $in['status'] : 'pending';
  $um = users_map();
  out(['rows' => array_map(fn($r) => att_req_view($r, $um), all('SELECT * FROM ' . T('att_requests') . ' WHERE status = ? ORDER BY ' . ($st === 'pending' ? 'from_date' : 'id DESC') . ' LIMIT 200', [$st])),
    'counts' => array_column(all('SELECT status, COUNT(*) AS n FROM ' . T('att_requests') . ' GROUP BY status'), 'n', 'status')]);

case 'att_decide':
  need_admin(); tx(); lock_row('att_requests', $in['id'] ?? 0); $r = att_req_row($in['id'] ?? 0);
  if ($r['status'] !== 'pending') fail('This request is already ' . $r['status'] . '.');
  $note = s($in['note'] ?? null, 500);
  if (($in['action'] ?? '') === 'reject') { if (!$note) fail('Write the reason for rejecting.'); upd('att_requests', $r['id'], ['status' => 'rejected', 'admin_note' => $note, 'decided_by' => $me['name'], 'decided_at' => now(), 'seen' => 0]); db()->commit(); out(['ok' => true]); }
  if (($in['action'] ?? '') !== 'approve') fail('Pick approve or reject.');
  $u = one('SELECT * FROM ' . T('users') . ' WHERE id = ?', [$r['user_id']]); $sp = att_split($u, $r, $r['id']);
  upd('att_requests', $r['id'], ['status' => 'approved', 'paid_days' => $sp['paid'], 'unpaid_days' => $sp['unpaid'], 'admin_note' => $note, 'decided_by' => $me['name'], 'decided_at' => now(), 'seen' => 0]);
  db()->commit(); out(['ok' => true]);

case 'att_month':
  need_admin(); $ym = preg_match('/^\d{4}-\d{2}$/', $in['month'] ?? '') ? $in['month'] : date('Y-m');
  $rows = []; foreach (all('SELECT * FROM ' . T('users') . " WHERE active = 1 AND role = 'employee' ORDER BY name") as $u) { $m = att_month($u, $ym); if (!empty($in['user_id']) && (int) $in['user_id'] !== (int) $u['id']) continue;
    $rows[] = ['user' => ['id' => (int) $u['id'], 'name' => $u['name'], 'department' => $u['department'], 'shift' => implode('–', att_shift($u)), 'probation_end' => today() < att_probation_end($u) ? att_probation_end($u) : null]] + (!empty($in['user_id']) ? $m : array_diff_key($m, ['days' => 1])); }
  out(['ym' => $ym, 'rows' => $rows]);

case 'att_set':   // admin corrects a check-in / check-out
  need_admin(); $uid = (int) ($in['user_id'] ?? 0); $d = d($in['date'] ?? null) ?? fail('Pick the date.');
  $tin = att_hm($in['in'] ?? ''); $tout = att_hm($in['out'] ?? '');
  if ($tin && $tout && att_min($tout) <= att_min($tin)) fail('Check-out must be after check-in.');
  $rec = one('SELECT * FROM ' . T('attendance') . ' WHERE user_id = ? AND adate = ?', [$uid, $d]);
  $data = ['check_in' => $tin ? "$d $tin:00" : null, 'check_out' => $tout ? "$d $tout:00" : null, 'note' => trim(($rec['note'] ?? '') . "\nEdited by " . $me['name'] . ' on ' . date('d M H:i') . (s($in['note'] ?? null, 300) ? ': ' . s($in['note'], 300) : ''))];
  if ($rec) upd('attendance', $rec['id'], $data); elseif ($tin) ins('attendance', $data + ['user_id' => $uid, 'adate' => $d, 'created_at' => now()]);
  out(['ok' => true]);

case 'att_settings':
  need_admin(); $c = att_cfg();
  foreach (['start', 'end'] as $k) if (isset($in[$k])) $c[$k] = att_hm($in[$k]) ?? fail('Enter shift times like 10:00.');
  if (att_min($c['end']) <= att_min($c['start'])) fail('Shift end must be after shift start.');
  if (isset($in['buffer'])) $c['buffer'] = max(0, min(120, (int) $in['buffer']));
  if (isset($in['email'])) $c['email'] = $in['email'] ? 1 : 0;
  if (isset($in['office_ips'])) $c['office_ips'] = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $in['office_ips']))));
  if (!empty($in['add_my_ip'])) $c['office_ips'] = array_values(array_unique(array_merge($c['office_ips'], [att_ip()])));
  if (isset($in['wa'])) { $w = (array) $in['wa']; $c['wa'] = ['on' => !empty($w['on']) ? 1 : 0, 'url' => s($w['url'] ?? null, 300) ?? $c['wa']['url'], 'token' => ($w['token'] ?? '') === '' ? $c['wa']['token'] : s($w['token'], 400), 'group' => s($w['group'] ?? null, 120) ?? '']; }
  set_setting('att_cfg', $c); out(['ok' => true, 'ip' => att_ip()]);

case 'att_test':
  need_admin();
  $what = $in['what'] ?? 'email';
  if ($what === 'wa') out(['ok' => att_wa('✅ Test from Zest CRM — attendance alerts will appear here.')]);
  out(['ok' => att_mail('Zest CRM test email', 'This is a test. Attendance alerts will arrive at this address.')]);
}
fail('Unknown action', 404);
