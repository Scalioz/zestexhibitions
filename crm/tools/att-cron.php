<?php
// Run every 5 minutes from Hostinger → Advanced → Cron Jobs, so attendance, report and sales alerts go out on time
// even when nobody has the CRM open.   php /home/<user>/domains/zestexhibitions.com/public_html/crm/tools/att-cron.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/calc.php';
require_once __DIR__ . '/../lib/schema.php';
require_once __DIR__ . '/../lib/att.php';
require_once __DIR__ . '/../lib/alerts.php';
if (!cfg()) exit("Not installed\n");
migrate();
set_setting('alerts_cron', date('Y-m-d H:i'));
att_tick(); alerts_tick();
echo "ok\n";
