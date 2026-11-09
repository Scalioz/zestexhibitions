<?php
// Public link to the company profile PDF that the team sends to new clients (WhatsApp / email). No login needed.
require_once __DIR__ . '/lib/db.php';
$f = null;
try { $f = one('SELECT * FROM ' . T('files') . " WHERE entity = 'profile' ORDER BY id DESC"); } catch (Throwable $e) {}
$path = $f ? __DIR__ . '/uploads/' . $f['stored_name'] : '';
if (!$f || !is_file($path)) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('The company profile is not available right now. Please visit www.zestexhibitions.com'); }
$name = preg_replace('/[^\w .()-]/u', '', pathinfo($f['orig_name'], PATHINFO_FILENAME)) ?: 'Zest-Exhibitions-Profile';
header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . (empty($_GET['dl']) ? 'inline' : 'attachment') . '; filename="' . $name . '.pdf"');
header('Cache-Control: public, max-age=600');
readfile($path);
