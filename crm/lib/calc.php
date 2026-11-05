<?php
// Money maths for one project. All amounts in rupees.
function project_bill($p, $extras, $pay, $vpay, $exp, $loss) {
  $base = (float) ($p['agreed'] ?? 0);
  $ext = 0; foreach ($extras as $x) $ext += $x['rate'] * $x['qty'];
  $taxable = ($p['gst_on'] !== null && $p['gst_on'] !== '') ? (float) $p['gst_on'] : $base + $ext;
  $gst = round($taxable * ((float) $p['gst_rate']) / 100, 2);
  $total = $base + $ext + $gst;
  $rec = array_sum(array_column($pay, 'amount'));
  $vend = array_sum(array_column($vpay, 'amount'));
  $e = array_sum(array_column($exp, 'amount'));
  $l = array_sum(array_column($loss, 'amount'));
  return [
    'base' => $base, 'extras' => $ext, 'taxable' => $taxable, 'gst' => $gst, 'total' => $total,
    'received' => $rec, 'balance' => round($total - $rec, 2),
    'vendor_paid' => $vend, 'expenses' => $e, 'losses' => $l,
    'profit' => round($base + $ext - $vend - $e - $l, 2),
    'gst_received' => $total > 0 ? round($rec * $gst / $total, 2) : 0,
  ];
}

// Bills for many projects at once (few queries, grouped in PHP)
function bills_for($projects) {
  if (!$projects) return [];
  $ids = array_column($projects, 'id');
  $in = implode(',', array_map('intval', $ids));
  $grp = function ($table) use ($in) {
    $g = [];
    foreach (all('SELECT * FROM ' . T($table) . " WHERE project_id IN ($in)") as $r) $g[$r['project_id']][] = $r;
    return $g;
  };
  $ex = $grp('extras'); $pa = $grp('client_payments'); $vp = $grp('vendor_payments'); $es = $grp('expenses'); $lo = $grp('losses');
  $out = [];
  foreach ($projects as $p) {
    $i = $p['id'];
    $out[$i] = project_bill($p, $ex[$i] ?? [], $pa[$i] ?? [], $vp[$i] ?? [], $es[$i] ?? [], $lo[$i] ?? []);
  }
  return $out;
}

function is_won($status) { return in_array($status, ['won', 'completed'], true); }

// Working days with no report, between start and end (inclusive)
function missing_days($user, $end) {
  $from = max(setting('track_from', today()), $user['joined_on']);
  $from = max($from, date('Y-m-d', strtotime($end . ' -60 days')));
  if ($from > $end) return [];
  $off = setting('weekly_off', [0]);
  $hol = array_column(all('SELECT hdate FROM ' . T('holidays') . ' WHERE hdate BETWEEN ? AND ?', [$from, $end]), 'hdate');
  $done = array_column(all('SELECT rdate FROM ' . T('reports') . ' WHERE user_id = ? AND rdate BETWEEN ? AND ?', [$user['id'], $from, $end]), 'rdate');
  // a day with work logged (expo data, design or production updates) also counts as reported
  $done = array_merge($done, array_column(all('SELECT adate FROM ' . T('activity') . ' WHERE user_id = ? AND adate BETWEEN ? AND ?', [$user['id'], $from, $end]), 'adate'));
  $out = [];
  for ($d = $from; $d <= $end; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
    if (in_array((int) date('w', strtotime($d)), $off, true) || in_array($d, $hol, true) || in_array($d, $done, true)) continue;
    $out[] = $d;
  }
  return $out;
}

function is_working_day($d) {
  if (in_array((int) date('w', strtotime($d)), setting('weekly_off', [0]), true)) return false;
  return !val('SELECT COUNT(*) FROM ' . T('holidays') . ' WHERE hdate = ?', [$d]);
}
