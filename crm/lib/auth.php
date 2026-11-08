<?php
require_once __DIR__ . '/db.php';

function start_session() {
  if (session_status() === PHP_SESSION_ACTIVE) return;
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  session_name('zestcrm');
  session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'secure' => $https, 'samesite' => 'Lax']);
  session_start();
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
  // auto-logout after 10 hours idle
  if (!empty($_SESSION['uid']) && time() - ($_SESSION['seen'] ?? time()) > 36000) { $_SESSION = []; session_destroy(); start_session(); }
  $_SESSION['seen'] = time();
}

function current_user() {
  static $u = false;
  if ($u !== false) return $u;
  start_session();
  $u = null;
  if (!empty($_SESSION['uid'])) {
    $u = one('SELECT * FROM ' . T('users') . ' WHERE id = ? AND active = 1', [$_SESSION['uid']]);
    if ($u) unset($u['pass_hash']);
    if (!$u) { $_SESSION = []; }
  }
  return $u;
}

// Everything a person works on: their main department plus any extra departments admin gave them.
function user_depts($u) { $x = json_decode($u['extra_depts'] ?? '', true); return array_values(array_unique(array_filter(array_merge([$u['department'] ?? ''], is_array($x) ? $x : [])))); }
function user_mods($u) { $m = (array) setting('dept_modules', []); return array_values(array_unique(array_filter(array_map(fn($d) => $m[$d] ?? null, user_depts($u)), fn($v) => $v && $v !== 'none'))); }
function has_mod($u, $mod) { return in_array($mod, user_mods($u), true); }
function is_admin() { $u = current_user(); return $u && $u['role'] === 'admin'; }

function try_login($email, $pass) {
  start_session();
  $_SESSION['fails'] = $_SESSION['fails'] ?? 0;
  if ($_SESSION['fails'] >= 5 && time() - ($_SESSION['fail_at'] ?? 0) < 300) return 'Too many attempts. Try again in 5 minutes.';
  $u = one('SELECT id, pass_hash, active FROM ' . T('users') . ' WHERE email = ?', [strtolower(trim($email))]);
  if (!$u || !password_verify($pass, $u['pass_hash'])) {
    $_SESSION['fails']++; $_SESSION['fail_at'] = time(); usleep(400000);
    return 'Email or password is wrong.';
  }
  if (!$u['active']) return 'This login has been disabled. Contact admin.';
  session_regenerate_id(true);
  $_SESSION['uid'] = (int) $u['id']; $_SESSION['fails'] = 0;
  return null;
}

function logout() { start_session(); $_SESSION = []; session_destroy(); }
