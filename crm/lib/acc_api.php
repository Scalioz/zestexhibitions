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
function inv_totals($lines, $rate) {
  $tax = 0; $non = 0; $out = [];
  foreach (array_slice((array) $lines, 0, 50) as $l) { $l = (array) $l;
    $d = s($l['desc'] ?? null, 500); if (!$d) continue;
    $q = (float) ($l['qty'] ?? 1) ?: 1; $r = round((float) ($l['rate'] ?? 0), 2); $amt = round($q * $r, 2); $tx = !empty($l['tax']) || !array_key_exists('tax', $l);
    $out[] = ['desc' => $d, 'sac' => s($l['sac'] ?? null, 20), 'qty' => $q, 'rate' => $r, 'amount' => $amt, 'tax' => $tx ? 1 : 0];
    if ($tx) $tax += $amt; else $non += $amt;
  }
  $gst = round($tax * $rate / 100, 2);
  return [$out, round($tax + $non, 2), $gst, round($tax + $non + $gst, 2), $tax];
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
    'to_post' => $toPost, 'inv_draft' => (int) val('SELECT COUNT(*) FROM ' . T('invoices') . " WHERE status = 'draft'"),
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
  foreach (['name', 'address', 'phone', 'gstin', 'pan', 'state', 'bank_name', 'bank_acno', 'bank_ifsc', 'sac', 'terms'] as $k) if (array_key_exists($k, $in)) $c[$k] = s($in[$k], 1000) ?? '';
  if ($bf = d($in['books_from'] ?? null)) $c['books_from'] = $bf;
  set_setting('company', $c); out(['ok' => true]);

// ================= ENTRIES =================
case 'acc_entry':
  need_acc();
  $k = $in['kind'] ?? ''; $date = d($in['date'] ?? null) ?? fail('Enter the date.');
  $amt = n($in['amount'] ?? 0) ?? 0; $narr = s($in['narration'] ?? null, 2000) ?? '';
  $pid = (int) ($in['project_id'] ?? 0) ?: null; $proj = $pid ? one('SELECT p.*, c.company FROM ' . T('projects') . ' p JOIN ' . T('clients') . ' c ON c.id = p.client_id WHERE p.id = ?', [$pid]) : null;
  if ($pid && !$proj) fail('Order not found.');
  db()->beginTransaction();
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
  $j = one('SELECT * FROM ' . T('journal') . ' WHERE id = ?', [(int) ($in['id'] ?? 0)]) ?? fail('Not found', 404);
  if ($j['ref_type'] === 'invoice') fail('This came from an invoice. Cancel the invoice instead.');
  if ($j['ref_type'] === 'claim') fail('This came from an approved expense claim and cannot be reversed here. Post a correcting journal instead.');
  $why = s($in['reason'] ?? null, 500) ?? fail('Write why it is being reversed.');
  db()->beginTransaction(); $rid = reverse_journal($j['id'], d($in['date'] ?? null) ?? today(), $why, $MID); db()->commit();
  out(['id' => $rid]);

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
  if (val('SELECT COUNT(*) FROM ' . T('journal') . ' WHERE ref_type = ? AND ref_id = ?', [$t, $id])) fail('Already posted.');
  if (!empty($in['skip'])) { ins('post_skip', ['ref_type' => $t, 'ref_id' => $id]); out(['ok' => true]); }
  $money = money_id($in['money_id'] ?? 0);
  db()->beginTransaction();
  if ($t === 'cpay') $jid = post_journal($x['pdate'], 'Receipt', "Receipt from {$x['company']} for {$x['expo']}" . ($x['reference'] ? " ({$x['reference']})" : ''), [[$money, $x['amount'], 0, ''], [party_account('client', $x['client_id'], $x['company']), 0, $x['amount'], '']], 'cpay', $id, $x['project_id'], $MID);
  elseif ($t === 'vpay') { $vn = val('SELECT name FROM ' . T('vendors') . ' WHERE id = ?', [$x['vendor_id']]) ?? 'Vendor';
    $exp = !empty($in['account_id']) ? acc_of_type($in['account_id'], 'expense') : acc_sub('fab');
    $jid = post_journal($x['pdate'], 'Payment', "Paid $vn for {$x['expo']} ({$x['company']})", [[$exp, $x['amount'], 0, ''], [$money, 0, $x['amount'], '']], 'vpay', $id, $x['project_id'], $MID); }
  else { $exp = !empty($in['account_id']) ? acc_of_type($in['account_id'], 'expense') : acc_sub(PEXP_SUB[$x['category']] ?? 'exp_misc');
    $jid = post_journal($x['edate'], 'Expense', "{$x['category']}: " . ($x['description'] ?: '') . " — {$x['expo']} ({$x['company']})", [[$exp, $x['amount'], 0, ''], [$money, 0, $x['amount'], '']], 'pexp', $id, $x['project_id'], $MID); }
  db()->commit(); out(['id' => $jid]);

// ================= INVOICES =================
case 'inv_list':
  need_acc();
  $w = '1=1'; $p = [];
  if (in_array($in['kind'] ?? '', ['proforma', 'tax'], true)) { $w .= ' AND i.kind = ?'; $p[] = $in['kind']; }
  if (in_array($in['status'] ?? '', ['draft', 'issued', 'cancelled'], true)) { $w .= ' AND i.status = ?'; $p[] = $in['status']; }
  if ($qs = s($in['q'] ?? null, 100)) { $w .= ' AND (c.company LIKE ? OR i.inv_no LIKE ?)'; $p[] = "%$qs%"; $p[] = "%$qs%"; }
  out(['invoices' => all('SELECT i.*, c.company, p.expo FROM ' . T('invoices') . ' i JOIN ' . T('clients') . ' c ON c.id = i.client_id LEFT JOIN ' . T('projects') . " p ON p.id = i.project_id WHERE $w ORDER BY i.id DESC LIMIT 500", $p)]);

case 'inv_get':
  need_acc();
  $i = one('SELECT i.*, c.company FROM ' . T('invoices') . ' i JOIN ' . T('clients') . ' c ON c.id = i.client_id WHERE i.id = ?', [(int) ($in['id'] ?? 0)]) ?? fail('Not found', 404);
  $i['lines'] = json_decode($i['lines_json'], true) ?: []; unset($i['lines_json']);
  out(['invoice' => $i]);

case 'inv_prefill':
  need_acc();
  $p = one('SELECT p.*, c.company FROM ' . T('projects') . ' p JOIN ' . T('clients') . ' c ON c.id = p.client_id WHERE p.id = ?', [(int) ($in['project_id'] ?? 0)]) ?? fail('Order not found.');
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
  out(['client_id' => (int) $p['client_id'], 'project_id' => (int) $p['id'], 'gst_rate' => (float) $p['gst_rate'], 'lines' => $lines]);

case 'inv_save':
  need_acc();
  $kind = ($in['kind'] ?? '') === 'tax' ? 'tax' : 'proforma';
  $cid = (int) ($in['client_id'] ?? 0); if (!val('SELECT COUNT(*) FROM ' . T('clients') . ' WHERE id = ?', [$cid])) fail('Pick the client.');
  $rate = (float) ($in['gst_rate'] ?? 18); if ($rate < 0 || $rate > 28) fail('GST rate looks wrong.');
  [$lines, $taxable, $gst, $total] = inv_totals($in['lines'] ?? [], $rate); if (!$lines) fail('Add at least one line.');
  $state = s($in['place_state'] ?? null, 60) ?? (setting('company', [])['state'] ?? '');
  $mode = mb_strtolower(trim($state)) === mb_strtolower(trim(setting('company', [])['state'] ?? '')) ? 'cgst_sgst' : 'igst';
  $data = ['kind' => $kind, 'inv_date' => d($in['inv_date'] ?? null) ?? today(), 'client_id' => $cid, 'project_id' => (int) ($in['project_id'] ?? 0) ?: null, 'place_state' => $state, 'gst_mode' => $mode, 'gst_rate' => $rate,
    'lines_json' => json_encode($lines, JSON_UNESCAPED_UNICODE), 'taxable' => $taxable, 'gst' => $gst, 'total' => $total, 'notes' => s($in['notes'] ?? null, 2000)];
  if (!empty($in['id'])) { $i = one('SELECT * FROM ' . T('invoices') . ' WHERE id = ?', [(int) $in['id']]) ?? fail('Not found', 404); if ($i['status'] !== 'draft') fail('Only a draft can be changed.'); upd('invoices', $i['id'], $data); out(['id' => (int) $i['id']]); }
  out(['id' => ins('invoices', $data + ['status' => 'draft', 'created_by' => $MID, 'created_at' => now(), 'from_id' => (int) ($in['from_id'] ?? 0) ?: null])]);

case 'inv_issue':
  need_acc();
  $i = one('SELECT i.*, c.company FROM ' . T('invoices') . ' i JOIN ' . T('clients') . ' c ON c.id = i.client_id WHERE i.id = ?', [(int) ($in['id'] ?? 0)]) ?? fail('Not found', 404);
  if ($i['status'] !== 'draft') fail('Already issued.');
  $pre = ($i['kind'] === 'tax' ? 'INV' : 'PI') . '/' . fy_of($i['inv_date']) . '/';
  $last = 0; foreach (all('SELECT inv_no FROM ' . T('invoices') . ' WHERE inv_no LIKE ?', [$pre . '%']) as $r) $last = max($last, (int) substr($r['inv_no'], strlen($pre)));
  $no = $pre . str_pad($last + 1, 3, '0', STR_PAD_LEFT);
  db()->beginTransaction();
  $jid = null;
  if ($i['kind'] === 'tax') {
    $lines = [[party_account('client', $i['client_id'], $i['company']), $i['total'], 0, "Invoice $no"], [acc_sub('sales'), 0, $i['taxable'], "Invoice $no"]];
    $lines = array_merge($lines, gst_lines((float) $i['gst'], $i['gst_mode'], 'out'));
    $jid = post_journal($i['inv_date'], 'Sales', "Tax invoice $no — {$i['company']}", $lines, 'invoice', $i['id'], $i['project_id'], $MID);
    if ($i['project_id']) upd('projects', $i['project_id'], ['invoice_no' => $no, 'invoice_date' => $i['inv_date']]);
  }
  upd('invoices', $i['id'], ['inv_no' => $no, 'status' => 'issued', 'journal_id' => $jid]);
  db()->commit(); out(['inv_no' => $no]);

case 'inv_convert':
  need_acc();
  $i = one('SELECT * FROM ' . T('invoices') . ' WHERE id = ?', [(int) ($in['id'] ?? 0)]) ?? fail('Not found', 404);
  if ($i['kind'] !== 'proforma' || $i['status'] !== 'issued') fail('Only an issued proforma can be turned into a tax invoice.');
  if ($ex = val('SELECT id FROM ' . T('invoices') . " WHERE from_id = ? AND status <> 'cancelled'", [$i['id']])) out(['id' => (int) $ex]);
  $copy = $i; unset($copy['id'], $copy['inv_no'], $copy['journal_id']);
  out(['id' => ins('invoices', array_merge($copy, ['kind' => 'tax', 'status' => 'draft', 'inv_date' => today(), 'from_id' => $i['id'], 'created_by' => $MID, 'created_at' => now()]))]);

case 'inv_cancel':
  need_acc();
  $i = one('SELECT * FROM ' . T('invoices') . ' WHERE id = ?', [(int) ($in['id'] ?? 0)]) ?? fail('Not found', 404);
  if ($i['status'] === 'cancelled') fail('Already cancelled.');
  if ($i['status'] === 'draft') { q('DELETE FROM ' . T('invoices') . ' WHERE id = ?', [$i['id']]); out(['deleted' => true]); }
  $why = s($in['reason'] ?? null, 500) ?? fail('Write why the invoice is cancelled.');
  db()->beginTransaction();
  if ($i['journal_id']) { $j = one('SELECT * FROM ' . T('journal') . ' WHERE id = ?', [$i['journal_id']]); upd('journal', $j['id'], ['ref_type' => 'invoice_x']); reverse_journal($j['id'], today(), "Invoice {$i['inv_no']} cancelled: $why", $MID); }
  upd('invoices', $i['id'], ['status' => 'cancelled', 'notes' => trim(($i['notes'] ?? '') . "\nCancelled: $why")]);
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
    $c = claim_row($in['id']); if ((int) $c['user_id'] !== $MID) fail('Not yours.', 403);
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
  $c = claim_row($in['id'] ?? 0); if ((int) $c['user_id'] !== $MID) fail('Not yours.', 403);
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
  $c = claim_row($in['id'] ?? 0);
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
  $narr = "Expense by {$u['name']}: {$c['particular']}";
  db()->beginTransaction();
  $lines = [[$exp, $c['total'] + ($credit ? 0 : $c['gst']), 0, $c['particular']]]; if ($credit) $lines = array_merge($lines, gst_lines((float) $c['gst'], $mode, 'in'));
  $lines[] = [$payTo, 0, $c['grand'], $c['particular']];
  $jid = post_journal($c['edate'], 'Claim', $narr, $lines, 'claim', $c['id'], $c['project_id'], $MID);
  if ($c['project_id']) ins('expenses', ['project_id' => $c['project_id'], 'edate' => $c['edate'], 'category' => pexp_cat(acc_get($exp)['sub']), 'description' => mb_substr("{$c['particular']} ({$u['name']}, claim #{$c['id']})", 0, 250), 'amount' => $c['total'] + ($credit ? 0 : $c['gst'])]);
  // the order expense above is already in the books through this claim — keep it out of the "to post" list
  if ($c['project_id']) ins('post_skip', ['ref_type' => 'pexp', 'ref_id' => (int) db()->lastInsertId()]);
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
