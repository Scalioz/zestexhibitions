<?php
require_once __DIR__ . '/lib/auth.php';
if (!cfg()) { header('Location: install.php'); exit; }
$me = current_user();
if (!$me) { header('Location: login.php'); exit; }
$h = fn($s) => htmlspecialchars($s ?? '', ENT_QUOTES);
$initials = strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($me['name'])), 0, 2))));
$v = filemtime(__DIR__ . '/assets/app.js') . filemtime(__DIR__ . '/assets/app.css');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="csrf" content="<?= $h($_SESSION['csrf']) ?>">
<title>Zest CRM</title><link rel="icon" href="assets/mark.svg">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@600;700;800&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@500&display=swap">
<link rel="stylesheet" href="assets/app.css?v=<?= $v ?>"></head>
<body>
<header class="top">
  <a href="#home"><img src="assets/logo-white.png" alt="Zest Exhibitions" class="logo"></a>
  <span class="app">CRM</span>
  <div class="me">
    <div class="who"><?= $h($me['name']) ?><small><?= $h($me['role'] === 'admin' ? 'Admin' : ($me['designation'] ?: $me['department'])) ?></small></div>
    <div class="avatar" aria-hidden="true"><?= $h($initials) ?></div>
    <a class="lk" href="#account">Account</a>
    <a class="lk" href="logout.php">Sign out</a>
  </div>
</header>
<div class="shell">
  <nav id="nav" aria-label="Main"></nav>
  <main><div class="wrap" id="view"><div class="loading">Loading…</div></div></main>
</div>
<div class="toast" id="toast" hidden></div>
<script src="assets/app.js?v=<?= $v ?>"></script>
</body></html>
