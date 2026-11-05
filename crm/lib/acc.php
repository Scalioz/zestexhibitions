<?php
// Accounting engine: double-entry posting, balances and reports. Every report returns
// ['title' => .., 'cols' => [..], 'num' => [indexes of money columns], 'rows' => [['c' => [cells], 'k' => ''|'h'|'t']]]
require_once __DIR__ . '/calc.php';
require_once __DIR__ . '/report.php';

function fy_of($date) { $y = (int) substr($date, 0, 4); $m = (int) substr($date, 5, 2); $s = $m >= 4 ? $y : $y - 1; return sprintf('%02d-%02d', $s % 100, ($s + 1) % 100); }
function acc_sub($sub) { $id = val('SELECT id FROM ' . T('accounts') . ' WHERE sub = ? AND party_type IS NULL ORDER BY id', [$sub]); if (!$id) throw new Exception("Account for $sub missing"); return (int) $id; }
function acc_get($id) { return one('SELECT * FROM ' . T('accounts') . ' WHERE id = ?', [(int) $id]); }
function money_accounts() { return all('SELECT id, name, sub FROM ' . T('accounts') . " WHERE sub IN ('bank','cash','petty') AND active = 1 ORDER BY sub, name"); }

// client / vendor / employee get their own ledger account, created the first time it is needed
function party_account($ptype, $pid, $name) {
  $id = val('SELECT id FROM ' . T('accounts') . ' WHERE party_type = ? AND party_id = ?', [$ptype, (int) $pid]);
  if ($id) return (int) $id;
  [$type, $sub, $label] = ['client' => ['asset', 'debtor', 'Client'], 'vendor' => ['liability', 'creditor', 'Vendor'], 'employee' => ['liability', 'employee', 'Staff']][$ptype];
  return ins('accounts', ['code' => null, 'name' => "$label: $name", 'type' => $type, 'sub' => $sub, 'party_type' => $ptype, 'party_id' => (int) $pid, 'opening' => 0, 'active' => 1, 'created_at' => now()]);
}

const VPREFIX = ['Sales' => 'SI', 'Receipt' => 'RV', 'Payment' => 'PV', 'Expense' => 'EV', 'Contra' => 'CV', 'Journal' => 'JV', 'Salary' => 'SV', 'Claim' => 'CL', 'Tax' => 'TX', 'Reversal' => 'RX'];

// lines: [[account_id, dr, cr, memo], ...]  — must balance
function post_journal($date, $vtype, $narr, $lines, $ref_type = null, $ref_id = null, $project_id = null, $user = null) {
  $dr = 0; $cr = 0; $clean = [];
  foreach ($lines as $l) {
    [$a, $d, $c] = [(int) $l[0], round((float) ($l[1] ?? 0), 2), round((float) ($l[2] ?? 0), 2)];
    if ($d < 0 || $c < 0) throw new UserError('Amounts cannot be negative.');
    if (!$a || ($d == 0 && $c == 0)) continue;
    if (!acc_get($a)) throw new UserError('Unknown account in entry.');
    $clean[] = [$a, $d, $c, mb_substr((string) ($l[3] ?? ''), 0, 250)]; $dr += $d; $cr += $c;
  }
  if (!$clean || round($dr, 2) <= 0) throw new UserError('Entry has no amount.');
  if (abs($dr - $cr) > 0.009) throw new UserError('Debit (' . inr_txt($dr) . ') and credit (' . inr_txt($cr) . ') must be equal.');
  $p = VPREFIX[$vtype] ?? 'JV'; $pre = "$p/" . fy_of($date) . '/';
  $n = 1 + (int) val('SELECT COUNT(*) FROM ' . T('journal') . ' WHERE vno LIKE ?', [$pre . '%']);
  $jid = ins('journal', ['jdate' => $date, 'vtype' => $vtype, 'vno' => $pre . str_pad($n, 4, '0', STR_PAD_LEFT), 'narration' => mb_substr((string) $narr, 0, 2000),
    'ref_type' => $ref_type, 'ref_id' => $ref_id, 'project_id' => $project_id, 'created_by' => $user, 'created_at' => now()]);
  foreach ($clean as [$a, $d, $c, $m]) ins('jlines', ['journal_id' => $jid, 'account_id' => $a, 'dr' => $d, 'cr' => $c, 'memo' => $m]);
  return $jid;
}
function reverse_journal($jid, $date, $why, $user) {
  $j = one('SELECT * FROM ' . T('journal') . ' WHERE id = ?', [(int) $jid]);
  if (!$j) throw new UserError('Entry not found.');
  if ($j['reversed_by']) throw new UserError('This entry is already reversed.');
  if ($j['vtype'] === 'Reversal') throw new UserError('A reversal cannot be reversed. Post a fresh entry instead.');
  $lines = array_map(fn($l) => [$l['account_id'], $l['cr'], $l['dr'], 'Reversal'], all('SELECT * FROM ' . T('jlines') . ' WHERE journal_id = ?', [$j['id']]));
  $rid = post_journal($date, 'Reversal', "Reversal of {$j['vno']}: $why", $lines, 'reversal', $j['id'], $j['project_id'], $user);
  upd('journal', $j['id'], ['reversed_by' => $rid]);
  return $rid;
}
class UserError extends Exception {}

// signed balance (debit positive) of one account up to a date (inclusive); $from = only movement from that date
function acc_balance($id, $to = null, $from = null) {
  $w = 'l.account_id = ?'; $p = [(int) $id];
  if ($to) { $w .= ' AND j.jdate <= ?'; $p[] = $to; }
  if ($from) { $w .= ' AND j.jdate >= ?'; $p[] = $from; }
  $mv = (float) val('SELECT COALESCE(SUM(l.dr - l.cr), 0) FROM ' . T('jlines') . ' l JOIN ' . T('journal') . " j ON j.id = l.journal_id WHERE $w", $p);
  return round(($from ? 0 : (float) val('SELECT opening FROM ' . T('accounts') . ' WHERE id = ?', [(int) $id])) + $mv, 2);
}
function balances_to($to) {   // all accounts at once: id => signed balance incl. opening
  $b = []; foreach (all('SELECT id, opening FROM ' . T('accounts')) as $a) $b[$a['id']] = (float) $a['opening'];
  foreach (all('SELECT l.account_id, SUM(l.dr - l.cr) AS m FROM ' . T('jlines') . ' l JOIN ' . T('journal') . ' j ON j.id = l.journal_id WHERE j.jdate <= ? GROUP BY l.account_id', [$to]) as $r) $b[$r['account_id']] = ($b[$r['account_id']] ?? 0) + (float) $r['m'];
  return $b;
}
function movement($from, $to) {
  $b = []; foreach (all('SELECT l.account_id, SUM(l.dr) AS d, SUM(l.cr) AS c FROM ' . T('jlines') . ' l JOIN ' . T('journal') . ' j ON j.id = l.journal_id WHERE j.jdate BETWEEN ? AND ? GROUP BY l.account_id', [$from, $to]) as $r) $b[$r['account_id']] = [(float) $r['d'], (float) $r['c']];
  return $b;
}
function inr2($n) {   // 123456.5 -> 1,23,456.50
  $n = round((float) $n, 2); $neg = $n < 0; [$i, $d] = explode('.', number_format(abs($n), 2, '.', ''));
  $last = substr($i, -3); $rest = substr($i, 0, -3); if ($rest !== '') $last = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) . ',' . $last;
  return ($neg ? '-' : '') . $last . '.' . $d;
}
function drcr($v) { return abs($v) < 0.005 ? '0.00' : ($v > 0 ? inr2($v) . ' Dr' : inr2(-$v) . ' Cr'); }
function R($cells, $k = '') { return ['c' => $cells, 'k' => $k]; }

// ---------- reports ----------
function r_daybook($from, $to) {
  $rows = []; $td = 0; $tc = 0;
  $js = all('SELECT * FROM ' . T('journal') . ' WHERE jdate BETWEEN ? AND ? ORDER BY jdate, id', [$from, $to]);
  $names = []; foreach (all('SELECT id, name FROM ' . T('accounts')) as $a) $names[$a['id']] = $a['name'];
  foreach ($js as $j) {
    $first = true;
    foreach (all('SELECT * FROM ' . T('jlines') . ' WHERE journal_id = ? ORDER BY cr, id', [$j['id']]) as $l) {
      $rows[] = R([$first ? $j['jdate'] : '', $first ? $j['vno'] : '', $first ? $j['vtype'] . ($j['reversed_by'] ? ' (reversed)' : '') : '', $names[$l['account_id']] ?? '—', $first ? $j['narration'] : $l['memo'], (float) $l['dr'] ?: '', (float) $l['cr'] ?: ''], $first ? 'j' . $j['id'] : '');
      $td += $l['dr']; $tc += $l['cr']; $first = false;
    }
  }
  $rows[] = R(['', '', '', 'Total', '', $td, $tc], 't');
  return ['title' => "Day book $from to $to", 'cols' => ['Date', 'Voucher', 'Type', 'Account', 'Narration', 'Debit (₹)', 'Credit (₹)'], 'num' => [5, 6], 'rows' => $rows];
}
function r_ledger($id, $from, $to) {
  $a = acc_get($id); if (!$a) throw new UserError('Pick an account.');
  $open = acc_balance($id, date('Y-m-d', strtotime("$from -1 day")));
  $rows = [R([$from, '', 'Opening balance', '', '', '', drcr($open)], 'h')]; $bal = $open; $td = 0; $tc = 0;
  $names = []; foreach (all('SELECT id, name FROM ' . T('accounts')) as $x) $names[$x['id']] = $x['name'];
  foreach (all('SELECT l.*, j.jdate, j.vno, j.vtype, j.narration FROM ' . T('jlines') . ' l JOIN ' . T('journal') . ' j ON j.id = l.journal_id WHERE l.account_id = ? AND j.jdate BETWEEN ? AND ? ORDER BY j.jdate, j.id, l.id', [$id, $from, $to]) as $l) {
    $other = array_unique(array_map(fn($o) => $names[$o['account_id']] ?? '', all('SELECT account_id FROM ' . T('jlines') . ' WHERE journal_id = ? AND account_id <> ?', [$l['journal_id'], $id])));
    $bal += $l['dr'] - $l['cr']; $td += $l['dr']; $tc += $l['cr'];
    $rows[] = R([$l['jdate'], $l['vno'], ($l['dr'] > 0 ? 'To ' : 'By ') . implode(', ', $other), $l['narration'], (float) $l['dr'] ?: '', (float) $l['cr'] ?: '', drcr($bal)]);
  }
  $rows[] = R(['', '', 'Total / closing balance', '', $td, $tc, drcr($bal)], 't');
  return ['title' => "Ledger: {$a['name']} ($from to $to)", 'cols' => ['Date', 'Voucher', 'Particulars', 'Narration', 'Debit (₹)', 'Credit (₹)', 'Balance'], 'num' => [4, 5], 'rows' => $rows, 'closing' => $bal];
}
function r_trial($to) {
  $b = balances_to($to); $rows = []; $td = 0; $tc = 0;
  foreach (['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Capital', 'income' => 'Income', 'expense' => 'Expenses'] as $t => $lab) {
    $acc = all('SELECT * FROM ' . T('accounts') . ' WHERE type = ? ORDER BY code IS NULL, code, name', [$t]); $sec = [];
    foreach ($acc as $a) { $v = round($b[$a['id']] ?? 0, 2); if (abs($v) < 0.005) continue; $sec[] = R([$a['code'] ?: '', $a['name'], $v > 0 ? $v : '', $v < 0 ? -$v : '']); if ($v > 0) $td += $v; else $tc -= $v; }
    if ($sec) { $rows[] = R(['', $lab, '', ''], 'h'); array_push($rows, ...$sec); }
  }
  $rows[] = R(['', 'Total', $td, $tc], 't');
  if (abs($td - $tc) > 0.009) $rows[] = R(['', 'Difference (check opening balances)', $td < $tc ? $tc - $td : '', $td > $tc ? $td - $tc : ''], 'bad');
  return ['title' => "Trial balance as on $to", 'cols' => ['Code', 'Account', 'Debit (₹)', 'Credit (₹)'], 'num' => [2, 3], 'rows' => $rows];
}
function pl_numbers($from, $to) {
  $mv = movement($from, $to); $inc = []; $exp = []; $ti = 0; $te = 0;
  foreach (all('SELECT * FROM ' . T('accounts') . " WHERE type IN ('income','expense') ORDER BY code IS NULL, code, name") as $a) {
    [$d, $c] = $mv[$a['id']] ?? [0, 0];
    if ($a['type'] === 'income') { $v = round($c - $d, 2); if ($v != 0) { $inc[] = [$a, $v]; $ti += $v; } }
    else { $v = round($d - $c, 2); if ($v != 0) { $exp[] = [$a, $v]; $te += $v; } }
  }
  return [$inc, $exp, $ti, $te];
}
function r_pl($from, $to) {
  [$inc, $exp, $ti, $te] = pl_numbers($from, $to);
  $rows = [R(['Income', ''], 'h')]; foreach ($inc as [$a, $v]) $rows[] = R([$a['name'], $v]); $rows[] = R(['Total income', $ti], 't');
  $rows[] = R(['Expenses', ''], 'h'); foreach ($exp as [$a, $v]) $rows[] = R([$a['name'], $v]); $rows[] = R(['Total expenses', $te], 't');
  $rows[] = R([$ti - $te >= 0 ? 'Net profit' : 'Net loss', abs($ti - $te)], $ti - $te >= 0 ? 'good' : 'bad');
  return ['title' => "Profit & loss $from to $to", 'cols' => ['Particulars', 'Amount (₹)'], 'num' => [1], 'rows' => $rows];
}
function r_bs($to) {
  $b = balances_to($to); $rows = []; $tot = [];
  $profit = 0; foreach (all('SELECT id, type FROM ' . T('accounts') . " WHERE type IN ('income','expense')") as $a) $profit -= $b[$a['id']] ?? 0;   // credit balance of P&L = profit
  foreach (['liability' => 'Liabilities', 'equity' => 'Capital & reserves', 'asset' => 'Assets'] as $t => $lab) {
    $rows[] = R([$lab, ''], 'h'); $s = 0;
    foreach (all('SELECT * FROM ' . T('accounts') . ' WHERE type = ? ORDER BY code IS NULL, code, name', [$t]) as $a) {
      $v = round(($b[$a['id']] ?? 0) * ($t === 'asset' ? 1 : -1), 2); if (abs($v) < 0.005) continue; $rows[] = R([$a['name'], $v]); $s += $v;
    }
    if ($t === 'equity') { $rows[] = R(['Profit & loss account (to date)', round($profit, 2)]); $s += $profit; }
    $tot[$t] = $s; $rows[] = R(["Total $lab", $s], 't');
  }
  $left = $tot['liability'] + $tot['equity'];
  $rows[] = R(['Liabilities + capital', $left], 't'); $rows[] = R(['Assets', $tot['asset']], 't');
  if (abs($left - $tot['asset']) > 0.009) $rows[] = R(['Difference (check opening balances)', $tot['asset'] - $left], 'bad');
  return ['title' => "Balance sheet as on $to", 'cols' => ['Particulars', 'Amount (₹)'], 'num' => [1], 'rows' => $rows];
}
function r_gst($from, $to) {
  $ids = []; foreach (all('SELECT id, sub FROM ' . T('accounts') . " WHERE sub IN ('gst_out_c','gst_out_s','gst_out_i','gst_in_c','gst_in_s','gst_in_i','tds_out')") as $a) $ids[$a['sub']] = $a['id'];
  $m = [];
  foreach (all('SELECT l.account_id, l.dr, l.cr, j.jdate, j.vtype FROM ' . T('jlines') . ' l JOIN ' . T('journal') . ' j ON j.id = l.journal_id WHERE j.jdate BETWEEN ? AND ?', [$from, $to]) as $l) {
    $k = substr($l['jdate'], 0, 7); $m[$k] = $m[$k] ?? ['out' => 0, 'in' => 0, 'paid' => 0, 'tds' => 0, 'tds_paid' => 0];
    $sub = array_search($l['account_id'], $ids);
    if ($sub === false) continue;
    $tax = $l['vtype'] === 'Tax';
    if (str_starts_with($sub, 'gst_out')) { if ($tax) $m[$k]['paid'] += $l['dr']; else $m[$k]['out'] += $l['cr'] - $l['dr']; }
    elseif (str_starts_with($sub, 'gst_in')) { if (!$tax) $m[$k]['in'] += $l['dr'] - $l['cr']; }
    elseif ($sub === 'tds_out') { if ($tax) $m[$k]['tds_paid'] += $l['dr']; else $m[$k]['tds'] += $l['cr'] - $l['dr']; }
  }
  ksort($m); $rows = []; $T = ['out' => 0, 'in' => 0, 'paid' => 0, 'tds' => 0, 'tds_paid' => 0];
  foreach ($m as $k => $v) { $rows[] = R([date('M Y', strtotime("$k-01")), $v['out'], $v['in'], $v['out'] - $v['in'], $v['paid'], $v['tds'], $v['tds_paid']]); foreach ($T as $x => $_) $T[$x] += $v[$x]; }
  $rows[] = R(['Total', $T['out'], $T['in'], $T['out'] - $T['in'], $T['paid'], $T['tds'], $T['tds_paid']], 't');
  $b = balances_to($to);
  $outBal = -array_sum(array_map(fn($s) => $b[$ids[$s]] ?? 0, ['gst_out_c', 'gst_out_s', 'gst_out_i'])); $inBal = array_sum(array_map(fn($s) => $b[$ids[$s]] ?? 0, ['gst_in_c', 'gst_in_s', 'gst_in_i']));
  $rows[] = R(["GST payable now (output ₹" . inr_txt($outBal) . " − input credit ₹" . inr_txt($inBal) . ")", '', '', round($outBal - $inBal, 2), '', -($b[$ids['tds_out']] ?? 0), ''], 'h');
  return ['title' => "GST & TDS $from to $to", 'cols' => ['Month', 'Output GST (₹)', 'Input GST (₹)', 'Net GST (₹)', 'GST paid (₹)', 'TDS deducted (₹)', 'TDS paid (₹)'], 'num' => [1, 2, 3, 4, 5, 6], 'rows' => $rows];
}
function r_expenses($from, $to) {
  $mv = []; $months = [];
  foreach (all('SELECT l.account_id, SUBSTR(j.jdate, 1, 7) AS m, SUM(l.dr - l.cr) AS v FROM ' . T('jlines') . ' l JOIN ' . T('journal') . ' j ON j.id = l.journal_id JOIN ' . T('accounts') . " a ON a.id = l.account_id WHERE a.type = 'expense' AND j.jdate BETWEEN ? AND ? GROUP BY l.account_id, SUBSTR(j.jdate, 1, 7)", [$from, $to]) as $r) { $mv[$r['account_id']][$r['m']] = (float) $r['v']; $months[$r['m']] = 1; }
  ksort($months); $months = array_keys($months); $rows = []; $colT = array_fill(0, count($months), 0); $grand = 0;
  foreach (all('SELECT * FROM ' . T('accounts') . " WHERE type = 'expense' ORDER BY code IS NULL, code, name") as $a) {
    if (empty($mv[$a['id']])) continue; $cells = [$a['name']]; $t = 0;
    foreach ($months as $i => $mm) { $v = $mv[$a['id']][$mm] ?? 0; $cells[] = $v ?: ''; $t += $v; $colT[$i] += $v; }
    $cells[] = $t; $grand += $t; $rows[] = R($cells);
  }
  $rows[] = R(array_merge(['Total'], $colT, [$grand]), 't');
  return ['title' => "Expenses $from to $to", 'cols' => array_merge(['Expense head'], array_map(fn($m) => date('M Y', strtotime("$m-01")), $months), ['Total (₹)']), 'num' => range(1, count($months) + 1), 'rows' => $rows];
}
function r_salary($from, $to) {
  $rows = []; $T = [0, 0, 0];
  foreach (all('SELECT s.*, u.name, u.department FROM ' . T('salaries') . ' s LEFT JOIN ' . T('users') . ' u ON u.id = s.user_id WHERE s.paid_on BETWEEN ? AND ? ORDER BY s.month, u.name', [$from, $to]) as $s) {
    $rows[] = R([$s['month'], $s['name'], $s['department'], (float) $s['gross'], (float) $s['deductions'] ?: '', $s['deduction_note'], (float) $s['net'], $s['paid_on']]); $T[0] += $s['gross']; $T[1] += $s['deductions']; $T[2] += $s['net'];
  }
  $rows[] = R(['Total', '', '', $T[0], $T[1], '', $T[2], ''], 't');
  return ['title' => "Salary register $from to $to", 'cols' => ['Month', 'Employee', 'Department', 'Gross (₹)', 'Deductions (₹)', 'Deduction details', 'Net paid (₹)', 'Paid on'], 'num' => [3, 4, 6], 'rows' => $rows];
}
function r_parties($to) {
  $b = balances_to($to); $rows = [];
  foreach (['debtor' => ['Receivables (clients owe us)', 1], 'creditor' => ['Payables to vendors', -1], 'employee' => ['Payable to staff', -1]] as $sub => [$lab, $sg]) {
    $sec = []; $s = 0;
    foreach (all('SELECT * FROM ' . T('accounts') . ' WHERE sub = ? ORDER BY name', [$sub]) as $a) { $v = round(($b[$a['id']] ?? 0) * $sg, 2); if (abs($v) < 0.005) continue; $sec[] = R([$a['name'], $v]); $s += $v; }
    $rows[] = R([$lab, ''], 'h'); array_push($rows, ...($sec ?: [R(['None', ''])])); $rows[] = R(["Total", $s], 't');
  }
  return ['title' => "Outstanding as on $to", 'cols' => ['Party', 'Amount (₹)'], 'num' => [1], 'rows' => $rows];
}
function r_claims($from, $to) {
  $rows = []; $T = 0;
  foreach (all('SELECT c.*, u.name, u.department, p.expo FROM ' . T('claims') . ' c LEFT JOIN ' . T('users') . ' u ON u.id = c.user_id LEFT JOIN ' . T('projects') . ' p ON p.id = c.project_id WHERE c.edate BETWEEN ? AND ? ORDER BY c.edate', [$from, $to]) as $c) {
    $rows[] = R([$c['edate'], $c['name'], $c['department'], $c['particular'], $c['category'], $c['expo'] ?: '', (float) $c['rate'], (float) $c['qty'], (float) $c['total'], (float) $c['gst'] ?: '', (float) $c['grand'], ucfirst(str_replace('_', ' ', $c['status'])), $c['decided_by'], $c['reason']]);
    if ($c['status'] === 'approved') $T += $c['grand'];
  }
  $rows[] = R(['Approved total', '', '', '', '', '', '', '', '', '', $T, '', '', ''], 't');
  return ['title' => "Expense claims $from to $to", 'cols' => ['Date', 'Employee', 'Department', 'Particular', 'Category', 'Order', 'Unit rate', 'Qty', 'Total', 'GST', 'Grand total', 'Status', 'Decided by', 'Reason / note'], 'num' => [6, 8, 9, 10], 'rows' => $rows];
}
function run_report($r, $in) {
  $c = setting('company', []);
  $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['from'] ?? '') ? $in['from'] : ($c['books_from'] ?? date('Y-04-01'));
  $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['to'] ?? '') ? $in['to'] : today();
  $money = function ($sub) use ($in) {
    $id = (int) ($in['account_id'] ?? 0);
    if ($id && val('SELECT sub FROM ' . T('accounts') . ' WHERE id = ?', [$id]) === $sub) return $id;
    return (int) val('SELECT id FROM ' . T('accounts') . ' WHERE sub = ? ORDER BY id', [$sub]);
  };
  return match ($r) {
    'daybook' => r_daybook($from, $to), 'ledger' => r_ledger((int) ($in['account_id'] ?? 0), $from, $to),
    'cash' => r_ledger($money('cash'), $from, $to), 'bank' => r_ledger($money('bank'), $from, $to), 'petty' => r_ledger($money('petty'), $from, $to),
    'trial' => r_trial($to), 'pl' => r_pl($from, $to), 'bs' => r_bs($to), 'gst' => r_gst($from, $to), 'expenses' => r_expenses($from, $to),
    'salary' => r_salary($from, $to), 'parties' => r_parties($to), 'claims' => r_claims($from, $to),
    default => throw new UserError('Unknown report.'),
  };
}

function amount_words($n) {
  $n = (int) round($n); if ($n === 0) return 'Zero';
  $o = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
  $t = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
  $two = fn($x) => $x < 20 ? $o[$x] : trim($t[intdiv($x, 10)] . ($x % 10 ? '-' . $o[$x % 10] : ''));
  $three = fn($x) => trim(($x >= 100 ? $o[intdiv($x, 100)] . ' Hundred ' : '') . ($x % 100 ? $two($x % 100) : ''));
  $parts = [];
  foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand']] as [$v, $w]) if ($n >= $v) { $parts[] = $three(intdiv($n, $v)) . " $w"; $n %= $v; }
  if ($n) $parts[] = $three($n);
  return implode(' ', $parts);
}
