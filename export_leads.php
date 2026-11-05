<?php
// Admin: expo data (research + telecalling) as Excel. One expo, or everything matching the filters.
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/report.php';
require_once __DIR__ . '/lib/xlsx.php';
$me = current_user();
if (!$me) { header('Location: login.php'); exit; }
if ($me['role'] !== 'admin') { http_response_code(403); exit('Only admin can export.'); }
$d = fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET[$k] ?? '') ? $_GET[$k] : null;
$w = '1=1'; $p = [];
if (!empty($_GET['expo_id'])) { $w .= ' AND l.expo_id = ?'; $p[] = (int) $_GET['expo_id']; }
if (!empty($_GET['user_id'])) { $w .= ' AND l.user_id = ?'; $p[] = (int) $_GET['user_id']; }
if (!empty($_GET['dept'])) { $w .= ' AND l.dept = ?'; $p[] = (string) $_GET['dept']; }
if ($f = $d('from')) { $w .= ' AND l.created_at >= ?'; $p[] = "$f 00:00:00"; }
if ($t = $d('to')) { $w .= ' AND l.created_at <= ?'; $p[] = "$t 23:59:59"; }
if ($q = trim($_GET['q'] ?? '')) { $w .= ' AND (e.name LIKE ? OR e.city LIKE ?)'; $p[] = "%$q%"; $p[] = "%$q%"; }
$rows = all('SELECT l.*, e.name AS expo, e.edate, e.city, e.website, u.name AS by_name FROM ' . T('leads') . ' l JOIN ' . T('expos') . ' e ON e.id = l.expo_id LEFT JOIN ' . T('users') . " u ON u.id = l.user_id WHERE $w ORDER BY e.name, l.id", $p);
$S = ['Expo data' => [['Sl No', 'Expo name', 'Expo date', 'Expo location / city', 'Expo website', 'Company name', 'Contact person', 'Phone number', 'Email', 'Comments / notes', 'Entered by', 'Department', 'Entered on', 'Last edited']]];
$sl = []; foreach ($rows as $r) { $sl[$r['expo_id']] = ($sl[$r['expo_id']] ?? 0) + 1;
  $S['Expo data'][] = [$sl[$r['expo_id']], $r['expo'], $r['edate'], $r['city'], $r['website'], $r['company'], $r['person'], $r['phone'], $r['email'], $r['notes'], $r['by_name'], $r['dept'], substr($r['created_at'], 0, 16), $r['updated_at'] ? substr($r['updated_at'], 0, 16) : ''];
}
// summary per expo
$S['Summary'] = [['Expo name', 'Expo date', 'City', 'Entries', 'Entered by']];
$g = []; foreach ($rows as $r) { $k = $r['expo_id']; $g[$k] = $g[$k] ?? [$r['expo'], $r['edate'], $r['city'], 0, []]; $g[$k][3]++; $g[$k][4][$r['by_name']] = ($g[$k][4][$r['by_name']] ?? 0) + 1; }
foreach ($g as $x) $S['Summary'][] = [$x[0], $x[1], $x[2], $x[3], implode(', ', array_map(fn($n, $c) => "$n ($c)", array_keys($x[4]), $x[4]))];
$one = !empty($_GET['expo_id']) && $rows ? preg_replace('/[^A-Za-z0-9]+/', '-', $rows[0]['expo']) : 'all';
$bin = xlsx_build($S);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Zest-expo-data-' . trim($one, '-') . '-' . date('Y-m-d') . '.xlsx"');
header('Content-Length: ' . strlen($bin));
header('Cache-Control: no-store');
echo $bin;
