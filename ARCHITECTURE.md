# Berufsmesse — Architektur & Verbindliche Konventionen

Dieses Dokument ist der **verbindliche Vertrag** für alle Module. Wer ein Feature-Modul baut, hält sich exakt an diese Konventionen.

## Überblick

Neubau der Original-App (`../Berufsmesse-original/` — dort Referenzverhalten nachlesen!). PHP 8.3, eigenes schlankes MVC, MariaDB, server-gerendert, leichtes Vanilla-JS. Kein Composer-Vendor, kein CDN — alles lokal.

```
berufsmesse/
├── public/              # Webroot (einziger Einstieg: index.php)
│   └── assets/          #   css/app.css (Design-System), js/, fonts/
├── src/
│   ├── Core/            # Database, Router, View, Session, Csrf, Auth, Permissions, Context, HttpException
│   ├── Controllers/     # Ein Controller pro Modul, erbt von Controller
│   ├── Services/        # Settings, Audit, Uploads, LoginThrottle, + Fachlogik (AutoAssign, Qr, Pdf …)
│   ├── routes/          # NN-modul.php — je Modul EINE Datei, wird geglobbt (Reihenfolge = Dateiname)
│   ├── bootstrap.php    # Autoloader + Context-Aufbau
│   └── helpers.php      # e(), format_date(), format_datetime()
├── templates/
│   ├── layouts/         # app.php (Sidebar-Shell), minimal.php (zentrierte Karte)
│   ├── partials/        # sidebar, topbar, flash
│   └── pages/<modul>/   # Templates je Modul in EIGENEM Unterordner
├── lib/                 # vendored: fpdf/ (FPDF), qrcode.php + qr.php (QR pure-PHP)
├── migrations/          # NNN_name.sql, idempotent NICHT nötig (Runner trackt in schema_migrations)
├── uploads/             # AUSSERHALB des Webroots: logos/, documents/
└── bin/migrate.php      # Migrations-Runner
```

## Request-Fluss

`public/index.php` → lädt `src/bootstrap.php` (gibt `Context $ctx`) → registriert alle `src/routes/*.php` → matcht → `new Controller($ctx)`, ruft Aktion `method(array $params)` auf.

**Rückgabewerte einer Aktion:** `string` = HTML, `array` = JSON-Antwort, oder `$this->redirect(...)` (beendet Request). Fehler via `throw new HttpException(403)` — der Front-Controller rendert Fehlerseite bzw. JSON (bei Pfaden mit `/api/`).

## Controller-Pattern (Pflicht)

```php
final class XyController extends Controller
{
    /** GET /{school}/admin/xy */
    public function index(array $params): string
    {
        $this->requireSchool($params['school']);     // lädt Schule+Edition in $ctx, prüft Login+Schulzugriff
        $this->requirePermission(Permissions::XY_SEHEN);
        $edition = $this->ctx->requireEdition();     // aktive Edition (wirft 404 wenn keine)

        $rows = $this->ctx->db->fetchAll('SELECT ... WHERE edition_id = ?', [(int)$edition['id']]);
        return $this->render('pages/xy/index', ['title' => 'Xy', 'rows' => $rows]);
    }

    /** POST /{school}/admin/xy */
    public function store(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::XY_ERSTELLEN);
        $this->requireCsrf();                        // bei JEDEM schreibenden Request!
        // ... validieren, $this->ctx->db->run(...), $this->ctx->audit->log('Xy erstellt', 'info', $details, $this->ctx->schoolId());
        $this->flash('success', 'Gespeichert.');
        $this->redirect($this->ctx->schoolUrl('/admin/xy'));
    }

    /** POST /{school}/api/xy/loeschen — JSON-Endpunkt */
    public function apiDelete(array $params): array
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::XY_LOESCHEN);
        $this->requireCsrf();                        // Token kommt via X-CSRF-Token-Header (BM.fetchJson)
        // ...
        return ['success' => true];
    }
}
```

Verfügbare Guards/Helfer aus `Controller`: `requireLogin()`, `requireSchool($slug)`, `requirePermission($p)`, `requireAdmin()`, `requireCsrf()`, `render($tpl, $data, $layout='app')`, `redirect($url)`, `flash($type,$msg)` (`success|error|warning|info`), `jsonError($msg,$status)`.

Kontext (`$this->ctx`): `db` (fetchOne/fetchAll/fetchValue/run/lastInsertId/transaction), `auth` (user()/id()/role()/can($perm,$schoolId)/isAdmin()), `settings` (get/getBool/getInt/set — mit `$schoolId`!), `audit->log()`, `csrf`, `session`, `view`, `school`, `edition`, `schoolId()`, `editionId()`, `requireEdition()`, `url($path)`, `schoolUrl($path)`, `config`.

## Sicherheits-Pflichten

1. **Jede** Ausgabe in Templates durch `e(...)`. Keine Ausnahmen.
2. **Jeder** schreibende Request (POST) → `requireCsrf()`.
3. **Nur** Prepared Statements (`$ctx->db->run/fetch*` mit `?`-Parametern). Nie String-Konkatenation von Werten — auch nicht mit `(int)`-Cast.
4. **Jede** Query auf editions-/schulgebundene Tabellen filtert nach `edition_id` bzw. `school_id` aus dem Kontext — niemals IDs aus dem Request unbestätigt übernehmen (Schul-Isolation!). Bei per-ID-Zugriff: Eigentum prüfen (`WHERE id=? AND edition_id=?`).
5. Berechtigungen NUR über `Permissions::KONSTANTEN` (nie String-Literale).
6. **Jede** Route ist anmeldungs- UND berechtigungsgeschützt: `requireLogin()`/`requireSchool()` plus `requirePermission()`/`requireAdmin()` — oder, bei rollengebundenen Bereichen (Schüler, Lehrkräfte, Portal), eine ausdrückliche Rollenprüfung, die mit HTTP 403 abbricht. Bewusst offene Routen stehen mit Begründung in den Ausnahmelisten von `tests/Unit/RouteGuardsTest.php`; der Test prüft alle registrierten Routen und schlägt bei einer ungeschützten neuen Route fehl.
7. Granulare Rechte tragen nur die Rollen aus `Permissions::GRANULAR_ROLES` (orga, teacher). `Auth::permissions()` setzt das durch, und ein Rollenwechsel entzieht Direktrechte samt Gruppenzuweisungen — Rechte enden mit der Rolle.
8. Uploads NUR über `Services\Uploads` (`store`, `stream`, `delete`). Logos: `new Uploads($ctx->config['uploads']['dir'])`, Subdir `logos`, Anzeige über URL `$ctx->url('/medien/logos/' . $filename)`. Dokumente: Subdir `documents`, Download ausschließlich über euren permissions-geprüften Endpunkt mit `$uploads->stream('documents', $filename, $originalName)`.
9. Passwort-Mindestlänge überall: 8 (Konstante `AuthController::MIN_PASSWORD_LENGTH`).
10. CSP ist strikt (`script-src 'self'`): **keine Inline-`<script>`-Blöcke, keine `onclick=`-Attribute!** Alles JS in eigene Dateien unter `public/assets/js/`, eingebunden über `$pageScripts` (siehe unten). Inline-`style="..."`-Attribute sind erlaubt.

## Routen-Konventionen

Je Modul eine Datei `src/routes/NN-modul.php`:
```php
return static function (Router $r): void {
    $r->get('/{school}/admin/xy', [XyController::class, 'index']);
    $r->post('/{school}/api/xy/loeschen', [XyController::class, 'apiDelete']);
};
```
- Deutsch, kebab-case: `/admin/aussteller`, `/api/anwesenheit/live` …
- JSON-Endpunkte enthalten IMMER `/api/` im Pfad (steuert Fehlerformat).
- Params: `{school}` (Slug), `{id}` etc. — via `$params['school']`.
- **Bereits vergebene URL-Struktur (Sidebar verlinkt genau darauf!):**

| Bereich | Pfade |
|---|---|
| Global | `/`, `/login`, `/logout`, `/zugang`, `/setup`, `/passwort-aendern`, `/medien/logos/{file}`, `/aussteller-einladung`, `/global-admin(...)`, `/global-admin/administratoren(...)` |
| Schüler | `/{school}/uebersicht`, `/{school}/aussteller`, `/{school}/einschreibung`, `/{school}/meine-anmeldungen`, `/{school}/tagesplan`, `/{school}/checkin`, `/{school}/drucken` |
| Alle Rollen | `/{school}/feedback`, `/{school}/feedback/{id}` (Zielgruppe des Bogens entscheidet) |
| Lehrer | `/{school}/klassen`, `/{school}/klassen/{class}`, `/{school}/scan` |
| Aussteller | `/{school}/portal`, `/portal/profil/{id}`, `/portal/slots`, `/portal/ausstattung`, `/portal/dokumente` |
| Admin | `/{school}/admin/` + `dashboard`, `aussteller`, `aussteller/pipeline`, `raeume`, `raumplan`, `kapazitaeten`, `anmeldungen`, `benutzer`, `berechtigungen`, `qr-codes`, `anwesenheit`, `anwesenheit-live`, `anwesenheit-bericht`, `leitstand`, `ausfall`, `aufsicht`, `druckzentrale`, `jahresvergleich`, `ausstattung`, `ankuendigungen`, `feedback`, `einstellungen`, `audit-log` |
| APIs | `/{school}/api/...` (Modul-frei wählbar), global `/api/...` |

`GET /{school}/` leitet rollenabhängig weiter: student→`/uebersicht`, teacher→`/klassen`, exhibitor→`/portal`, admin/school_admin/orga→`/admin/dashboard`.

## Templates & Design-System

- Template je Seite unter `templates/pages/<modul>/name.php`, gerendert mit `$this->render('pages/<modul>/name', [...])`. Immer `'title' => '...'` mitgeben.
- In Templates verfügbar: `$ctx`, `$auth`, `$csrf`, `$view` (für `renderPartial`) + übergebene Daten. Escaping via `e()`, Datum via `format_date()` / `format_datetime()`.
- Formulare: IMMER `<?= $csrf->field() ?>` einfügen.
- Seiten-JS: `'pageScripts' => ['modul-name.js']` an render() → lädt `/assets/js/modul-name.js`. AJAX via `BM.fetchJson(url, {json: {...}})` (setzt CSRF-Header automatisch); Rückgabe-Konvention `{success: bool, error?: string, ...}`. Toasts: `BM.flash('success', 'Text')`.
- **CSS: NUR Klassen aus `public/assets/css/app.css` verwenden — KEINE neuen CSS-Dateien, keine `<style>`-Blöcke.** Verfügbare Bausteine:
  - Seitenkopf: `page-header` > `page-title-group` (`page-eyebrow`, `h1.page-title`, `p.page-sub`) + `page-actions`
  - Karten: `card` (+`card-header`/`card-body`/`card-footer` oder `card-pad`), Statistik: `stat-grid` > `stat-card` (+`stat-accent|stat-success|stat-danger`) > `stat-value`/`stat-label`
  - Buttons: `btn` + `btn-primary|btn-accent|btn-danger|btn-danger-ghost|btn-ghost|btn-sm|btn-lg|btn-block`
  - Formulare: `field` > `label` + `input`/`select`/`textarea` (Klasse `input` für inputs) + `hint`/`error-text`; `form-grid`, `checkbox-row`, Schalter: `label.switch > input + span.slider`
  - Tabellen: `table-wrap` > `table.data-table`; Aktionen-Spalte: `row-actions`
  - Badges: `badge` + `badge-primary|accent|success|warning|danger|info`; `chip-row`
  - Alerts: `alert alert-info|success|warning|error`
  - Modals: `<dialog class="modal" id="...">` mit `modal-header`/`modal-body`/`modal-footer`; öffnen per Button-Attribut `data-open-modal="id"`, schließen `data-close-modal`
  - Tabs: `tabs` > `a.tab` (+`.active`); Layout: `grid-2`, `grid-3`, `stack`, `cluster`, `divider`, `empty-state`, `progress`, `menu`/`menu-list`/`menu-item`, `qr-frame`, `mono`, `text-soft|text-faint|text-sm`
  - Bestätigungen: `data-confirm="Wirklich löschen?"` auf `<form>` oder Button/Link
- Icons: Emoji (wie in der Sidebar). Kein Font Awesome.

## Datenmodell

Vollständig in `migrations/001_init.sql` — vor Implementierung LESEN. Kernpunkte:
- Alles hängt an `messe_editions` (Edition = Jahrgang je Schule). Zeitraum/Datum/Max-Anmeldungen stehen NUR in der Edition (nicht in settings!).
- `timeslots.is_managed=1` = fester Zuteilungsslot; `is_managed=0 && is_break=0` = freie Wahl (Check-in schreibt automatisch ein); `is_break=1` = Pause. Diese Flags sind die EINZIGE Quelle — nie Slot-Nummern hartcodieren.
- Kapazität eines Aussteller-Slots: Eintrag in `room_slot_capacities` für (room, timeslot); existiert keiner → `rooms.capacity`. Aussteller ohne Raum → `exhibitors.total_slots`. (Gewollte Abweichung: das Original teilte `rooms.capacity` durch 3 — hier gilt die Raumkapazität pro Slot, was fachlich korrekt ist.)
- `registrations.timeslot_id NULL` = gewählt, aber noch nicht zugeteilt. `priority` 1–3 (1=hoch). UNIQUE (user, exhibitor).
- `settings` mit `school_id` (NULL=global). Bekannte Keys: `registration_page_enabled`, `site_password_enabled`, `site_password` (bcrypt), `auto_close_registration`, `qr_code_url`, `qr_validity_enabled`, `qr_validity_before` (Min., Def. 10), `qr_validity_after` (Def. 15), `qr_validity_teacher_enabled`, `qr_validity_teacher_before` (Def. 20), `qr_validity_teacher_after` (Def. 30), `checkin_self_scan_enabled`, `checkin_teacher_scan_enabled`. Boolean als '1'/'0'.
- **Global-Administratoren** (`role='admin'`) werden AUSSCHLIESSLICH unter `/global-admin/administratoren` angelegt und geändert. Im Schulkontext ist die Rolle weder vergebbar noch verwaltbar (`UsersController::assignableRoles/importableRoles/assertMayManage`); in der Benutzerliste einer Schule erscheinen sie nur bei `users.visible_in_school_list = 1` (setzbar nur im Global-Admin, verlangt eine Schulzuordnung). Die `school_id` eines Admins ist rein organisatorisch — Zugriff hat er ohnehin überall.
- **Feedback-Bögen** hängen an `feedback_forms.edition_id`. Ausfüllbar ist ein Bogen genau dann, wenn `status='open'` UND das optionale Zeitfenster (`opens_at`/`closes_at`) passt — die Logik dazu steht ausschließlich in `Services\FeedbackService::isOpen()`, nie in Controllern nachbauen. Zielgruppe je Bogen über `audience_students|teachers|exhibitors`. Bei `is_anonymous=1` bleibt `feedback_responses.user_id` NULL und die Klasse leer; `feedback_participants` hält nur fest, DASS jemand abgegeben hat (UNIQUE = Schutz gegen Doppelabgabe). Fragen werden beim Speichern anhand ihrer `id` abgeglichen, Optionen anhand ihrer Beschriftung — so überleben bereits gegebene Antworten eine Umformulierung.
- **Aussteller über die Jahre**: `exhibitors.previous_exhibitor_id` verkettet dasselbe Unternehmen über Editionen (gesetzt von `Services\EditionCloner`). Darauf bauen `Services\ExhibitorHistory` (Teilnahmehistorie) und der Jahresvergleich auf. `pipeline_status` ist der Akquisestand; er wird gekoppelt mit `active` gepflegt (`confirmed` → sichtbar, alles andere → unsichtbar) — die Kopplung steht ausschließlich in `ExhibitorPipelineController::VISIBILITY`.
- **Edition klonen** (`Services\EditionCloner`): kopiert NUR Struktur (Zeitraster, Räume, Kapazitäten, Ausstellerstammdaten, Orga-Zuordnungen, Feedback-Fragen). Anmeldungen, Anwesenheiten, QR-Token, Ausstattungsanfragen und Feedback-Antworten werden nie mitkopiert. Wer den Cloner erweitert, hält sich daran.
- **Warteliste**: Es gibt keine eigene Tabelle — ein Wunsch ohne Zeitslot (`registrations.timeslot_id IS NULL`) IST der Wartelisteneintrag. Wird ein Platz frei, ruft der jeweilige Löschpfad `Services\Waitlist::promote()` auf. Neue Stellen, die Zuteilungen entfernen, müssen das ebenfalls tun.
- **Probelauf der Zuteilung** (`AutoAssign::simulate`): führt die echte Zuteilung in einer Transaktion aus und rollt sie in `finally` zurück. Die Verteilungslogik wird bewusst NICHT ein zweites Mal nachgebaut.
- **Offline-Scans**: `public/assets/js/scan-queue.js` puffert Scans im localStorage und sendet sie mit `offline_recorded_at` nach. Der Server prüft diesen Zeitstempel über `Controller::offlineTimestamp()` (nur Vergangenheit, max. 6 h) — clientgelieferte Zeiten nie ungeprüft übernehmen. Idempotent ist der Nachtrag durch `INSERT IGNORE` auf dem UNIQUE von `attendance`.
- Rollen-ENUM: `admin, school_admin, orga, teacher, student, exhibitor`. Rechte: `Permissions`-Klasse (Katalog, `requiredFor()`, `dependentsOf()`, `defaultsForRole()`); `$ctx->auth->can()` wertet direkt+Gruppen+Defaults aus.
- Schema-Änderungen: NEUE Datei `migrations/00N_*.sql` — NIE `001_init.sql` editieren (außer vor dem ersten Release nach Absprache; aktuell gilt: 001 gehört dem Kern, Module ergänzen eigene Dateien nur wenn zwingend nötig).

## QR & PDF (vendored Libs)

- QR: `require_once dirname(__DIR__, 2) . '/lib/qr.php';` → `qrSvg($data, $scale, $margin)` (SVG-String), `qrDrawFpdf($pdf, $data, $x, $y, $size)` (in FPDF zeichnen).
- PDF: `require_once dirname(__DIR__, 2) . '/lib/fpdf/fpdf.php';` → Klasse `FPDF`. Umlaute: `iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $text)` vor jedem Cell/Write (Helfer im Pdf-Service anlegen).
- Kamera-Scan: jsQR liegt lokal unter `/assets/js/vendor/jsqr.min.js` (global `jsQR`), Chart.js unter `/assets/js/vendor/chart.umd.min.js` (global `Chart`) — via `pageScripts: ['vendor/jsqr.min.js', 'eigenes.js']`.

## Verhaltens-Referenz Original

Verhalten im Zweifel im Original nachlesen (`../Berufsmesse-original/`): `functions.php` (Fachlogik), `pages/*.php`, `api/*.php`. Abweichungen vom Original, die GEWOLLT sind:
- Einschreibezeitraum/Messedatum aus `messe_editions` statt settings.
- Freie Slots über `is_managed`-Flag statt hartcodiert [2,4].
- E-Mail: NICHT einbauen; wo das Original In-App-Benachrichtigungen (`login_notifications`, `announcements`) nutzt, ebenso verfahren. Aussteller-Einladung erzeugt einen Link zum manuellen Verteilen (Anzeige im Admin-UI mit Kopier-Button).
- Guided Tours: NUR per Knopfdruck startbar, nie automatisch.
- Kein Easter Egg außer Retro (bereits umgesetzt).

## Seiten-Blöcke (Anordnen-Modus)

Jede inhaltliche Seite deklariert ihre Abschnitte als **Blöcke**, damit Schul-Admins sie pro Schule per Drag & Drop anordnen und ausblenden können (Button „🧩 Anordnen" in der Topbar, Modus `?anordnen=1`). Kern: `src/Core/PageBlocks.php`; Speicherung als Setting `page_layout:{pageKey}` je Schule.

**Pflicht-Pattern im Template** (Referenzen: `templates/pages/admin-dashboard/index.php`, `templates/pages/student/dashboard.php`):

```php
<?php foreach (page_blocks('seiten-key', [
    'block-a' => 'Menschlicher Blockname',
    'block-b' => 'Zweiter Abschnitt',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'block-a'): ?>
    ... kompletter Abschnitt (Card, Tabelle, Formular …) ...
<?php elseif ($blockKey === 'block-b'): ?>
    ...
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
```

Regeln:
- `pageKey` = URL-Segment der Seite in kebab-case, eindeutig (z. B. `admin-benutzer`, `meine-anmeldungen`, `portal-slots`, `global-schulen`). Block-Keys kebab-case, stabil (werden gespeichert!).
- Der `page-header` (Titel + page-actions) bleibt IMMER außerhalb der Blöcke. Modals (`<dialog>`) und `<datalist>` ebenfalls außerhalb (nach dem foreach), sonst wandern/verschwinden sie mit.
- 2–8 Blöcke pro Seite; ein Block = ein in sich geschlossener Abschnitt. Bedingte Abschnitte: Bedingung INNERHALB des Block-Zweigs lassen (leerer Block ist ok).
- Der Wrapper ist im Normalbetrieb `display: contents` — Grid-Layouts (`grid-2` als Kinder) funktionieren unverändert; nichts am CSS ändern.
- Ausnahmen (KEINE Blöcke): Auth-/Minimal-Seiten (Login, Setup …), Fehlerseite, `pages/customize/*` (Darstellung selbst), reine Druck-/PDF-Ausgaben.

## Deutsch & Stil

UI-Text durchgehend Deutsch (Du-Form für Schüler:innen, neutral in Admin-Bereichen). Code-Kommentare Deutsch, kurz, nur wo nötig. `declare(strict_types=1);` in jeder PHP-Datei. Typisierte Signaturen. Keine toten TODOs.
