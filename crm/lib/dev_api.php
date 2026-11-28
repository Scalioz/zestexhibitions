<?php
// Office devices given to staff: register, hand over, employee acknowledgement, surrender, replacement. Included from api.php for dev_* actions.
if (!defined('ZEST_API')) exit;

const DEV_KINDS = ['laptop' => 'Laptop', 'tablet' => 'Tablet', 'mobile' => 'Mobile phone', 'sim' => 'SIM card', 'other' => 'Other'];
const DEV_COND = ['new' => 'New', 'good' => 'Good', 'fair' => 'Fair (minor wear)', 'worn' => 'Worn out', 'damaged' => 'Damaged', 'notworking' => 'Not working'];
// why a hand-over ended → what happens to the device
const DEV_END = ['returned' => ['Returned / surrendered', 'stock'], 'worn' => ['Worn out — retired', 'retired'], 'damaged' => ['Damaged — retired', 'retired'], 'lost' => ['Lost / stolen', 'lost'], 'upgrade' => ['Upgraded — back to stock', 'stock']];

function dev_row($id) { return one('SELECT * FROM ' . T('devices') . ' WHERE id = ?', [(int) $id]) ?? fail('Device not found.', 404); }
function dev_issue($id) { return one('SELECT * FROM ' . T('device_issues') . ' WHERE id = ?', [(int) $id]) ?? fail('Hand-over not found.', 404); }
function dev_label($d) { return trim((DEV_KINDS[$d['kind']] ?? 'Device') . ' · ' . $d['name'] . ($d['serial'] ? ' · S/N ' . $d['serial'] : '')); }
function dev_issues_view($rows, $um) {
  $um2 = $um; foreach ($rows as &$r) { $r['user'] = $um2[$r['user_id']] ?? '—'; $r['by'] = $um2[$r['issued_by']] ?? ''; $r['ret_by'] = $um2[$r['returned_by']] ?? ''; $r['end_label'] = $r['end_reason'] ? (DEV_END[$r['end_reason']][0] ?? $r['end_reason']) : null; } unset($r);
  return $rows;
}
function dev_open_issue($devId) { return one('SELECT * FROM ' . T('device_issues') . ' WHERE device_id = ? AND returned_on IS NULL', [(int) $devId]); }
function dev_hand($dev, $uid, $in, $replaces = null) {
  global $MID;
  if (!in_array($dev['status'], ['stock'], true)) fail(dev_label($dev) . ' is not in the office — it is ' . $dev['status'] . '.');
  if (!one('SELECT id FROM ' . T('users') . ' WHERE id = ? AND active = 1', [(int) $uid])) fail('Pick an active team member.');
  $id = ins('device_issues', ['device_id' => $dev['id'], 'user_id' => (int) $uid, 'issued_on' => d($in['issued_on'] ?? null) ?? today(), 'issued_by' => $MID,
    'cond_out' => isset(DEV_COND[$in['cond_out'] ?? '']) ? $in['cond_out'] : 'good', 'accessories' => s($in['accessories'] ?? null, 300) ?? $dev['accessories'], 'note' => s($in['note'] ?? null, 1000),
    'replaces_id' => $replaces, 'created_at' => now()]);
  upd('devices', $dev['id'], ['status' => 'issued']);
  return $id;
}
function dev_end($iss, $in) {
  global $MID;
  $why = isset(DEV_END[$in['end_reason'] ?? '']) ? $in['end_reason'] : 'returned';
  $on = d($in['returned_on'] ?? null) ?? today(); if ($on < $iss['issued_on']) fail('The surrender date cannot be before the date it was given.');
  upd('device_issues', $iss['id'], ['returned_on' => $on, 'cond_in' => isset(DEV_COND[$in['cond_in'] ?? '']) ? $in['cond_in'] : null, 'end_reason' => $why, 'return_note' => s($in['return_note'] ?? null, 1000), 'returned_by' => $MID]);
  upd('devices', $iss['device_id'], ['status' => DEV_END[$why][1]]);
}

switch ($A) {

case 'dev_list':   // admin: every device with who holds it now, and the full hand-over history
  need_admin();
  $um = users_map();
  $devs = all('SELECT * FROM ' . T('devices') . ' ORDER BY kind, name');
  $iss = dev_issues_view(all('SELECT * FROM ' . T('device_issues') . ' ORDER BY issued_on DESC, id DESC'), $um);
  $by = []; foreach ($iss as $i) $by[$i['device_id']][] = $i;
  $active = array_column(all('SELECT id, active FROM ' . T('users')), 'active', 'id');
  foreach ($devs as &$d) { $d['history'] = $by[$d['id']] ?? []; $d['holder'] = null; foreach ($d['history'] as $h) if (!$h['returned_on']) $d['holder'] = $h + ['user_active' => (int) ($active[$h['user_id']] ?? 0)]; } unset($d);
  out(['devices' => $devs, 'kinds' => DEV_KINDS, 'conds' => DEV_COND, 'ends' => array_map(fn($x) => $x[0], DEV_END),
    'users' => all('SELECT id, name, department FROM ' . T('users') . " WHERE active = 1 AND role <> 'admin' ORDER BY name")]);

case 'dev_save':   // admin: add / edit a device
  need_admin();
  $row = ['kind' => isset(DEV_KINDS[$in['kind'] ?? '']) ? $in['kind'] : 'other', 'name' => s($in['name'] ?? null, 150) ?? fail('Enter the make & model, e.g. Lenovo IdeaPad 3.'),
    'serial' => s($in['serial'] ?? null, 100), 'imei' => s($in['imei'] ?? null, 100), 'accessories' => s($in['accessories'] ?? null, 300),
    'bought_on' => d($in['bought_on'] ?? null), 'cost' => is_numeric($in['cost'] ?? '') ? round((float) $in['cost'], 2) : null, 'notes' => s($in['notes'] ?? null, 2000)];
  if ($row['serial'] && val('SELECT id FROM ' . T('devices') . ' WHERE serial = ? AND id <> ?', [$row['serial'], (int) ($in['id'] ?? 0)])) fail('A device with this serial number is already in the list.');
  if (!empty($in['id'])) { dev_row($in['id']); upd('devices', (int) $in['id'], $row); out(['id' => (int) $in['id']]); }
  $id = ins('devices', $row + ['status' => 'stock', 'created_by' => $MID, 'created_at' => now()]);
  if (!empty($in['give_to'])) dev_hand(dev_row($id), $in['give_to'], $in);   // add and hand over in one go
  out(['id' => $id]);

case 'dev_give':   // admin: hand a device in the office to a team member
  need_admin();
  tx(); lock_row('devices', $in['device_id'] ?? 0); $dev = dev_row($in['device_id'] ?? 0);
  out(['id' => dev_hand($dev, $in['user_id'] ?? 0, $in)]);

case 'dev_return':   // admin: device surrendered / worn out / lost
  need_admin();
  tx(); $iss = dev_issue($in['id'] ?? 0); if ($iss['returned_on']) fail('This was already closed.');
  dev_end($iss, $in); out(['ok' => true]);

case 'dev_replace':   // admin: close the old hand-over and give a replacement in one step
  need_admin();
  tx(); $iss = dev_issue($in['id'] ?? 0); if ($iss['returned_on']) fail('This was already closed.');
  if (!empty($in['new_name'])) {   // a brand-new device, added on the spot
    $old = dev_row($iss['device_id']);
    $nid = ins('devices', ['kind' => isset(DEV_KINDS[$in['new_kind'] ?? '']) ? $in['new_kind'] : $old['kind'], 'name' => s($in['new_name'], 150), 'serial' => s($in['new_serial'] ?? null, 100), 'imei' => s($in['new_imei'] ?? null, 100),
      'accessories' => s($in['accessories'] ?? null, 300), 'bought_on' => d($in['new_bought_on'] ?? null), 'cost' => is_numeric($in['new_cost'] ?? '') ? round((float) $in['new_cost'], 2) : null, 'status' => 'stock', 'created_by' => $MID, 'created_at' => now()]);
  } else $nid = (int) ($in['new_device_id'] ?? 0) ?: fail('Pick the replacement device, or add a new one.');
  $new = dev_row($nid); if ((int) $new['id'] === (int) $iss['device_id']) fail('Pick a different device as the replacement.');
  $in['returned_on'] = $in['returned_on'] ?? $in['issued_on'] ?? today(); $in['end_reason'] = isset(DEV_END[$in['end_reason'] ?? '']) ? $in['end_reason'] : 'worn';
  dev_end($iss, $in);
  out(['id' => dev_hand($new, $iss['user_id'], ['issued_on' => $in['issued_on'] ?? today(), 'cond_out' => $in['cond_out'] ?? 'new', 'accessories' => $in['accessories'] ?? null, 'note' => $in['note'] ?? null], $iss['id'])]);

case 'dev_undo':   // admin: remove a hand-over entered by mistake (only while not yet acknowledged)
  need_admin();
  tx(); $iss = dev_issue($in['id'] ?? 0);
  if ($iss['ack_at']) fail('The employee has already acknowledged this — record a surrender instead.');
  if ($iss['returned_on']) fail('This hand-over is already closed.');
  q('DELETE FROM ' . T('device_issues') . ' WHERE id = ?', [$iss['id']]); upd('devices', $iss['device_id'], ['status' => 'stock']);
  out(['ok' => true]);

case 'dev_del':   // admin: delete a device never handed to anyone
  need_admin();
  $dev = dev_row($in['id'] ?? 0);
  if (val('SELECT COUNT(*) FROM ' . T('device_issues') . ' WHERE device_id = ?', [$dev['id']])) fail('This device has a hand-over history, so it cannot be deleted. Mark it retired instead.');
  q('DELETE FROM ' . T('devices') . ' WHERE id = ?', [$dev['id']]); out(['ok' => true]);

case 'dev_status':   // admin: retire / mark lost / back to stock a device that is not with anyone
  need_admin();
  $dev = dev_row($in['id'] ?? 0); if (dev_open_issue($dev['id'])) fail('This device is with someone — record the surrender first.');
  $st = in_array($in['status'] ?? '', ['stock', 'retired', 'lost'], true) ? $in['status'] : fail('Pick a status.');
  upd('devices', $dev['id'], ['status' => $st]); out(['ok' => true]);

case 'dev_mine':   // employee: devices with me now, and what I had before
  $um = users_map();
  $rows = dev_issues_view(all('SELECT i.*, d.kind, d.name, d.serial, d.imei FROM ' . T('device_issues') . ' i JOIN ' . T('devices') . ' d ON d.id = i.device_id WHERE i.user_id = ? ORDER BY i.returned_on IS NOT NULL, i.issued_on DESC, i.id DESC', [$MID]), $um);
  foreach ($rows as &$r) $r['label'] = dev_label($r); unset($r);
  out(['rows' => $rows, 'kinds' => DEV_KINDS, 'conds' => DEV_COND, 'me' => $me['name']]);

case 'dev_ack':   // employee: "I have received this device"
  tx(); $iss = dev_issue($in['id'] ?? 0);
  if ((int) $iss['user_id'] !== $MID) fail('This device was not given to you.', 403);
  if ($iss['returned_on']) fail('This device has already been taken back.');
  if (empty($in['agree'])) fail('Tick the box to confirm.');
  if (!$iss['ack_at']) upd('device_issues', $iss['id'], ['ack_at' => now(), 'ack_ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
  out(['ok' => true]);
}
fail('Unknown action.', 404);
