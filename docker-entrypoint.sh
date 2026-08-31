#!/bin/sh
set -e

echo "Warte auf Datenbank ${DB_HOST}..."
until mysql -h "${DB_HOST}" -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" -e "SELECT 1" >/dev/null 2>&1; do
    sleep 2
done

echo "Führe Migrationen aus..."
php /var/www/html/bin/migrate.php

exec "$@"
