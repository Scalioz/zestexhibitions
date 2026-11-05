<?php
// Accounts: any book or report as Excel (same numbers as on screen).
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/acc.php';
require_once __DIR__ . '/lib/xlsx.php';
$me = current_user();
if (!$me) { header('Location: login.php'); exit; }
if (!($me['role'] === 'admin' || (setting('dept_modules', [])[$me['department']] ?? '') === 'accounts')) { http_response_code(403); exit('Only accounts can export.'); }
try { $r = run_report($_GET['r'] ?? '', $_GET); } catch (UserError $e) { http_response_code(400); exit($e->getMessage()); }
$rows = [$r['cols']];
foreach ($r['rows'] as $x) $rows[] = array_map(fn($v) => is_numeric($v) && !is_string($v) ? round((float) $v, 2) : $v, $x['c']);
$bin = xlsx_build(['Report' => $rows]);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Zest-' . preg_replace('/[^A-Za-z0-9]+/', '-', $r['title']) . '.xlsx"');
header('Content-Length: ' . strlen($bin));
echo $bin;
