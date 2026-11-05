#!/bin/bash
# Starts a TEST copy of Zest CRM on this VPS (port 8097). Safe to run again.
set -e
DIR=/root/zest-crm-test; PORT=8097
if [ -d "$DIR/.git" ]; then git -C "$DIR" pull -q; else git clone -q --depth 1 https://github.com/Scalioz/zestexhibitions.git "$DIR"; fi
mkdir -p "$DIR/data"
echo "<?php return ['driver'=>'sqlite','path'=>'/var/www/data/zest-test.sqlite'];" > "$DIR/crm/config.php"
# PHP with the picture tools (GD), zip and photo-rotation support — built once, then reused (same as Hostinger has)
if ! docker image inspect zestcrm-php:2 >/dev/null 2>&1; then
  echo "Preparing the server image (one time, about 2 minutes)..."
  docker build -q -t zestcrm-php:2 - >/dev/null <<'DOCKER'
FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libwebp-dev libzip-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp && docker-php-ext-install -j"$(nproc)" gd zip exif \
 && a2enmod rewrite && sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf && rm -rf /var/lib/apt/lists/*
RUN printf "upload_max_filesize=60M\npost_max_size=64M\nmemory_limit=768M\nmax_execution_time=300\n" > /usr/local/etc/php/conf.d/zest.ini
DOCKER
fi
docker rm -f zestcrm-test >/dev/null 2>&1 || true
docker run -d --name zestcrm-test --restart unless-stopped -p $PORT:80 \
  -v "$DIR/crm":/var/www/html -v "$DIR/data":/var/www/data zestcrm-php:2 >/dev/null
sleep 2
docker exec zestcrm-test php /var/www/html/tools/seed-test.php
docker exec zestcrm-test chown -R www-data:www-data /var/www/data /var/www/html/uploads
command -v ufw >/dev/null && ufw allow $PORT/tcp >/dev/null 2>&1 || true
IP=$(curl -s -4 ifconfig.me || hostname -I | awk '{print $1}')
echo ""; echo "ZEST CRM TEST IS LIVE:  http://$IP:$PORT/login.php"
