#!/bin/bash
# Starts a TEST copy of Zest CRM on this VPS (port 8097) with MariaDB — the same kind of database Hostinger uses.
#   curl -s https://raw.githubusercontent.com/Scalioz/zestexhibitions/main/crm/tools/vps-test.sh | bash               → update the code, keep test data
#   curl -s https://raw.githubusercontent.com/Scalioz/zestexhibitions/main/crm/tools/vps-test.sh | bash -s fresh      → run the full self-test, then wipe everything (admin login only)
set -e
DIR=/root/zest-crm-test; PORT=8097; MODE="${1:-update}"
if [ -d "$DIR/.git" ]; then git -C "$DIR" fetch -q origin main && git -C "$DIR" reset -q --hard origin/main; else git clone -q --depth 1 https://github.com/Scalioz/zestexhibitions.git "$DIR"; fi
mkdir -p "$DIR/data" "$DIR/mysql"
[ -f "$DIR/data/db.pass" ] || { head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' > "$DIR/data/db.pass"; head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' > "$DIR/data/db.root"; }
PW=$(cat "$DIR/data/db.pass"); ROOT=$(cat "$DIR/data/db.root")

# PHP with picture tools (GD), zip, photo rotation and MySQL — built once, then reused
if ! docker image inspect zestcrm-php:3 >/dev/null 2>&1; then
  echo "Preparing the server image (one time, about 2-3 minutes)..."
  docker build -q -t zestcrm-php:3 - >/dev/null <<'DOCKER'
FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libwebp-dev libzip-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp && docker-php-ext-install -j"$(nproc)" gd zip exif pdo_mysql \
 && a2enmod rewrite && sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf && rm -rf /var/lib/apt/lists/*
RUN printf "upload_max_filesize=60M\npost_max_size=64M\nmemory_limit=768M\nmax_execution_time=300\n" > /usr/local/etc/php/conf.d/zest.ini
DOCKER
fi

# Database (MariaDB)
docker network create zestnet >/dev/null 2>&1 || true
if ! docker ps --format '{{.Names}}' | grep -qx zestcrm-db; then
  docker rm -f zestcrm-db >/dev/null 2>&1 || true
  docker run -d --name zestcrm-db --network zestnet --restart unless-stopped -e MARIADB_ROOT_PASSWORD="$ROOT" -e MARIADB_DATABASE=zestcrm \
    -e MARIADB_USER=zest -e MARIADB_PASSWORD="$PW" -v "$DIR/mysql":/var/lib/mysql mariadb:11 >/dev/null
fi
echo -n "Waiting for the database"; for i in $(seq 1 60); do docker exec zestcrm-db mariadb -uzest -p"$PW" -e 'SELECT 1' zestcrm >/dev/null 2>&1 && break; echo -n "."; sleep 2; done; echo ""
echo "<?php return ['driver'=>'mysql','host'=>'zestcrm-db','name'=>'zestcrm','user'=>'zest','pass'=>'$PW'];" > "$DIR/crm/config.php"

# Web server
docker rm -f zestcrm-test >/dev/null 2>&1 || true
docker run -d --name zestcrm-test --network zestnet --restart unless-stopped -p $PORT:80 -v "$DIR/crm":/var/www/html zestcrm-php:3 >/dev/null
sleep 2
wipe() {
  docker exec zestcrm-db mariadb -uroot -p"$ROOT" -e "DROP DATABASE IF EXISTS zestcrm; CREATE DATABASE zestcrm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON zestcrm.* TO 'zest'@'%';"
  rm -rf "$DIR/crm/uploads/20"*
}
USERS=$(docker exec zestcrm-db mariadb -N -uzest -p"$PW" zestcrm -e "SELECT COUNT(*) FROM zc_users" 2>/dev/null || echo 0)
if [ "$MODE" = "fresh" ] || [ "$USERS" = "0" ]; then
  wipe
  docker exec zestcrm-test php /var/www/html/tools/seed-test.php --empty >/dev/null
  docker exec zestcrm-test chown -R www-data:www-data /var/www/html/uploads
  echo ""; echo "Running the self-test on MariaDB (about a minute)..."; echo "----------------------------------------"
  docker exec zestcrm-test php /var/www/html/tools/selftest.php http://127.0.0.1/ admin@zest.test ZestAdmin@2026 | grep -v '  ✓' || true
  echo "----------------------------------------"
  echo "Clearing all test data..."; wipe
  docker exec zestcrm-test php /var/www/html/tools/seed-test.php --empty >/dev/null
fi
docker exec zestcrm-test chown -R www-data:www-data /var/www/html/uploads
command -v ufw >/dev/null && ufw allow $PORT/tcp >/dev/null 2>&1 || true
IP=$(curl -s -4 ifconfig.me || hostname -I | awk '{print $1}')
echo ""; echo "ZEST CRM TEST IS LIVE:  http://$IP:$PORT/login.php   (admin@zest.test / ZestAdmin@2026)"
