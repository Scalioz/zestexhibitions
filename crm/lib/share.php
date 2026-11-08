<?php
// Secure links for clients: open one quote, invoice or file without logging in. Valid 30 days, can be switched off.
function share_open($kind) {   // returns the link row when the page is opened with ?t=TOKEN, else null (or stops with an error)
  $t = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['t'] ?? ''));
  if ($t === '') return null;
  $r = null; try { $r = one('SELECT * FROM ' . T('share_links') . ' WHERE token = ? AND kind = ?', [$t, $kind]); } catch (PDOException $e) {}
  if (!$r || (int) $r['revoked'] || $r['expires_at'] < date('Y-m-d H:i:s')) { http_response_code(410); exit('<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><body style="font:16px system-ui;padding:40px;text-align:center;color:#1b2a5c"><h2>This link has expired</h2><p>Please ask Zest Exhibitions for a new one.</p></body>'); }
  return $r;
}
function share_make($kind, $id, $uid) {
  $ex = one('SELECT token FROM ' . T('share_links') . ' WHERE kind = ? AND ref_id = ? AND revoked = 0 AND expires_at > ? ORDER BY id DESC', [$kind, (int) $id, date('Y-m-d H:i:s', strtotime('+7 days'))]);
  if ($ex) return $ex['token'];
  $t = rtrim(strtr(base64_encode(random_bytes(18)), '+/', 'AZ'), '=');
  ins('share_links', ['token' => $t, 'kind' => $kind, 'ref_id' => (int) $id, 'created_by' => (int) $uid, 'created_at' => now(), 'expires_at' => date('Y-m-d H:i:s', strtotime('+30 days')), 'revoked' => 0]);
  return $t;
}
