<?php
// Optional: run every 5 minutes from Hostinger → Advanced → Cron Jobs, so the late alert goes out on time
// even when nobody has the CRM open.   php /home/<user>/domains/crm.zestexhibitions.com/public_html/tools/att-cron.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/calc.php';
require_once __DIR__ . '/../lib/schema.php';
require_once __DIR__ . '/../lib/att.php';
if (!cfg()) exit("Not installed\n");
migrate(); att_tick();
echo "ok\n";
