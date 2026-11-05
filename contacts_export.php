<?php
// Contacts directory as Excel (same filters as on screen).
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/xlsx.php';
$me = current_user();
if (!$me) { header('Location: login.php'); exit; }
function users_map() { $m = []; foreach (all('SELECT id, name FROM ' . T('users')) as $u) $m[$u['id']] = $u['name']; return $m; }
require_once __DIR__ . '/lib/cards.php';
$rows = directory_rows($_GET, $me['role'] === 'admin', (int) $me['id']);
$out = [['Name', 'Designation', 'Company', 'Phone', 'Email', 'City', 'Website', 'Address', 'Source', 'Tag / where from', 'Added by', 'Added on', 'Notes']];
foreach ($rows as $r) $out[] = [$r['name'], $r['designation'], $r['company'], $r['phone'], $r['email'], $r['city'], $r['website'] ?? '', $r['address'] ?? '', $r['source'], $r['tag'], $r['by'], substr((string) $r['at'], 0, 10), $r['note'] ?? ''];
$bin = xlsx_build(['Contacts' => $out]);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Zest-contacts-' . date('Y-m-d') . '.xlsx"');
header('Content-Length: ' . strlen($bin));
echo $bin;
