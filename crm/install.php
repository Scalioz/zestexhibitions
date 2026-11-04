<?php
// One-time installer. After success it refuses to run again.
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/schema.php';

$cfgFile = __DIR__ . '/config.php';
$done = file_exists($cfgFile);
$err = null; $ok = false;

if (!$done && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $c = ['driver' => 'mysql', 'host' => trim($_POST['host'] ?: 'localhost'), 'name' => trim($_POST['dbname']), 'user' => trim($_POST['dbuser']), 'pass' => $_POST['dbpass']];
  $aname = trim($_POST['aname']); $aemail = strtolower(trim($_POST['aemail'])); $apass = $_POST['apass'];
  if (!$c['name'] || !$c['user']) $err = 'Enter the database name and database user.';
  elseif (!$aname || !filter_var($aemail, FILTER_VALIDATE_EMAIL)) $err = 'Enter the admin name and a valid email.';
  elseif (strlen($apass) < 8) $err = 'Admin password must be at least 8 characters.';
  else {
    try {
      $test = new PDO("mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4", $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
      $php = "<?php\n// Zest CRM settings — created by install.php. Keep private.\nreturn " . var_export($c, true) . ";\n";
      if (@file_put_contents($cfgFile, $php) === false) throw new Exception('Could not save config.php. Check folder permissions.');
      create_schema('mysql');
      seed_defaults();
      if (!val('SELECT COUNT(*) FROM ' . T('users') . " WHERE role = 'admin'")) {
        ins('users', ['name' => $aname, 'email' => $aemail, 'pass_hash' => password_hash($apass, PASSWORD_DEFAULT), 'role' => 'admin',
          'designation' => 'Business Head', 'department' => 'Operations', 'active' => 1, 'joined_on' => today(), 'created_at' => now()]);
      }
      $ok = true;
    } catch (Throwable $e) {
      if (file_exists($cfgFile) && !$ok) @unlink($cfgFile);
      $err = 'Setup failed: ' . $e->getMessage();
    }
  }
}
$h = fn($s) => htmlspecialchars($s ?? '', ENT_QUOTES);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Install · Zest CRM</title><link rel="icon" href="assets/mark.svg">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@600;700;800&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
<link rel="stylesheet" href="assets/auth.css"></head>
<body><div class="auth">
<?php include __DIR__ . '/lib/hero.php'; ?>
<main class="side"><div class="card">
  <img src="assets/logo.png" alt="Zest Exhibitions" class="logo">
  <?php if ($ok): ?>
    <h1>Zest CRM is installed</h1>
    <p class="lead">Your database and admin login are ready.</p>
    <a class="btn" href="login.php">Go to login</a>
  <?php elseif ($done): ?>
    <h1>Already installed</h1>
    <p class="lead">For safety, setup cannot run again.</p>
    <a class="btn" href="login.php">Go to login</a>
  <?php else: ?>
    <h1>Set up Zest CRM</h1>
    <p class="lead">Enter the database you created in Hostinger, then create the admin login.</p>
    <?php if ($err): ?><div class="err"><?= $h($err) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <fieldset><legend>Database (from Hostinger → Databases)</legend>
        <label>Database host<input name="host" value="<?= $h($_POST['host'] ?? 'localhost') ?>"></label>
        <label>Database name<input name="dbname" required value="<?= $h($_POST['dbname'] ?? '') ?>" placeholder="u123456789_zestcrm"></label>
        <label>Database user<input name="dbuser" required value="<?= $h($_POST['dbuser'] ?? '') ?>" placeholder="u123456789_zestadmin"></label>
        <label>Database password<input name="dbpass" type="password"></label>
      </fieldset>
      <fieldset><legend>Admin login</legend>
        <label>Your name<input name="aname" required value="<?= $h($_POST['aname'] ?? '') ?>"></label>
        <label>Email (login ID)<input name="aemail" type="email" required value="<?= $h($_POST['aemail'] ?? '') ?>"></label>
        <label>Password (min 8 characters)<input name="apass" type="password" required minlength="8"></label>
      </fieldset>
      <button class="btn" type="submit">Install</button>
    </form>
  <?php endif; ?>
</div></main></div></body></html>
