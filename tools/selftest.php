<?php
// Self-test: logs in as admin, creates test people and runs every main flow through the real web app.
// Use on a TEST copy only (it adds data):  php tools/selftest.php http://localhost/ admin@email password
if (php_sapi_name() !== 'cli') exit("CLI only\n");
[$_, $BASE, $AEMAIL, $APASS] = $argv + [null, 'http://localhost/', 'admin@zest.test', 'ZestAdmin@2026'];
$BASE = rtrim($BASE, '/') . '/';
$pass = 0; $failN = 0; $fails = [];
function ok($name, $cond, $info = '') { global $pass, $failN, $fails; if ($cond) { $pass++; echo "  ✓ $name\n"; } else { $failN++; $fails[] = "$name — $info"; echo "  ✗ $name — $info\n"; } return $cond; }

class Who {
  public $jar, $csrf, $name;
  function __construct($email, $pw, $name) {
    global $BASE; $this->name = $name; $this->jar = tempnam(sys_get_temp_dir(), 'zst');
    $h = $this->get('login.php'); preg_match('/name="csrf" value="([^"]+)"/', $h['body'], $m);
    $this->req('login.php', http_build_query(['csrf' => $m[1] ?? '', 'email' => $email, 'password' => $pw]), ['Content-Type: application/x-www-form-urlencoded']);
    $i = $this->get('index.php'); preg_match('/name="csrf" content="([^"]+)"/', $i['body'], $m2); $this->csrf = $m2[1] ?? '';
  }
  function req($path, $body = null, $hdr = []) {
    global $BASE; $c = curl_init($BASE . $path);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => $hdr, CURLOPT_HEADER => true]);
    if ($body !== null) { curl_setopt($c, CURLOPT_POST, true); curl_setopt($c, CURLOPT_POSTFIELDS, $body); }
    $r = curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); $hs = curl_getinfo($c, CURLINFO_HEADER_SIZE); curl_close($c);
    return ['code' => $code, 'head' => substr((string) $r, 0, $hs), 'body' => substr((string) $r, $hs)];
  }
  function get($path) { return $this->req($path); }
  function api($a, $d = []) { $r = $this->req('api.php?a=' . $a, json_encode($d), ['Content-Type: application/json', 'X-CSRF: ' . $this->csrf]); $j = json_decode($r['body'], true); return is_array($j) ? $j : ['error' => 'HTTP ' . $r['code'] . ': ' . substr(strip_tags($r['body']), 0, 160)]; }
  function upload($entity, $id, $file, $name) { $r = $this->req('api.php?a=upload', ['entity' => $entity, 'entity_id' => $id, 'file' => new CURLFile($file, 'image/png', $name)], ['X-CSRF: ' . $this->csrf]); return json_decode($r['body'], true) ?: ['error' => substr($r['body'], 0, 160)]; }
}
$e = fn($r) => $r['error'] ?? '';
$T = date('His'); $today = date('Y-m-d');
echo "Zest CRM self-test on $BASE\n";

echo "Logins & team\n";
$A = new Who($AEMAIL, $APASS, 'admin'); $boot = $A->api('boot');
if (!ok('admin can sign in', isset($boot['me']['id']), $e($boot))) { echo "\nStopped: admin login failed.\n"; exit(1); }
$mods = $boot['dept_modules'] ?? []; $dep = function ($mod) use ($mods) { foreach ($mods as $d => $m) if ($m === $mod) return $d; return null; };
$people = [];
foreach (['records' => 'Sales', 'accounts' => 'Accounts', 'ops' => 'Production', 'design' => 'Design', 'leads' => 'Research'] as $mod => $label) {
  $d = $dep($mod); if (!$d) { ok("a department for $label exists", false, 'no department set to ' . $mod); continue; }
  $em = "selftest.$mod.$T@zest.test"; $r = $A->api('user_save', ['name' => "Test $label $T", 'email' => $em, 'department' => $d, 'password' => 'Selftest#2026']);
  if (ok("create $label login", !empty($r['id']), $e($r))) $people[$mod] = ['id' => $r['id'], 'who' => new Who($em, 'Selftest#2026', $label)];
}
$S = $people['records']['who'] ?? null; $X = $people['accounts']['who'] ?? null; $O = $people['ops']['who'] ?? null; $R = $people['leads']['who'] ?? null;
if (!$S || !$X) { echo "\nStopped: sales or accounts login missing.\n"; exit(1); }
foreach ($people as $m => $p) ok("$m login works", isset($p['who']->api('boot')['me']['id']));

echo "Clients & orders\n";
$c = $S->api('client_save', ['company' => "Selftest Industries $T", 'person' => 'Amit', 'phone' => '98' . substr($T . '12345678', 0, 8), 'email' => "amit$T@example.com", 'city' => 'Pune',
  'expo' => ['expo' => "Selftest Expo $T", 'location' => 'Pune', 'start_date' => date('Y-m-d', strtotime('+20 days')), 'area_sqm' => '25', 'space_type' => 'Bare space', 'sides_open' => '3', 'stall_no' => 'D4']]);
ok('sales adds a client with an expo enquiry', !empty($c['id']), $e($c));
$cl = $S->api('client', ['id' => $c['id'] ?? 0]); $pid = $cl['client']['projects'][0]['id'] ?? 0;
ok('order was created', $pid > 0, $e($cl));
ok('save order fields', empty($S->api('project_save', ['id' => $pid, 'fields' => ['agreed' => '215000', 'offered' => '230000', 'end_date' => date('Y-m-d', strtotime('+22 days')), 'status' => 'quoted']])['error']));
$v = $A->api('vendor_add', ['name' => "Selftest Vendor $T", 'phone' => '9000000001', 'city' => 'Pune']); ok('add vendor', !empty($v['id']), $e($v));
foreach ([['payment', ['pdate' => $today, 'amount' => '50000', 'mode' => 'Bank']], ['expense', ['edate' => $today, 'category' => 'Flight', 'amount' => '6500']], ['vpayment', ['vendor_id' => $v['id'] ?? 0, 'pdate' => $today, 'amount' => '40000', 'mode' => 'Bank']], ['extra', ['item' => 'Extra podium', 'rate' => '4000', 'qty' => '1', 'added_on' => $today]]] as [$k, $d]) {
  $r = $S->api('child_add', ['project_id' => $pid, 'kind' => $k, 'data' => $d]); ok("add $k on the order", !empty($r['id']) || !empty($r['ok']), $e($r));
}
$up = tempnam(sys_get_temp_dir(), 'png') . '.png'; $im = imagecreatetruecolor(1400, 1000); for ($y = 0; $y < 1000; $y += 2) for ($x = 0; $x < 1400; $x += 2) imagefilledrectangle($im, $x, $y, $x + 1, $y + 1, imagecolorallocate($im, ($x + rand(0, 60)) % 256, ($y + rand(0, 60)) % 256, rand(0, 255))); imagepng($im, $up);   // photo-like, ~3 MB
$u = $S->upload('pbrief', $pid, $up, 'brief.png'); ok('upload a file (compressed automatically)', !empty($u['id']) && ($u['size'] ?? 0) < ($u['orig_size'] ?? 0), $e($u) ?: json_encode($u));

echo "Design & quote\n";
$ad = $S->api('appr_request', ['project_id' => $pid, 'kind' => 'design', 'note' => 'V1']); ok('sales sends design for approval', !empty($ad['id']), $e($ad));
ok('admin approves design', empty($A->api('appr_decide', ['id' => $ad['id'] ?? 0, 'decision' => 'approved'])['error']));
ok('sales marks design sent', empty($S->api('appr_sent', ['id' => $ad['id'] ?? 0, 'sent_on' => $today])['error']));
ok('sales marks customer approved', empty(($r = $S->api('design_client_ok', ['project_id' => $pid, 'approval_id' => $ad['id'] ?? 0, 'on' => $today]))['error']), $e($r));
$qp = $S->api('quote_prefill', ['project_id' => $pid]); ok('quote fills from the order', ($qp['doc']['details'][0]['v'] ?? '') === 'D4' && count($qp['doc']['items'] ?? []) > 5, $e($qp));
$doc = $qp['doc'] ?? []; $doc['discount'] = 15000;
$qs = $S->api('quote_save', ['project_id' => $pid, 'doc' => $doc, 'submit' => 1, 'note' => 'selftest']); ok('quote sent to admin', ($qs['status'] ?? '') === 'pending', $e($qs));
ok('admin approves quote', empty($A->api('appr_decide', ['id' => $qs['id'] ?? 0, 'decision' => 'approved'])['error']));
$qv = $S->get('quote.php?id=' . ($qs['id'] ?? 0)); ok('sales opens approved quote', $qv['code'] === 200 && str_contains($qv['body'], 'QT/') && str_contains($qv['body'], 'Download PDF'), 'HTTP ' . $qv['code']);
ok('quote marked sent to customer', empty($S->api('appr_sent', ['id' => $qs['id'] ?? 0, 'sent_on' => $today])['error']));

echo "Invoices\n";
$ir = $S->api('inv_request', ['project_id' => $pid, 'kind' => 'proforma', 'note' => '70% advance']); ok('sales requests proforma', !empty($ir['id']), $e($ir));
$ig = $X->api('inv_get', ['id' => $ir['id'] ?? 0])['invoice'] ?? [];
$is = $X->api('inv_save', ['id' => $ig['id'] ?? 0, 'kind' => 'proforma', 'client_id' => $ig['client_id'] ?? 0, 'project_id' => $pid, 'inv_date' => $today, 'place_state' => 'Maharashtra', 'gst_rate' => 18, 'lines' => $ig['lines'] ?? [], 'submit' => 1]);
ok('accounts prepares and sends to admin', !empty($is['id']), $e($is));
$id = $A->api('inv_decide', ['id' => $ir['id'] ?? 0, 'action' => 'approve']); ok('admin approves proforma', str_starts_with($id['inv_no'] ?? '', 'PI/'), $e($id));
$tx = $S->api('inv_request', ['from_id' => $ir['id'] ?? 0, 'note' => 'tax invoice']); $tg = $X->api('inv_get', ['id' => $tx['id'] ?? 0])['invoice'] ?? [];
$X->api('inv_save', ['id' => $tg['id'] ?? 0, 'kind' => 'tax', 'client_id' => $tg['client_id'] ?? 0, 'project_id' => $pid, 'inv_date' => $today, 'place_state' => 'Maharashtra', 'gst_rate' => 18, 'lines' => $tg['lines'] ?? [], 'submit' => 1]);
$td = $A->api('inv_decide', ['id' => $tx['id'] ?? 0, 'action' => 'approve']); ok('tax invoice approved and booked', str_starts_with($td['inv_no'] ?? '', 'INV/'), $e($td));
$iv = $S->get('invoice.php?id=' . ($tx['id'] ?? 0)); ok('sales opens tax invoice', $iv['code'] === 200 && str_contains($iv['body'], 'TAX INVOICE'), 'HTTP ' . $iv['code']);

echo "Expenses & books\n";
$cs = $S->api('claim_save', ['edate' => $today, 'particular' => 'Cab to venue', 'category' => 'Local conveyance', 'rate' => 450, 'qty' => 2, 'gst' => 0, 'project_id' => $pid]); ok('sales submits expense claim', !empty($cs['id']), $e($cs));
$meta = $X->api('acc_meta'); $expA = 0; $bank = 0; foreach ($meta['accounts'] ?? [] as $a) { if ($a['type'] === 'expense' && !$expA) $expA = $a['id']; if ($a['sub'] === 'bank') $bank = $a['id']; }
ok('accounts approves claim', empty(($r = $X->api('claim_decide', ['id' => $cs['id'] ?? 0, 'action' => 'approve', 'account_id' => $expA, 'pay_from' => 'staff']))['error']), $e($r));
$en = $X->api('acc_entry', ['kind' => 'expense', 'date' => $today, 'account_id' => $expA, 'amount' => 12000, 'gst' => 2160, 'paid_from' => $bank, 'narration' => 'Office rent (selftest)']); ok('accounts posts an expense entry', !empty($en['id']), $e($en));
$inbox = $X->api('acc_inbox'); $first = array_values(array_filter($inbox['cpay'] ?? [], fn($x) => (int) $x['project_id'] === (int) $pid))[0] ?? null;
ok('payment recorded on the order waits in "Post from orders"', (bool) $first, $e($inbox));
if ($first) ok('post it to the books', empty(($r = $X->api('acc_post_crm', ['ref_type' => 'cpay', 'ref_id' => $first['id'], 'money_id' => $bank]))['error']), $e($r));
$ed = $A->api('acc_edit', ['id' => $en['id'] ?? 0, 'date' => $today, 'narration' => 'Office rent (corrected)', 'lines' => [['account_id' => $expA, 'dr' => 13000, 'cr' => 0], ['account_id' => $bank, 'dr' => 0, 'cr' => 13000]]]); ok('admin corrects an entry', !empty($ed['id']), $e($ed));
foreach (['trial', 'pl', 'bs', 'gst', 'daybook', 'expenses', 'parties', 'claims', 'salary'] as $rep) {
  $r = $X->api('acc_report', ['r' => $rep]); $okR = isset($r['report']['rows']);
  if ($rep === 'trial' && $okR) { $t = array_values(array_filter($r['report']['rows'], fn($x) => $x['k'] === 't'))[0]['c'] ?? []; $okR = isset($t[2]) && abs($t[2] - $t[3]) < 0.01; }
  ok("report: $rep" . ($rep === 'trial' ? ' (balanced)' : ''), $okR, $e($r));
}
ok('accounts dashboard', isset($X->api('acc_home')['money']));

echo "Production, research, referrals, contacts\n";
if ($O) { ok('admin assigns production', empty($A->api('assign_ops', ['project_id' => $pid, 'user_id' => $people['ops']['id']])['error']));
  ok('production saves job details', empty(($r = $O->api('ops_save', ['id' => $pid, 'ops' => ['length' => ['v' => 5, 'u' => 'm'], 'width' => ['v' => 5, 'u' => 'm'], 'furniture' => [['item' => 'Bar stool', 'qty' => 2]], 'vinyl_total' => 4]]))['error']), $e($r)); }
if ($R) { $ex = $R->api('lexpo_save', ['name' => "Selftest Expo Data $T", 'edate' => $today, 'city' => 'Delhi']); ok('research adds an expo', !empty($ex['id']), $e($ex));
  ok('research adds a lead', !empty(($r = $R->api('lead_add', ['expo_id' => $ex['id'] ?? 0, 'company' => "Lead Co $T", 'person' => 'Neha', 'phone' => '9811100000']))['id']), $e($r)); }
$rf = $A->api('ref_save', ['name' => 'Priya', 'phone' => '9822001122', 'company' => "Referral Co $T", 'area' => '36 sqm', 'assigned_to' => $people['records']['id']]); ok('admin refers a lead to sales', !empty($rf['id']), $e($rf));
ok('sales sees the referral', count($S->api('ref_list')['rows'] ?? []) > 0);
$cd = $S->api('card_save', ['name' => 'Rohit', 'company' => "Card Co $T", 'phone' => '9876501234', 'tag' => 'Selftest']); ok('add a contact', !empty($cd['id']), $e($cd));
$dl = $A->api('dir_list', []); ok('contacts directory (all sources)', ($dl['total'] ?? 0) >= 4, $e($dl));

echo "Daily reports & dashboards\n";
$rm = $S->api('report_mine'); $k0 = $rm['template'][0]['k'] ?? 'company';
$rs = $S->api('report_submit', ['items' => [[$k0 => 'Called 5 clients']]]); ok('sales submits daily report', !empty($rs['id']), $e($rs));
ok('admin edits a daily report', empty(($r = $A->api('report_admin_save', ['id' => $rs['id'] ?? 0, 'items' => [[$k0 => 'Called 6 clients']]]))['error']), $e($r));
foreach (['home' => $A, 'report_log' => $A, 'sales_log' => $A, 'client_log' => $A, 'jobs_board' => $A, 'storage' => $A, 'settings' => $A, 'users' => $A] as $a => $who) ok("admin page: $a", empty(($r = $who->api($a, []))['error']), $e($r));
foreach ([$S, $X, $O, $R] as $w) if ($w) ok("{$w->name} dashboard", empty(($r = $w->api('home', []))['error']), $e($r));

echo "Excel exports\n";
foreach (['export.php', 'export_sales.php', 'export_leads.php', 'contacts_export.php', 'acc_export.php?r=trial'] as $x) { $r = $A->get($x); ok("download $x", $r['code'] === 200 && substr($r['body'], 0, 2) === 'PK', 'HTTP ' . $r['code'] . ' ' . substr(strip_tags($r['body']), 0, 120)); }

echo "\n" . ($failN ? "RESULT: $failN PROBLEM(S), $pass passed\n" . implode("\n", array_map(fn($x) => " - $x", $fails)) . "\n" : "RESULT: ALL GOOD — $pass checks passed\n");
exit($failN ? 1 : 0);
