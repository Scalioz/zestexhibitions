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
"CREATE TABLE IF NOT EXISTS {$t('vendors')} (id $PK, name VARCHAR(160) NOT NULL, phone VARCHAR(40), notes TEXT, active TINYINT NOT NULL DEFAULT 1)$E",
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
  expo VARCHAR(200) NOT NULL, location VARCHAR(120), start_date DATE, end_date DATE, area_sqm DECIMAL(10,2), stall_no VARCHAR(40),
  designed_by VARCHAR(120), assigned_on DATE, design_approved_by VARCHAR(120),
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
"CREATE TABLE IF NOT EXISTS {$t('files')} (id $PK, entity VARCHAR(30) NOT NULL, entity_id $FK NOT NULL, orig_name VARCHAR(255) NOT NULL, stored_name VARCHAR(80) NOT NULL, mime VARCHAR(100), size INT, uploaded_by $FK, created_at DATETIME NOT NULL)$E",
"CREATE INDEX zc_files_entity ON {$t('files')} (entity, entity_id)",
  ];
  foreach ($sql as $s) {
    try { db()->exec($s); }
    catch (PDOException $e) { if (stripos($s, 'CREATE INDEX') !== 0) throw $e; } // index may already exist on re-run
  }
}

function seed_defaults() {
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
