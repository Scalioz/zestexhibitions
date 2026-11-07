<?php
// Accounts: entries, invoices, expense claims, CRM posting, reports. Included from api.php.
if (!defined('ZEST_API')) exit;
require_once __DIR__ . '/acc.php';

function need_acc() { global $ACC; if (!$ACC) fail('Only accounts can do this.', 403); }
function need_claimer() { global $ADMIN, $MODULE; if (!$ADMIN && !in_array($MODULE, ['records', 'ops'], true)) fail('Expense claims are for sales and production.', 403); }
function money_id($id) { $a = acc_get($id); if (!$a || !in_array($a['sub'], ['bank', 'cash', 'petty'], true)) fail('Pick bank, cash or petty cash.'); return (int) $a['id']; }
function acc_of_type($id, $type) { $a = acc_get($id); if (!$a || $a['type'] !== $type) fail("Pick an $type account."); return (int) $a['id']; }
function mode_of($moneyId) { $s = acc_get($moneyId)['sub']; return $s === 'bank' ? 'Bank' : 'Cash'; }
function gst_lines($amt, $mode, $dir) {   // split GST into CGST+SGST or IGST input/output accounts
  if ($amt <= 0) return [];
  $k = $dir === 'in' ? 'gst_in_' : 'gst_out_'; $side = $dir === 'in' ? 1 : 2;
  if ($mode === 'igst') { $l = [acc_sub($k . 'i'), 0, 0, 'IGST']; $l[$side] = $amt; return [$l]; }
  $h = round($amt / 2, 2); $c = [acc_sub($k . 'c'), 0, 0, 'CGST']; $s = [acc_sub($k . 's'), 0, 0, 'SGST']; $c[$side] = $h; $s[$side] = round($amt - $h, 2);
  return [$c, $s];
}
const CLAIM_CATS = ['Travel (flight / train)' => 'exp_travel', 'Hotel & stay' => 'exp_hotel', 'Local conveyance' => 'exp_local', 'Food & meals' => 'exp_food',
  'Courier & transport' => 'exp_courier', 'Printing & stationery' => 'exp_print', 'Site materials & labour' => 'exp_site', 'Other' => 'exp_misc'];
const PEXP_SUB = ['Flight' => 'exp_travel', 'Train' => 'exp_travel', 'Hotel' => 'exp_hotel', 'Commute' => 'exp_local', 'Food' => 'exp_food', 'Courier' => 'exp_courier'];
function pexp_cat($sub) { return ['exp_travel' => 'Flight', 'exp_hotel' => 'Hotel', 'exp_local' => 'Commute', 'exp_food' => 'Food', 'exp_courier' => 'Courier'][$sub] ?? 'Other'; }
function claim_row($id) { $c = one('SELECT * FROM ' . T('claims') . ' WHERE id = ?', [(int) $id]); if (!$c) fail('Expense not found.', 404); return $c; }
function claim_note($cid, $kind, $text) { global $MID; ins('claim_notes', ['claim_id' => (int) $cid, 'user_id' => $MID, 'at' => now(), 'kind' => $kind, 'text' => $text]); }
function claims_out($where, $p) {
  $rows = all('SELECT c.*, u.name AS user_name, u.department, pr.expo, cl.company FROM ' . T('claims') . ' c LEFT JOIN ' . T('users') . ' u ON u.id = c.user_id LEFT JOIN ' . T('projects') . ' pr ON pr.id = c.project_id LEFT JOIN ' . T('clients') . " cl ON cl.id = pr.client_id WHERE $where ORDER BY c.submitted_at DESC LIMIT 500", $p);
  $fl = files_of('claim', array_column($rows, 'id')); $um = users_map();
  $notes = []; if ($rows) foreach (all('SELECT * FROM ' . T('claim_notes') . ' WHERE claim_id IN (' . implode(',', array_map('intval', array_column($rows, 'id'))) . ') ORDER BY id') as $n) $notes[$n['claim_id']][] = $n + ['by' => $um[$n['user_id']] ?? '—'];
  foreach ($rows as &$r) { $r['files'] = $fl[$r['id']] ?? []; $r['notes'] = $notes[$r['id']] ?? []; } unset($r);
  return $rows;
}
// lines → [lines, value before GST (after discount), GST, grand total, GST-able amount, discount, round off]
// The discount comes off the GST-able amount (before GST). Round off brings the grand total to whole rupees unless turned off.
function inv_totals($lines, $rate, $disc = 0, $mode = 'auto') {
  $tax = 0; $non = 0; $out = [];
  foreach (array_slice((array) $lines, 0, 50) as $l) { $l = (array) $l;
    $d = s($l['desc'] ?? null, 500); if (!$d) continue;
    $q = (float) ($l['qty'] ?? 1) ?: 1; $r = round((float) ($l['rate'] ?? 0), 2); $amt = round($q * $r, 2); $tx = !empty($l['tax']) || !array_key_exists('tax', $l);
    $out[] = ['desc' => $d, 'sac' => s($l['sac'] ?? null, 20), 'qty' => $q, 'rate' => $r, 'amount' => $amt, 'tax' => $tx ? 1 : 0];
    if ($tx) $tax += $amt; else $non += $amt;
  }
  $disc = round((float) $disc, 2); if ($disc < 0) fail('Discount cannot be negative.');
  $base = $tax > 0 ? $tax : $non; if ($disc > 0 && $disc >= $base) fail('Discount is more than the ' . ($tax > 0 ? 'amount with GST' : 'total') . '.');
  if ($tax > 0) $tax = round($tax - $disc, 2); else $non = round($non - $disc, 2);
  $gst = round($tax * $rate / 100, 2); $pre = round($tax + $non + $gst, 2);
  $ro = $mode === 'none' ? 0.0 : round(round($pre) - $pre, 2);
  return [$out, round($tax + $non, 2), $gst, round($pre + $ro, 2), $tax, $disc, $ro];
}

// ---------- invoice workflow: sales request → accounts prepare → admin approve ----------
const INV_EDITABLE = ['requested', 'draft', 'on_hold', 'rejected'];
function need_seller() { global $ADMIN, $MODULE; if (!$ADMIN && $MODULE !== 'records') fail('Invoice requests are raised by sales.', 403); }
function inv_row($id) {
  $i = one('SELECT i.*, c.company, c.city, p.expo, p.owner_id, u.name AS requester FROM ' . T('invoices') . ' i JOIN ' . T('clients') . ' c ON c.id = i.client_id LEFT JOIN ' . T('projects') . ' p ON p.id = i.project_id LEFT JOIN ' . T('users') . ' u ON u.id = i.requested_by WHERE i.id = ?', [(int) $id]);
  if (!$i) fail('Invoice not found.', 404);
  return $i;
}
function inv_can_see($i) { global $ACC, $MID; return $ACC || (int) $i['requested_by'] === $MID || (int) ($i['owner_id'] ?? 0) === $MID; }
function inv_log($id, $action, $note = null, $ver = null, $snap = null) {
  global $MID; ins('inv_log', ['invoice_id' => (int) $id, 'user_id' => $MID, 'at' => now(), 'action' => $action, 'note' => $note, 'version' => $ver, 'snap' => $snap === null ? null : json_encode($snap, JSON_UNESCAPED_UNICODE)]);
}
function inv_snap($i) {
  return ['inv_date' => $i['inv_date'], 'place_state' => $i['place_state'], 'gst_rate' => (float) $i['gst_rate'], 'notes' => (string) ($i['notes'] ?? ''),
    'lines' => json_decode($i['lines_json'], true) ?: [], 'discount' => (float) ($i['discount'] ?? 0), 'round_off' => (float) ($i['round_off'] ?? 0), 'taxable' => (float) $i['taxable'], 'gst' => (float) $i['gst'], 'total' => (float) $i['total']];
}
function inv_diff($a, $b) {   // what changed between two versions, in plain words
  $ch = []; $r = fn($n) => '₹' . inr2($n);
  if ($a['inv_date'] !== $b['inv_date']) $ch[] = "Date: {$a['inv_date']} → {$b['inv_date']}";
  if ((string) $a['place_state'] !== (string) $b['place_state']) $ch[] = "Place of supply: {$a['place_state']} → {$b['place_state']}";
  if ($a['gst_rate'] != $b['gst_rate']) $ch[] = "GST rate: {$a['gst_rate']}% → {$b['gst_rate']}%";
  $n = max(count($a['lines']), count($b['lines']));
  for ($k = 0; $k < $n; $k++) {
    $x = $a['lines'][$k] ?? null; $y = $b['lines'][$k] ?? null; $no = 'Line ' . ($k + 1);
    if (!$x) { $ch[] = "$no added: {$y['desc']} — {$y['qty']} × " . $r($y['rate']); continue; }
    if (!$y) { $ch[] = "$no removed: {$x['desc']}"; continue; }
    if ($x['desc'] !== $y['desc']) $ch[] = "$no description: “{$x['desc']}” → “{$y['desc']}”";
    if ((float) $x['qty'] != (float) $y['qty']) $ch[] = "$no qty: {$x['qty']} → {$y['qty']}";
    if ((float) $x['rate'] != (float) $y['rate']) $ch[] = "$no rate: " . $r($x['rate']) . ' → ' . $r($y['rate']);
    if ((int) $x['tax'] !== (int) $y['tax']) $ch[] = "$no GST: " . ($x['tax'] ? 'yes' : 'no') . ' → ' . ($y['tax'] ? 'yes' : 'no');
  }
  if (trim($a['notes']) !== trim($b['notes'])) $ch[] = 'Notes: “' . (mb_strimwidth(trim($a['notes']), 0, 120, '…') ?: 'empty') . '” → “' . (mb_strimwidth(trim($b['notes']), 0, 120, '…') ?: 'empty') . '”';
  if (($a['discount'] ?? 0) != ($b['discount'] ?? 0)) $ch[] = 'Discount: ' . $r($a['discount'] ?? 0) . ' → ' . $r($b['discount'] ?? 0);
  if (($a['round_off'] ?? 0) != ($b['round_off'] ?? 0)) $ch[] = 'Round off: ' . $r($a['round_off'] ?? 0) . ' → ' . $r($b['round_off'] ?? 0);
  if ($a['total'] != $b['total']) $ch[] = 'Grand total: ' . $r($a['total']) . ' → ' . $r($b['total']);
  return $ch;
}
function inv_prefill_lines($pid) {
  $p = one('SELECT p.*, c.company FROM ' . T('projects') . ' p JOIN ' . T('clients') . ' c ON c.id = p.client_id WHERE p.id = ?', [(int) $pid]) ?? fail('Order not found.');
  $sac = setting('company', [])['sac'] ?? '998596';
  $desc = 'Stall fabrication incl. erection, labour & transport — ' . $p['expo'] . ($p['location'] ? ", {$p['location']}" : '') . ($p['area_sqm'] ? ', ' . rtrim(rtrim($p['area_sqm'], '0'), '.') . ' sqm' : '') . ($p['stall_no'] ? ", stall {$p['stall_no']}" : '');
  $base = (float) ($p['agreed'] ?: $p['offered']); $lines = [];
  $ext = all('SELECT * FROM ' . T('extras') . ' WHERE project_id = ?', [$p['id']]); $extT = array_sum(array_map(fn($x) => $x['rate'] * $x['qty'], $ext));
  if ($p['gst_on'] !== null && $p['gst_on'] !== '' && (float) $p['gst_on'] < $base + $extT) {   // GST charged only on part of the amount
    $lines[] = ['desc' => $desc, 'sac' => $sac, 'qty' => 1, 'rate' => (float) $p['gst_on'], 'tax' => 1];
    $lines[] = ['desc' => 'Balance amount (no GST, as agreed)', 'sac' => '', 'qty' => 1, 'rate' => round($base + $extT - (float) $p['gst_on'], 2), 'tax' => 0];
  } else {
    $lines[] = ['desc' => $desc, 'sac' => $sac, 'qty' => 1, 'rate' => $base, 'tax' => 1];
    foreach ($ext as $x) $lines[] = ['desc' => 'Extra: ' . $x['item'], 'sac' => $sac, 'qty' => (float) $x['qty'], 'rate' => (float) $x['rate'], 'tax' => 1];
  }
  return ['client_id' => (int) $p['client_id'], 'project_id' => (int) $p['id'], 'gst_rate' => (float) ($p['gst_rate'] ?: 18), 'lines' => $lines, 'owner_id' => (int) $p['owner_id']];
}
function inv_issue_do($i) {   // gives the number (kept if a proforma is being revised) and posts a tax invoice to the books
  global $MID;
  $no = $i['inv_no'];
  if (!$no) {   // unique index on inv_no: if two approvals happen at the same instant, the second takes the next number
    $pre = ($i['kind'] === 'tax' ? 'INV' : 'PI') . '/' . fy_of($i['inv_date']) . '/';
    for ($try = 0; ; $try++) {
      $no = $pre . str_pad(next_no('invoices', 'inv_no', $pre) + 1, 3, '0', STR_PAD_LEFT);
      try { upd('invoices', $i['id'], ['inv_no' => $no]); break; } catch (PDOException $e) { if ($try >= 8 || !is_dup($e)) throw $e; usleep(random_int(20000, 120000)); }
    }
  }
  $jid = $i['journal_id'] ?: null;
  if ($i['kind'] === 'tax' && !$jid) {
    $lines = [[party_account('client', $i['client_id'], $i['company']), $i['total'], 0, "Invoice $no"], [acc_sub('sales'), 0, $i['taxable'], "Invoice $no"]];
    $lines = array_merge($lines, gst_lines((float) $i['gst'], $i['gst_mode'], 'out'));
    $ro = (float) ($i['round_off'] ?? 0); if ($ro != 0) $lines[] = [acc_sub('roundoff'), $ro < 0 ? -$ro : 0, $ro > 0 ? $ro : 0, "Round off — invoice $no"];
    $jid = post_journal($i['inv_date'], 'Sales', "Tax invoice $no — {$i['company']}", $lines, 'invoice', $i['id'], $i['project_id'], $MID);
    if ($i['project_id']) upd('projects', $i['project_id'], ['invoice_no' => $no, 'invoice_date' => $i['inv_date']]);
  }
  upd('invoices', $i['id'], ['inv_no' => $no, 'status' => 'issued', 'journal_id' => $jid]);
  return $no;
}
function inv_counts() {
  $c = ['todo' => 0, 'pending' => 0, 'changes' => 0, 'issued' => 0, 'cancelled' => 0, 'all' => 0];
  foreach (all('SELECT status, COUNT(*) AS n FROM ' . T('invoices') . ' GROUP BY status') as $r) {
    $g = ['requested' => 'todo', 'draft' => 'todo', 'pending' => 'pending', 'on_hold' => 'changes', 'rejected' => 'changes', 'issued' => 'issued', 'cancelled' => 'cancelled'][$r['status']] ?? null;
    if ($g) $c[$g] += $r['n']; $c['all'] += $r['n'];
  }
  return $c;
}
const INV_GROUPS = ['todo' => ['requested', 'draft'], 'pending' => ['pending'], 'changes' => ['on_hold', 'rejected'], 'issued' => ['issued'], 'cancelled' => ['cancelled']];

// ---------- admin corrections ----------
function audit_log($kind, $id, $action, $before, $after, $pid = null) {
  global $MID; ins('audit', ['user_id' => $MID, 'at' => now(), 'project_id' => $pid, 'kind' => $kind, 'entry_id' => (int) $id, 'action' => $action,
    'before_json' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE), 'after_json' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE)]);
}
function claim_post($c, $exp, $credit, $mode, $payTo, $uname) {   // books an approved claim (+ order expense) and returns the voucher id
  global $MID;
  $lines = [[$exp, $c['total'] + ($credit ? 0 : $c['gst']), 0, $c['particular']]]; if ($credit) $lines = array_merge($lines, gst_lines((float) $c['gst'], $mode, 'in'));
  $lines[] = [$payTo, 0, $c['grand'], $c['particular']];
  $jid = post_journal($c['edate'], 'Claim', "Expense by $uname: {$c['particular']}", $lines, 'claim', $c['id'], $c['project_id'], $MID);
  if ($c['project_id']) {
    ins('expenses', ['project_id' => $c['project_id'], 'edate' => $c['edate'], 'category' => pexp_cat(acc_get($exp)['sub']), 'description' => mb_substr("{$c['particular']} ($uname, claim #{$c['id']})", 0, 250), 'amount' => $c['total'] + ($credit ? 0 : $c['gst'])]);
    ins('post_skip', ['ref_type' => 'pexp', 'ref_id' => (int) db()->lastInsertId()]);   // already in the books through this claim
  }
  return $jid;
}
function claim_unpost($c) {   // reverses an approved claim's voucher and removes its order expense; returns how it was booked
  global $MID;
  $how = null;
  if ($c['journal_id'] && ($j = one('SELECT * FROM ' . T('journal') . ' WHERE id = ?', [$c['journal_id']])) && !$j['reversed_by']) {
    $ls = all('SELECT l.*, a.type, a.sub FROM ' . T('jlines') . ' l JOIN ' . T('accounts') . ' a ON a.id = l.account_id WHERE l.journal_id = ?', [$j['id']]);
    $how = ['exp' => null, 'credit' => false, 'mode' => 'cgst_sgst', 'pay' => null];
    foreach ($ls as $l) {
      if ($l['cr'] > 0) $how['pay'] = (int) $l['account_id'];
      elseif (str_starts_with((string) $l['sub'], 'gst_in_')) { $how['credit'] = true; if ($l['sub'] === 'gst_in_i') $how['mode'] = 'igst'; }
      elseif ($l['type'] === 'expense') $how['exp'] = (int) $l['account_id'];
    }
    reverse_journal($j['id'], $j['jdate'], 'Expense claim corrected by admin', $MID);
    upd('journal', $j['id'], ['ref_type' => 'claim_x']);
  }
  if ($c['project_id']) foreach (all('SELECT id FROM ' . T('expenses') . ' WHERE project_id = ? AND description LIKE ?', [$c['project_id'], '%claim #' . $c['id'] . ')']) as $x) {
    q('DELETE FROM ' . T('post_skip') . " WHERE ref_type = 'pexp' AND ref_id = ?", [$x['id']]); q('DELETE FROM ' . T('expenses') . ' WHERE id = ?', [$x['id']]);
  }
  return $how;
}

try {
switch ($A) {

// ================= META & HOME =================
case 'acc_meta':
  need_acc();
  out([
    'accounts' => all('SELECT id, code, name, type, sub, party_type FROM ' . T('accounts') . ' WHERE active = 1 ORDER BY type, code IS NULL, code, name'),
    'clients' => all('SELECT id, company FROM ' . T('clients') . ' ORDER BY company'),
    'vendors' => all('SELECT id, name FROM ' . T('vendors') . ' WHERE active = 1 ORDER BY name'),
    'staff' => all('SELECT id, name, department FROM ' . T('users') . " WHERE active = 1 ORDER BY name"),
    'projects' => all('SELECT p.id, p.expo, p.status, c.company, p.client_id FROM ' . T('projects') . ' p JOIN ' . T('clients') . " c ON c.id = p.client_id WHERE p.status <> 'rejected' ORDER BY p.id DESC LIMIT 400"),
    'company' => setting('company', []), 'claim_cats' => CLAIM_CATS,
  ]);

case 'acc_home':
  need_acc();
  $b = balances_to(today()); $sum = function ($subs, $sg = 1) use ($b) { $t = 0; foreach (all('SELECT id FROM ' . T('accounts') . ' WHERE sub IN (' . implode(',', array_fill(0, count($subs), '?')) . ')', $subs) as $a) $t += ($b[$a['id']] ?? 0) * $sg; return round($t, 2); };
  $money = array_map(fn($a) => $a + ['balance' => round($b[$a['id']] ?? 0, 2)], money_accounts());
  [, , $ti, $te] = pl_numbers(date('Y-m-01'), today());
  $c = setting('company', []); $bf = $c['books_from'] ?? '2000-01-01';
  $toPost = (int) val('SELECT COUNT(*) FROM ' . T('client_payments') . ' x WHERE pdate >= ? AND NOT EXISTS (SELECT 1 FROM ' . T('journal') . " j WHERE j.ref_type = 'cpay' AND j.ref_id = x.id) AND NOT EXISTS (SELECT 1 FROM " . T('post_skip') . " s WHERE s.ref_type = 'cpay' AND s.ref_id = x.id)", [$bf])
          + (int) val('SELECT COUNT(*) FROM ' . T('vendor_payments') . ' x WHERE pdate >= ? AND NOT EXISTS (SELECT 1 FROM ' . T('journal') . " j WHERE j.ref_type = 'vpay' AND j.ref_id = x.id) AND NOT EXISTS (SELECT 1 FROM " . T('post_skip') . " s WHERE s.ref_type = 'vpay' AND s.ref_id = x.id)", [$bf])
          + (int) val('SELECT COUNT(*) FROM ' . T('expenses') . ' x WHERE edate >= ? AND NOT EXISTS (SELECT 1 FROM ' . T('journal') . " j WHERE j.ref_type = 'pexp' AND j.ref_id = x.id) AND NOT EXISTS (SELECT 1 FROM " . T('post_skip') . " s WHERE s.ref_type = 'pexp' AND s.ref_id = x.id)", [$bf]);
  out(['money' => $money, 'receivable' => $sum(['debtor']), 'payable' => $sum(['creditor'], -1), 'staff' => $sum(['employee', 'claims_pay'], -1),
    'gst_net' => round($sum(['gst_out_c', 'gst_out_s', 'gst_out_i'], -1) - $sum(['gst_in_c', 'gst_in_s', 'gst_in_i']), 2), 'tds' => $sum(['tds_out'], -1),
    'income_month' => $ti, 'expense_month' => $te,
    'claims_waiting' => (int) val('SELECT COUNT(*) FROM ' . T('claims') . " WHERE status = 'submitted'"), 'claims_hold' => (int) val('SELECT COUNT(*) FROM ' . T('claims') . " WHERE status = 'on_hold'"),
    'to_post' => $toPost, 'inv' => inv_counts(),
    'recent' => all('SELECT j.*, (SELECT SUM(dr) FROM ' . T('jlines') . ' l WHERE l.journal_id = j.id) AS amount FROM ' . T('journal') . ' j ORDER BY j.id DESC LIMIT 12')]);

// ================= CHART OF ACCOUNTS =================
case 'acc_accounts':
  need_acc();
  $b = balances_to(today());
  $rows = all('SELECT a.*, (SELECT COUNT(*) FROM ' . T('jlines') . ' l WHERE l.account_id = a.id) AS used FROM ' . T('accounts') . ' a ORDER BY a.type, a.party_type IS NOT NULL, a.code IS NULL, a.code, a.name');
  foreach ($rows as &$r) $r['balance'] = round($b[$r['id']] ?? 0, 2); unset($r);
  out(['accounts' => $rows]);

case 'acc_account_save':
  need_acc();
  $name = s($in['name'] ?? null, 160); if (!$name) fail('Enter the account name.');
  $open = n($in['opening'] ?? 0) ?? 0; $side = ($in['opening_side'] ?? 'dr') === 'cr' ? -1 : 1;
  if (!empty($in['id'])) {
    $a = acc_get($in['id']); if (!$a) fail('Not found', 404);
    upd('accounts', $a['id'], ['name' => $name, 'code' => s($in['code'] ?? null, 20), 'opening' => round($open * $side, 2), 'active' => !empty($in['active']) ? 1 : 0]);
    out(['ok' => true]);
  }
  $type = in_array($in['type'] ?? '', ['asset', 'liability', 'equity', 'income', 'expense'], true) ? $in['type'] : fail('Pick the account type.');
  $sub = in_array($in['sub'] ?? '', ['bank', 'cash', 'petty'], true) && $type === 'asset' ? $in['sub'] : null;
  out(['id' => ins('accounts', ['code' => s($in['code'] ?? null, 20), 'name' => $name, 'type' => $type, 'sub' => $sub, 'opening' => round($open * $side, 2), 'active' => 1, 'created_at' => now()])]);

case 'acc_company_save':
  need_acc();
  $c = setting('company', []);
  foreach (['name', 'address', 'phone', 'email', 'website', 'gstin', 'pan', 'state', 'bank_benef', 'bank_name', 'bank_branch', 'bank_acno', 'bank_ifsc', 'bank_swift', 'bank_micr', 'sac', 'terms'] as $k) if (array_key_exists($k, $in)) $c[$k] = s($in[$k], 1000) ?? '';
  if ($bf = d($in['books_from'] ?? null)) $c['books_from'] = $bf;
  set_setting('company', $c); out(['ok' => true]);

// ================= ENTRIES =================
case 'acc_entry':
  need_acc();
  $k = $in['kind'] ?? ''; $date = d($in['date'] ?? null) ?? fail('Enter the date.');
  $amt = n($in['amount'] ?? 0) ?? 0; $narr = s($in['narration'] ?? null, 2000) ?? '';
  $pid = (int) ($in['project_id'] ?? 0) ?: null; $proj = $pid ? one('SELECT p.*, c.company FROM ' . T('projects') . ' p JOIN ' . T('clients') . ' c ON c.id = p.client_id WHERE p.id = ?', [$pid]) : null;
  if ($pid && !$proj) fail('Order not found.');
  tx();
  switch ($k) {
    case 'expense': {   // company overhead or order expense, paid or owed to a vendor
      $exp = acc_of_type($in['account_id'] ?? 0, 'expense'); if ($amt <= 0) fail('Enter the amount.');
      $gst = n($in['gst'] ?? 0) ?? 0; $credit = !empty($in['gst_credit']); $mode = ($in['gst_mode'] ?? '') === 'igst' ? 'igst' : 'cgst_sgst';
      $credAcc = !empty($in['vendor_id']) ? party_account('vendor', (int) $in['vendor_id'], val('SELECT name FROM ' . T('vendors') . ' WHERE id = ?', [(int) $in['vendor_id']]) ?? 'Vendor') : money_id($in['paid_from'] ?? 0);
      $lines = [[$exp, $amt + ($credit ? 0 : $gst), 0, $narr]]; if ($credit) $lines = array_merge($lines, gst_lines($gst, $mode, 'in'));
      $lines[] = [$credAcc, 0, $amt + $gst, $narr];
      $jid = post_journal($date, 'Expense', $narr ?: acc_get($exp)['name'], $lines, null, null, $pid, $MID);
      if ($proj) { $eid = ins('expenses', ['project_id' => $pid, 'edate' => $date, 'category' => pexp_cat(acc_get($exp)['sub']), 'description' => mb_substr($narr ?: acc_get($exp)['name'], 0, 250), 'amount' => $amt + ($credit ? 0 : $gst)]); upd('journal', $jid, ['ref_type' => 'pexp', 'ref_id' => $eid]); }
      break; }
    case 'vendor_pay': {
      $vid = (int) ($in['vendor_id'] ?? 0); $vn = val('SELECT name FROM ' . T('vendors') . ' WHERE id = ?', [$vid]) ?? fail('Pick the vendor.');
      $tds = n($in['tds'] ?? 0) ?? 0; if ($amt <= 0) fail('Enter the amount paid.');
      $money = money_id($in['paid_from'] ?? 0); $exp = !empty($in['account_id']) ? acc_of_type($in['account_id'], 'expense') : acc_sub('fab');
      $lines = [[$exp, $amt + $tds, 0, "Paid to $vn"], [$money, 0, $amt, "Paid to $vn"]]; if ($tds > 0) $lines[] = [acc_sub('tds_out'), 0, $tds, "TDS on $vn"];
      $jid = post_journal($date, 'Payment', $narr ?: "Payment to $vn" . ($proj ? " for {$proj['expo']} ({$proj['company']})" : ''), $lines, null, null, $pid, $MID);
      if ($proj) { $vp = ins('vendor_payments', ['project_id' => $pid, 'vendor_id' => $vid, 'pdate' => $date, 'amount' => $amt + $tds, 'mode' => mode_of($money), 'reference' => s($in['reference'] ?? null, 120)]); upd('journal', $jid, ['ref_type' => 'vpay', 'ref_id' => $vp]); }
      break; }
    case 'receipt': {
      $money = money_id($in['paid_from'] ?? 0); $tds = n($in['tds'] ?? 0) ?? 0; if ($amt <= 0) fail('Enter the amount received.');
      $cid = (int) ($in['client_id'] ?? ($proj['client_id'] ?? 0));
      if ($cid) { $cn = val('SELECT company FROM ' . T('clients') . ' WHERE id = ?', [$cid]) ?? fail('Client not found.'); $credit = party_account('client', $cid, $cn); }
      else { $credit = acc_of_type($in['account_id'] ?? 0, 'income'); $cn = acc_get($credit)['name']; }
      $lines = [[$money, $amt, 0, "Received from $cn"], [$credit, 0, $amt + $tds, "Received from $cn"]]; if ($tds > 0) $lines[] = [acc_sub('tds_in'), $tds, 0, "TDS deducted by $cn"];
      $jid = post_journal($date, 'Receipt', $narr ?: "Receipt from $cn" . ($proj ? " for {$proj['expo']}" : ''), $lines, null, null, $pid, $MID);
      if ($proj) { $cp = ins('client_payments', ['project_id' => $pid, 'pdate' => $date, 'amount' => $amt + $tds, 'mode' => mode_of($money), 'reference' => s($in['reference'] ?? null, 120)]); upd('journal', $jid, ['ref_type' => 'cpay', 'ref_id' => $cp]); }
      break; }
    case 'salary': {
      $u = one('SELECT id, name FROM ' . T('users') . ' WHERE id = ?', [(int) ($in['user_id'] ?? 0)]) ?? fail('Pick the employee.');
      $month = preg_match('/^\d{4}-\d{2}$/', $in['month'] ?? '') ? $in['month'] : fail('Pick the salary month.');
      $gross = n($in['gross'] ?? 0) ?? 0; $ded = n($in['deductions'] ?? 0) ?? 0; $tds = n($in['tds'] ?? 0) ?? 0; $net = round($gross - $ded - $tds, 2);
      if ($gross <= 0 || $net < 0) fail('Check gross salary and deductions.');
      $money = money_id($in['paid_from'] ?? 0);
      $lines = [[acc_sub('salary'), $gross, 0, "Salary {$u['name']} $month"], [$money, 0, $net, "Salary {$u['name']} $month"]];
      if ($ded > 0) $lines[] = [acc_sub('sal_ded'), 0, $ded, s($in['deduction_note'] ?? null, 200) ?? 'Deductions'];
      if ($tds > 0) $lines[] = [acc_sub('tds_out'), 0, $tds, 'TDS on salary'];
      $jid = post_journal($date, 'Salary', $narr ?: "Salary for $month — {$u['name']}", $lines, null, null, null, $MID);
      ins('salaries', ['user_id' => $u['id'], 'month' => $month, 'gross' => $gross, 'deductions' => $ded + $tds, 'deduction_note' => trim((s($in['deduction_note'] ?? null, 200) ?? '') . ($tds > 0 ? ' TDS ₹' . inr_txt($tds) : '')), 'net' => $net, 'paid_on' => $date, 'journal_id' => $jid, 'created_at' => now()]);
      break; }
    case 'reimburse': {
      $u = one('SELECT id, name FROM ' . T('users') . ' WHERE id = ?', [(int) ($in['user_id'] ?? 0)]) ?? fail('Pick the employee.');
      if ($amt <= 0) fail('Enter the amount.');
      $jid = post_journal($date, 'Payment', $narr ?: "Expense reimbursement to {$u['name']}", [[party_account('employee', $u['id'], $u['name']), $amt, 0, 'Reimbursement'], [money_id($in['paid_from'] ?? 0), 0, $amt, 'Reimbursement']], null, null, null, $MID);
      break; }
    case 'contra': {
      $from = money_id($in['from_id'] ?? 0); $to = money_id($in['to_id'] ?? 0); if ($from === $to) fail('From and to must be different.'); if ($amt <= 0) fail('Enter the amount.');
      $jid = post_journal($date, 'Contra', $narr ?: acc_get($from)['name'] . ' → ' . acc_get($to)['name'], [[$to, $amt, 0, ''], [$from, 0, $amt, '']], null, null, null, $MID);
      break; }
    case 'tax_gst': {   // set input credit off against output GST and pay the rest
      $b = balances_to($date); $bal = fn($s) => round($b[acc_sub($s)] ?? 0, 2);
      $out = ['gst_out_i' => -$bal('gst_out_i'), 'gst_out_c' => -$bal('gst_out_c'), 'gst_out_s' => -$bal('gst_out_s')];
      $inp = ['gst_in_i' => $bal('gst_in_i'), 'gst_in_c' => $bal('gst_in_c'), 'gst_in_s' => $bal('gst_in_s')];
      $O = array_sum(array_map(fn($v) => max(0, $v), $out)); $I = array_sum(array_map(fn($v) => max(0, $v), $inp));
      $useIn = min($I, $O); if ($amt > $O - $useIn + 0.01) fail('Payment is more than GST payable (₹' . inr_txt($O - $useIn) . ').');
      if ($useIn + $amt <= 0) fail('Nothing to set off or pay.');
      $lines = []; $left = $useIn + $amt;
      foreach ($out as $s => $v) { if ($v <= 0 || $left <= 0) continue; $x = min($v, $left); $lines[] = [acc_sub($s), round($x, 2), 0, 'GST set off / paid']; $left -= $x; }
      $left = $useIn; foreach ($inp as $s => $v) { if ($v <= 0 || $left <= 0) continue; $x = min($v, $left); $lines[] = [acc_sub($s), 0, round($x, 2), 'Input credit used']; $left -= $x; }
      if ($amt > 0) $lines[] = [money_id($in['paid_from'] ?? 0), 0, $amt, 'GST paid'];
      $jid = post_journal($date, 'Tax', $narr ?: 'GST payment' . (s($in['period'] ?? null) ? ' for ' . s($in['period']) : ''), $lines, null, null, null, $MID);
      break; }
    case 'tax_tds': {
      if ($amt <= 0) fail('Enter the amount.');
      $jid = post_journal($date, 'Tax', $narr ?: 'TDS deposited' . (s($in['period'] ?? null) ? ' for ' . s($in['period']) : ''), [[acc_sub('tds_out'), $amt, 0, 'TDS deposited'], [money_id($in['paid_from'] ?? 0), 0, $amt, 'TDS deposited']], null, null, null, $MID);
      break; }
    case 'journal': {
      $lines = array_map(fn($l) => [(int) (((array) $l)['account_id'] ?? 0), n(((array) $l)['dr'] ?? 0) ?? 0, n(((array) $l)['cr'] ?? 0) ?? 0, s(((array) $l)['memo'] ?? null, 250)], (array) ($in['lines'] ?? []));
      $jid = post_journal($date, 'Journal', $narr ?: 'Journal entry', $lines, null, null, $pid, $MID);
      break; }
    default: fail('Unknown entry type.');
  }
  db()->commit();
  out(['id' => $jid, 'vno' => val('SELECT vno FROM ' . T('journal') . ' WHERE id = ?', [$jid])]);

case 'acc_journal':
  need_acc();
  $j = one('SELECT * FROM ' . T('journal') . ' WHERE id = ?', [(int) ($in['id'] ?? 0)]) ?? fail('Not found', 404);
  $j['lines'] = all('SELECT l.*, a.name FROM ' . T('jlines') . ' l JOIN ' . T('accounts') . ' a ON a.id = l.account_id WHERE l.journal_id = ? ORDER BY l.cr, l.id', [$j['id']]);
  $j['files'] = files_of('voucher', [$j['id']])[$j['id']] ?? []; $j['by'] = users_map()[$j['created_by']] ?? '—';
  out(['journal' => $j]);

case 'acc_reverse':
  need_acc();
  lock_row('journal', $in['id'] ?? 0);
  $j = one('SELECT * FROM ' . T('journal') . ' WHERE id = ?', [(int) ($in['id'] ?? 0)]) ?? fail('Not found', 404);
  if ($j['ref_type'] === 'invoice') fail('This came from an invoice. Cancel the invoice instead.');
  if ($j['ref_type'] === 'claim') fail('This came from an approved expense claim and cannot be reversed here. Post a correcting journal instead.');
  $why = s($in['reason'] ?? null, 500) ?? fail('Write why it is being reversed.');
  tx(); $rid = reverse_journal($j['id'], d($in['date'] ?? null) ?? today(), $why, $MID); db()->commit();
  out(['id' => $rid]);


case 'acc_edit':   // admin: correct a book entry (old one is reversed on its own date, corrected one posted in its place)
  need_admin();
  lock_row('journal', $in['id'] ?? 0);
  $j = one('SELECT * FROM ' . T('journal') . ' WHERE id = ?', [(int) ($in['id'] ?? 0)]) ?? fail('Not found', 404);
  if ($j['reversed_by']) fail('This entry is already reversed / replaced.');
  if ($j['vtype'] === 'Reversal') fail('A reversal cannot be edited.');
  if ($j['ref_type'] === 'invoice') fail('This came from a tax invoice. Cancel the invoice instead.');
  if ($j['ref_type'] === 'claim') fail('This came from an expense claim. Edit the claim in Expense approvals — the books are corrected automatically.');
  $lines = [];
  foreach (array_slice((array) ($in['lines'] ?? []), 0, 40) as $l) { $l = (array) $l; $a = (int) ($l['account_id'] ?? 0); $dr = n($l['dr'] ?? null) ?? 0; $cr = n($l['cr'] ?? null) ?? 0;
    if (!$a && !$dr && !$cr) continue; if (!acc_get($a)) fail('Pick an account on every line.'); $lines[] = [$a, $dr, $cr, s($l['memo'] ?? null, 255)]; }
  if (count($lines) < 2) fail('An entry needs at least two lines.');
  $date = d($in['date'] ?? null) ?? $j['jdate']; $narr = s($in['narration'] ?? null, 1000) ?? $j['narration'];
  $before = $j + ['lines' => all('SELECT account_id, dr, cr, memo FROM ' . T('jlines') . ' WHERE journal_id = ?', [$j['id']])];
  tx();
  $new = post_journal($date, $j['vtype'], $narr, $lines, $j['ref_type'], $j['ref_id'], $j['project_id'], $MID);
  reverse_journal($j['id'], $j['jdate'], 'Edited by admin — replaced by ' . val('SELECT vno FROM ' . T('journal') . ' WHERE id = ?', [$new]), $MID);
  if ($j['ref_type']) upd('journal', $j['id'], ['ref_type' => mb_substr($j['ref_type'] . '_x', 0, 20)]);
  upd('journal', $new, ['replaces' => $j['id']]);
  q('UPDATE ' . T('files') . " SET entity_id = ? WHERE entity = 'voucher' AND entity_id = ?", [$new, $j['id']]);
  audit_log('journal', $j['id'], 'edit', $before, ['id' => $new, 'date' => $date, 'narration' => $narr, 'lines' => $lines], $j['project_id']);
  db()->commit(); out(['id' => $new]);

case 'claim_admin_save':   // admin: change any expense claim; an approved one is re-booked with the new figures
  need_admin();
  claim_row($in['id'] ?? 0); $c = lock_row('claims', $in['id']);
  $data = ['edate' => d($in['edate'] ?? null) ?? fail('Enter the date of expense.'), 'particular' => s($in['particular'] ?? null, 255) ?? fail('Enter the particular of expense.'),
    'category' => array_key_exists($in['category'] ?? '', CLAIM_CATS) ? $in['category'] : 'Other', 'rate' => n($in['rate'] ?? null) ?? fail('Enter the unit rate.'), 'qty' => n($in['qty'] ?? 1) ?: 1, 'gst' => n($in['gst'] ?? 0) ?? 0, 'note' => s($in['note'] ?? null, 2000),
    'project_id' => (int) ($in['project_id'] ?? 0) ?: null];
  if ($data['rate'] <= 0) fail('Unit rate must be more than zero.');
  $data['total'] = round($data['rate'] * $data['qty'], 2); $data['grand'] = round($data['total'] + $data['gst'], 2);
  $ch = [];
  foreach (['edate' => 'Date', 'particular' => 'Particular', 'category' => 'Category', 'rate' => 'Unit rate', 'qty' => 'Qty', 'gst' => 'GST', 'grand' => 'Grand total', 'note' => 'Note', 'project_id' => 'Order'] as $k => $l)
    if ((string) ($c[$k] ?? '') !== (string) ($data[$k] ?? '') && !(is_numeric($c[$k]) && is_numeric($data[$k]) && (float) $c[$k] == (float) $data[$k])) $ch[] = "$l: " . ($c[$k] ?? '—') . ' → ' . ($data[$k] ?? '—');
  if (!$ch) fail('Nothing was changed.');
  tx();
  if ($c['status'] === 'approved') {
    $how = claim_unpost($c); upd('claims', $c['id'], $data); $c2 = claim_row($c['id']);
    $u = one('SELECT name FROM ' . T('users') . ' WHERE id = ?', [$c['user_id']]);
    $credit = $how && $how['credit'] && $c2['gst'] > 0;
    $jid = claim_post($c2, $how['exp'] ?? acc_sub(CLAIM_CATS[$c2['category']] ?? 'exp_misc'), $credit, $how['mode'] ?? 'cgst_sgst', $how['pay'] ?? party_account('employee', $c['user_id'], $u['name']), $u['name']);
    upd('claims', $c['id'], ['journal_id' => $jid]);
  } else upd('claims', $c['id'], $data);
  claim_note($c['id'], 'edited', 'Changed by admin: ' . implode(' · ', $ch));
  audit_log('claim', $c['id'], 'edit', $c, $data, $c['project_id']);
  db()->commit(); out(['ok' => true]);

case 'claim_admin_del':
  need_admin();
  claim_row($in['id'] ?? 0); $c = lock_row('claims', $in['id']);
  tx();
  if ($c['status'] === 'approved') claim_unpost($c);
  audit_log('claim', $c['id'], 'delete', $c, null, $c['project_id']);
  q('DELETE FROM ' . T('claim_notes') . ' WHERE claim_id = ?', [$c['id']]); q('DELETE FROM ' . T('claims') . ' WHERE id = ?', [$c['id']]);
  db()->commit(); out(['ok' => true]);

// ================= POST CRM ENTRIES =================
case 'acc_inbox':
  need_acc();
  $c = setting('company', []); $bf = $c['books_from'] ?? '2000-01-01';
  $nj = fn($t) => "NOT EXISTS (SELECT 1 FROM " . T('journal') . " j WHERE j.ref_type = '$t' AND j.ref_id = x.id) AND NOT EXISTS (SELECT 1 FROM " . T('post_skip') . " s WHERE s.ref_type = '$t' AND s.ref_id = x.id)";
  $q = fn($tbl, $t, $dcol, $extra) => all("SELECT x.*, p.expo, p.client_id, c.company $extra FROM " . T($tbl) . ' x JOIN ' . T('projects') . ' p ON p.id = x.project_id JOIN ' . T('clients') . " c ON c.id = p.client_id WHERE x.$dcol >= ? AND " . $nj($t) . " ORDER BY x.$dcol", [$bf]);
  out(['cpay' => $q('client_payments', 'cpay', 'pdate', ''), 'vpay' => $q('vendor_payments', 'vpay', 'pdate', ', (SELECT name FROM ' . T('vendors') . ' v WHERE v.id = x.vendor_id) AS vendor'), 'pexp' => $q('expenses', 'pexp', 'edate', ''), 'books_from' => $bf]);

case 'acc_post_crm':
  need_acc();
  $t = $in['ref_type'] ?? ''; $id = (int) ($in['ref_id'] ?? 0);
  $tbl = ['cpay' => 'client_payments', 'vpay' => 'vendor_payments', 'pexp' => 'expenses'][$t] ?? fail('Unknown item.');
  $x = one('SELECT x.*, p.expo, p.client_id, c.company FROM ' . T($tbl) . ' x JOIN ' . T('projects') . ' p ON p.id = x.project_id JOIN ' . T('clients') . ' c ON c.id = p.client_id WHERE x.id = ?', [$id]) ?? fail('Not found', 404);
  lock_row($tbl, $id);
  if (val('SELECT COUNT(*) FROM ' . T('journal') . ' WHERE ref_type = ? AND ref_id = ?', [$t, $id])) fail('Already posted.');
  if (val('SELECT COUNT(*) FROM ' . T('post_skip') . ' WHERE ref_type = ? AND ref_id = ?', [$t, $id])) fail('Already skipped.');
  if (!empty($in['skip'])) { ins('post_skip', ['ref_type' => $t, 'ref_id' => $id]); out(['ok' => true]); }
  $money = money_id($in['money_id'] ?? 0);
  tx();
  if ($t === 'cpay') $jid = post_journal($x['pdate'], 'Receipt', "Receipt from {$x['company']} for {$x['expo']}" . ($x['reference'] ? " ({$x['reference']})" : ''), [[$money, $x['amount'], 0, ''], [party_account('client', $x['client_id'], $x['company']), 0, $x['amount'], '']], 'cpay', $id, $x['project_id'], $MID);
  elseif ($t === 'vpay') { $vn = val('SELECT name FROM ' . T('vendors') . ' WHERE id = ?', [$x['vendor_id']]) ?? 'Vendor';
    $exp = !empty($in['account_id']) ? acc_of_type($in['account_id'], 'expense') : acc_sub('fab');
    $jid = post_journal($x['pdate'], 'Payment', "Paid $vn for {$x['expo']} ({$x['company']})", [[$exp, $x['amount'], 0, ''], [$money, 0, $x['amount'], '']], 'vpay', $id, $x['project_id'], $MID); }
  else { $exp = !empty($in['account_id']) ? acc_of_type($in['account_id'], 'expense') : acc_sub(PEXP_SUB[$x['category']] ?? 'exp_misc');
    $jid = post_journal($x['edate'], 'Expense', "{$x['category']}: " . ($x['description'] ?: '') . " — {$x['expo']} ({$x['company']})", [[$exp, $x['amount'], 0, ''], [$money, 0, $x['amount'], '']], 'pexp', $id, $x['project_id'], $MID); }
  db()->commit(); out(['id' => $jid]);

// ================= INVOICES =================
// Flow: sales raises a request → accounts prepares and sends to admin → admin approves (number given, tax invoice posted) / holds / rejects.
case 'inv_list':
  need_acc();
  $g = $in['group'] ?? 'all'; $w = '1=1'; $p = [];
  if (isset(INV_GROUPS[$g])) { $w .= ' AND i.status IN (' . implode(',', array_fill(0, count(INV_GROUPS[$g]), '?')) . ')'; $p = INV_GROUPS[$g]; }
  if (in_array($in['kind'] ?? '', ['proforma', 'tax'], true)) { $w .= ' AND i.kind = ?'; $p[] = $in['kind']; }
  if ($qs = s($in['q'] ?? null, 100)) { $w .= ' AND (c.company LIKE ? OR i.inv_no LIKE ?)'; $p[] = "%$qs%"; $p[] = "%$qs%"; }
  out(['counts' => inv_counts(), 'invoices' => all('SELECT i.*, c.company, p.expo, u.name AS requester FROM ' . T('invoices') . ' i JOIN ' . T('clients') . ' c ON c.id = i.client_id LEFT JOIN ' . T('projects') . ' p ON p.id = i.project_id LEFT JOIN ' . T('users') . " u ON u.id = i.requested_by WHERE $w ORDER BY i.id DESC LIMIT 500", $p)]);

case 'inv_mine':   // sales: my requests and the approved invoices for my orders
  need_seller();
  $rows = all('SELECT i.*, c.company, p.expo FROM ' . T('invoices') . ' i JOIN ' . T('clients') . ' c ON c.id = i.client_id LEFT JOIN ' . T('projects') . ' p ON p.id = i.project_id WHERE ' . ($ADMIN ? '1=1' : '(i.requested_by = ? OR p.owner_id = ?)') . ' ORDER BY i.id DESC LIMIT 500', $ADMIN ? [] : [$MID, $MID]);
  $orders = all('SELECT p.id, p.expo, c.company, p.client_id FROM ' . T('projects') . ' p JOIN ' . T('clients') . " c ON c.id = p.client_id WHERE " . ($ADMIN ? '1=1' : 'p.owner_id = ?') . " AND p.status <> 'rejected' ORDER BY p.id DESC LIMIT 300", $ADMIN ? [] : [$MID]);
  $clients = all('SELECT id, company FROM ' . T('clients') . ($ADMIN ? '' : ' WHERE owner_id = ?') . ' ORDER BY company', $ADMIN ? [] : [$MID]);
  out(['invoices' => $rows, 'orders' => $orders, 'clients' => $clients]);

case 'inv_request':   // sales asks accounts for a proforma / tax invoice
  need_seller();
  $kind = ($in['kind'] ?? '') === 'tax' ? 'tax' : 'proforma';
  $note = s($in['note'] ?? null, 2000) ?? fail('Write what should be billed (e.g. 50% advance, full amount, extras).');
  $state = setting('company', [])['state'] ?? 'Delhi';
  if (!empty($in['from_id'])) {   // tax invoice from an approved proforma
    lock_row('invoices', $in['from_id']); $f = inv_row($in['from_id']); if (!inv_can_see($f)) fail('Not your invoice.', 403);
    if ($f['kind'] !== 'proforma' || $f['status'] !== 'issued') fail('Only an approved proforma can be turned into a tax invoice.');
    if ($ex = val('SELECT id FROM ' . T('invoices') . " WHERE from_id = ? AND status <> 'cancelled'", [$f['id']])) fail('A tax invoice for this proforma is already requested.');
    $data = ['kind' => 'tax', 'inv_date' => today(), 'client_id' => $f['client_id'], 'project_id' => $f['project_id'], 'place_state' => $f['place_state'], 'gst_mode' => $f['gst_mode'], 'gst_rate' => $f['gst_rate'],
      'lines_json' => $f['lines_json'], 'discount' => $f['discount'] ?? 0, 'round_off' => $f['round_off'] ?? 0, 'round_mode' => $f['round_mode'] ?? 'auto', 'taxable' => $f['taxable'], 'gst' => $f['gst'], 'total' => $f['total'], 'notes' => $f['notes'], 'from_id' => $f['id']];
  } elseif (!empty($in['project_id'])) {
    $pf = inv_prefill_lines($in['project_id']); if (!$ADMIN && $pf['owner_id'] !== $MID) fail('This order is not yours.', 403);
    [$lines, $taxable, $gst, $total, , , $ro] = inv_totals($pf['lines'], $pf['gst_rate']);
    $data = ['kind' => $kind, 'inv_date' => today(), 'client_id' => $pf['client_id'], 'project_id' => $pf['project_id'], 'place_state' => $state, 'gst_mode' => 'cgst_sgst', 'gst_rate' => $pf['gst_rate'],
      'lines_json' => json_encode($lines, JSON_UNESCAPED_UNICODE), 'round_off' => $ro, 'taxable' => $taxable, 'gst' => $gst, 'total' => $total];
  } else {
    $cid = (int) ($in['client_id'] ?? 0); if (!val('SELECT COUNT(*) FROM ' . T('clients') . ' WHERE id = ?' . ($ADMIN ? '' : ' AND owner_id = ' . $MID), [$cid])) fail('Pick the order or the client.');
    $data = ['kind' => $kind, 'inv_date' => today(), 'client_id' => $cid, 'project_id' => null, 'place_state' => $state, 'gst_mode' => 'cgst_sgst', 'gst_rate' => 18, 'lines_json' => '[]', 'taxable' => 0, 'gst' => 0, 'total' => 0];
  }
  $id = ins('invoices', $data + ['status' => 'requested', 'requested_by' => $MID, 'request_note' => $note, 'version' => 0, 'created_by' => $MID, 'created_at' => now()]);
  inv_log($id, 'requested', $note);
  out(['id' => $id]);

case 'inv_get':
  $i = inv_row($in['id'] ?? 0); if (!inv_can_see($i)) fail('Not your invoice.', 403);
  $i['lines'] = json_decode($i['lines_json'], true) ?: []; unset($i['lines_json']);
  $um = users_map();
  $log = array_map(function ($l) use ($um) { $l['by'] = $um[$l['user_id']] ?? '—'; $s = $l['snap'] ? json_decode($l['snap'], true) : null; $l['changes'] = $s['changes'] ?? []; $l['total'] = $s['data']['total'] ?? null; unset($l['snap']); return $l; },
    all('SELECT * FROM ' . T('inv_log') . ' WHERE invoice_id = ? ORDER BY id', [$i['id']]));
  out(['invoice' => $i, 'log' => $log, 'tax_child' => val('SELECT id FROM ' . T('invoices') . " WHERE from_id = ? AND status <> 'cancelled'", [$i['id']]) ?: null]);

case 'inv_prefill':
  need_acc();
  $pf = inv_prefill_lines($in['project_id'] ?? 0); unset($pf['owner_id']); out($pf);

case 'inv_save':   // accounts prepares / corrects; optional send to admin in the same step
  need_acc();
  $kind = ($in['kind'] ?? '') === 'tax' ? 'tax' : 'proforma';
  $cid = (int) ($in['client_id'] ?? 0); if (!val('SELECT COUNT(*) FROM ' . T('clients') . ' WHERE id = ?', [$cid])) fail('Pick the client.');
  $rate = (float) ($in['gst_rate'] ?? 18); if ($rate < 0 || $rate > 28) fail('GST rate looks wrong.');
  $rmode = ($in['round_mode'] ?? 'auto') === 'none' ? 'none' : 'auto';
  $dIn = str_replace([',', ' ', '₹'], '', (string) ($in['discount'] ?? '')); if ($dIn !== '' && !is_numeric($dIn)) fail('Enter the discount as a number.');
  [$lines, $taxable, $gst, $total, , $disc, $ro] = inv_totals($in['lines'] ?? [], $rate, (float) $dIn, $rmode); if (!$lines) fail('Add at least one line.');
  $state = s($in['place_state'] ?? null, 60) ?? (setting('company', [])['state'] ?? '');
  $mode = mb_strtolower(trim($state)) === mb_strtolower(trim(setting('company', [])['state'] ?? '')) ? 'cgst_sgst' : 'igst';
  $data = ['inv_date' => d($in['inv_date'] ?? null) ?? today(), 'client_id' => $cid, 'project_id' => (int) ($in['project_id'] ?? 0) ?: null, 'place_state' => $state, 'gst_mode' => $mode, 'gst_rate' => $rate,
    'lines_json' => json_encode($lines, JSON_UNESCAPED_UNICODE), 'discount' => $disc, 'round_off' => $ro, 'round_mode' => $rmode, 'taxable' => $taxable, 'gst' => $gst, 'total' => $total, 'notes' => s($in['notes'] ?? null, 2000)];
  tx();
  if (!empty($in['id'])) {
    lock_row('invoices', $in['id']); $i = inv_row($in['id']); if (!in_array($i['status'], INV_EDITABLE, true)) fail($i['status'] === 'pending' ? 'It is waiting for admin. It can be changed if admin puts it on hold or rejects it.' : 'This invoice can no longer be changed.');
    if ($i['inv_no'] && $i['kind'] !== $kind) fail('Kind cannot change after a number is given.');
    upd('invoices', $i['id'], $data + ['kind' => $kind, 'status' => $i['status'] === 'requested' ? 'draft' : $i['status']]);
    if ($i['status'] === 'requested') inv_log($i['id'], 'prepared');
    $id = (int) $i['id'];
  } else {
    $id = ins('invoices', $data + ['kind' => $kind, 'status' => 'draft', 'version' => 0, 'created_by' => $MID, 'created_at' => now(), 'from_id' => (int) ($in['from_id'] ?? 0) ?: null]);
    inv_log($id, 'created');
  }
  if (!empty($in['submit'])) {
    $i = inv_row($id); $ver = (int) $i['version'] + 1; $snap = inv_snap($i);
    $prev = val('SELECT snap FROM ' . T('inv_log') . " WHERE invoice_id = ? AND action = 'submitted' AND snap IS NOT NULL ORDER BY id DESC LIMIT 1", [$id]);
    $changes = $prev ? inv_diff(json_decode($prev, true)['data'], $snap) : [];
    if ($prev && !$changes && in_array($i['status'], ['on_hold', 'rejected'], true) && !s($in['message'] ?? null)) fail('Nothing was changed. Make the corrections, or write a message for admin.');
    upd('invoices', $id, ['status' => 'pending', 'version' => $ver, 'submitted_at' => now()]);
    inv_log($id, 'submitted', s($in['message'] ?? null, 2000), $ver, ['data' => $snap, 'changes' => $changes]);
  }
  db()->commit(); out(['id' => $id]);

case 'inv_decide':   // admin: approve / hold / reject
  need_admin();
  lock_row('invoices', $in['id'] ?? 0); $i = inv_row($in['id'] ?? 0); if ($i['status'] !== 'pending') fail('This is already decided — it is not waiting for approval any more.');
  $act = $in['action'] ?? ''; $why = s($in['reason'] ?? null, 2000);
  if (!in_array($act, ['approve', 'hold', 'reject'], true)) fail('Pick approve, hold or reject.');
  if ($act !== 'approve' && !$why) fail($act === 'hold' ? 'Write what details are needed.' : 'Write why it is rejected and what to correct.');
  tx();
  if ($act === 'approve') { $no = inv_issue_do($i); upd('invoices', $i['id'], ['decided_by' => $me['name'], 'decided_at' => now(), 'reason' => $why]); inv_log($i['id'], 'approved', $why, (int) $i['version']); db()->commit(); out(['inv_no' => $no]); }
  upd('invoices', $i['id'], ['status' => $act === 'hold' ? 'on_hold' : 'rejected', 'decided_by' => $me['name'], 'decided_at' => now(), 'reason' => $why]);
  inv_log($i['id'], $act === 'hold' ? 'hold' : 'rejected', $why, (int) $i['version']);
  db()->commit(); out(['ok' => true]);

case 'inv_reply':   // accounts or sales add details / comments
  $i = inv_row($in['id'] ?? 0); if (!inv_can_see($i)) fail('Not your invoice.', 403);
  if ($i['status'] === 'cancelled') fail('This invoice is cancelled.');
  inv_log($i['id'], 'reply', s($in['text'] ?? null, 2000) ?? fail('Write the details.'));
  out(['ok' => true]);

case 'inv_correct':   // reopen an approved proforma for correction (number is kept, goes through approval again)
  lock_row('invoices', $in['id'] ?? 0); $i = inv_row($in['id'] ?? 0); if (!inv_can_see($i)) fail('Not your invoice.', 403);
  if ($i['status'] !== 'issued') fail('Only an approved invoice can be reopened.');
  if ($i['kind'] === 'tax') fail('An approved tax invoice cannot be edited. Admin can cancel it and a new one can be made.');
  $why = s($in['reason'] ?? null, 2000) ?? fail('Write what needs to be corrected.');
  if (val('SELECT id FROM ' . T('invoices') . " WHERE from_id = ? AND status <> 'cancelled'", [$i['id']])) fail('A tax invoice is already made from this proforma.');
  upd('invoices', $i['id'], ['status' => $ACC ? 'draft' : 'requested']);
  inv_log($i['id'], 'correction', $why);
  out(['ok' => true]);

case 'inv_convert':   // accounts: draft tax invoice from an approved proforma
  need_acc();
  lock_row('invoices', $in['id'] ?? 0); $i = inv_row($in['id'] ?? 0);
  if ($i['kind'] !== 'proforma' || $i['status'] !== 'issued') fail('Only an approved proforma can be turned into a tax invoice.');
  if ($ex = val('SELECT id FROM ' . T('invoices') . " WHERE from_id = ? AND status <> 'cancelled'", [$i['id']])) out(['id' => (int) $ex]);
  $copy = array_intersect_key($i, array_flip(['client_id', 'project_id', 'place_state', 'gst_mode', 'gst_rate', 'lines_json', 'discount', 'round_off', 'round_mode', 'taxable', 'gst', 'total', 'notes', 'requested_by', 'request_note']));
  $id = ins('invoices', $copy + ['kind' => 'tax', 'status' => 'draft', 'version' => 0, 'inv_date' => today(), 'from_id' => $i['id'], 'created_by' => $MID, 'created_at' => now()]);
  inv_log($id, 'created', 'Made from proforma ' . $i['inv_no']);
  out(['id' => $id]);

case 'inv_cancel':
  need_acc();
  lock_row('invoices', $in['id'] ?? 0); $i = inv_row($in['id'] ?? 0);
  if ($i['status'] === 'cancelled') fail('Already cancelled.');
  if (!$ADMIN && !in_array($i['status'], ['requested', 'draft'], true)) fail('Only admin can cancel an invoice after it is sent for approval.');
  $why = s($in['reason'] ?? null, 500) ?? fail('Write why it is cancelled.');
  tx();
  if ($i['journal_id']) { $j = one('SELECT * FROM ' . T('journal') . ' WHERE id = ?', [$i['journal_id']]); upd('journal', $j['id'], ['ref_type' => 'invoice_x']); reverse_journal($j['id'], today(), "Invoice {$i['inv_no']} cancelled: $why", $MID); }
  upd('invoices', $i['id'], ['status' => 'cancelled']);
  inv_log($i['id'], 'cancelled', $why);
  db()->commit(); out(['ok' => true]);

// ================= EXPENSE CLAIMS =================
case 'claim_mine':
  need_claimer();
  $opts = $MODULE === 'ops' ? all('SELECT p.id, p.expo, c.company FROM ' . T('projects') . ' p JOIN ' . T('clients') . " c ON c.id = p.client_id WHERE p.ops_id = ? AND p.status <> 'rejected' ORDER BY p.id DESC", [$MID])
        : all('SELECT p.id, p.expo, c.company FROM ' . T('projects') . ' p JOIN ' . T('clients') . " c ON c.id = p.client_id WHERE " . ($ADMIN ? '1=1' : 'p.owner_id = ?') . " AND p.status <> 'rejected' ORDER BY p.id DESC LIMIT 300", $ADMIN ? [] : [$MID]);
  out(['claims' => claims_out('c.user_id = ?', [$MID]), 'projects' => $opts, 'cats' => array_keys(CLAIM_CATS)]);

case 'claim_save':
  need_claimer();
  $data = ['edate' => d($in['edate'] ?? null) ?? fail('Enter the date of expense.'), 'particular' => s($in['particular'] ?? null, 255) ?? fail('Enter the particular of expense.'),
    'category' => array_key_exists($in['category'] ?? '', CLAIM_CATS) ? $in['category'] : 'Other', 'rate' => n($in['rate'] ?? null) ?? fail('Enter the unit rate.'), 'qty' => n($in['qty'] ?? 1) ?: 1, 'gst' => n($in['gst'] ?? 0) ?? 0, 'note' => s($in['note'] ?? null, 2000)];
  if ($data['edate'] > today()) fail('Date of expense cannot be in the future.');
  if ($data['rate'] <= 0) fail('Unit rate must be more than zero.');
  $data['total'] = round($data['rate'] * $data['qty'], 2); $data['grand'] = round($data['total'] + $data['gst'], 2);
  $pid = (int) ($in['project_id'] ?? 0);
  if ($pid && !$ADMIN && !val('SELECT COUNT(*) FROM ' . T('projects') . ' WHERE id = ? AND (owner_id = ? OR ops_id = ?)', [$pid, $MID, $MID])) fail('Pick one of your own orders.');
  $data['project_id'] = $pid ?: null;
  if (!empty($in['id'])) {
    claim_row($in['id']); $c = lock_row('claims', $in['id']); if ((int) $c['user_id'] !== $MID) fail('Not yours.', 403);
    if (!in_array($c['status'], ['rejected', 'on_hold'], true)) fail('Only a rejected or on-hold expense can be changed.');
    upd('claims', $c['id'], $data + ['status' => 'submitted', 'submitted_at' => now()]); claim_note($c['id'], 'resubmitted', s($in['reply'] ?? null, 2000) ?? 'Corrected and sent again');
    out(['id' => (int) $c['id']]);
  }
  $id = ins('claims', $data + ['user_id' => $MID, 'status' => 'submitted', 'submitted_at' => now(), 'created_at' => now()]);
  claim_note($id, 'submitted', null); out(['id' => $id]);

case 'claim_reply':
  need_claimer();
  $c = claim_row($in['id'] ?? 0); if ((int) $c['user_id'] !== $MID) fail('Not yours.', 403);
  if ($c['status'] !== 'on_hold') fail('Accounts has not asked for more details on this one.');
  $t = s($in['text'] ?? null, 2000) ?? fail('Write the details accounts asked for.');
  claim_note($c['id'], 'reply', $t); upd('claims', $c['id'], ['status' => 'submitted', 'submitted_at' => now()]);
  out(['ok' => true]);

case 'claim_del':
  need_claimer();
  claim_row($in['id'] ?? 0); $c = lock_row('claims', $in['id']); if ((int) $c['user_id'] !== $MID) fail('Not yours.', 403);
  if ($c['status'] === 'approved') fail('An approved expense is in the books and cannot be deleted.');
  q('DELETE FROM ' . T('claim_notes') . ' WHERE claim_id = ?', [$c['id']]); q('DELETE FROM ' . T('claims') . ' WHERE id = ?', [$c['id']]);
  out(['ok' => true]);

case 'acc_claims':
  need_acc();
  $w = '1=1'; $p = [];
  $st = $in['status'] ?? 'submitted'; if (in_array($st, ['submitted', 'on_hold', 'approved', 'rejected'], true)) { $w .= ' AND c.status = ?'; $p[] = $st; }
  if (!empty($in['user_id'])) { $w .= ' AND c.user_id = ?'; $p[] = (int) $in['user_id']; }
  if ($qs = s($in['q'] ?? null, 100)) { $w .= ' AND (c.particular LIKE ? OR u.name LIKE ?)'; $p[] = "%$qs%"; $p[] = "%$qs%"; }
  $counts = []; foreach (['submitted', 'on_hold', 'approved', 'rejected'] as $k) $counts[$k] = (int) val('SELECT COUNT(*) FROM ' . T('claims') . ' WHERE status = ?', [$k]);
  out(['claims' => claims_out($w, $p), 'counts' => $counts, 'cat_acc' => array_map(fn($s) => acc_sub($s), CLAIM_CATS)]);

case 'claim_decide':
  need_acc();
  claim_row($in['id'] ?? 0); $c = lock_row('claims', $in['id']);
  if (!in_array($c['status'], ['submitted', 'on_hold'], true)) fail('This expense is already ' . str_replace('_', ' ', $c['status']) . '.');
  $act = $in['action'] ?? ''; $why = s($in['reason'] ?? null, 2000);
  if ($act === 'reject' || $act === 'hold') {
    if (!$why) fail($act === 'reject' ? 'Write the reason for rejecting.' : 'Write what details you need.');
    upd('claims', $c['id'], ['status' => $act === 'reject' ? 'rejected' : 'on_hold', 'decided_by' => $me['name'], 'decided_at' => now(), 'reason' => $why]);
    claim_note($c['id'], $act === 'reject' ? 'rejected' : 'hold', $why); out(['ok' => true]);
  }
  if ($act !== 'approve') fail('Choose approve, reject or hold.');
  $u = one('SELECT id, name FROM ' . T('users') . ' WHERE id = ?', [$c['user_id']]);
  $exp = acc_of_type($in['account_id'] ?? 0, 'expense'); $credit = !empty($in['gst_credit']) && $c['gst'] > 0; $mode = ($in['gst_mode'] ?? '') === 'igst' ? 'igst' : 'cgst_sgst';
  $payTo = ($in['pay_from'] ?? 'staff') === 'staff' ? party_account('employee', $u['id'], $u['name']) : money_id($in['pay_from']);
  tx();
  $jid = claim_post($c, $exp, $credit, $mode, $payTo, $u['name']);
  upd('claims', $c['id'], ['status' => 'approved', 'decided_by' => $me['name'], 'decided_at' => now(), 'reason' => $why, 'journal_id' => $jid]);
  claim_note($c['id'], 'approved', $why);
  db()->commit(); out(['ok' => true]);

// ================= REPORTS =================
case 'acc_report':
  need_acc();
  out(['report' => run_report($in['r'] ?? '', $in)]);
}
} catch (UserError $e) { if (db()->inTransaction()) db()->rollBack(); fail($e->getMessage()); }
fail('Unknown action', 404);
