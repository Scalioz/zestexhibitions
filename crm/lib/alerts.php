<?php
// Automatic WhatsApp alerts: attendance, daily reports not sent, daily sales summary.
// Each alert has its own on/off switch, time(s) and people/groups to send to.
// Messages go out through the WhatsApp gateway saved in Auto alerts (the same one attendance uses).
require_once __DIR__ . '/calc.php';
require_once __DIR__ . '/att.php';

function alerts_cfg() {
  $d = ['att' => ['on' => 1, 'auto' => 1, 'times' => '', 'group' => 1, 'users' => [], 'extra' => ''],
        'rep' => ['on' => 1, 'time' => '19:30', 'group' => 1, 'users' => [], 'extra' => '', 'dm' => 1],
        'sales' => ['on' => 1, 'time' => '20:00', 'group' => 1, 'users' => [], 'extra' => '', 'dm' => 0, 'month' => 1]];
  $s = (array) setting('alerts', []);
  foreach ($d as $k => $v) $d[$k] = array_replace($v, array_intersect_key((array) ($s[$k] ?? []), $v));
  return $d;
}
function al_hm_list($s) { $o = []; foreach (preg_split('/[\s,;]+/', (string) $s) as $t) if ($h = att_hm($t)) $o[] = $h; $o = array_values(array_unique($o)); sort($o); return $o; }
function al_phone($p) {   // Indian mobile → 91XXXXXXXXXX
  $d = preg_replace('/\D/', '', (string) $p);
  if (strlen($d) === 11 && $d[0] === '0') $d = substr($d, 1);
  if (strlen($d) === 10) $d = '91' . $d;
  return strlen($d) >= 11 && strlen($d) <= 15 ? $d : null;
}
function al_inr($n) { $n = round((float) $n); $i = (string) abs($n); $last = substr($i, -3); $rest = substr($i, 0, -3); if ($rest !== '') $last = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) . ',' . $last; return ($n < 0 ? '-' : '') . '₹' . $last; }
function al_first($name) { return explode(' ', trim((string) $name))[0]; }

// where one alert goes: the main group (if ticked) + chosen team members + any extra numbers / group IDs
function alert_targets($kind) {
  $c = alerts_cfg()[$kind] ?? null; if (!$c) return [];
  $w = att_cfg()['wa']; $t = [];
  if (!empty($c['group']) && $w['group']) $t[] = $w['group'];
  if ($c['users']) { $in = implode(',', array_map('intval', (array) $c['users']));
    foreach (all('SELECT phone FROM ' . T('users') . " WHERE active = 1 AND id IN ($in)") as $u) if ($p = al_phone($u['phone'])) $t[] = $p; }
  foreach (preg_split('/[\s,;]+/', (string) $c['extra']) as $x) { $x = trim($x); if ($x === '') continue; if (str_contains($x, '@')) $t[] = $x; elseif ($p = al_phone($x)) $t[] = $p; }
  return array_values(array_unique($t));
}

// one message to one chat (group ID or phone). Returns true when the gateway accepted it.
function wa_send_to($to, $text) {
  $w = att_cfg()['wa']; if (empty($w['on']) || !$w['token'] || !$to || !function_exists('curl_init')) return false;
  $ch = curl_init($w['url'] ?: 'https://gate.whapi.cloud/messages/text');
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['to' => $to, 'body' => $text]), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => ['content-type: application/json', 'authorization: Bearer ' . $w['token']]]);
  curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  $ok = $code >= 200 && $code < 300;
  $log = (array) setting('alerts_log', []); array_unshift($log, ['at' => date('Y-m-d H:i'), 'to' => $to, 'ok' => $ok ? 1 : 0, 'code' => $code, 'text' => mb_substr($text, 0, 80)]);
  set_setting('alerts_log', array_slice($log, 0, 30));
  return $ok;
}
function alert_send($kind, $text) { $n = 0; foreach (alert_targets($kind) as $t) if (wa_send_to($t, $text)) $n++; return $n; }

// ---------- the three messages ----------
function alert_att_text($d = null) {
  $d = $d ?: today(); $board = att_board($d); if (!$board) return null;
  $nm = fn($code, $time = false) => implode(', ', array_map(fn($r) => $r['user']['name'] . ($time && $r['in'] ? ' (' . $r['in'] . ')' : ''), array_filter($board, fn($r) => $r['code'] === $code)));
  $in = count(array_filter($board, fn($r) => in_array($r['code'], ['ontime', 'late'], true)));
  $t = '🕙 *Attendance* — ' . date('h:i A, D d M') . "\n" . "$in of " . count($board) . ' checked in';
  if ($x = $nm('ontime', true)) $t .= "\n✅ On time: $x";
  if ($x = $nm('late', true)) $t .= "\n🟠 Late: $x";
  if ($x = $nm('absent')) $t .= "\n❌ Not checked in: $x";
  if ($x = $nm('notyet')) $t .= "\n⏳ Not in yet (still within time): $x";
  if ($x = $nm('leave')) $t .= "\n🌴 On leave: $x";
  if ($x = $nm('requested')) $t .= "\n📝 Leave requested: $x";
  $w = implode(', ', array_map(fn($r) => $r['user']['name'], array_filter($board, fn($r) => $r['wfh']))); if ($w) $t .= "\n🏠 Work from home: $w";
  return $t;
}

function alert_rep_rows($d = null) {   // who owes today's daily report
  $d = $d ?: today(); $out = ['missing' => [], 'done' => []];
  foreach (all('SELECT * FROM ' . T('users') . " WHERE active = 1 AND role = 'employee' ORDER BY name") as $u) {
    if (($u['joined_on'] ?: '2000-01-01') > $d) continue;
    if (in_array($d, missing_days($u, $d), true)) $out['missing'][] = $u; else $out['done'][] = $u;
  }
  return $out;
}
function alert_rep_text($d = null) {
  $d = $d ?: today(); $r = alert_rep_rows($d); if (!$r['missing'] && !$r['done']) return null;
  $t = '📝 *Daily reports* — ' . date('D d M', strtotime($d));
  $t .= $r['missing'] ? "\n❌ Not sent yet (" . count($r['missing']) . '): ' . implode(', ', array_column($r['missing'], 'name')) : "\n✅ Everyone has sent today's report. 👏";
  if ($r['missing'] && $r['done']) $t .= "\n✅ Sent: " . implode(', ', array_column($r['done'], 'name'));
  if ($r['missing']) $t .= "\n\nPlease submit in the CRM → Daily report before you leave.";
  return $t;
}

function alert_sales_data($d = null) {
  $d = $d ?: today(); $um = []; foreach (all('SELECT id, name, phone FROM ' . T('users')) as $u) $um[$u['id']] = $u;
  $P = fn($r) => ['owner_id' => (int) $r['owner_id'], 'company' => $r['company'], 'expo' => $r['expo']];
  $won = all('SELECT p.*, c.company FROM ' . T('projects') . ' p JOIN ' . T('clients') . ' c ON c.id = p.client_id WHERE p.won_on = ?', [$d]);
  $wb = bills_for($won);
  $orders = array_map(fn($p) => $P($p) + ['value' => $wb[$p['id']]['total'] ?? 0], $won);
  $pays = array_map(fn($r) => $P($r) + ['value' => (float) $r['amount'], 'mode' => $r['mode']], all('SELECT cp.amount, cp.mode, p.owner_id, p.expo, c.company FROM ' . T('client_payments') . ' cp JOIN ' . T('projects') . ' p ON p.id = cp.project_id JOIN ' . T('clients') . ' c ON c.id = p.client_id WHERE cp.pdate = ? ORDER BY cp.id', [$d]));
  $quotes = array_map(fn($r) => $P($r) + ['value' => (float) $r['amount']], all('SELECT q.amount, p.owner_id, p.expo, c.company FROM ' . T('quotes') . ' q JOIN ' . T('projects') . ' p ON p.id = q.project_id JOIN ' . T('clients') . ' c ON c.id = p.client_id WHERE q.qdate = ? ORDER BY q.id', [$d]));
  $enq = array_map(fn($r) => $P($r) + ['value' => 0], all('SELECT p.owner_id, p.expo, c.company FROM ' . T('projects') . ' p JOIN ' . T('clients') . ' c ON c.id = p.client_id WHERE p.created_at >= ? AND p.created_at < ?', [$d . ' 00:00:00', date('Y-m-d', strtotime($d . ' +1 day')) . ' 00:00:00']));
  $people = [];
  foreach (['orders' => $orders, 'pays' => $pays, 'quotes' => $quotes, 'enq' => $enq] as $k => $rows) foreach ($rows as $r) {
    $o = $r['owner_id']; $people[$o] = $people[$o] ?? ['user' => $um[$o] ?? ['id' => $o, 'name' => '—', 'phone' => ''], 'orders' => [], 'pays' => [], 'quotes' => [], 'enq' => []];
    $people[$o][$k][] = $r;
  }
  uasort($people, fn($a, $b) => array_sum(array_column($b['orders'], 'value')) <=> array_sum(array_column($a['orders'], 'value')) ?: array_sum(array_column($b['pays'], 'value')) <=> array_sum(array_column($a['pays'], 'value')));
  $m0 = date('Y-m-01', strtotime($d));
  $mw = all('SELECT * FROM ' . T('projects') . ' WHERE won_on BETWEEN ? AND ?', [$m0, $d]); $mb = bills_for($mw);
  $month = ['orders' => count($mw), 'value' => array_sum(array_map(fn($p) => $mb[$p['id']]['total'] ?? 0, $mw)),
    'collected' => (float) val('SELECT COALESCE(SUM(amount),0) FROM ' . T('client_payments') . ' WHERE pdate BETWEEN ? AND ?', [$m0, $d])];
  return ['date' => $d, 'people' => $people, 'orders' => $orders, 'pays' => $pays, 'quotes' => $quotes, 'enq' => $enq, 'month' => $month];
}
function al_sum($rows) { return array_sum(array_column($rows, 'value')); }
function al_person_lines($p, $full = true) {
  $L = [];
  if ($p['orders']) $L[] = '🏆 ' . count($p['orders']) . ' new order' . (count($p['orders']) > 1 ? 's' : '') . ' ' . al_inr(al_sum($p['orders'])) . ($full ? ' — ' . implode(', ', array_map(fn($r) => $r['company'] . ($r['expo'] ? ' (' . $r['expo'] . ')' : ''), $p['orders'])) : '');
  if ($p['pays']) $L[] = '💰 Collected ' . al_inr(al_sum($p['pays'])) . ($full ? ' — ' . implode(', ', array_map(fn($r) => $r['company'] . ' ' . al_inr($r['value']), $p['pays'])) : '');
  if ($p['quotes']) $L[] = '📄 ' . count($p['quotes']) . ' quote' . (count($p['quotes']) > 1 ? 's' : '') . ' sent ' . al_inr(al_sum($p['quotes']));
  if ($p['enq']) $L[] = '🆕 ' . count($p['enq']) . ' new enquir' . (count($p['enq']) > 1 ? 'ies' : 'y');
  return $L;
}
function alert_sales_text($d = null, $withMonth = true) {
  $s = alert_sales_data($d);
  $t = '📊 *Sales today* — ' . date('D d M', strtotime($s['date']));
  $t .= "\nNew orders: " . count($s['orders']) . ' · ' . al_inr(al_sum($s['orders'])) . "\nPayments collected: " . al_inr(al_sum($s['pays'])) . (count($s['pays']) ? ' (' . count($s['pays']) . ')' : '');
  $t .= "\nQuotes sent: " . count($s['quotes']) . ($s['quotes'] ? ' · ' . al_inr(al_sum($s['quotes'])) : '') . ' · New enquiries: ' . count($s['enq']);
  if ($s['people']) { foreach ($s['people'] as $p) $t .= "\n\n👤 *" . $p['user']['name'] . "*\n" . implode("\n", al_person_lines($p)); }
  else $t .= "\n\nNo orders, payments or quotes entered today.";
  if ($withMonth) $t .= "\n\n📅 This month so far: " . $s['month']['orders'] . ' orders · ' . al_inr($s['month']['value']) . ' · collected ' . al_inr($s['month']['collected']);
  return $t;
}

// ---------- the clock: runs once a minute from CRM requests and from the cron job ----------
function alerts_tick() {
  if (setting('alerts_tick') === date('Y-m-d H:i')) return; set_setting('alerts_tick', date('Y-m-d H:i'));
  $w = att_cfg()['wa']; if (empty($w['on']) || !$w['token']) return;
  $c = alerts_cfg(); $d = today(); $now = (int) date('H') * 60 + (int) date('i'); $work = is_working_day($d);
  $sent = (array) setting('alerts_sent', []); if (($sent['date'] ?? '') !== $d) $sent = ['date' => $d, 'k' => []];
  $due = function ($key, $hm) use (&$sent, $now) { if (in_array($key, $sent['k'], true)) return false; $m = att_min($hm); if ($now < $m) return false; $sent['k'][] = $key; return $now <= $m + 180; };
  $save = function () use (&$sent) { set_setting('alerts_sent', $sent); };
  if ($work && !empty($c['att']['on'])) foreach (al_hm_list($c['att']['times']) as $hm) if ($due("att@$hm", $hm)) { $save(); if ($t = alert_att_text($d)) alert_send('att', $t); }
  if ($work && !empty($c['rep']['on']) && ($hm = att_hm($c['rep']['time'])) && $due("rep@$hm", $hm)) {
    $save(); $r = alert_rep_rows($d);
    if ($r['missing'] || $r['done']) alert_send('rep', alert_rep_text($d));
    if (!empty($c['rep']['dm'])) foreach ($r['missing'] as $u) if ($p = al_phone($u['phone'])) wa_send_to($p, 'Hi ' . al_first($u['name']) . ", your daily report for today (" . date('d M') . ") is not in the CRM yet. Please submit it before you leave. 🙏\n— Zest CRM");
  }
  if (!empty($c['sales']['on']) && ($hm = att_hm($c['sales']['time'])) && $due("sales@$hm", $hm)) {
    $save(); $s = alert_sales_data($d);
    if ($work || $s['people']) alert_send('sales', alert_sales_text($d, !empty($c['sales']['month'])));
    if (!empty($c['sales']['dm'])) foreach ($s['people'] as $p) if ($ph = al_phone($p['user']['phone'] ?? '')) wa_send_to($ph, 'Hi ' . al_first($p['user']['name']) . ', your day — ' . date('d M') . "\n" . implode("\n", al_person_lines($p)) . "\nGreat work! 💪\n— Zest CRM");
  }
  $save();
}
