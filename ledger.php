<?php
// Printable ledger / statement of account for one client.
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/calc.php';
$me = current_user();
if (!$me) { header('Location: login.php'); exit; }
$c = one('SELECT * FROM ' . T('clients') . ' WHERE id = ?', [(int) ($_GET['client'] ?? 0)]);
if (!$c || ($me['role'] !== 'admin' && (int) $c['owner_id'] !== (int) $me['id'])) { http_response_code(404); exit('Client not found.'); }

$ps = all('SELECT * FROM ' . T('projects') . " WHERE client_id = ? AND status IN ('won','completed') ORDER BY COALESCE(start_date, created_at)", [$c['id']]);
$h = fn($s) => htmlspecialchars((string) ($s ?? ''), ENT_QUOTES);
function inr($n) {
  $n = round((float) $n, 2); $neg = $n < 0; $n = abs($n);
  [$i, $d] = explode('.', number_format($n, 2, '.', ''));
  $last = substr($i, -3); $rest = substr($i, 0, -3);
  if ($rest !== '') $last = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) . ',' . $last;
  return ($neg ? '-' : '') . $last . '.' . $d;
}
function fd($d) { return $d ? date('d-M-Y', strtotime($d)) : ''; }
function words($n) {
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

$rows = []; $bal = 0; $TD = 0; $TC = 0; $sum = [];
foreach ($ps as $p) {
  $ex = all('SELECT * FROM ' . T('extras') . ' WHERE project_id = ? ORDER BY id', [$p['id']]);
  $pay = all('SELECT * FROM ' . T('client_payments') . ' WHERE project_id = ? ORDER BY pdate, id', [$p['id']]);
  $b = project_bill($p, $ex, $pay, [], [], []);
  $title = $p['expo'] . ($p['start_date'] ? ', ' . date('j M Y', strtotime($p['start_date'])) : '') . ($p['location'] ? ', ' . $p['location'] : '');
  $rows[] = ['head' => $title];
  $inv = $p['invoice_date']; $vno = $p['invoice_no'];
  $add = function ($date, $part, $sub, $type, $dr, $cr) use (&$rows, &$bal, &$TD, &$TC) { $bal += $dr - $cr; $TD += $dr; $TC += $cr; $rows[] = compact('date', 'part', 'sub', 'type', 'dr', 'cr') + ['bal' => $bal]; };
  $add($inv, 'To Stall Fabrication A/c', 'Incl. erection, labour & transport charges' . ($p['area_sqm'] ? ' · Stall area ' . rtrim(rtrim($p['area_sqm'], '0'), '.') . ' sqm' : '') . ($vno ? ' · Inv ' . $vno : ''), 'Sales', $b['base'], 0);
  foreach ($ex as $x) $add($inv, 'To ' . $x['item'] . ' A/c', 'Extra: ' . rtrim(rtrim($x['qty'], '0'), '.') . ' × ₹' . inr($x['rate']), 'Sales', $x['rate'] * $x['qty'], 0);
  $add($inv, 'To Output GST @ ' . rtrim(rtrim($p['gst_rate'], '0'), '.') . '% A/c', 'On ₹' . inr($b['taxable']), 'Sales', $b['gst'], 0);
  foreach ($pay as $i => $py) $add($py['pdate'], 'By ' . ($py['mode'] === 'Cash' ? 'Cash' : 'Bank') . ' A/c', 'Payment received' . ($py['mode'] !== 'Bank' && $py['mode'] !== 'Cash' ? ' (' . $py['mode'] . ')' : '') . ($py['reference'] ? ' · ' . $py['reference'] : ''), 'Receipt', 0, $py['amount']);
  $sum[] = ['t' => $title, 'b' => $b['total'], 'r' => $b['received']];
}
$period = $ps ? fd(min(array_map(fn($p) => $p['start_date'] ?: substr($p['created_at'], 0, 10), $ps))) : fd(today());
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ledger · <?= $h($c['company']) ?></title><link rel="icon" href="assets/mark.svg">
<style>
@page{size:A4;margin:14mm}
body{font-family:Arial,Helvetica,sans-serif;color:#1b2340;font-size:11.5px;margin:0;background:#e9ebf0}
.page{background:#fff;max-width:820px;margin:20px auto;padding:28px 32px;box-shadow:0 2px 14px rgba(0,0,0,.08)}
.bar{max-width:820px;margin:16px auto 0;display:flex;gap:8px;justify-content:flex-end}
.bar button,.bar a{font:600 13px Arial;padding:8px 14px;border-radius:6px;border:1px solid #1b2a5c;background:#1b2a5c;color:#fff;cursor:pointer;text-decoration:none}
.bar a{background:#fff;color:#1b2a5c}
.hdr{display:flex;align-items:center;justify-content:space-between;border-bottom:3px solid #1b2a5c;padding-bottom:10px}
.hdr img{height:56px}
.addr{text-align:right;font-size:10.5px;line-height:1.55;color:#333}.addr b{font-size:14px;color:#1b2a5c}
h1{text-align:center;font-size:15px;letter-spacing:3px;margin:16px 0 4px;color:#1b2a5c}
.sub{text-align:center;color:#555;margin-bottom:14px}
.meta{display:flex;justify-content:space-between;border:1px solid #9aa3b5;padding:8px 12px;margin-bottom:12px;line-height:1.7;gap:12px}
table{width:100%;border-collapse:collapse}
th{background:#1b2a5c;color:#fff;padding:7px 6px;font-size:11px;border:1px solid #1b2a5c}
td{border:1px solid #9aa3b5;padding:6px;vertical-align:top}
td.n{text-align:right;white-space:nowrap}td.c{text-align:center;white-space:nowrap}
tr.ev td{background:#dfe4ef;font-weight:bold;color:#1b2a5c}
tr.tot td{font-weight:bold;background:#eef1f7}tr.bal td{font-weight:bold;background:#fff7c2}
.small{color:#666;font-size:10px}
.foot{display:flex;justify-content:space-between;margin-top:50px}.sig{border-top:1px solid #333;width:200px;text-align:center;padding-top:5px}
.note{margin-top:22px;font-size:10px;color:#666;border-top:1px dashed #bbb;padding-top:6px;text-align:center}
tr{page-break-inside:avoid}
@media print{body{background:#fff}.page{box-shadow:none;margin:0;padding:0;max-width:none}.bar{display:none}}
</style></head><body>
<div class="bar"><a href="index.php#client-<?= (int) $c['id'] ?>">← Back</a><button onclick="window.print()">Print / Save as PDF</button></div>
<div class="page">
<div class="hdr"><img src="assets/logo.png" alt="Zest Exhibitions">
<div class="addr"><b>ZEST EXHIBITIONS</b><br>G-79/1, Third Floor, Front Side, Glam Apartment,<br>Shaheen Bagh, Okhla, New Delhi - 110025<br>Tel: 9311966189</div></div>
<h1>LEDGER ACCOUNT</h1>
<div class="sub">Statement of Account for the period <?= $period ?> to <?= fd(today()) ?></div>
<div class="meta"><div><b>Party A/c:</b> <?= $h($c['company']) ?><?php if ($c['gst_no']): ?><br><b>GSTIN:</b> <?= $h($c['gst_no']) ?><?php endif; ?><br><b>Events:</b> <?= $h(implode(', ', array_column($ps, 'expo')) ?: '—') ?></div>
<div style="text-align:right"><b>Ledger Folio:</b> Sundry Debtors<br><b>Date:</b> <?= fd(today()) ?></div></div>
<?php if (!$rows): ?><p>No confirmed orders for this client yet.</p><?php else: ?>
<table><thead><tr><th style="width:11%">Date</th><th>Particulars</th><th style="width:9%">Vch Type</th><th style="width:13%">Debit (₹)</th><th style="width:13%">Credit (₹)</th><th style="width:15%">Balance (₹)</th></tr></thead>
<?php foreach ($rows as $r): if (isset($r['head'])): ?><tr class="ev"><td colspan="6"><?= $h($r['head']) ?></td></tr><?php else: ?>
<tr><td class="c"><?= fd($r['date']) ?></td><td><?= $h($r['part']) ?><br><span class="small"><?= $h($r['sub']) ?></span></td><td class="c"><?= $r['type'] ?></td>
<td class="n"><?= $r['dr'] ? inr($r['dr']) : '' ?></td><td class="n"><?= $r['cr'] ? inr($r['cr']) : '' ?></td><td class="n"><?= inr(abs($r['bal'])) . ($r['bal'] > 0 ? ' Dr' : ($r['bal'] < 0 ? ' Cr' : '')) ?></td></tr>
<?php endif; endforeach; ?>
<tr class="tot"><td></td><td>Total</td><td></td><td class="n"><?= inr($TD) ?></td><td class="n"><?= inr($TC) ?></td><td></td></tr>
<tr class="bal"><td></td><td>Closing Balance (<?= $bal >= 0 ? 'Amount Due' : 'Advance' ?>)</td><td></td><td class="n"><?= $bal < 0 ? inr(-$bal) : '' ?></td><td class="n"><?= $bal > 0 ? inr($bal) : '' ?></td><td class="n"><?= inr(abs($bal)) . ($bal > 0 ? ' Dr' : ($bal < 0 ? ' Cr' : '')) ?></td></tr>
<tr class="tot"><td></td><td></td><td></td><td class="n"><?= inr(max($TD, $TC)) ?></td><td class="n"><?= inr(max($TD, $TC)) ?></td><td></td></tr>
</table>
<p style="margin-top:12px"><b>Amount in words:</b> Rupees <?= words(abs($bal)) ?> Only<?= $bal > 0 ? ' (balance payable)' : '' ?></p>
<?php if (count($sum) > 1): ?>
<h3 style="font-size:12px;color:#1b2a5c;margin:18px 0 6px">Event-wise Summary</h3>
<table><tr><th style="text-align:left">Event</th><th style="width:16%">Billed (₹)</th><th style="width:16%">Received (₹)</th><th style="width:16%">Balance (₹)</th></tr>
<?php foreach ($sum as $s): ?><tr><td><?= $h($s['t']) ?></td><td class="n"><?= inr($s['b']) ?></td><td class="n"><?= inr($s['r']) ?></td><td class="n"><?= inr($s['b'] - $s['r']) ?></td></tr><?php endforeach; ?>
<tr class="bal"><td>Total</td><td class="n"><?= inr(array_sum(array_column($sum, 'b'))) ?></td><td class="n"><?= inr(array_sum(array_column($sum, 'r'))) ?></td><td class="n"><?= inr($bal) ?></td></tr></table>
<?php endif; endif; ?>
<div class="foot"><div class="sig">Prepared By</div><div class="sig">For Zest Exhibitions<br><br>Authorised Signatory</div></div>
<div class="note">This is a computer-generated statement of account.</div>
</div></body></html>
