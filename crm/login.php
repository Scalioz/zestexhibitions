<?php
require_once __DIR__ . '/lib/auth.php';
if (!cfg()) { header('Location: install.php'); exit; }
start_session();
if (current_user()) { header('Location: index.php'); exit; }
$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) $err = 'Page expired. Please try again.';
  else {
    $err = try_login($_POST['email'] ?? '', $_POST['password'] ?? '');
    if (!$err) { header('Location: index.php'); exit; }
  }
}
$h = fn($s) => htmlspecialchars($s ?? '', ENT_QUOTES);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in · Zest CRM</title><link rel="icon" href="assets/mark.svg">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@600;700;800&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
<link rel="stylesheet" href="assets/auth.css"></head>
<body><div class="auth">
<?php include __DIR__ . '/lib/hero.php'; ?>
<main class="side"><div class="card">
  <img src="assets/logo.png" alt="Zest Exhibitions" class="logo">
  <h1>Sign in to Zest CRM</h1>
  <p class="lead">Use the email and password given by your admin.</p>
  <?php if ($err): ?><div class="err"><?= $h($err) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
    <label>Email<input name="email" type="email" required autocomplete="username" value="<?= $h($_POST['email'] ?? '') ?>" autofocus></label>
    <label>Password<input name="password" type="password" required autocomplete="current-password"></label>
    <button class="btn" type="submit">Sign in</button>
  </form>
  <p class="hint">Forgot your password? Ask your admin to reset it.</p>
  <p class="foot">Zest Exhibitions · G-79/1, Shaheen Bagh, Okhla, New Delhi 110025</p>
</div></main></div></body></html>
