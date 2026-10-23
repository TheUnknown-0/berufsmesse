#!/bin/sh
set -e

DB_WAIT_TIMEOUT="${DB_WAIT_TIMEOUT:-120}"

# Das Passwort NICHT als Argument übergeben — es stünde sonst für jeden
# sichtbar in der Prozessliste des Containers.
export MYSQL_PWD="${DB_PASS}"

echo "Warte auf Datenbank ${DB_HOST} (max. ${DB_WAIT_TIMEOUT}s)..."
waited=0
until mysql -h "${DB_HOST}" -u"${DB_USER}" "${DB_NAME}" -e "SELECT 1" >/dev/null 2>&1; do
    if [ "${waited}" -ge "${DB_WAIT_TIMEOUT}" ]; then
        echo "Datenbank ${DB_HOST} nach ${DB_WAIT_TIMEOUT}s nicht erreichbar — Abbruch." >&2
        echo "Ohne Abbruch bliebe der Container endlos in der Warteschleife und" >&2
        echo "gälte dabei als lauffähig; so greift die Restart-Policy." >&2
        exit 1
    fi
    waited=$((waited + 2))
    sleep 2
done

echo "Führe Migrationen aus..."
php /var/www/html/bin/migrate.php

# Sicherheitscheck (PHP, Systempakete, MariaDB) regelmäßig im Hintergrund.
# Läuft als www-data, nicht als root. Ein Fehlschlag (etwa ohne Internet)
# beendet die Schleife nicht; das Ergebnis steht im Global-Admin.
SECURITY_CHECK_INTERVAL="${SECURITY_CHECK_INTERVAL:-21600}"
if [ "${SECURITY_CHECK_INTERVAL}" -gt 0 ] 2>/dev/null; then
    (
        while true; do
            setpriv --reuid=www-data --regid=www-data --init-groups \
                php /var/www/html/bin/security-check.php || true
            sleep "${SECURITY_CHECK_INTERVAL}"
        done
    ) &
fi

exec "$@"
