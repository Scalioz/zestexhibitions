<?php
if (!class_exists('UserError')) { class UserError extends Exception {} }
// Email with attachments, sent from each person's OWN mailbox (their CRM login email).
// Admin sets the company mail server once (Holidays & lists → Email sending); each person saves their mailbox password once (Account).
// A copy is saved in the person's "Sent" folder (IMAP) so it shows in Outlook like any other sent mail.

function mail_cfg() {
  return array_replace(['host' => '', 'port' => 465, 'secure' => 'ssl', 'imap_host' => '', 'imap_port' => 993], array_intersect_key((array) setting('smtp', []), array_flip(['host', 'port', 'secure', 'imap_host', 'imap_port'])));
}
function mail_server_ready() { return (bool) mail_cfg()['host']; }

// mailbox passwords are stored encrypted with a key made from this server's config file (not stored in the database)
function mail_key() { return hash('sha256', 'zest-mail|' . json_encode(cfg()), true); }
function mail_lock($plain) {
  $iv = random_bytes(12); $tag = '';
  $ct = openssl_encrypt($plain, 'aes-256-gcm', mail_key(), OPENSSL_RAW_DATA, $iv, $tag);
  return base64_encode($iv . $tag . $ct);
}
function mail_unlock($stored) {
  $raw = base64_decode((string) $stored, true); if (!$raw || strlen($raw) < 29) return null;
  $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', mail_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
  return $p === false ? null : $p;
}
function mail_user_pass($uid) { return mail_unlock(val('SELECT mail_pass FROM ' . T('users') . ' WHERE id = ?', [(int) $uid])); }
function mail_user_ready($uid) { return mail_server_ready() && (bool) val('SELECT mail_pass FROM ' . T('users') . ' WHERE id = ?', [(int) $uid]); }

// $m = ['to' => [emails], 'cc' => [emails], 'subject' => '', 'text' => '', 'files' => [[name, bytes, mime]]]
function mail_build($m, $from, $fromName) {
  $enc = fn($s) => preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
  $addr = function ($e, $n = '') use ($enc) { if (!$n) return $e; $n = str_replace(['"', '\\', "\r", "\n"], '', $n); $x = $enc($n); return ($x === $n ? '"' . $n . '"' : $x) . " <$e>"; };
  $b = 'zb' . bin2hex(random_bytes(12));
  $h = ['Date: ' . date('r'), 'From: ' . $addr($from, $fromName), 'To: ' . implode(', ', $m['to']), 'Subject: ' . $enc($m['subject']),
    'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . (explode('@', $from)[1] ?? 'localhost') . '>', 'MIME-Version: 1.0', "Content-Type: multipart/mixed; boundary=\"$b\""];
  if (!empty($m['cc'])) $h[] = 'Cc: ' . implode(', ', $m['cc']);
  $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode(str_replace("\n", "\r\n", str_replace("\r", '', $m['text']))));
  foreach ($m['files'] ?? [] as [$name, $bytes, $mime]) {
    $fn = str_replace(['"', "\r", "\n"], '', $name);
    $body .= "--$b\r\nContent-Type: $mime; name=\"$fn\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$fn\"\r\n\r\n" . chunk_split(base64_encode($bytes));
  }
  $body .= "--$b--\r\n";
  return implode("\r\n", $h) . "\r\n\r\n" . $body;
}

// send as this user (their login email + their saved mailbox password). Returns ['sent_copy' => bool]
function mail_send_as($user, $m, $pass = null) {
  $c = mail_cfg();
  if (!$c['host']) throw new UserError('Admin has not set up email sending yet (Holidays & lists → Email sending).');
  $pass = $pass ?? mail_user_pass($user['id']);
  if (!$pass) throw new UserError('Save your email password once in Account → Email sending, then send.');
  $m['to'] = array_values(array_filter((array) $m['to'], fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
  $m['cc'] = array_values(array_diff(array_filter((array) ($m['cc'] ?? []), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)), $m['to']));
  if (!$m['to']) throw new UserError('Enter a valid email address.');
  $from = $user['email'];
  $raw = mail_build($m, $from, $user['name'] . ' | Zest Exhibitions');
  smtp_talk($c, $from, $pass, array_merge($m['to'], $m['cc']), $raw);
  $saved = false;
  if (!empty($m['save_sent'])) { try { $saved = imap_save_sent($c, $from, $pass, $raw); } catch (Throwable $e) { $saved = false; } }
  return ['sent_copy' => $saved];
}

function mail_sock($host, $port, $secure) {
  $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
  $s = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . (int) $port, $en, $es, 25, STREAM_CLIENT_CONNECT, $ctx);
  if (!$s) throw new UserError("Could not reach the mail server $host:$port ($es). Check the server name and port.");
  stream_set_timeout($s, 40); return $s;
}

function smtp_talk($c, $user, $pass, $rcpts, $data) {
  $s = mail_sock($c['host'], $c['port'], $c['secure']);
  $read = function () use ($s) { $all = ''; while (($l = fgets($s, 2048)) !== false) { $all .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $all; };
  $cmd = function ($line, $ok) use ($s, $read) {
    if ($line !== null) fwrite($s, $line . "\r\n");
    $r = $read(); $code = (int) substr($r, 0, 3);
    if (!in_array($code, (array) $ok, true)) {
      $msg = trim(preg_replace('/\s+/', ' ', $r)) ?: 'no reply';
      if ($code === 535 || $code === 534) throw new UserError('Your mailbox did not accept the password. Check it in Account → Email sending (some providers need an "app password").');
      throw new UserError('Mail server said: ' . mb_substr($msg, 0, 200));
    }
    return $r;
  };
  try {
    $cmd(null, 220);
    $ehlo = 'EHLO ' . preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    $cap = $cmd($ehlo, 250);
    if ($c['secure'] === 'tls') {
      $cmd('STARTTLS', 220);
      if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) throw new UserError('Secure connection to the mail server failed.');
      $cap = $cmd($ehlo, 250);
    }
    if (stripos($cap, 'AUTH') !== false || $c['secure'] !== 'none') { $cmd('AUTH LOGIN', 334); $cmd(base64_encode($user), 334); $cmd(base64_encode($pass), 235); }
    $cmd("MAIL FROM:<$user>", 250);
    foreach (array_unique($rcpts) as $r) $cmd("RCPT TO:<$r>", [250, 251]);
    $cmd('DATA', 354);
    $data = preg_replace('/^\./m', '..', str_replace("\r\n", "\n", $data)); $data = str_replace("\n", "\r\n", $data);
    $cmd($data . "\r\n.", 250);
    @fwrite($s, "QUIT\r\n");
  } finally { fclose($s); }
}

// put a copy in the person's Sent folder, so it appears in Outlook / webmail like a normal sent mail
function imap_save_sent($c, $user, $pass, $raw) {
  if (!$c['imap_host']) return false;
  $s = mail_sock($c['imap_host'], $c['imap_port'] ?: 993, (int) $c['imap_port'] === 143 ? 'none' : 'ssl'); $n = 0;
  $q = fn($x) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $x) . '"';
  $run = function ($cmd, $literal = null) use ($s, &$n) {
    $tag = 'z' . (++$n); fwrite($s, "$tag $cmd\r\n"); $lines = [];
    while (($l = fgets($s, 8192)) !== false) {
      if ($literal !== null && str_starts_with($l, '+')) { fwrite($s, $literal . "\r\n"); $literal = null; continue; }
      if (str_starts_with($l, "$tag ")) return [stripos($l, "$tag OK") === 0, $lines];
      $lines[] = rtrim($l, "\r\n");
    }
    return [false, $lines];
  };
  try {
    fgets($s, 2048);
    if (!$run('LOGIN ' . $q($user) . ' ' . $q($pass))[0]) return false;
    [$ok, $list] = $run('LIST "" "*"'); $folder = null; $byName = null;
    foreach ($list as $l) {
      if (!preg_match('/^\* LIST \(([^)]*)\) (?:"(?:[^"\\\\]|\\\\.)*"|NIL) (.+)$/i', $l, $mm)) continue;
      $name = trim($mm[2]); if ($name !== '' && $name[0] === '"') $name = stripcslashes(substr($name, 1, -1));
      if (stripos($mm[1], '\\Sent') !== false) { $folder = $name; break; }
      if (!$byName && preg_match('/^(INBOX[.\/])?Sent( Items| Messages| Mail)?$/i', $name)) $byName = $name;
    }
    $folder = $folder ?: $byName ?: 'Sent';
    $data = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $raw));
    [$ok] = $run('APPEND ' . $q($folder) . ' (\\Seen) {' . strlen($data) . '}', $data);
    $run('LOGOUT');
    return $ok;
  } finally { fclose($s); }
}
