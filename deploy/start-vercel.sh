#!/usr/bin/env bash
# Start untuk Vercel: DB = MySQL eksternal (env DB_HOST dkk), seed sekali, lalu Apache di $PORT (default 80).
set -euo pipefail
cd "$(dirname "$0")"
source ./demo.env   # DB_NAME, SQL_FILES, DOC_ROOT/PARSE_HTML_AS_PHP (opsional)
: "${DB_HOST:?DB_HOST belum di-set (lihat deploy/db_compat.php)}"
export DEMO_DB_NAME="$DB_NAME" SQL_FILES
php ./seed.php

PORT=${PORT:-80}
sed -i "s/^Listen 80$/Listen $PORT/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:$PORT>/" /etc/apache2/sites-available/000-default.conf
# Folder deploy/ berisi script internal, jangan bisa diakses lewat web
printf '<Directory "/var/www/html/deploy">\n  Require all denied\n</Directory>\n' > /etc/apache2/conf-enabled/zz-deny-deploy.conf
if [ -n "${DOC_ROOT:-}" ]; then
  sed -i "s#DocumentRoot /var/www/html#DocumentRoot \"/var/www/html/$DOC_ROOT\"#" /etc/apache2/sites-available/000-default.conf
  printf "<Directory \"/var/www/html/%s\">\n  AllowOverride All\n  Require all granted\n</Directory>\n" "$DOC_ROOT" >> /etc/apache2/apache2.conf
fi
if [ "${PARSE_HTML_AS_PHP:-0}" = "1" ]; then
  printf '<FilesMatch "\\.html$">\n  SetHandler application/x-httpd-php\n</FilesMatch>\n' > /etc/apache2/conf-enabled/zz-html-php.conf
fi
exec apache2-foreground
