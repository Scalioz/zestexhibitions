<?php
// Money maths for one project. All amounts in rupees.
// Discount on the agreed price (before GST): typed as ₹ or as %
function order_discount($p) {
  $a = (float) ($p['agreed'] ?? 0); $v = (float) ($p['disc_val'] ?? 0); if ($v <= 0 || $a <= 0) return 0.0;
  return ($p['disc_type'] ?? '') === 'pct' ? round($a * min($v, 100) / 100, 2) : min(round($v, 2), $a);
}
// Bill total rounded to the nearest ₹1 (default), ₹10, ₹100 or ₹1,000 — 0 = no rounding
function order_round_to($p) { $r = $p['round_to'] ?? null; return ($r === null || $r === '') ? 1 : (int) $r; }
function project_bill($p, $extras, $pay, $vpay, $exp, $loss) {
  $agreed = (float) ($p['agreed'] ?? 0); $disc = order_discount($p);
  $base = round($agreed - $disc, 2);   // price after discount
  $ext = 0; foreach ($extras as $x) $ext += $x['rate'] * $x['qty'];
  $taxable = ($p['gst_on'] !== null && $p['gst_on'] !== '') ? (float) $p['gst_on'] : $base + $ext;
  $gst = round($taxable * ((float) $p['gst_rate']) / 100, 2);
  $pre = round($base + $ext + $gst, 2); $step = order_round_to($p);
  $total = $step > 0 ? round(round($pre / $step) * $step, 2) : $pre; $ro = round($total - $pre, 2);
  $rec = array_sum(array_column($pay, 'amount'));
  $vend = array_sum(array_column($vpay, 'amount'));
  $e = array_sum(array_column($exp, 'amount'));
  $l = array_sum(array_column($loss, 'amount'));
  return [
    'agreed' => $agreed, 'discount' => $disc, 'base' => $base, 'extras' => $ext, 'taxable' => $taxable, 'gst' => $gst, 'round_off' => $ro, 'round_to' => $step, 'total' => $total,
    'received' => $rec, 'balance' => round($total - $rec, 2),
    'vendor_paid' => $vend, 'expenses' => $e, 'losses' => $l,
    'profit' => round($base + $ext + $ro - $vend - $e - $l, 2),
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

const GST_STATE = ['01' => 'Jammu & Kashmir', '02' => 'Himachal Pradesh', '03' => 'Punjab', '04' => 'Chandigarh', '05' => 'Uttarakhand', '06' => 'Haryana', '07' => 'Delhi', '08' => 'Rajasthan', '09' => 'Uttar Pradesh',
  '10' => 'Bihar', '11' => 'Sikkim', '12' => 'Arunachal Pradesh', '13' => 'Nagaland', '14' => 'Manipur', '15' => 'Mizoram', '16' => 'Tripura', '17' => 'Meghalaya', '18' => 'Assam', '19' => 'West Bengal',
  '20' => 'Jharkhand', '21' => 'Odisha', '22' => 'Chhattisgarh', '23' => 'Madhya Pradesh', '24' => 'Gujarat', '25' => 'Daman & Diu', '26' => 'Dadra & Nagar Haveli and Daman & Diu', '27' => 'Maharashtra', '28' => 'Andhra Pradesh (old)',
  '29' => 'Karnataka', '30' => 'Goa', '31' => 'Lakshadweep', '32' => 'Kerala', '33' => 'Tamil Nadu', '34' => 'Puducherry', '35' => 'Andaman & Nicobar Islands', '36' => 'Telangana', '37' => 'Andhra Pradesh', '38' => 'Ladakh', '97' => 'Other Territory'];
function gstin_clean($g) { return strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) $g)); }
function gstin_state($g) { $g = gstin_clean($g); return strlen($g) === 15 ? (GST_STATE[substr($g, 0, 2)] ?? null) : null; }
// who an invoice is billed to: the client's main name & GST, or one of its other registered firms / GST numbers
function bill_to($cid, $gid = 0) {
  $c = one('SELECT company, gst_no, location, city FROM ' . T('clients') . ' WHERE id = ?', [(int) $cid]); if (!$c) return [];
  if ((int) $gid) { $g = one('SELECT * FROM ' . T('client_gst') . ' WHERE id = ? AND client_id = ?', [(int) $gid, (int) $cid]);
    if ($g) return ['bill_gst_id' => (int) $g['id'], 'bill_name' => $g['legal_name'], 'bill_gstin' => $g['gstin'] ?: null, 'bill_address' => $g['address'] ?: ($c['location'] ?: $c['city'])]; }
  return ['bill_gst_id' => null, 'bill_name' => $c['company'], 'bill_gstin' => $c['gst_no'] ?: null, 'bill_address' => $c['location'] ?: $c['city']];
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
  try { foreach (all('SELECT from_date, to_date FROM ' . T('att_requests') . " WHERE user_id = ? AND kind = 'leave' AND status = 'approved' AND (half IS NULL OR half = '') AND to_date >= ? AND from_date <= ?", [$user['id'], $from, $end]) as $lv)   // approved full-day leave: no report expected
    for ($x = $lv['from_date']; $x <= $lv['to_date']; $x = date('Y-m-d', strtotime($x . ' +1 day'))) $done[] = $x; } catch (PDOException $e) { /* table not created yet */ }
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
