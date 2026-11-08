<?php
// Who may see an uploaded file (used by file.php and by share links).
function file_allowed($me, $f) {
  $admin = $me['role'] === 'admin'; $uid = (int) $me['id'];
  $allowed = $admin;
  if (!$allowed) {
    $pid = null;
    switch ($f['entity']) {
      case 'card': case 'brief': case 'gstcert': $allowed = (int) val('SELECT owner_id FROM ' . T('clients') . ' WHERE id = ?', [$f['entity_id']]) === $uid; break;
      case 'floor': case 'final': case 'wd': case 'pbrief': $pid = $f['entity_id']; break;
      case 'quote': $pid = val('SELECT project_id FROM ' . T('quotes') . ' WHERE id = ?', [$f['entity_id']]); break;
      case 'vquote': $pid = val('SELECT project_id FROM ' . T('vendor_quotes') . ' WHERE id = ?', [$f['entity_id']]); break;
      case 'claim': $allowed = (int) val('SELECT user_id FROM ' . T('claims') . ' WHERE id = ?', [$f['entity_id']]) === $uid
        || has_mod($me, 'accounts'); break;
      case 'cardscan': $allowed = (int) val('SELECT user_id FROM ' . T('card_scans') . ' WHERE id = ?', [$f['entity_id']]) === $uid; break;
      case 'voucher': $allowed = has_mod($me, 'accounts'); break;
      case 'appr': $pid = val('SELECT project_id FROM ' . T('approvals') . ' WHERE id = ?', [$f['entity_id']]); break;
      case 'revision': $pid = val('SELECT project_id FROM ' . T('revisions') . ' WHERE id = ?', [$f['entity_id']]); break;
      case 'ritem': $allowed = (int) val('SELECT r.user_id FROM ' . T('report_items') . ' i JOIN ' . T('reports') . ' r ON r.id = i.report_id WHERE i.id = ?', [$f['entity_id']]) === $uid; break;
    }
    if ($pid) {   // order owner (sales), or the designer / production person assigned to it
      $pp = one('SELECT owner_id, designer_id, ops_id FROM ' . T('projects') . ' WHERE id = ?', [$pid]);
      $allowed = $pp && ((int) $pp['owner_id'] === $uid || (in_array($f['entity'], ['floor', 'final', 'wd', 'pbrief'], true) && ((int) $pp['designer_id'] === $uid || (int) $pp['ops_id'] === $uid)));
    }
  }
  return $allowed;
}
