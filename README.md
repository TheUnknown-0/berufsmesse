# Berufsmesse

Webanwendung zur Organisation und Durchführung schulischer Berufsmessen — Neubau mit sauberer Architektur. Mandantenfähig (mehrere Schulen), mehrjährig (Messe-Editionen), mit Aussteller-Portal, Schüler-Einschreibung inkl. automatischer Slot-Zuteilung, QR-Check-in, PDF-Druckzentrale, granularem Rechtesystem und Audit-Log.

## Features

| Bereich | Funktion |
|---|---|
| **Schulen & Editionen** | Mehrere Schulen mit eigenen URLs (`/schul-slug/`), Messe-Jahrgänge mit Einschreibezeitraum & Veranstaltungsdatum |
| **Aussteller** | CRUD, Logo & Dokumente, Branchen, Angebotstypen, Sichtbarkeitssteuerung, Aussteller-Portal mit eigenen Logins |
| **Einschreibung** | Schüler wählen Aussteller mit Priorität 1–3, automatische 2-Phasen-Slot-Zuteilung |
| **QR-Check-in** | Selbst-Scan (Aussteller-Codes) & Lehrer-Scan (Schüler-Codes), Zeitfenster, Falsch-Raum-Erkennung, Live-Dashboard |
| **Feedback** | Bögen à la Google Forms (7 Fragetypen), Zielgruppen Schüler/Lehrkräfte/Aussteller, Freischaltung per Status + Zeitfenster, anonym oder namentlich, Auswertung & Export |
| **Berichte** | PDF: persönliche Pläne, Klassenlisten, Raumpläne, Zugangsdaten, QR-Karten; Export CSV/XLSX |
| **Rechte** | Rollen (admin, school_admin, orga, teacher, student, exhibitor) + ~45 granulare Berechtigungen mit Abhängigkeitslogik & Gruppen; globale Administratoren nur im Global-Admin verwaltbar |
| **Betrieb** | Audit-Log, Ankündigungen, In-App-Benachrichtigungen, Aufsichtsplan, Ausstattungsanfragen |

## Schnellstart (Docker)

```bash
cp .env.example .env      # Passwörter setzen!
docker compose up -d --build
```

Anwendung: `http://localhost:9000` — beim ersten Aufruf führt ein **Setup-Assistent** durch Admin-Konto, Schule und erste Messe (kein manuelles SQL nötig).

phpMyAdmin (nur bei Bedarf): `docker compose --profile tools up -d` → Port 8080.

## Umgebungsvariablen

| Variable | Standard | Beschreibung |
|---|---|---|
| `DB_HOST` / `DB_USER` / `DB_NAME` | `db` / `berufsmesse` / `berufsmesse` | Datenbankverbindung |
| `DB_PASS` / `DB_ROOT_PASS` | *(leer)* | **Pflicht** — sichere Passwörter setzen |
| `APP_PORT` | `9000` | Host-Port |
| `APP_ENV` | `production` | `development` zeigt Fehlerdetails |
| `BASE_URL` | `/` | Basis-Pfad bei Betrieb im Unterverzeichnis |
| `TRUSTED_PROXIES` | private Netze | CIDR-Liste für X-Forwarded-For |
| `SECURE_COOKIES` | Auto | `1` erzwingt Secure-Flag (hinter HTTPS-Proxy) |

## Architektur

Siehe [ARCHITECTURE.md](ARCHITECTURE.md) — Front-Controller (`public/index.php`), eigenes schlankes MVC unter `src/`, Templates unter `templates/`, versionierte Migrationen unter `migrations/` (laufen beim Container-Start automatisch). Keine externen Laufzeit-Abhängigkeiten: FPDF und QR-Erzeugung sind vendored (`lib/`), Fonts/JS-Bibliotheken liegen lokal (`public/assets/`) — die App läuft vollständig offline im Schulnetz und mit strikter Content-Security-Policy.

## Backup

```bash
# Datenbank
docker compose exec db sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysqldump -u"$MYSQL_USER" "$MYSQL_DATABASE"' > backup.sql
# Uploads (Logos & Dokumente)
docker run --rm -v berufsmesse_uploads:/data -v "$(pwd):/backup" alpine tar czf /backup/uploads.tar.gz -C /data .
```

## Lokale Entwicklung ohne Docker

PHP ≥ 8.2 (`pdo_mysql`, `mbstring`, `gd`, `zip`) + MariaDB ≥ 10.6:

```bash
mysql -u root -p -e "CREATE DATABASE berufsmesse CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
export DB_HOST=127.0.0.1 DB_USER=root DB_PASS=... DB_NAME=berufsmesse APP_ENV=development
php bin/migrate.php
php -S localhost:8000 -t public
```
