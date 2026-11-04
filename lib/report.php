<?php
// Admin reporting: every order with everything about it, used by the Client log screen and the Excel export.
require_once __DIR__ . '/calc.php';

function report_orders($f) {
  $w = '1=1'; $p = [];
  if (!empty($f['from'])) { $w .= ' AND COALESCE(p.start_date, DATE(p.created_at)) >= ?'; $p[] = $f['from']; }
  if (!empty($f['to'])) { $w .= ' AND COALESCE(p.start_date, DATE(p.created_at)) <= ?'; $p[] = $f['to']; }
  if (!empty($f['status'])) { $w .= ' AND p.status = ?'; $p[] = $f['status']; }
  if (!empty($f['user_id'])) { $w .= ' AND p.owner_id = ?'; $p[] = (int) $f['user_id']; }
  if (!empty($f['client_id'])) { $w .= ' AND p.client_id = ?'; $p[] = (int) $f['client_id']; }
  if (!empty($f['q'])) { $w .= ' AND (c.company LIKE ? OR p.expo LIKE ?)'; $p[] = '%' . $f['q'] . '%'; $p[] = '%' . $f['q'] . '%'; }
  $ps = all('SELECT p.*, c.company, c.city AS client_city, c.dnq FROM ' . T('projects') . ' p JOIN ' . T('clients') . " c ON c.id = p.client_id WHERE $w ORDER BY c.company, COALESCE(p.start_date, p.created_at) DESC", $p);
  if (!$ps) return [];
  $b = bills_for($ps);
  $in = implode(',', array_map('intval', array_column($ps, 'id')));
  $vn = []; foreach (all('SELECT id, name FROM ' . T('vendors')) as $v) $vn[$v['id']] = $v['name'];
  $chosen = []; foreach (all('SELECT project_id, vendor_id FROM ' . T('vendor_quotes') . " WHERE selected = 1 AND project_id IN ($in)") as $r) $chosen[$r['project_id']] = $vn[$r['vendor_id']] ?? '';
  $l1 = []; foreach (all('SELECT project_id, MIN(amount) AS m FROM ' . T('vendor_quotes') . " WHERE project_id IN ($in) GROUP BY project_id") as $r) $l1[$r['project_id']] = (float) $r['m'];
  $paidTo = []; foreach (all('SELECT DISTINCT project_id, vendor_id FROM ' . T('vendor_payments') . " WHERE project_id IN ($in)") as $r) $paidTo[$r['project_id']][] = $vn[$r['vendor_id']] ?? '';
  $revs = []; foreach (all('SELECT project_id, COUNT(*) AS n FROM ' . T('revisions') . " WHERE project_id IN ($in) GROUP BY project_id") as $r) $revs[$r['project_id']] = (int) $r['n'];
  $qv = []; foreach (all('SELECT project_id, COUNT(*) AS n FROM ' . T('quotes') . " WHERE project_id IN ($in) GROUP BY project_id") as $r) $qv[$r['project_id']] = (int) $r['n'];
  $lastPay = []; foreach (all('SELECT project_id, MAX(pdate) AS d FROM ' . T('client_payments') . " WHERE project_id IN ($in) GROUP BY project_id") as $r) $lastPay[$r['project_id']] = $r['d'];
  $um = users_map_r();
  foreach ($ps as &$x) {
    $i = $x['id'];
    $x['bill'] = $b[$i];
    $x['owner'] = $um[$x['owner_id']] ?? '—';
    $x['fabricator'] = $chosen[$i] ?? implode(', ', array_unique($paidTo[$i] ?? []));
    $x['vendor_l1'] = $l1[$i] ?? null;
    $x['revisions_n'] = $revs[$i] ?? 0;
    $x['quotes_n'] = $qv[$i] ?? 0;
    $x['last_payment'] = $lastPay[$i] ?? null;
  }
  unset($x);
  return $ps;
}

// Indian number format for text: 150000 -> 1,50,000
function inr_txt($n) {
  $n = round((float) $n); $neg = $n < 0; $i = (string) abs($n);
  $last = substr($i, -3); $rest = substr($i, 0, -3);
  if ($rest !== '') $last = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) . ',' . $last;
  return ($neg ? '-' : '') . $last;
}

function users_map_r() { $m = []; foreach (all('SELECT id, name FROM ' . T('users')) as $u) $m[$u['id']] = $u['name']; return $m; }

// Daily-report entries that mention this company = who followed up and when
function followups_for($company) {
  $name = trim(preg_replace('/\b(pvt|private|ltd|limited|llp|inc|co|company|the)\b\.?/i', ' ', $company));
  $word = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY)[0] ?? '';
  if (mb_strlen($word) < 3) return [];
  $um = users_map_r(); $out = [];
  foreach (all('SELECT i.data_json, r.rdate, r.user_id, r.dept, r.fields_json FROM ' . T('report_items') . ' i JOIN ' . T('reports') . ' r ON r.id = i.report_id WHERE i.data_json LIKE ? ORDER BY r.rdate DESC LIMIT 100', ['%' . $word . '%']) as $r) {
    $d = json_decode($r['data_json'], true) ?: []; $fields = json_decode($r['fields_json'], true) ?: [];
    $first = $fields[0]['k'] ?? 'company';
    if (stripos((string) ($d[$first] ?? ''), $word) === false) continue;
    $parts = [];
    foreach ($fields as $fl) if ($fl['t'] !== 'file' && $fl['k'] !== $first && !empty($d[$fl['k']])) $parts[] = $fl['l'] . ': ' . $d[$fl['k']];
    $out[] = ['date' => $r['rdate'], 'by' => $um[$r['user_id']] ?? '—', 'dept' => $r['dept'], 'what' => implode(' · ', $parts)];
  }
  return $out;
}
