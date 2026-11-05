# Zest CRM

Internal CRM for Zest Exhibitions — clients, expo orders, design, quotes, vendor L1, extras, payments, expenses, daily reports, ledger.

- PHP 8.1+ and MySQL (any Hostinger hosting plan)
- Live at: https://crm.zestexhibitions.com

## First-time setup on Hostinger
The live site is deployed from the **`crm-live`** branch, which holds only this `crm/` folder (so the CRM sits at the root of the subdomain).
1. hPanel → Domains → Subdomains → create `crm` for zestexhibitions.com (note its folder, e.g. `public_html/crm`)
2. hPanel → Databases → MySQL → create a database + user (note name, user, password)
3. hPanel → Advanced → PHP configuration → PHP 8.2 or 8.3; extensions gd, zip, exif, curl, fileinfo, pdo_mysql on; upload_max_filesize 64M, post_max_size 64M, memory_limit 768M, max_execution_time 300
4. hPanel → Files → File Manager → open the subdomain folder → delete the default files so it is empty
5. hPanel → Advanced → GIT → repository `https://github.com/Scalioz/zestexhibitions.git`, branch `crm-live`, directory = the subdomain folder → Create → Deploy
6. hPanel → Security → SSL → install / check SSL for crm.zestexhibitions.com
7. Open https://crm.zestexhibitions.com/install.php → database details + admin login → Install

## Updating
Changes go to `main`; `crm-live` is refreshed with `git subtree split --prefix=crm -b crm-live && git push -f origin crm-live`.
Then hPanel → Advanced → GIT → Deploy (or turn on the auto-deploy webhook).
`config.php` and `uploads/` are never in Git, so updates don't touch data or settings. New database changes are applied automatically on first use.

## Test server (VPS, MariaDB — same as Hostinger)
`curl -s https://raw.githubusercontent.com/Scalioz/zestexhibitions/main/crm/tools/vps-test.sh | bash` updates the test copy.
Add `-s fresh` after `bash` to run the full self-test (`tools/selftest.php`) and then wipe all test data.

## Backups
hPanel → Files → Backups (database + files). Download a copy monthly.

## Folders
- `api.php` – all data actions (permission checks here)
- `assets/` – app screens (app.js, app.css), logos
- `lib/` – database, login, calculations, table definitions (blocked from web)
- `uploads/` – uploaded files (blocked from web; served via file.php after login)
- `ledger.php` – printable statement of account per client
