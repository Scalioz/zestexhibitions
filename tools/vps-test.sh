#!/bin/bash
# Starts a TEST copy of Zest CRM on this VPS (port 8097). Safe to run again.
set -e
DIR=/root/zest-crm-test; PORT=8097
if [ -d "$DIR/.git" ]; then git -C "$DIR" pull -q; else git clone -q --depth 1 https://github.com/Scalioz/zestexhibitions.git "$DIR"; fi
mkdir -p "$DIR/data"
echo "<?php return ['driver'=>'sqlite','path'=>'/var/www/data/zest-test.sqlite'];" > "$DIR/crm/config.php"
docker rm -f zestcrm-test >/dev/null 2>&1 || true
docker run -d --name zestcrm-test --restart unless-stopped -p $PORT:80 \
  -v "$DIR/crm":/var/www/html -v "$DIR/data":/var/www/data php:8.3-apache >/dev/null
docker exec zestcrm-test bash -c "a2enmod rewrite >/dev/null; sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf; apache2ctl -k graceful"
docker exec zestcrm-test php /var/www/html/tools/seed-test.php
docker exec zestcrm-test chown -R www-data:www-data /var/www/data /var/www/html/uploads
command -v ufw >/dev/null && ufw allow $PORT/tcp >/dev/null 2>&1 || true
IP=$(curl -s -4 ifconfig.me || hostname -I | awk '{print $1}')
echo ""; echo "ZEST CRM TEST IS LIVE:  http://$IP:$PORT/login.php"
