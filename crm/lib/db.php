<?php
// Database helper. Config lives in /config.php (created by install.php).
date_default_timezone_set('Asia/Kolkata');

function cfg() {
  static $c = null;
  if ($c === null) {
    $f = __DIR__ . '/../config.php';
    if (!file_exists($f)) return null;
    $c = require $f;
  }
  return $c;
}

function db() {
  static $pdo = null;
  if ($pdo) return $pdo;
  $c = cfg();
  if (!$c) throw new Exception('Not installed');
  if (($c['driver'] ?? 'mysql') === 'sqlite') {
    $pdo = new PDO('sqlite:' . $c['path']);
    $pdo->exec('PRAGMA foreign_keys = ON');
  } else {
    $pdo = new PDO("mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4", $c['user'], $c['pass']);
    $pdo->exec("SET time_zone = '+05:30'");
  }
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  return $pdo;
}

function T($name) { return 'zc_' . $name; }   // table prefix

function q($sql, $p = []) { $s = db()->prepare($sql); $s->execute($p); return $s; }
function one($sql, $p = []) { $r = q($sql, $p)->fetch(); return $r ?: null; }
function all($sql, $p = []) { return q($sql, $p)->fetchAll(); }
function val($sql, $p = []) { $r = q($sql, $p)->fetchColumn(); return $r === false ? null : $r; }

function ins($table, $data) {
  $cols = array_keys($data);
  $sql = 'INSERT INTO ' . T($table) . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
  q($sql, array_values($data));
  return (int) db()->lastInsertId();
}

function upd($table, $id, $data) {
  if (!$data) return;
  $set = implode(',', array_map(fn($c) => "$c = ?", array_keys($data)));
  q('UPDATE ' . T($table) . " SET $set WHERE id = ?", array_merge(array_values($data), [$id]));
}

function setting($k, $default = null) {
  $v = val('SELECT v FROM ' . T('settings') . ' WHERE k = ?', [$k]);
  return $v === null ? $default : json_decode($v, true);
}

function set_setting($k, $v) {
  $j = json_encode($v);
  if (val('SELECT COUNT(*) FROM ' . T('settings') . ' WHERE k = ?', [$k])) q('UPDATE ' . T('settings') . ' SET v = ? WHERE k = ?', [$j, $k]);
  else q('INSERT INTO ' . T('settings') . ' (k, v) VALUES (?, ?)', [$k, $j]);
}

function today() { return date('Y-m-d'); }
function now() { return date('Y-m-d H:i:s'); }
