<?php
if (!class_exists('UserError')) { class UserError extends Exception {} }
// Sends email with attachments: through the company mailbox (SMTP, set in Holidays & lists) or, if that is not set, the hosting's mail().
function mail_cfg() {
  return array_replace(['host' => '', 'port' => 465, 'secure' => 'ssl', 'user' => '', 'pass' => '', 'from_name' => 'Zest Exhibitions'], (array) setting('smtp', []));
}
function mail_ready() { $c = mail_cfg(); return $c['host'] && $c['user'] && $c['pass']; }

// $m = ['to' => [emails], 'cc' => [emails], 'subject' => '', 'text' => '', 'reply_to' => email|null, 'reply_name' => '', 'from_name' => '', 'files' => [[name, bytes, mime]]]
function mail_build($m, $from, $fromName) {
  $enc = fn($s) => preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
  $addr = function ($e, $n = '') use ($enc) { if (!$n) return $e; $n = str_replace(['"', '\\', "\r", "\n"], '', $n); $x = $enc($n); return ($x === $n ? '"' . $n . '"' : $x) . " <$e>"; };
  $b = 'zb' . bin2hex(random_bytes(12));
  $h = ['Date: ' . date('r'), 'From: ' . $addr($from, $fromName), 'To: ' . implode(', ', $m['to']), 'Subject: ' . $enc($m['subject']),
    'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . (explode('@', $from)[1] ?? 'localhost') . '>', 'MIME-Version: 1.0', "Content-Type: multipart/mixed; boundary=\"$b\""];
  if (!empty($m['cc'])) $h[] = 'Cc: ' . implode(', ', $m['cc']);
  if (!empty($m['reply_to'])) $h[] = 'Reply-To: ' . $addr($m['reply_to'], $m['reply_name'] ?? '');
  $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode(str_replace("\n", "\r\n", str_replace("\r", '', $m['text']))));
  foreach ($m['files'] ?? [] as [$name, $bytes, $mime]) {
    $fn = str_replace(['"', "\r", "\n"], '', $name);
    $body .= "--$b\r\nContent-Type: $mime; name=\"$fn\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$fn\"\r\n\r\n" . chunk_split(base64_encode($bytes));
  }
  $body .= "--$b--\r\n";
  return [$h, $body];
}

function mail_send($m) {
  $c = mail_cfg();
  $m['to'] = array_values(array_filter((array) $m['to'], fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
  $m['cc'] = array_values(array_diff(array_filter((array) ($m['cc'] ?? []), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)), $m['to']));
  if (!$m['to']) throw new UserError('Enter a valid email address.');
  $fromName = $m['from_name'] ?? $c['from_name'];
  if (!mail_ready()) {   // no mailbox set: hosting mail (may land in spam — set up the company mailbox for best delivery)
    $host = preg_replace(['/^www\./', '/:\d+$/'], '', $_SERVER['HTTP_HOST'] ?? 'zestexhibitions.com');
    [$h, $body] = mail_build($m, "noreply@$host", $fromName);
    $to = implode(', ', $m['to']); $subj = ''; $hh = [];
    foreach ($h as $line) { if (str_starts_with($line, 'To: ')) continue; if (str_starts_with($line, 'Subject: ')) { $subj = substr($line, 9); continue; } $hh[] = $line; }
    if (!function_exists('mail') || !@mail($to, $subj, $body, implode("\r\n", $hh))) throw new UserError('The server could not send the email. Set up the company mailbox in Holidays & lists → Email sending.');
    return 'hosting';
  }
  [$h, $body] = mail_build($m, $c['user'], $fromName);
  smtp_talk($c, $c['user'], array_merge($m['to'], $m['cc']), implode("\r\n", $h) . "\r\n\r\n" . $body);
  return 'smtp';
}

function smtp_talk($c, $from, $rcpts, $data) {
  $secure = $c['secure'] === 'ssl' ? 'ssl://' : 'tcp://';
  $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
  $s = @stream_socket_client($secure . $c['host'] . ':' . (int) $c['port'], $en, $es, 25, STREAM_CLIENT_CONNECT, $ctx);
  if (!$s) throw new UserError("Could not reach the mail server {$c['host']}:{$c['port']} ($es). Check the server name and port.");
  stream_set_timeout($s, 40);
  $read = function () use ($s) { $all = ''; while (($l = fgets($s, 2048)) !== false) { $all .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $all; };
  $cmd = function ($line, $ok, $hide = false) use ($s, $read) {
    if ($line !== null) fwrite($s, $line . "\r\n");
    $r = $read(); $code = (int) substr($r, 0, 3);
    if (!in_array($code, (array) $ok, true)) {
      $msg = trim(preg_replace('/\s+/', ' ', $r)) ?: 'no reply';
      if ($code === 535 || $code === 534) throw new UserError('The mailbox did not accept the login (' . mb_substr($msg, 0, 160) . '). Check the email and password — some providers need an "app password".');
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
    if (stripos($cap, 'AUTH') !== false || $c['secure'] !== 'none') {
      $cmd('AUTH LOGIN', 334); $cmd(base64_encode($c['user']), 334); $cmd(base64_encode($c['pass']), 235);
    }
    $cmd("MAIL FROM:<$from>", 250);
    foreach (array_unique($rcpts) as $r) $cmd("RCPT TO:<$r>", [250, 251]);
    $cmd('DATA', 354);
    $data = preg_replace('/^\./m', '..', str_replace("\r\n", "\n", $data)); $data = str_replace("\n", "\r\n", $data);
    $cmd($data . "\r\n.", 250);
    @fwrite($s, "QUIT\r\n");
  } finally { fclose($s); }
}
