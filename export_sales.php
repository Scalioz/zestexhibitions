<?php
// Admin: approval log (every design / quote version with dates) as Excel.
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/report.php';
require_once __DIR__ . '/lib/xlsx.php';
$me = current_user();
if (!$me) { header('Location: login.php'); exit; }
if ($me['role'] !== 'admin') { http_response_code(403); exit('Only admin can export.'); }
$d = fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET[$k] ?? '') ? $_GET[$k] : null;
$w = '1=1'; $p = [];
$st = $_GET['status'] ?? 'all';
if ($st === 'pending') $w .= " AND a.status = 'pending'";
elseif ($st === 'approved') $w .= " AND a.status = 'approved' AND a.sent_on IS NULL";
elseif ($st === 'rejected') $w .= " AND a.status = 'rejected'";
elseif ($st === 'sent') $w .= ' AND a.sent_on IS NOT NULL';
if (in_array($_GET['kind'] ?? '', ['design', 'quote'], true)) { $w .= ' AND a.kind = ?'; $p[] = $_GET['kind']; }
if (!empty($_GET['user_id'])) { $w .= ' AND a.requested_by = ?'; $p[] = (int) $_GET['user_id']; }
if ($f = $d('from')) { $w .= ' AND a.requested_at >= ?'; $p[] = "$f 00:00:00"; }
if ($t = $d('to')) { $w .= ' AND a.requested_at <= ?'; $p[] = "$t 23:59:59"; }
if ($q = trim($_GET['q'] ?? '')) { $w .= ' AND (c.company LIKE ? OR pr.expo LIKE ?)'; $p[] = "%$q%"; $p[] = "%$q%"; }
$rows = all('SELECT a.*, pr.expo, pr.location, c.company, u.name AS rb FROM ' . T('approvals') . ' a JOIN ' . T('projects') . ' pr ON pr.id = a.project_id JOIN ' . T('clients') . ' c ON c.id = pr.client_id LEFT JOIN ' . T('users') . " u ON u.id = a.requested_by WHERE $w ORDER BY c.company, pr.expo, a.kind, a.version", $p);
$days = fn($a, $b) => $a && $b ? round((strtotime($b) - strtotime($a)) / 86400, 1) : '';
$S = ['Approvals' => [['Client', 'Expo', 'Expo location', 'Type', 'Version', 'Quote amount (₹)', 'Sales person', 'Sent for approval on', 'Sales note', 'Status', 'Decided by', 'Decided on', 'Admin note', 'Days to decide', 'Sent to customer on', 'Marked sent by', 'Files']]];
foreach ($rows as $r) {
  $files = implode(', ', array_column(all('SELECT orig_name FROM ' . T('files') . " WHERE entity = 'appr' AND entity_id = ?", [$r['id']]), 'orig_name'));
  $S['Approvals'][] = [$r['company'], $r['expo'], $r['location'], ucfirst($r['kind']), 'V' . $r['version'], $r['amount'] === null ? '' : (float) $r['amount'], $r['rb'], substr($r['requested_at'], 0, 16), $r['note'],
    $r['sent_on'] ? 'Sent to customer' : ucfirst($r['status']), $r['decided_by'], $r['decided_at'] ? substr($r['decided_at'], 0, 16) : '', $r['admin_note'], $days($r['requested_at'], $r['decided_at']), $r['sent_on'], $r['sent_by'], $files];
}
$bin = xlsx_build($S);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Zest-sales-approvals-' . date('Y-m-d') . '.xlsx"');
header('Content-Length: ' . strlen($bin));
header('Cache-Control: no-store');
echo $bin;
