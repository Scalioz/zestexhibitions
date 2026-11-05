<?php
// Design & quote approvals: sales request → admin approves / rejects → sales marks sent to customer.
// Included from api.php after login, CSRF and helpers. Every case ends with out() or fail().
if (!defined('ZEST_API')) exit;

function appr_row($id) { $a = one('SELECT * FROM ' . T('approvals') . ' WHERE id = ?', [(int) $id]); if (!$a) fail('Approval request not found.', 404); return $a; }
function appr_list($where, $p, $limit = 500) {
  $rows = all('SELECT a.*, pr.expo, pr.location, pr.start_date, pr.owner_id, pr.client_id, c.company, u.name AS requested_by_name FROM ' . T('approvals') . ' a JOIN ' . T('projects') . ' pr ON pr.id = a.project_id JOIN ' . T('clients') . ' c ON c.id = pr.client_id LEFT JOIN ' . T('users') . " u ON u.id = a.requested_by WHERE $where ORDER BY a.requested_at DESC, a.id DESC LIMIT $limit", $p);
  $fl = files_of('appr', array_column($rows, 'id'));
  foreach ($rows as &$r) { $r['files'] = $fl[$r['id']] ?? []; $r['stage'] = $r['sent_on'] ? 'sent' : $r['status']; } unset($r);
  return $rows;
}
function can_sales() { global $RECORDS; if (!$RECORDS) fail('Your department does not send designs or quotes.', 403); }

switch ($A) {

case 'appr_request':
  can_sales();
  $p = project_row($in['project_id'] ?? 0);
  $kind = ($in['kind'] ?? '') === 'quote' ? 'quote' : (($in['kind'] ?? '') === 'design' ? 'design' : fail('Pick design or quote.'));
  $amount = $kind === 'quote' ? n($in['amount'] ?? null) : null;
  if ($kind === 'quote' && !$amount) fail('Enter the quote amount.');
  $reuse = array_filter(array_map('intval', (array) ($in['reuse_file_ids'] ?? [])));
  $ver = (int) val('SELECT COALESCE(MAX(version),0)+1 FROM ' . T('approvals') . ' WHERE project_id = ? AND kind = ?', [$p['id'], $kind]);
  db()->beginTransaction();
  $id = ins('approvals', ['project_id' => $p['id'], 'kind' => $kind, 'version' => $ver, 'amount' => $amount, 'note' => s($in['note'] ?? null, 4000),
    'requested_by' => $MID, 'requested_at' => now(), 'status' => 'pending']);
  if ($reuse) {   // attach files already on this order (e.g. the designer's PDF) without uploading again
    $ok = all('SELECT * FROM ' . T('files') . " WHERE id IN (" . implode(',', $reuse) . ") AND entity IN ('final','wd','floor','pbrief') AND entity_id = ?", [$p['id']]);
    foreach ($ok as $f) ins('files', ['entity' => 'appr', 'entity_id' => $id, 'orig_name' => $f['orig_name'], 'stored_name' => $f['stored_name'], 'mime' => $f['mime'], 'size' => $f['size'], 'uploaded_by' => $MID, 'created_at' => now()]);
  }
  db()->commit();
  out(['id' => $id, 'version' => $ver]);

case 'appr_cancel':
  can_sales();
  $a = appr_row($in['id'] ?? 0); project_row($a['project_id']);
  if ($a['status'] !== 'pending') fail('Only a request still waiting for approval can be withdrawn.');
  if (!$ADMIN && (int) $a['requested_by'] !== $MID) fail('Only the person who asked can withdraw it.', 403);
  $files = all('SELECT * FROM ' . T('files') . " WHERE entity = 'appr' AND entity_id = ?", [$a['id']]);
  q('DELETE FROM ' . T('files') . " WHERE entity = 'appr' AND entity_id = ?", [$a['id']]);
  q('DELETE FROM ' . T('approvals') . ' WHERE id = ?', [$a['id']]);
  foreach ($files as $f) if (!val('SELECT COUNT(*) FROM ' . T('files') . ' WHERE stored_name = ?', [$f['stored_name']])) @unlink(dirname(__DIR__) . '/uploads/' . $f['stored_name']);
  out(['ok' => true]);

case 'appr_decide':
  need_admin();
  $a = appr_row($in['id'] ?? 0);
  if ($a['sent_on']) fail('This version was already sent to the customer.');
  $dec = in_array($in['decision'] ?? '', ['approved', 'rejected'], true) ? $in['decision'] : fail('Choose approve or reject.');
  $note = s($in['note'] ?? null, 4000);
  if ($dec === 'rejected' && !$note) fail('Write what needs to change, so sales can fix it.');
  upd('approvals', $a['id'], ['status' => $dec, 'decided_by' => $me['name'], 'decided_at' => now(), 'admin_note' => $note]);
  if ($dec === 'approved' && $a['kind'] === 'design') upd('projects', $a['project_id'], ['design_approved_by' => $me['name']]);
  out(['ok' => true]);

case 'appr_sent':
  can_sales();
  $a = appr_row($in['id'] ?? 0); $p = project_row($a['project_id']);
  if ($a['status'] !== 'approved') fail('Only an approved version can be sent to the customer.');
  if ($a['sent_on']) fail('Already marked as sent.');
  $on = d($in['sent_on'] ?? null) ?? today();
  if ($on < substr($a['decided_at'], 0, 10)) fail('Sent date cannot be before the approval date.');
  db()->beginTransaction();
  upd('approvals', $a['id'], ['sent_on' => $on, 'sent_by' => $me['name'], 'sent_at' => now()]);
  if ($a['kind'] === 'quote') {   // keeps "Quote versions sent to client", offered price and order status in step
    $qv = (int) val('SELECT COALESCE(MAX(version),0)+1 FROM ' . T('quotes') . ' WHERE project_id = ?', [$p['id']]);
    $qid = ins('quotes', ['project_id' => $p['id'], 'version' => $qv, 'qdate' => $on, 'amount' => $a['amount'], 'approved_by' => $a['decided_by'], 'notes' => 'Approval request V' . $a['version'] . ($a['note'] ? ' — ' . $a['note'] : '')]);
    foreach (all('SELECT * FROM ' . T('files') . " WHERE entity = 'appr' AND entity_id = ?", [$a['id']]) as $f)   // same PDF shows on the sent quote
      ins('files', ['entity' => 'quote', 'entity_id' => $qid, 'orig_name' => $f['orig_name'], 'stored_name' => $f['stored_name'], 'mime' => $f['mime'], 'size' => $f['size'], 'uploaded_by' => $MID, 'created_at' => now()]);
    upd('projects', $p['id'], ['offered' => $a['amount']] + ($p['status'] === 'enquiry' ? ['status' => 'quoted'] : []));
  }
  db()->commit();
  out(['ok' => true]);

// ----- admin: sales log -----
case 'sales_log':
  need_admin();
  $w = '1=1'; $p = [];
  $st = $in['status'] ?? 'pending';
  if ($st === 'pending') $w .= " AND a.status = 'pending'";
  elseif ($st === 'approved') $w .= " AND a.status = 'approved' AND a.sent_on IS NULL";
  elseif ($st === 'rejected') $w .= " AND a.status = 'rejected'";
  elseif ($st === 'sent') $w .= ' AND a.sent_on IS NOT NULL';
  if (in_array($in['kind'] ?? '', ['design', 'quote'], true)) { $w .= ' AND a.kind = ?'; $p[] = $in['kind']; }
  if (!empty($in['user_id'])) { $w .= ' AND a.requested_by = ?'; $p[] = (int) $in['user_id']; }
  if ($fr = d($in['from'] ?? null)) { $w .= ' AND a.requested_at >= ?'; $p[] = "$fr 00:00:00"; }
  if ($to = d($in['to'] ?? null)) { $w .= ' AND a.requested_at <= ?'; $p[] = "$to 23:59:59"; }
  if ($qs = s($in['q'] ?? null, 100)) { $w .= ' AND (c.company LIKE ? OR pr.expo LIKE ?)'; $p[] = "%$qs%"; $p[] = "%$qs%"; }
  $rows = appr_list($w, $p);
  // every version of the same order + kind, so admin sees V1, V2, V3 together
  $hist = [];
  if ($rows) { $pids = implode(',', array_unique(array_map('intval', array_column($rows, 'project_id'))));
    foreach (appr_list("a.project_id IN ($pids)", [], 2000) as $h) $hist[$h['project_id'] . ':' . $h['kind']][] = $h; }
  foreach ($rows as &$r) { $r['versions'] = array_reverse($hist[$r['project_id'] . ':' . $r['kind']] ?? []); } unset($r);
  // per sales person
  $mods = setting('dept_modules', []); $people = []; $m1 = date('Y-m-01');
  foreach (all('SELECT id, name, department FROM ' . T('users') . " WHERE active = 1 AND role = 'employee' ORDER BY name") as $u) {
    if (($mods[$u['department']] ?? 'none') !== 'records') continue;
    $ps = all('SELECT * FROM ' . T('projects') . ' WHERE owner_id = ?', [$u['id']]); $b = bills_for($ps);
    $won = array_filter($ps, fn($x) => is_won($x['status']));
    $cnt = fn($k, $extra = '') => (int) val('SELECT COUNT(*) FROM ' . T('approvals') . " WHERE requested_by = ? AND kind = ? $extra", [$u['id'], $k]);
    $people[] = ['id' => $u['id'], 'name' => $u['name'], 'dept' => $u['department'],
      'clients' => (int) val('SELECT COUNT(*) FROM ' . T('clients') . ' WHERE owner_id = ?', [$u['id']]),
      'clients_month' => (int) val('SELECT COUNT(*) FROM ' . T('clients') . ' WHERE owner_id = ? AND created_at >= ?', [$u['id'], "$m1 00:00:00"]),
      'orders' => count($ps), 'won' => count($won), 'business' => array_sum(array_map(fn($x) => $b[$x['id']]['total'], $won)),
      'designs' => $cnt('design'), 'quotes' => $cnt('quote'),
      'pending' => (int) val('SELECT COUNT(*) FROM ' . T('approvals') . " WHERE requested_by = ? AND status = 'pending'", [$u['id']]),
      'to_send' => (int) val('SELECT COUNT(*) FROM ' . T('approvals') . " WHERE requested_by = ? AND status = 'approved' AND sent_on IS NULL", [$u['id']]),
      'rejected' => (int) val('SELECT COUNT(*) FROM ' . T('approvals') . " WHERE requested_by = ? AND status = 'rejected'", [$u['id']])];
  }
  // recent activity feed
  $um = users_map(); $feed = [];
  foreach (appr_list('1=1', [], 80) as $a) {
    $t = ($a['kind'] === 'quote' ? 'Quote' : 'Design') . ' V' . $a['version'] . ' · ' . $a['company'] . ' · ' . $a['expo'] . ($a['amount'] ? ' · ₹' . inr_txt($a['amount']) : '');
    $feed[] = ['at' => $a['requested_at'], 'type' => 'Sent for approval', 'who' => $a['requested_by_name'], 'text' => $t];
    if ($a['decided_at']) $feed[] = ['at' => $a['decided_at'], 'type' => $a['status'] === 'approved' ? 'Approved' : 'Rejected', 'who' => $a['decided_by'], 'text' => $t . ($a['admin_note'] ? ' — ' . $a['admin_note'] : '')];
    if ($a['sent_at']) $feed[] = ['at' => $a['sent_at'], 'type' => 'Sent to customer', 'who' => $a['sent_by'], 'text' => $t . ' (on ' . date('d M', strtotime($a['sent_on'])) . ')'];
  }
  foreach (all('SELECT company, owner_id, created_by, created_at FROM ' . T('clients') . ' ORDER BY id DESC LIMIT 40') as $c) $feed[] = ['at' => $c['created_at'], 'type' => 'Client added', 'who' => $um[$c['created_by']] ?? '—', 'text' => $c['company']];
  foreach (all('SELECT p.expo, p.owner_id, p.created_at, c.company FROM ' . T('projects') . ' p JOIN ' . T('clients') . ' c ON c.id = p.client_id ORDER BY p.id DESC LIMIT 40') as $o) $feed[] = ['at' => $o['created_at'], 'type' => 'Enquiry opened', 'who' => $um[$o['owner_id']] ?? '—', 'text' => $o['company'] . ' · ' . $o['expo']];
  usort($feed, fn($a, $b) => strcmp($b['at'], $a['at']));
  $counts = []; foreach (['pending' => "status = 'pending'", 'approved' => "status = 'approved' AND sent_on IS NULL", 'rejected' => "status = 'rejected'", 'sent' => 'sent_on IS NOT NULL'] as $k => $c) $counts[$k] = (int) val('SELECT COUNT(*) FROM ' . T('approvals') . " WHERE $c");
  out(['rows' => $rows, 'people' => $people, 'feed' => array_slice($feed, 0, 60), 'counts' => $counts]);
}
fail('Unknown action', 404);
