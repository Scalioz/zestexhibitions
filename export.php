<?php
// Admin: download everything (or a filtered period) as one Excel file with several sheets.
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/report.php';
require_once __DIR__ . '/lib/xlsx.php';
$me = current_user();
if (!$me) { header('Location: login.php'); exit; }
if ($me['role'] !== 'admin') { http_response_code(403); exit('Only admin can export.'); }
$d = fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET[$k] ?? '') ? $_GET[$k] : null;
$f = ['from' => $d('from'), 'to' => $d('to'), 'status' => in_array($_GET['status'] ?? '', ['enquiry', 'quoted', 'won', 'rejected', 'completed'], true) ? $_GET['status'] : null,
  'user_id' => (int) ($_GET['user_id'] ?? 0), 'q' => trim($_GET['q'] ?? '') ?: null];
$orders = report_orders($f);
$ids = array_map('intval', array_column($orders, 'id')); $in = $ids ? implode(',', $ids) : '0';
$cids = array_values(array_unique(array_map('intval', array_column($orders, 'client_id')))); $cin = $cids ? implode(',', $cids) : '0';
$um = users_map_r(); $vn = []; foreach (all('SELECT id, name FROM ' . T('vendors')) as $v) $vn[$v['id']] = $v['name'];
$ex = []; foreach ($orders as $o) $ex[$o['id']] = $o['expo'] . ' — ' . $o['company'];
$n = fn($v) => $v === null || $v === '' ? '' : (float) $v;

$S = [];
$S['Orders'] = [['Client', 'Client city', 'Expo', 'Expo location', 'Start date', 'End date', 'Area (sqm)', 'Space type', 'Stall no.', 'Status', 'Handled by (sales)', 'Designed by', 'Design assigned on', 'Design corrections', 'Design approved by',
  'Quote versions', 'Offered (₹)', 'Agreed (₹)', 'Extras (₹)', 'GST (₹)', 'Bill total (₹)', 'Received (₹)', 'Balance (₹)', 'Last payment', 'Fabricated by (vendor)', 'Vendor L1 quote (₹)', 'Vendor paid (₹)', 'Expenses (₹)', 'Losses (₹)', 'Profit excl. GST (₹)',
  'Quote approved by', 'Invoice no.', 'Invoice date', 'Rejected by', 'Rejection reason', 'Rejection details', 'Do not quote']];
foreach ($orders as $o) { $b = $o['bill'];
  $S['Orders'][] = [$o['company'], $o['client_city'], $o['expo'], $o['location'], $o['start_date'], $o['end_date'], $n($o['area_sqm']), $o['space_type'], $o['stall_no'], ucfirst($o['status']), $o['owner'], $o['designed_by'], $o['assigned_on'], $o['revisions_n'], $o['design_approved_by'],
    $o['quotes_n'], $n($o['offered']), $n($o['agreed']), $b['extras'], $b['gst'], $b['total'], $b['received'], $b['balance'], $o['last_payment'], $o['fabricator'], $n($o['vendor_l1']), $b['vendor_paid'], $b['expenses'], $b['losses'], $b['profit'],
    $o['approved_by'], $o['invoice_no'], $o['invoice_date'], $o['rejected_by'] === 'us' ? 'Zest' : ($o['rejected_by'] === 'client' ? 'Client' : ''), $o['reason'], $o['rejection_note'], $o['dnq'] ? 'YES' : ''];
}
$S['Clients'] = [['Company', 'Contact person', 'Designation', 'Phone', 'Email', 'City', 'Address', 'GSTIN', 'Handled by', 'Others in the deal', 'Orders', 'Won', 'Business (₹)', 'Received (₹)', 'Outstanding (₹)', 'Do not quote', 'DNQ reason', 'Added on', 'GST certificate on file']];
$byC = []; foreach ($orders as $o) { $k = $o['client_id']; $byC[$k] = $byC[$k] ?? ['o' => 0, 'w' => 0, 't' => 0, 'r' => 0, 'b' => 0]; $byC[$k]['o']++; if (is_won($o['status'])) { $byC[$k]['w']++; $byC[$k]['t'] += $o['bill']['total']; $byC[$k]['r'] += $o['bill']['received']; $byC[$k]['b'] += $o['bill']['balance']; } }
$contacts = []; foreach (all('SELECT * FROM ' . T('contacts') . " WHERE client_id IN ($cin)") as $ct) $contacts[$ct['client_id']][] = $ct['name'] . ($ct['designation'] ? " ({$ct['designation']})" : '') . ($ct['phone'] ? " {$ct['phone']}" : '');
foreach (all('SELECT * FROM ' . T('clients') . " WHERE id IN ($cin) ORDER BY company") as $c) { $a = $byC[$c['id']];
  $S['Clients'][] = [$c['company'], $c['person'], $c['designation'], $c['phone'], $c['email'], $c['city'], $c['location'], $c['gst_no'], $um[$c['owner_id']] ?? '', implode('; ', $contacts[$c['id']] ?? []), $a['o'], $a['w'], $a['t'], $a['r'], $a['b'], $c['dnq'] ? 'YES' : '', $c['dnq_reason'], substr($c['created_at'], 0, 10), val('SELECT COUNT(*) FROM ' . T('files') . " WHERE entity = 'gstcert' AND entity_id = ?", [$c['id']]) ? 'YES' : ''];
}
$S['Client payments'] = [['Date', 'Order', 'Mode', 'Reference', 'Amount (₹)']];
foreach (all('SELECT * FROM ' . T('client_payments') . " WHERE project_id IN ($in) ORDER BY pdate") as $r) $S['Client payments'][] = [$r['pdate'], $ex[$r['project_id']], $r['mode'], $r['reference'], (float) $r['amount']];
$S['Vendor payments'] = [['Date', 'Order', 'Vendor', 'Mode', 'Reference', 'Amount (₹)']];
foreach (all('SELECT * FROM ' . T('vendor_payments') . " WHERE project_id IN ($in) ORDER BY pdate") as $r) $S['Vendor payments'][] = [$r['pdate'], $ex[$r['project_id']], $vn[$r['vendor_id']] ?? '', $r['mode'], $r['reference'], (float) $r['amount']];
$S['Vendor quotes'] = [['Order', 'Vendor', 'Quote (₹)', 'Chosen', 'Quality note', 'Date']];
foreach (all('SELECT * FROM ' . T('vendor_quotes') . " WHERE project_id IN ($in) ORDER BY project_id, amount") as $r) $S['Vendor quotes'][] = [$ex[$r['project_id']], $vn[$r['vendor_id']] ?? '', (float) $r['amount'], $r['selected'] ? 'YES' : '', $r['note'], $r['qdate']];
$S['Quotes sent'] = [['Order', 'Version', 'Date', 'Amount (₹)', 'Approved by', 'What changed']];
foreach (all('SELECT * FROM ' . T('quotes') . " WHERE project_id IN ($in) ORDER BY project_id, version") as $r) $S['Quotes sent'][] = [$ex[$r['project_id']], 'V' . $r['version'], $r['qdate'], (float) $r['amount'], $r['approved_by'], $r['notes']];
$S['Design'] = [['Order', 'Date', 'Done by', 'What changed']];
foreach (all('SELECT * FROM ' . T('revisions') . " WHERE project_id IN ($in) ORDER BY project_id, rdate") as $r) $S['Design'][] = [$ex[$r['project_id']], $r['rdate'], $r['done_by'], $r['changes']];
$S['Extras'] = [['Order', 'Item', 'Unit rate (₹)', 'Qty', 'Amount (₹)']];
foreach (all('SELECT * FROM ' . T('extras') . " WHERE project_id IN ($in) ORDER BY project_id") as $r) $S['Extras'][] = [$ex[$r['project_id']], $r['item'], (float) $r['rate'], (float) $r['qty'], $r['rate'] * $r['qty']];
$S['Expenses & losses'] = [['Date', 'Order', 'Kind', 'Type', 'Details', 'Amount (₹)']];
foreach (all('SELECT * FROM ' . T('expenses') . " WHERE project_id IN ($in)") as $r) $S['Expenses & losses'][] = [$r['edate'], $ex[$r['project_id']], 'Expense', $r['category'], $r['description'], (float) $r['amount']];
foreach (all('SELECT * FROM ' . T('losses') . " WHERE project_id IN ($in)") as $r) $S['Expenses & losses'][] = [$r['ldate'], $ex[$r['project_id']], 'Loss', $r['ltype'], $r['note'], (float) $r['amount']];
// Daily reports for the same period (all employees)
$rf = $f['from'] ?? '2000-01-01'; $rt = $f['to'] ?? date('Y-m-d');
$S['Daily reports'] = [['Date', 'Employee', 'Department', 'Sl', 'Company / main field', 'Details']];
foreach (all('SELECT i.*, r.rdate, r.user_id, r.dept, r.fields_json FROM ' . T('report_items') . ' i JOIN ' . T('reports') . ' r ON r.id = i.report_id WHERE r.rdate BETWEEN ? AND ? ORDER BY r.rdate, r.user_id, i.sl', [$rf, $rt]) as $r) {
  $dd = json_decode($r['data_json'], true) ?: []; $fl = json_decode($r['fields_json'], true) ?: []; $first = $fl[0]['k'] ?? 'company'; $parts = [];
  foreach ($fl as $x) if ($x['t'] !== 'file' && $x['k'] !== $first && ($dd[$x['k']] ?? '') !== '' && ($dd[$x['k']] ?? null) !== null) $parts[] = $x['l'] . ': ' . $dd[$x['k']];
  $S['Daily reports'][] = [$r['rdate'], $um[$r['user_id']] ?? '', $r['dept'], (int) $r['sl'], $dd[$first] ?? '', implode(' | ', $parts)];
}
$name = 'Zest-CRM-' . ($f['from'] ? $f['from'] . '-to-' : '') . ($f['to'] ?? date('Y-m-d')) . '.xlsx';
$bin = xlsx_build($S);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . strlen($bin));
header('Cache-Control: no-store');
echo $bin;
