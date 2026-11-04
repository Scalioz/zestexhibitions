# Zest CRM

Internal CRM for Zest Exhibitions — clients, expo orders, design, quotes, vendor L1, extras, payments, expenses, daily reports, ledger.

- PHP 8.1+ and MySQL (any Hostinger hosting plan)
- Live at: https://crm.zestexhibitions.com

## First-time setup on Hostinger
1. hPanel → Domains → Subdomains → create `crm` for zestexhibitions.com
2. hPanel → Databases → MySQL → create a database + user (note name, user, password)
3. hPanel → Advanced → GIT → repository `https://github.com/Scalioz/zestexhibitions.git`, branch `main`, folder = the crm subdomain folder → Create → Deploy
4. Open https://crm.zestexhibitions.com/install.php → enter database details + admin login → Install
5. hPanel → Security → SSL → make sure SSL is active for crm.zestexhibitions.com

## Updating
Push to `main` → hPanel → Advanced → GIT → Deploy (or turn on auto-deploy webhook).
`config.php` and `uploads/` are never in Git, so updates don't touch data or settings.

## Backups
hPanel → Files → Backups (database + files). Download a copy monthly.

## Folders
- `api.php` – all data actions (permission checks here)
- `assets/` – app screens (app.js, app.css), logos
- `lib/` – database, login, calculations, table definitions (blocked from web)
- `uploads/` – uploaded files (blocked from web; served via file.php after login)
- `ledger.php` – printable statement of account per client
