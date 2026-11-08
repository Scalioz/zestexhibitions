<?php
// Serves an uploaded file only to people allowed to see it.
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/fileauth.php';
require_once __DIR__ . '/lib/share.php';
$pub = share_open('file');   // a secure link sent to a client (no login)
$me = $pub ? null : current_user();
if (!$pub && !$me) { http_response_code(401); exit('Please sign in.'); }
$f = one('SELECT * FROM ' . T('files') . ' WHERE id = ?', [(int) ($pub ? $pub['ref_id'] : ($_GET['id'] ?? 0))]);
if (!$f) { http_response_code(404); exit('File not found.'); }

$allowed = $pub ? true : file_allowed($me, $f);
if (!$allowed) { http_response_code(403); exit('You do not have access to this file.'); }

$path = __DIR__ . '/uploads/' . $f['stored_name'];
if (!is_file($path)) { http_response_code(404); exit('File missing on server.'); }
$inline = preg_match('#^(image/(png|jpeg|gif|webp)|application/pdf)$#', $f['mime']) && empty($_GET['dl']);
header('Content-Type: ' . ($inline ? $f['mime'] : 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; object-src 'self'");
$name = str_replace(['"', "\r", "\n"], '', $f['orig_name']);
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($f['orig_name']));
header('Cache-Control: private, max-age=3600');
readfile($path);
