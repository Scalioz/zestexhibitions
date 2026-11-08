<?php
// Creates all tables. Works on MySQL (live) and SQLite (local testing).
function create_schema($driver) {
  $PK = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
  $FK = $driver === 'sqlite' ? 'INTEGER' : 'INT UNSIGNED';
  $E  = $driver === 'sqlite' ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
  $M  = 'DECIMAL(14,2)';
  $t = fn($n) => T($n);
  $sql = [
"CREATE TABLE IF NOT EXISTS {$t('settings')} (k VARCHAR(64) PRIMARY KEY, v TEXT NOT NULL)$E",
"CREATE TABLE IF NOT EXISTS {$t('users')} (
  id $PK, name VARCHAR(120) NOT NULL, email VARCHAR(160) NOT NULL UNIQUE, pass_hash VARCHAR(255) NOT NULL,
  role VARCHAR(10) NOT NULL DEFAULT 'employee', designation VARCHAR(120), department VARCHAR(60) NOT NULL DEFAULT 'Sales',
  phone VARCHAR(30), active TINYINT NOT NULL DEFAULT 1, joined_on DATE NOT NULL, created_at DATETIME NOT NULL)$E",
"CREATE TABLE IF NOT EXISTS {$t('holidays')} (id $PK, hdate DATE NOT NULL UNIQUE, reason VARCHAR(160) NOT NULL)$E",
"CREATE TABLE IF NOT EXISTS {$t('reasons')} (id $PK, label VARCHAR(160) NOT NULL, active TINYINT NOT NULL DEFAULT 1)$E",
"CREATE TABLE IF NOT EXISTS {$t('vendors')} (id $PK, name VARCHAR(160) NOT NULL, phone VARCHAR(40), email VARCHAR(160), city VARCHAR(80), notes TEXT, active TINYINT NOT NULL DEFAULT 1)$E",
"CREATE TABLE IF NOT EXISTS {$t('clients')} (
  id $PK, company VARCHAR(200) NOT NULL, email VARCHAR(160), phone VARCHAR(40), person VARCHAR(120), designation VARCHAR(120),
  location TEXT, city VARCHAR(80), gst_no VARCHAR(30), notes TEXT, owner_id $FK NOT NULL,
  dnq TINYINT NOT NULL DEFAULT 0, dnq_reason VARCHAR(160), dnq_note TEXT, dnq_on DATE, dnq_by VARCHAR(120),
  created_by $FK, created_at DATETIME NOT NULL)$E",
"CREATE INDEX zc_clients_owner ON {$t('clients')} (owner_id)",
"CREATE INDEX zc_clients_company ON {$t('clients')} (company)",
"CREATE TABLE IF NOT EXISTS {$t('contacts')} (id $PK, client_id $FK NOT NULL, name VARCHAR(120) NOT NULL, designation VARCHAR(120), phone VARCHAR(40), email VARCHAR(160))$E",
"CREATE INDEX zc_contacts_client ON {$t('contacts')} (client_id)",
"CREATE TABLE IF NOT EXISTS {$t('projects')} (
  id $PK, client_id $FK NOT NULL, owner_id $FK NOT NULL,
  expo VARCHAR(200) NOT NULL, location VARCHAR(120), start_date DATE, end_date DATE, area_sqm DECIMAL(10,2), space_type VARCHAR(20), sides_open INT, stall_no VARCHAR(40),
  designed_by VARCHAR(120), assigned_on DATE, design_approved_by VARCHAR(120), designer_id INT, design_assigned_by VARCHAR(120), design_submitted_on DATE, design_notes TEXT,
  ops_id INT, ops_assigned_by VARCHAR(120), ops_assigned_on DATE, ops_json TEXT, ops_updated_at DATETIME,
  status VARCHAR(12) NOT NULL DEFAULT 'enquiry', offered $M, agreed $M, gst_rate DECIMAL(5,2) NOT NULL DEFAULT 18, gst_on $M,
  approved_by VARCHAR(120), invoice_no VARCHAR(40), invoice_date DATE, rejected_by VARCHAR(10), reason VARCHAR(160), rejection_note TEXT,
  created_at DATETIME NOT NULL)$E",
"CREATE INDEX zc_projects_client ON {$t('projects')} (client_id)",
"CREATE INDEX zc_projects_owner ON {$t('projects')} (owner_id)",
"CREATE TABLE IF NOT EXISTS {$t('revisions')} (id $PK, project_id $FK NOT NULL, rdate DATE NOT NULL, done_by VARCHAR(120), changes TEXT)$E",
"CREATE TABLE IF NOT EXISTS {$t('quotes')} (id $PK, project_id $FK NOT NULL, version INT NOT NULL, qdate DATE NOT NULL, amount $M NOT NULL, approved_by VARCHAR(120), notes TEXT)$E",
"CREATE TABLE IF NOT EXISTS {$t('vendor_quotes')} (id $PK, project_id $FK NOT NULL, vendor_id $FK NOT NULL, amount $M NOT NULL, note TEXT, selected TINYINT NOT NULL DEFAULT 0, qdate DATE NOT NULL)$E",
"CREATE TABLE IF NOT EXISTS {$t('extras')} (id $PK, project_id $FK NOT NULL, item VARCHAR(160) NOT NULL, rate $M NOT NULL, qty DECIMAL(10,2) NOT NULL DEFAULT 1, added_on DATE NOT NULL)$E",
"CREATE TABLE IF NOT EXISTS {$t('client_payments')} (id $PK, project_id $FK NOT NULL, pdate DATE NOT NULL, amount $M NOT NULL, mode VARCHAR(12) NOT NULL, reference VARCHAR(120))$E",
"CREATE TABLE IF NOT EXISTS {$t('vendor_payments')} (id $PK, project_id $FK NOT NULL, vendor_id $FK NOT NULL, pdate DATE NOT NULL, amount $M NOT NULL, mode VARCHAR(12) NOT NULL, reference VARCHAR(120))$E",
"CREATE TABLE IF NOT EXISTS {$t('expenses')} (id $PK, project_id $FK NOT NULL, edate DATE NOT NULL, category VARCHAR(20) NOT NULL, description VARCHAR(255), amount $M NOT NULL)$E",
"CREATE TABLE IF NOT EXISTS {$t('losses')} (id $PK, project_id $FK NOT NULL, ldate DATE NOT NULL, ltype VARCHAR(40) NOT NULL, note VARCHAR(255), amount $M NOT NULL)$E",
"CREATE TABLE IF NOT EXISTS {$t('reports')} (id $PK, user_id $FK NOT NULL, rdate DATE NOT NULL, dept VARCHAR(60) NOT NULL, fields_json TEXT NOT NULL, submitted_at DATETIME NOT NULL, UNIQUE (user_id, rdate))$E",
"CREATE TABLE IF NOT EXISTS {$t('report_items')} (id $PK, report_id $FK NOT NULL, sl INT NOT NULL, data_json TEXT NOT NULL)$E",
"CREATE INDEX zc_ritems_report ON {$t('report_items')} (report_id)",
"CREATE TABLE IF NOT EXISTS {$t('files')} (id $PK, entity VARCHAR(30) NOT NULL, entity_id $FK NOT NULL, orig_name VARCHAR(255) NOT NULL, stored_name VARCHAR(80) NOT NULL, mime VARCHAR(100), size INT, orig_size INT, shrunk INT NOT NULL DEFAULT 0, uploaded_by $FK, created_at DATETIME NOT NULL)$E",
"CREATE INDEX zc_files_entity ON {$t('files')} (entity, entity_id)",
  ];
  foreach ($sql as $s) {
    try { db()->exec($s); }
    catch (PDOException $e) { if (stripos($s, 'CREATE INDEX') !== 0) throw $e; } // index may already exist on re-run
  }
}

// Upgrades older databases in place. Each step runs once.
function migrate() {
  $v = (int) setting('schema_v', 1);
  if ($v < 3) {
    try { db()->exec('ALTER TABLE ' . T('projects') . ' ADD COLUMN space_type VARCHAR(20)'); } catch (PDOException $e) { /* already there */ }
    set_setting('schema_v', 3);
  }
  if ($v < 4) {
    foreach (['email VARCHAR(160)', 'city VARCHAR(80)'] as $col) {
      try { db()->exec('ALTER TABLE ' . T('vendors') . ' ADD COLUMN ' . $col); } catch (PDOException $e) { /* already there */ }
    }
    set_setting('schema_v', 4);
  }
  if ($v < 5) { create_audit_table(); set_setting('schema_v', 5); }
  if ($v < 6) {
    foreach (['sides_open INT', 'designer_id INT', 'design_assigned_by VARCHAR(120)', 'design_submitted_on DATE', 'design_notes TEXT',
              'ops_id INT', 'ops_assigned_by VARCHAR(120)', 'ops_assigned_on DATE', 'ops_json TEXT', 'ops_updated_at DATETIME'] as $col) {
      try { db()->exec('ALTER TABLE ' . T('projects') . ' ADD COLUMN ' . $col); } catch (PDOException $e) { /* already there */ }
    }
    create_work_tables();
    if (setting('dept_modules') === null) {
      $m = default_dept_modules();
      foreach ((array) setting('record_depts', []) as $dname) $m[$dname] = 'records';   // keep what admin had ticked
      set_setting('dept_modules', $m);
    }
    if (setting('furniture_items') === null) set_setting('furniture_items', default_furniture());
    set_setting('schema_v', 6);
  }
  if ($v < 7) { create_approvals_table(); set_setting('schema_v', 7); }
  if ($v < 8) {
    create_accounts_tables(); seed_accounts();
    $m = setting('dept_modules', default_dept_modules());
    if (($m['Accounts'] ?? 'none') === 'none' && in_array('Accounts', setting('departments', []), true)) { $m['Accounts'] = 'accounts'; set_setting('dept_modules', $m); }
    set_setting('schema_v', 8);
  }
  if ($v < 9) {   // invoice approval workflow
    foreach (['requested_by INT', 'request_note TEXT', 'version INT NOT NULL DEFAULT 0', 'submitted_at DATETIME', 'decided_by VARCHAR(120)', 'decided_at DATETIME', 'reason TEXT'] as $col) {
      try { db()->exec('ALTER TABLE ' . T('invoices') . ' ADD COLUMN ' . $col); } catch (PDOException $e) { /* already there */ }
    }
    create_accounts_tables();
    set_setting('schema_v', 9);
  }
  if ($v < 10) {   // admin edits of book entries
    try { db()->exec('ALTER TABLE ' . T('journal') . ' ADD COLUMN replaces INT'); } catch (PDOException $e) { /* already there */ }
    set_setting('schema_v', 10);
  }
  if ($v < 11) {   // numbers can never repeat, even when people save at the same moment
    foreach (['CREATE UNIQUE INDEX zc_j_vno ON ' . T('journal') . ' (vno)', 'CREATE UNIQUE INDEX zc_inv_no ON ' . T('invoices') . ' (inv_no)'] as $q) { try { db()->exec($q); } catch (PDOException $e) { /* already there */ } }
    set_setting('schema_v', 11);
  }
  if ($v < 12) {   // automatic file compression
    foreach (['orig_size INT', 'shrunk INT NOT NULL DEFAULT 0'] as $col) { try { db()->exec('ALTER TABLE ' . T('files') . ' ADD COLUMN ' . $col); } catch (PDOException $e) { /* already there */ } }
    set_setting('schema_v', 12);
  }
  if ($v < 13) {   // quote generator + "customer approved the design"
    foreach (['doc MEDIUMTEXT', 'quote_no VARCHAR(40)'] as $col) { try { db()->exec('ALTER TABLE ' . T('approvals') . ' ADD COLUMN ' . $col); } catch (PDOException $e) { /* already there */ } }
    foreach (['design_ok_on DATE', 'design_ok_by VARCHAR(120)', 'design_ok_ver INT'] as $col) { try { db()->exec('ALTER TABLE ' . T('projects') . ' ADD COLUMN ' . $col); } catch (PDOException $e) { /* already there */ } }
    try { db()->exec('CREATE UNIQUE INDEX zc_appr_qno ON ' . T('approvals') . ' (quote_no)'); } catch (PDOException $e) { /* already there */ }
    $c = setting('company', []);   // bank details from Zest's quotation format
    foreach (['bank_benef' => 'ZEST EXHIBITIONS', 'bank_name' => 'AXIS BANK', 'bank_branch' => 'ITT, Nehru Place, New Delhi - 110019', 'bank_acno' => '9230-2000-4724944', 'bank_ifsc' => 'UTIB0005104',
              'bank_swift' => 'AXISINBBXX', 'bank_micr' => '1102113848', 'email' => 'info@zestexhibitions.com', 'website' => 'www.zestexhibitions.com'] as $k => $val) if (empty($c[$k])) $c[$k] = $val;
    set_setting('company', $c);
    set_setting('schema_v', 13);
  }
  if ($v < 14) { create_referrals_table(); set_setting('schema_v', 14); }
  if ($v < 15) { create_cards_tables(); set_setting('schema_v', 15); }
  if ($v < 16) {   // discount and round off on invoices
    foreach (['discount DECIMAL(14,2) NOT NULL DEFAULT 0', 'round_off DECIMAL(10,2) NOT NULL DEFAULT 0', "round_mode VARCHAR(8) NOT NULL DEFAULT 'auto'"] as $col) { try { db()->exec('ALTER TABLE ' . T('invoices') . ' ADD COLUMN ' . $col); } catch (PDOException $e) { /* already there */ } }
    if (val('SELECT COUNT(*) FROM ' . T('accounts')) && !val('SELECT COUNT(*) FROM ' . T('accounts') . " WHERE sub = 'roundoff'")) ins('accounts', ['code' => '4910', 'name' => 'Round off', 'type' => 'income', 'sub' => 'roundoff', 'opening' => 0, 'active' => 1, 'created_at' => now()]);
    set_setting('schema_v', 16);
  }
  if ($v < 17) {   // standard note on every invoice / proforma; account name shown on bank details
    $c = (array) setting('company', []); $e = 'Electricity charges during fabrication and expo days at the venue are not included in the above quote. Client pays them directly to the event organiser.';
    if (stripos($c['terms'] ?? '', 'Electricity charges') === false) $c['terms'] = trim(($c['terms'] ?? '') . "\n" . $e);
    if (empty($c['bank_benef'])) $c['bank_benef'] = 'Zest Exhibitions';
    set_setting('company', $c); set_setting('schema_v', 17);
  }
  if ($v < 18) {   // an employee can work in more than one department
    try { db()->exec('ALTER TABLE ' . T('users') . ' ADD COLUMN extra_depts TEXT'); } catch (PDOException $e) { /* already there */ }
    set_setting('schema_v', 18);
  }
}
function create_cards_tables() {   // business cards read from photos (and contacts added by hand)
  $sqlite = (cfg()['driver'] ?? 'mysql') === 'sqlite';
  $PK = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
  $E = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
  foreach (['CREATE TABLE IF NOT EXISTS ' . T('cards') . " (id $PK, name VARCHAR(120), designation VARCHAR(120), company VARCHAR(200), phone VARCHAR(40), phone2 VARCHAR(120), phone_key VARCHAR(12),
      email VARCHAR(160), email2 VARCHAR(255), website VARCHAR(160), city VARCHAR(80), address TEXT, tag VARCHAR(160), note TEXT, source VARCHAR(10) NOT NULL DEFAULT 'card', scan_id INT,
      user_id INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME)$E",
    'CREATE INDEX zc_cards_user ON ' . T('cards') . ' (user_id)', 'CREATE INDEX zc_cards_phone ON ' . T('cards') . ' (phone_key)', 'CREATE INDEX zc_cards_email ON ' . T('cards') . ' (email)',
    'CREATE TABLE IF NOT EXISTS ' . T('card_scans') . " (id $PK, user_id INT NOT NULL, tag VARCHAR(160), status VARCHAR(10) NOT NULL, found INT NOT NULL DEFAULT 0, added INT NOT NULL DEFAULT 0, error TEXT, created_at DATETIME NOT NULL)$E",
  ] as $q) { try { db()->exec($q); } catch (PDOException $e) { if (stripos($q, 'CREATE INDEX') !== 0) throw $e; } }
}
function create_referrals_table() {   // leads admin gets from his contacts and hands to a sales person / another admin
  $sqlite = (cfg()['driver'] ?? 'mysql') === 'sqlite';
  $PK = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
  $E = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
  foreach (['CREATE TABLE IF NOT EXISTS ' . T('referrals') . " (id $PK, name VARCHAR(120) NOT NULL, phone VARCHAR(40) NOT NULL, company VARCHAR(200) NOT NULL, area VARCHAR(60), note TEXT,
      referred_by INT NOT NULL, assigned_to INT NOT NULL, assigned_at DATETIME NOT NULL, status VARCHAR(12) NOT NULL DEFAULT 'new', seen_at DATETIME, client_id INT, converted_at DATETIME,
      closed_reason TEXT, closed_at DATETIME, history TEXT, created_at DATETIME NOT NULL)$E",
    'CREATE INDEX zc_ref_to ON ' . T('referrals') . ' (assigned_to, status)'] as $q) { try { db()->exec($q); } catch (PDOException $e) { if (stripos($q, 'CREATE INDEX') !== 0) throw $e; } }
}

// ---------- ACCOUNTS (double entry) ----------
function create_accounts_tables() {
  $sqlite = (cfg()['driver'] ?? 'mysql') === 'sqlite';
  $PK = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
  $E = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
  $M = 'DECIMAL(14,2)';
  foreach ([
    // type: asset | liability | equity | income | expense.  sub marks special accounts (bank, cash, petty, debtor, creditor, employee, gst_in, gst_out, tds ...)
    'CREATE TABLE IF NOT EXISTS ' . T('accounts') . " (id $PK, code VARCHAR(20), name VARCHAR(160) NOT NULL, type VARCHAR(10) NOT NULL, sub VARCHAR(20), party_type VARCHAR(10), party_id INT, opening $M NOT NULL DEFAULT 0, active TINYINT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL)$E",
    'CREATE INDEX zc_acc_party ON ' . T('accounts') . ' (party_type, party_id)',
    'CREATE TABLE IF NOT EXISTS ' . T('journal') . " (id $PK, jdate DATE NOT NULL, vtype VARCHAR(20) NOT NULL, vno VARCHAR(30), narration TEXT, ref_type VARCHAR(20), ref_id INT, project_id INT, created_by INT, created_at DATETIME NOT NULL, reversed_by INT, replaces INT)$E",
    'CREATE INDEX zc_j_date ON ' . T('journal') . ' (jdate)',
    'CREATE INDEX zc_j_ref ON ' . T('journal') . ' (ref_type, ref_id)',
    'CREATE TABLE IF NOT EXISTS ' . T('jlines') . " (id $PK, journal_id INT NOT NULL, account_id INT NOT NULL, dr $M NOT NULL DEFAULT 0, cr $M NOT NULL DEFAULT 0, memo VARCHAR(255))$E",
    'CREATE INDEX zc_jl_j ON ' . T('jlines') . ' (journal_id)',
    'CREATE INDEX zc_jl_a ON ' . T('jlines') . ' (account_id)',
    'CREATE TABLE IF NOT EXISTS ' . T('invoices') . " (id $PK, kind VARCHAR(10) NOT NULL, inv_no VARCHAR(40), inv_date DATE NOT NULL, client_id INT NOT NULL, project_id INT, place_state VARCHAR(60), gst_mode VARCHAR(10) NOT NULL DEFAULT 'cgst_sgst', gst_rate DECIMAL(5,2) NOT NULL DEFAULT 18,
      lines_json TEXT NOT NULL, taxable $M NOT NULL DEFAULT 0, gst $M NOT NULL DEFAULT 0, total $M NOT NULL DEFAULT 0, status VARCHAR(10) NOT NULL DEFAULT 'draft', notes TEXT, from_id INT, journal_id INT, created_by INT, created_at DATETIME NOT NULL,
      requested_by INT, request_note TEXT, version INT NOT NULL DEFAULT 0, submitted_at DATETIME, decided_by VARCHAR(120), decided_at DATETIME, reason TEXT)$E",
    'CREATE TABLE IF NOT EXISTS ' . T('inv_log') . " (id $PK, invoice_id INT NOT NULL, user_id INT NOT NULL, at DATETIME NOT NULL, action VARCHAR(16) NOT NULL, note TEXT, version INT, snap TEXT)$E",
    'CREATE INDEX zc_invlog_i ON ' . T('inv_log') . ' (invoice_id)',
    'CREATE TABLE IF NOT EXISTS ' . T('claims') . " (id $PK, user_id INT NOT NULL, edate DATE NOT NULL, particular VARCHAR(255) NOT NULL, category VARCHAR(40), rate $M NOT NULL, qty DECIMAL(10,2) NOT NULL DEFAULT 1, total $M NOT NULL, gst $M NOT NULL DEFAULT 0, grand $M NOT NULL,
      project_id INT, note TEXT, status VARCHAR(10) NOT NULL DEFAULT 'submitted', submitted_at DATETIME NOT NULL, decided_by VARCHAR(120), decided_at DATETIME, reason TEXT, journal_id INT, created_at DATETIME NOT NULL)$E",
    'CREATE INDEX zc_claims_user ON ' . T('claims') . ' (user_id)',
    'CREATE TABLE IF NOT EXISTS ' . T('claim_notes') . " (id $PK, claim_id INT NOT NULL, user_id INT NOT NULL, at DATETIME NOT NULL, kind VARCHAR(12) NOT NULL, text TEXT)$E",
    'CREATE TABLE IF NOT EXISTS ' . T('post_skip') . " (ref_type VARCHAR(10) NOT NULL, ref_id INT NOT NULL, PRIMARY KEY (ref_type, ref_id))$E",
    'CREATE TABLE IF NOT EXISTS ' . T('salaries') . " (id $PK, user_id INT NOT NULL, month VARCHAR(7) NOT NULL, gross $M NOT NULL, deductions $M NOT NULL DEFAULT 0, deduction_note VARCHAR(255), net $M NOT NULL, paid_on DATE NOT NULL, journal_id INT, created_at DATETIME NOT NULL)$E",
  ] as $q) { try { db()->exec($q); } catch (PDOException $e) { if (stripos($q, 'CREATE INDEX') !== 0) throw $e; } }
}

function seed_accounts() {
  if (val('SELECT COUNT(*) FROM ' . T('accounts'))) return;
  $A = [
    ['1100', 'Bank - Current A/c', 'asset', 'bank'], ['1200', 'Cash in hand', 'asset', 'cash'], ['1210', 'Petty cash', 'asset', 'petty'],
    ['1300', 'Sundry debtors (other)', 'asset', null], ['1400', 'Input CGST', 'asset', 'gst_in_c'], ['1401', 'Input SGST', 'asset', 'gst_in_s'], ['1402', 'Input IGST', 'asset', 'gst_in_i'],
    ['1450', 'TDS receivable', 'asset', 'tds_in'], ['1500', 'Advances to staff', 'asset', null], ['1600', 'Security deposits', 'asset', null],
    ['2100', 'Sundry creditors (other)', 'liability', null], ['2200', 'Output CGST', 'liability', 'gst_out_c'], ['2201', 'Output SGST', 'liability', 'gst_out_s'], ['2202', 'Output IGST', 'liability', 'gst_out_i'],
    ['2300', 'TDS payable', 'liability', 'tds_out'], ['2400', 'Salary deductions payable (PF/ESI)', 'liability', 'sal_ded'], ['2500', 'Expense claims payable (staff)', 'liability', 'claims_pay'],
    ['3100', 'Capital account', 'equity', null],
    ['4100', 'Stall fabrication income', 'income', 'sales'], ['4910', 'Round off', 'income', 'roundoff'], ['4200', 'Other income', 'income', null],
    ['5100', 'Fabrication cost (vendors)', 'expense', 'fab'], ['5200', 'Salaries & wages', 'expense', 'salary'], ['5300', 'Office rent', 'expense', null], ['5310', 'Electricity', 'expense', null],
    ['5320', 'Internet & phone', 'expense', null], ['5400', 'Travel - flight / train', 'expense', 'exp_travel'], ['5410', 'Hotel & stay', 'expense', 'exp_hotel'], ['5420', 'Local conveyance', 'expense', 'exp_local'],
    ['5430', 'Food & meals', 'expense', 'exp_food'], ['5440', 'Courier & transport', 'expense', 'exp_courier'], ['5450', 'Printing & stationery', 'expense', 'exp_print'], ['5460', 'Site materials & labour', 'expense', 'exp_site'],
    ['5500', 'Office expenses', 'expense', null], ['5510', 'Marketing & advertising', 'expense', null], ['5520', 'Professional fees', 'expense', null], ['5530', 'Bank charges', 'expense', null],
    ['5540', 'Repairs & maintenance', 'expense', null], ['5900', 'Miscellaneous expenses', 'expense', 'exp_misc'],
  ];
  foreach ($A as [$c, $n, $t, $sub]) ins('accounts', ['code' => $c, 'name' => $n, 'type' => $t, 'sub' => $sub, 'opening' => 0, 'active' => 1, 'created_at' => now()]);
  if (setting('company') === null) set_setting('company', ['name' => 'Zest Exhibitions', 'address' => 'G-79/1, Third Floor, Front Side, Glam Apartment, Shaheen Bagh, Okhla, New Delhi - 110025', 'phone' => '9311966189',
    'gstin' => '', 'pan' => '', 'state' => 'Delhi', 'bank_name' => '', 'bank_acno' => '', 'bank_ifsc' => '', 'sac' => '998596', 'terms' => 'Payment due as per agreed schedule.', 'books_from' => date('Y') . '-04-01']);
}

// Design and quote versions that sales send to admin for approval before they go to the customer
function create_approvals_table() {
  $sqlite = (cfg()['driver'] ?? 'mysql') === 'sqlite';
  $PK = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
  $E = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
  foreach ([
    'CREATE TABLE IF NOT EXISTS ' . T('approvals') . " (id $PK, project_id INT NOT NULL, kind VARCHAR(10) NOT NULL, version INT NOT NULL, amount DECIMAL(14,2), note TEXT,
      requested_by INT NOT NULL, requested_at DATETIME NOT NULL, status VARCHAR(10) NOT NULL DEFAULT 'pending', decided_by VARCHAR(120), decided_at DATETIME, admin_note TEXT,
      sent_on DATE, sent_by VARCHAR(120), sent_at DATETIME, doc MEDIUMTEXT, quote_no VARCHAR(40))$E",
    'CREATE INDEX zc_appr_project ON ' . T('approvals') . ' (project_id)',
    'CREATE INDEX zc_appr_status ON ' . T('approvals') . ' (status)',
  ] as $q) { try { db()->exec($q); } catch (PDOException $e) { if (stripos($q, 'CREATE INDEX') !== 0) throw $e; } }
}

// What each department works on. records = clients & orders; leads = expo data research; calls = telecalling;
// design = design jobs; ops = production jobs; none = daily report only.
function default_dept_modules() {
  return ['Sales' => 'records', 'Telecalling' => 'calls', 'Design' => 'design', 'Data Research' => 'leads', 'Operations' => 'ops', 'Accounts' => 'accounts'];
}
function default_furniture() {
  return ['Sofa', 'Chairs', 'Table', 'Coffee table', 'Bar stools', 'TV / LED screen', 'Reception desk', 'Storage / cabinet', 'Brochure stand',
          '3D acrylic logo', 'Plotter cut letters', 'Plants'];
}

// Expo data (research + telecalling) and a per-day activity marker
function create_work_tables() {
  $sqlite = (cfg()['driver'] ?? 'mysql') === 'sqlite';
  $PK = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
  $E = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
  foreach ([
    'CREATE TABLE IF NOT EXISTS ' . T('expos') . " (id $PK, name VARCHAR(200) NOT NULL, edate DATE, city VARCHAR(80), website VARCHAR(255), created_by INT NOT NULL, created_at DATETIME NOT NULL)$E",
    'CREATE TABLE IF NOT EXISTS ' . T('leads') . " (id $PK, expo_id INT NOT NULL, user_id INT NOT NULL, dept VARCHAR(60) NOT NULL, company VARCHAR(200) NOT NULL, person VARCHAR(120), phone VARCHAR(60), email VARCHAR(160), notes TEXT, created_at DATETIME NOT NULL, updated_at DATETIME)$E",
    'CREATE INDEX zc_leads_expo ON ' . T('leads') . ' (expo_id)',
    'CREATE INDEX zc_leads_user ON ' . T('leads') . ' (user_id, created_at)',
    'CREATE TABLE IF NOT EXISTS ' . T('activity') . " (user_id INT NOT NULL, adate DATE NOT NULL, PRIMARY KEY (user_id, adate))$E",
  ] as $q) { try { db()->exec($q); } catch (PDOException $e) { if (stripos($q, 'CREATE INDEX') !== 0) throw $e; } }
}

// Change history: who corrected which money entry, before → after
function create_audit_table() {
  $sqlite = (cfg()['driver'] ?? 'mysql') === 'sqlite';
  $PK = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
  $E = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
  db()->exec('CREATE TABLE IF NOT EXISTS ' . T('audit') . " (id $PK, user_id INT NOT NULL, at DATETIME NOT NULL, project_id INT, kind VARCHAR(20) NOT NULL, entry_id INT NOT NULL, action VARCHAR(10) NOT NULL, before_json TEXT, after_json TEXT)$E");
}

function seed_defaults() {
  create_audit_table();
  create_work_tables();
  create_approvals_table();
  create_accounts_tables(); seed_accounts();
  if (setting('dept_modules') === null) set_setting('dept_modules', default_dept_modules());
  if (setting('furniture_items') === null) set_setting('furniture_items', default_furniture());
  if (!val('SELECT COUNT(*) FROM ' . T('reasons'))) {
    foreach (['Payment history issue / dues pending','Unreasonable price expectation','Difficult to work with','Timeline not feasible',
              'Scope not suitable for us','Price too high (client chose other vendor)','Client cancelled expo participation',
              'Design not approved','No response from client','Other'] as $r) ins('reasons', ['label' => $r]);
  }
  if (setting('departments') === null) set_setting('departments', ['Sales','Telecalling','Design','Data Research','Operations','Accounts']);
  if (setting('weekly_off') === null) set_setting('weekly_off', [0]);
  if (setting('track_from') === null) set_setting('track_from', today());
  if (setting('templates') === null) set_setting('templates', default_templates());
}

function default_templates() {
  return [
    'Sales' => [['k'=>'company','l'=>'Company name','t'=>'text','req'=>1],['k'=>'person','l'=>'Contact person','t'=>'text'],['k'=>'phone','l'=>'Phone','t'=>'text'],['k'=>'email','l'=>'Email','t'=>'text'],['k'=>'city','l'=>'City','t'=>'text'],['k'=>'expo','l'=>'Expo name','t'=>'text'],['k'=>'area','l'=>'Area looking for','t'=>'text'],['k'=>'brief','l'=>'Brief on requirements','t'=>'long'],['k'=>'files','l'=>'Requirement file','t'=>'file']],
    'Telecalling' => [['k'=>'company','l'=>'Company name','t'=>'text','req'=>1],['k'=>'person','l'=>'Contact person','t'=>'text'],['k'=>'phone','l'=>'Phone','t'=>'text'],['k'=>'outcome','l'=>'Call outcome','t'=>'select','o'=>['Interested','Call back','Not interested','No answer','Wrong number']],['k'=>'callback','l'=>'Call-back date','t'=>'date'],['k'=>'expo','l'=>'Expo interested in','t'=>'text'],['k'=>'brief','l'=>'Remarks','t'=>'long']],
    'Design' => [['k'=>'company','l'=>'Client','t'=>'text','req'=>1],['k'=>'expo','l'=>'Expo','t'=>'text'],['k'=>'stage','l'=>'Work done','t'=>'select','o'=>['New design','Correction','Final design','Working drawing']],['k'=>'version','l'=>'Version','t'=>'text'],['k'=>'hours','l'=>'Hours spent','t'=>'number'],['k'=>'status','l'=>'Status','t'=>'select','o'=>['In progress','Sent for approval','Approved']],['k'=>'brief','l'=>'Remarks','t'=>'long'],['k'=>'files','l'=>'Design file','t'=>'file']],
    'Data Research' => [['k'=>'company','l'=>'Source / expo list','t'=>'text','req'=>1],['k'=>'sector','l'=>'Sector','t'=>'text'],['k'=>'records','l'=>'Records collected','t'=>'number'],['k'=>'verified','l'=>'Verified','t'=>'number'],['k'=>'brief','l'=>'Remarks','t'=>'long'],['k'=>'files','l'=>'Data file (Excel / CSV)','t'=>'file']],
    'Operations' => [['k'=>'company','l'=>'Client / project','t'=>'text','req'=>1],['k'=>'expo','l'=>'Expo','t'=>'text'],['k'=>'brief','l'=>'Work done','t'=>'long'],['k'=>'files','l'=>'Attachment','t'=>'file']],
    'Accounts' => [['k'=>'company','l'=>'Party','t'=>'text','req'=>1],['k'=>'brief','l'=>'Work done','t'=>'long'],['k'=>'files','l'=>'Attachment','t'=>'file']],
  ];
}
