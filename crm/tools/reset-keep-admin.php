<?php
// Wipes all CRM data but keeps admin logins and settings (departments, report formats, rejection reasons).
// Run on the server only:  php tools/reset-keep-admin.php --yes
if (php_sapi_name() !== 'cli') exit("CLI only\n");
if (($argv[1] ?? '') !== '--yes') exit("This deletes ALL clients, orders, payments, vendors, employees, reports and files.\nRun again with --yes to confirm.\n");
$root = dirname(__DIR__);
require "$root/lib/db.php"; require "$root/lib/schema.php";
migrate();
$tables = ['post_skip', 'jlines', 'journal', 'invoices', 'claims', 'claim_notes', 'salaries', 'approvals', 'leads', 'expos', 'activity', 'report_items', 'reports', 'files', 'audit', 'losses', 'expenses', 'vendor_payments', 'client_payments', 'extras',
  'vendor_quotes', 'quotes', 'revisions', 'projects', 'contacts', 'clients', 'vendors', 'holidays'];
db()->beginTransaction();
foreach ($tables as $t) q('DELETE FROM ' . T($t));
q('UPDATE ' . T('accounts') . ' SET opening = 0'); q('DELETE FROM ' . T('accounts') . ' WHERE party_type IS NOT NULL');
$gone = q('DELETE FROM ' . T('users') . " WHERE role <> 'admin'")->rowCount();
set_setting('track_from', today());
db()->commit();
// remove uploaded files
$n = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/uploads", FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) {
  if ($f->isDir()) { @rmdir($f->getPathname()); continue; }
  if (in_array($f->getFilename(), ['.htaccess', 'index.html'], true) && $f->getPath() === "$root/uploads") continue;
  @unlink($f->getPathname()); $n++;
}
$admins = array_column(all('SELECT email FROM ' . T('users')), 'email');
echo "Done. Removed $gone employee login(s), all records and $n file(s).\nKept admin login(s): " . implode(', ', $admins) . "\n";
