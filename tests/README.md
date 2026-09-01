# Tests

Die Anwendung kommt ohne Composer aus (siehe `src/bootstrap.php`). Damit das
so bleibt, läuft PHPUnit als PHAR statt über `vendor/` — `tests/bootstrap.php`
registriert denselben handgeschriebenen PSR-4-Autoloader wie die Anwendung.

## Einmalig einrichten

PHPUnit-PHAR holen (liegt in `tools/`, ist nicht eingecheckt):

```sh
curl -sSL -o tools/phpunit.phar https://phar.phpunit.de/phpunit-11.phar
```

Testdatenbank anlegen. Sie ist strikt getrennt von der Entwicklungsdatenbank —
die Tests leeren ihr Schema bei jedem Lauf:

```sh
docker compose exec -T db mariadb -uroot -p"$DB_ROOT_PASS" -e "
  CREATE DATABASE IF NOT EXISTS berufsmesse_test
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  GRANT ALL PRIVILEGES ON berufsmesse_test.* TO 'berufsmesse'@'%';
  FLUSH PRIVILEGES;"
```

## Ausführen

```sh
docker compose run --rm --no-deps \
  -v "$PWD:/app" -w /app \
  -e DB_HOST=db -e DB_USER=berufsmesse -e DB_PASS="$DB_PASS" \
  --entrypoint sh app -c 'php tools/phpunit.phar'
```

Nur eine Suite: `--testsuite Unit` bzw. `--testsuite Integration`.

Abweichende Zugangsdaten für die Testdatenbank lassen sich über
`TEST_DB_HOST`, `TEST_DB_NAME`, `TEST_DB_USER` und `TEST_DB_PASS` setzen;
ohne sie gelten die `DB_*`-Werte mit der Datenbank `berufsmesse_test`.

## Aufbau

| Verzeichnis         | Inhalt                                                        |
|---------------------|---------------------------------------------------------------|
| `tests/Unit`        | Reine Rechenlogik, keine Datenbank                             |
| `tests/Integration` | Echtes SQL gegen das Schema aus `migrations/`                  |
| `tests/Support`     | `DatabaseTestCase`: Schema aufbauen, je Test eine Transaktion  |

`DatabaseTestCase` spielt die Migrationen einmal pro Lauf ein und rollt nach
jedem Test zurück. Tests sehen sich dadurch gegenseitig nicht und brauchen
kein eigenes Aufräumen.

## Nachgewiesene Regressionen

Diese Tests wurden gegen die jeweils fehlerhafte Fassung gegengeprüft und
schlagen dort tatsächlich fehl — sie sichern also wirklich etwas ab:

| Test | Sichert ab |
|---|---|
| `AbgesagteAusstellerTest` | Die Zuteilung schickte Schüler:innen zu abgesagten Ausstellern (3 von 4 Tests werden ohne den Fix rot) |
| `LoginThrottleTest` | Fehlversuche fremder Konten sperrten die ganze Schule hinter derselben NAT-Adresse aus |
| `PublicBaseUrlTest` | QR-Codes und Einladungslinks liefen bei Betrieb im Unterverzeichnis auseinander |

## Was noch fehlt

- **Parallelitätstests.** `WaitlistTest` prüft die fachlichen Regeln, nicht das
  Verhalten unter gleichzeitigen Requests. Der Race, gegen den
  `Waitlist::promote()` gehärtet wurde, lässt sich in einem einzelnen Prozess
  nicht deterministisch auslösen — dafür braucht es zwei Verbindungen, die
  sich an der FOR-UPDATE-Sperre begegnen. Der Schutz selbst (Transaktion,
  `FOR UPDATE`, `rowCount`-Prüfung) ist gebaut, aber nicht durch einen Test
  bewiesen. Als zweite Verteidigungslinie greift der UNIQUE-Constraint aus
  `005_integritaet.sql`.
- **Schul-Isolation.** Dass `edition_id`/`school_id` in allen Abfragen
  erzwungen werden, ist bisher nirgends abgesichert. Eine externe Prüfung hat
  es stichprobenartig gegen die laufende Instanz bestätigt (fremde Schule 403,
  fremdes Objekt 404) — ein automatisierter Test fehlt.
- **Offline-Scans.** Dass ein gepufferter Scan sein Zeitfenster über
  `offline_recorded_at` bewertet bekommt und bei einer Störung erhalten
  bleibt, ist bislang nur manuell geprüft.
- **Rechte.** `PermissionService` samt Abhängigkeitslogik ist ungetestet.
- **Statische Analyse.** PHPStan ist nicht eingerichtet.
