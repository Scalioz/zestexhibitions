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

// SQLite (test server): take the write lock at the start of a transaction, so two people saving at once queue up cleanly.
class ZestSqlite extends PDO {
  private $tx = false;
  public function beginTransaction(): bool { $this->exec('BEGIN IMMEDIATE'); $this->tx = true; return true; }
  public function commit(): bool { $this->exec('COMMIT'); $this->tx = false; return true; }
  public function rollBack(): bool { $this->exec('ROLLBACK'); $this->tx = false; return true; }
  public function inTransaction(): bool { return $this->tx; }
}

function db() {
  static $pdo = null;
  if ($pdo) return $pdo;
  $c = cfg();
  if (!$c) throw new Exception('Not installed');
  if (($c['driver'] ?? 'mysql') === 'sqlite') {
    $pdo = new ZestSqlite('sqlite:' . $c['path']);
    $pdo->exec('PRAGMA busy_timeout = 15000');   // wait for another person's save instead of failing
    $pdo->exec('PRAGMA journal_mode = WAL');      // readers never block writers
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
  } else {
    $pdo = new PDO("mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4", $c['user'], $c['pass']);
    $pdo->exec("SET time_zone = '+05:30'");
  }
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  $GLOBALS['ZEST_PDO'] = $pdo;
  return $pdo;
}
function tx() { if (!db()->inTransaction()) db()->beginTransaction(); }   // start a transaction (once)
// Re-read a row inside the transaction and hold it, so two people clicking the same button at the same moment are handled one after the other.
function lock_row($table, $id) {
  tx();
  $mysql = (cfg()['driver'] ?? 'mysql') !== 'sqlite';
  return one('SELECT * FROM ' . T($table) . ' WHERE id = ?' . ($mysql ? ' FOR UPDATE' : ''), [(int) $id]);
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
