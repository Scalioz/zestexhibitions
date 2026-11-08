<?php
// Admin: monthly attendance sheet as Excel — summary per person + every day.
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/calc.php';
require_once __DIR__ . '/lib/att.php';
require_once __DIR__ . '/lib/xlsx.php';
$me = current_user();
if (!$me) { header('Location: login.php'); exit; }
if ($me['role'] !== 'admin') { http_response_code(403); exit('Only admin can export.'); }
$ym = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
$S = ['Summary' => [['Employee', 'Department', 'Shift', 'Working days', 'Present', 'Late', 'Absent', 'Leave (full days)', 'Half days', 'Paid leave', 'Unpaid / deductible leave', 'Permissions', 'Work from home', 'Forgot check-out', 'Probation until']],
      'Daily' => [['Date', 'Day', 'Employee', 'Department', 'Expected by', 'Check in', 'Check out', 'Status', 'Notes', 'Outside office network']]];
foreach (all('SELECT * FROM ' . T('users') . " WHERE active = 1 AND role = 'employee' ORDER BY name") as $u) {
  $m = att_month($u, $ym); $pe = att_probation_end($u);
  $S['Summary'][] = [$u['name'], $u['department'], implode('–', att_shift($u)), $m['working'], $m['present'], $m['late'], $m['absent'], $m['leave'], $m['half'], $m['paid'], $m['unpaid'], $m['permission'], $m['wfh'], $m['no_out'], today() < $pe ? $pe : ''];
  foreach ($m['days'] as $d) if ($d['code'] !== 'off') $S['Daily'][] = [$d['date'], date('D', strtotime($d['date'])), $u['name'], $u['department'], $d['expected'], $d['in'], $d['out'], $d['label'], implode('; ', $d['notes']), $d['off_net'] ? 'Yes' : ''];
}
$bin = xlsx_build($S);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Zest-attendance-' . $ym . '.xlsx"');
header('Content-Length: ' . strlen($bin));
echo $bin;
