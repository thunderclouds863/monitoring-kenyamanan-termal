#!/usr/bin/env bash
# Start untuk Vercel: DB = MySQL eksternal (env DB_HOST dkk), seed sekali, lalu Apache di $PORT (default 80).
set -euo pipefail
cd "$(dirname "$0")"
source ./demo.env   # DB_NAME, SQL_FILES, DOC_ROOT/PARSE_HTML_AS_PHP (opsional)
: "${DB_HOST:?DB_HOST belum di-set (lihat deploy/db_compat.php)}"
export DEMO_DB_NAME="$DB_NAME" SQL_FILES
php ./seed.php

PORT=${PORT:-80}
if [ "$PORT" != "80" ]; then
  sed -i "s/^Listen 80$/Listen $PORT/" /etc/apache2/ports.conf
  sed -i "s/<VirtualHost \*:80>/<VirtualHost *:$PORT>/" /etc/apache2/sites-available/000-default.conf
fi
exec apache2-foreground
