<?php
// Attendance: check in / check out, leave, permission and work-from-home requests, the late alert.
// Rules (Zest): 1 paid leave (casual or sick) per calendar month after a 6-month probation; anything more is unpaid;
// during probation every leave is unpaid; unused leave does not carry over. Only admin approves.

function att_cfg() {
  return array_replace(['start' => '10:00', 'end' => '19:00', 'buffer' => 10, 'email' => 1, 'office_ips' => [], 'probation_months' => 6, 'paid_per_month' => 1,
    'wa' => ['on' => 0, 'url' => 'https://gate.whapi.cloud/messages/text', 'token' => '', 'group' => '']], (array) setting('att_cfg', []));
}
function att_hm($t) { return preg_match('/^([01]?\d|2[0-3]):([0-5]\d)/', (string) $t, $m) ? sprintf('%02d:%02d', $m[1], $m[2]) : null; }
function att_min($hm) { [$h, $m] = array_map('intval', explode(':', $hm)); return $h * 60 + $m; }
function att_fmt($min) { return sprintf('%02d:%02d', intdiv($min, 60), $min % 60); }
function att_shift($u) { $c = att_cfg(); return [att_hm($u['shift_start'] ?? '') ?: $c['start'], att_hm($u['shift_end'] ?? '') ?: $c['end']]; }
function att_probation_end($u) { return date('Y-m-d', strtotime(($u['joined_on'] ?: today()) . ' +' . (int) att_cfg()['probation_months'] . ' months')); }
function att_tracked_users($date = null) {   // employees whose attendance is tracked on this date
  $date = $date ?: today(); $from = setting('att_from', today());
  if ($date < $from) return [];
  return array_values(array_filter(all('SELECT * FROM ' . T('users') . " WHERE active = 1 AND role = 'employee' ORDER BY name"), fn($u) => ($u['joined_on'] ?: '2000-01-01') <= $date));
}
function att_days($from, $to) { $o = []; for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) if (is_working_day($d)) $o[] = $d; return $o; }

// requests touching a date range, grouped by user
function att_reqs($from, $to, $statuses = ['approved', 'pending'], $uid = null) {
  $in = implode(',', array_map(fn($s) => "'" . preg_replace('/[^a-z]/', '', $s) . "'", $statuses));
  $p = [$to, $from]; $w = ''; if ($uid) { $w = ' AND user_id = ?'; $p[] = (int) $uid; }
  $g = []; foreach (all('SELECT * FROM ' . T('att_requests') . " WHERE status IN ($in) AND from_date <= ? AND to_date >= ?$w ORDER BY id", $p) as $r) $g[$r['user_id']][] = $r;
  return $g;
}

// What is expected of a person on a date and what happened. $rec = attendance row or null, $reqs = their requests.
function att_status($u, $date, $rec, $reqs) {
  $c = att_cfg(); [$s, $e] = att_shift($u); $exp = att_min($s); $end = att_min($e); $buf = (int) $c['buffer'];
  $o = ['date' => $date, 'expected' => $s, 'shift' => "$s–$e", 'in' => $rec ? substr($rec['check_in'] ?? '', 11, 5) : '', 'out' => $rec && $rec['check_out'] ? substr($rec['check_out'], 11, 5) : '',
    'wfh' => false, 'notes' => [], 'late_min' => 0, 'off_net' => $rec ? (int) ($rec['off_net'] ?? 0) : 0];
  if (!is_working_day($date)) return $o + ['code' => 'off', 'label' => 'Holiday / week off'];
  $pending = null;
  foreach ($reqs as $r) {
    if ($r['from_date'] > $date || $r['to_date'] < $date) continue;
    if ($r['status'] === 'pending') { $pending = $pending ?: $r; continue; }
    if ($r['kind'] === 'leave') {
      if (!$r['half']) return $o + ['code' => 'leave', 'label' => att_type_label($r['leave_type']) . ' leave', 'req' => (int) $r['id']];
      $mid = intdiv($exp + $end, 2);
      if ($r['half'] === 'first') { $exp = $mid; $o['notes'][] = 'Half-day leave (first half)'; } else { $end = $mid; $o['notes'][] = 'Half-day leave (second half)'; }
    } elseif ($r['kind'] === 'permission') {
      $pf = att_min(att_hm($r['from_time']) ?: $s); $pt = att_min(att_hm($r['to_time']) ?: $s);
      if ($pf <= $exp + $buf) $exp = max($exp, $pt);
      $o['notes'][] = 'Permission ' . att_fmt($pf) . '–' . att_fmt($pt);
    } elseif ($r['kind'] === 'wfh') { $o['wfh'] = true; $o['notes'][] = 'Work from home'; }
  }
  $o['expected'] = att_fmt($exp);
  if ($rec && $rec['check_in']) {
    $in = att_min(substr($rec['check_in'], 11, 5)); $o['late_min'] = max(0, $in - $exp);
    $code = $o['late_min'] > $buf ? 'late' : 'ontime';
    if (!$rec['check_out'] && ($date < today() || ($date === today() && (int) date('H') * 60 + (int) date('i') > $end + 30))) $o['notes'][] = 'Forgot to check out';
    return $o + ['code' => $code, 'label' => $code === 'late' ? 'Late by ' . ($o['late_min'] >= 60 ? intdiv($o['late_min'], 60) . ' h ' . ($o['late_min'] % 60) . ' min' : $o['late_min'] . ' min') : 'On time'];
  }
  $nowMin = (int) date('H') * 60 + (int) date('i');
  if ($pending) return $o + ['code' => 'requested', 'label' => att_kind_label($pending) . ' requested — not decided', 'req' => (int) $pending['id']];
  if ($date < today() || $nowMin > $exp + $buf) return $o + ['code' => 'absent', 'label' => $date < today() ? 'Absent (no check-in)' : 'Not checked in'];
  return $o + ['code' => 'notyet', 'label' => 'Expected by ' . att_fmt($exp + $buf)];
}
function att_type_label($t) { return ['casual' => 'Casual', 'sick' => 'Sick', 'unpaid' => 'Unpaid'][$t] ?? 'Leave'; }
function att_kind_label($r) { return $r['kind'] === 'leave' ? att_type_label($r['leave_type']) . ' leave' : ($r['kind'] === 'permission' ? 'Permission' : 'Work from home'); }

// How many days of a leave request are paid and how many are unpaid (deductible).
function att_split($u, $r, $ignoreId = null) {
  $days = att_days($r['from_date'], $r['to_date']); $per = $r['half'] ? 0.5 : 1.0;
  $paid = 0; $unpaid = 0; $why = [];
  if ($r['kind'] !== 'leave') return ['paid' => 0, 'unpaid' => 0, 'days' => 0, 'why' => []];
  $prob = att_probation_end($u); $allow = (float) att_cfg()['paid_per_month'];
  $usedBy = [];   // paid days already used in each month
  foreach (all('SELECT * FROM ' . T('att_requests') . " WHERE user_id = ? AND kind = 'leave' AND status = 'approved'" . ($ignoreId ? ' AND id <> ' . (int) $ignoreId : ''), [$u['id']]) as $o) {
    $left = (float) $o['paid_days'];
    foreach (att_days($o['from_date'], $o['to_date']) as $d) { if ($left <= 0) break; $v = min($left, $o['half'] ? 0.5 : 1.0); $usedBy[substr($d, 0, 7)] = ($usedBy[substr($d, 0, 7)] ?? 0) + $v; $left -= $v; }
  }
  foreach ($days as $d) {
    $m = substr($d, 0, 7);
    if ($r['leave_type'] === 'unpaid') { $unpaid += $per; continue; }
    if ($d < $prob) { $unpaid += $per; $why['prob'] = 'In probation until ' . date('d M Y', strtotime($prob)) . ' — leave is unpaid'; continue; }
    $free = max(0, $allow - ($usedBy[$m] ?? 0)); $p = min($free, $per);
    $paid += $p; $unpaid += $per - $p; $usedBy[$m] = ($usedBy[$m] ?? 0) + $p;
    if ($per - $p > 0) $why[$m] = 'Paid leave for ' . date('M Y', strtotime($d)) . ' already used — extra days are unpaid';
  }
  return ['paid' => $paid, 'unpaid' => $unpaid, 'days' => count($days) * $per, 'why' => array_values($why)];
}

// Today's (or any date's) board for admin
function att_board($date) {
  $users = att_tracked_users($date); if (!$users) return [];
  $recs = []; foreach (all('SELECT * FROM ' . T('attendance') . ' WHERE adate = ?', [$date]) as $r) $recs[$r['user_id']] = $r;
  $reqs = att_reqs($date, $date);
  return array_map(fn($u) => ['user' => ['id' => (int) $u['id'], 'name' => $u['name'], 'department' => $u['department']]] + att_status($u, $date, $recs[$u['id']] ?? null, $reqs[$u['id']] ?? []), $users);
}

// Month summary per person (for the sheet and the employee's own page)
function att_month($u, $ym) {
  $from = "$ym-01"; $to = min(date('Y-m-t', strtotime($from)), today()); $start = max($from, setting('att_from', today()), $u['joined_on'] ?: $from);
  $recs = []; foreach (all('SELECT * FROM ' . T('attendance') . ' WHERE user_id = ? AND adate BETWEEN ? AND ?', [$u['id'], $from, date('Y-m-t', strtotime($from))]) as $r) $recs[$r['adate']] = $r;
  $reqs = att_reqs($from, date('Y-m-t', strtotime($from)), ['approved', 'pending'], $u['id'])[$u['id']] ?? [];
  $S = ['working' => 0, 'present' => 0, 'late' => 0, 'absent' => 0, 'leave' => 0, 'half' => 0, 'permission' => 0, 'wfh' => 0, 'no_out' => 0, 'paid' => 0, 'unpaid' => 0, 'days' => []];
  for ($d = $start; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
    $st = att_status($u, $d, $recs[$d] ?? null, $reqs); $S['days'][] = $st;
    if ($st['code'] === 'off') continue;
    $S['working']++;
    if (in_array($st['code'], ['ontime', 'late'], true)) $S['present']++;
    if ($st['code'] === 'late') $S['late']++;
    if (in_array($st['code'], ['absent'], true) && $d < today()) $S['absent']++;
    if ($st['code'] === 'leave') $S['leave']++;
    foreach ($st['notes'] as $n) { if (str_starts_with($n, 'Half-day')) $S['half']++; if (str_starts_with($n, 'Permission')) $S['permission']++; if ($n === 'Work from home') $S['wfh']++; if ($n === 'Forgot to check out') $S['no_out']++; }
  }
  foreach (all('SELECT paid_days, unpaid_days, from_date FROM ' . T('att_requests') . " WHERE user_id = ? AND kind = 'leave' AND status = 'approved' AND from_date BETWEEN ? AND ?", [$u['id'], $from, date('Y-m-t', strtotime($from))]) as $r) { $S['paid'] += (float) $r['paid_days']; $S['unpaid'] += (float) $r['unpaid_days']; }
  return $S;
}

// ---------- alerts: email to admins, optional WhatsApp group ----------
function att_mail($subject, $body) {
  if (!function_exists('mail')) return false;
  $to = array_column(all('SELECT email FROM ' . T('users') . " WHERE role = 'admin' AND active = 1"), 'email'); if (!$to) return false;
  $host = preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'zestexhibitions.com'); $host = preg_replace('/:\d+$/', '', $host);
  $reply = (setting('company', [])['email'] ?? '') ?: 'info@zestexhibitions.com';
  $h = "From: Zest CRM <noreply@$host>\r\nReply-To: $reply\r\nContent-Type: text/plain; charset=UTF-8";
  return @mail(implode(',', $to), '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $h);
}
function att_wa($text) {   // posts into the WhatsApp group through a gateway, when switched on in settings
  $w = att_cfg()['wa']; if (empty($w['on']) || !$w['token'] || !$w['group'] || !function_exists('curl_init')) return false;
  $ch = curl_init($w['url'] ?: 'https://gate.whapi.cloud/messages/text');
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['to' => $w['group'], 'body' => $text]), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => ['content-type: application/json', 'authorization: Bearer ' . $w['token']]]);
  $r = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  return $code >= 200 && $code < 300;
}
function att_alert($subject, $text) { $c = att_cfg(); if (!empty($c['email'])) att_mail($subject, $text); att_wa($text); }

// Runs on CRM requests (at most once a minute) and from the optional cron job: sends the late alert and the check-out reminder.
function att_tick() {
  if (setting('att_tick') === date('Y-m-d H:i')) return; set_setting('att_tick', date('Y-m-d H:i'));
  $d = today(); if (!is_working_day($d)) return;
  $board = att_board($d); if (!$board) return;
  $sent = (array) setting('att_alerted', []); if (($sent['date'] ?? '') !== $d) $sent = ['date' => $d, 'late' => [], 'out' => 0];
  $miss = array_values(array_filter($board, fn($r) => $r['code'] === 'absent' && !in_array($r['user']['id'], $sent['late'], true)));
  if ($miss) {
    $line = fn($code) => implode(', ', array_map(fn($r) => $r['user']['name'] . ($code === 'late' ? ' (' . $r['in'] . ')' : ''), array_filter($board, fn($r) => $r['code'] === $code)));
    $t = '🕙 Attendance — ' . date('h:i A, D d M') . "\n" . '❌ Not checked in: ' . implode(', ', array_map(fn($r) => $r['user']['name'], $miss));
    if ($x = $line('ontime')) $t .= "\n✅ On time: $x"; if ($x = $line('late')) $t .= "\n🟠 Late: $x"; if ($x = $line('leave')) $t .= "\n🌴 On leave: $x"; if ($x = $line('requested')) $t .= "\n⏳ Leave requested: $x";
    $w = implode(', ', array_map(fn($r) => $r['user']['name'], array_filter($board, fn($r) => $r['wfh']))); if ($w) $t .= "\n🏠 Work from home: $w";
    att_alert('Not checked in: ' . implode(', ', array_map(fn($r) => $r['user']['name'], $miss)), $t);
    foreach ($miss as $r) $sent['late'][] = $r['user']['id'];
  }
  $c = att_cfg(); $nowMin = (int) date('H') * 60 + (int) date('i');
  if (!$sent['out'] && $nowMin > att_min($c['end']) + 30) {
    $no = array_filter($board, fn($r) => in_array('Forgot to check out', $r['notes'], true));
    if ($no) att_alert('Forgot to check out', '🕖 Not checked out yet: ' . implode(', ', array_map(fn($r) => $r['user']['name'] . ' (in ' . $r['in'] . ')', $no)));
    $sent['out'] = 1;
  }
  set_setting('att_alerted', $sent);
}
