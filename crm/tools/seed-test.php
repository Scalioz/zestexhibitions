<?php
// Test-server only: creates a fresh SQLite database with logins and sample data.
if (php_sapi_name() !== 'cli') exit;
$root = dirname(__DIR__);
require "$root/lib/db.php"; require "$root/lib/schema.php";
create_schema('sqlite'); seed_defaults();
if (val('SELECT COUNT(*) FROM ' . T('users'))) { echo "Already seeded\n"; exit; }
set_setting('track_from', today());
$u = fn($n, $e, $p, $r, $d, $dep) => ins('users', ['name' => $n, 'email' => $e, 'pass_hash' => password_hash($p, PASSWORD_DEFAULT), 'role' => $r, 'designation' => $d, 'department' => $dep, 'active' => 1, 'joined_on' => today(), 'created_at' => now()]);
$u('Osman', 'admin@zest.test', 'ZestAdmin@2026', 'admin', 'Business Head', 'Operations');
$ravi = $u('Ravi (test)', 'sales@zest.test', 'ZestSales@2026', 'employee', 'Sales Executive', 'Sales');
// Sample: Alucraft's 3 real orders
$cid = ins('clients', ['company' => 'Alucraft Manufacturing And Marketing LLP', 'city' => 'Bengaluru', 'owner_id' => $ravi, 'created_by' => 1, 'created_at' => now()]);
$orders = [
  ['ZAK Fenestration Expo', 'Bengaluru', '2026-04-28', 80, 700000, 600000, [['2026-04-06', 210000, 'Bank'], ['2026-04-20', 200000, 'Bank'], ['2026-04-30', 100000, 'Cash'], ['2026-05-07', 100000, 'Bank'], ['2026-05-16', 75000, 'Bank']], []],
  ['Inframe Expo', 'Chennai', '2026-09-04', 18, 210000, null, [['2026-08-29', 173460, 'Bank'], ['2026-09-05', 74340, 'Bank']], []],
  ['Acetech', 'Bengaluru', '2026-10-09', 24, 280000, null, [['2026-10-07', 200000, 'Bank']], [['Extra Requirement', 6000]]],
];
foreach ($orders as [$expo, $loc, $sd, $area, $agreed, $gstOn, $pays, $extras]) {
  $pid = ins('projects', ['client_id' => $cid, 'owner_id' => $ravi, 'expo' => $expo, 'location' => $loc, 'start_date' => $sd, 'area_sqm' => $area,
    'status' => 'won', 'offered' => $agreed, 'agreed' => $agreed, 'gst_rate' => 18, 'gst_on' => $gstOn, 'created_at' => now()]);
  foreach ($pays as [$d, $a, $m]) ins('client_payments', ['project_id' => $pid, 'pdate' => $d, 'amount' => $a, 'mode' => $m]);
  foreach ($extras as [$i, $r]) ins('extras', ['project_id' => $pid, 'item' => $i, 'rate' => $r, 'qty' => 1, 'added_on' => $sd]);
}
echo "Seeded\n";
