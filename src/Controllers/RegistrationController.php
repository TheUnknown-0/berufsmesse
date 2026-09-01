<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions;
use App\Services\Capacity;
use App\Services\Notifications;
use App\Services\Waitlist;
use DateTimeImmutable;

/**
 * Schüler-Einschreibung: Auswahl der Wunsch-Aussteller mit Priorität
 * sowie Übersicht und Abmeldung eigener Anmeldungen.
 *
 * Einschreibezeitraum, Messedatum und Maximalzahl kommen ausschließlich
 * aus der aktiven Edition (messe_editions) — nicht aus settings.
 */
final class RegistrationController extends Controller
{
    /** Obergrenze für die Anzahl wählbarer Prioritätsstufen (Schutz vor Fehlkonfiguration). */
    private const MAX_PRIORITIES = 10;

    // ---------- Einschreibung ----------

    /** GET /{school}/einschreibung */
    public function index(array $params): string
    {
        $this->requireSchool($params['school']);
        $user = $this->requireEnrolmentAccess();
        $edition = $this->ctx->requireEdition();

        $max = self::maxRegistrations($edition);
        $open = self::isRegistrationOpen($edition);
        $tester = $user['role'] !== 'student';

        $exhibitors = $this->ctx->db->fetchAll(
            'SELECT e.id, e.name, e.short_description, e.categories,
                    r.room_number, r.room_name
             FROM exhibitors e
             LEFT JOIN rooms r ON r.id = e.room_id
             WHERE e.edition_id = ? AND e.active = 1
             ORDER BY e.name',
            [(int) $edition['id']],
        );

        $own = $this->ownRegistrations((int) $user['id'], (int) $edition['id']);
        $selected = [];
        foreach ($own as $row) {
            $selected[(int) $row['exhibitor_id']] = $row['priority'] !== null ? (int) $row['priority'] : null;
        }

        return $this->render('pages/registration/index', [
            'title' => 'Einschreibung',
            'edition' => $edition,
            'exhibitors' => $exhibitors,
            'selected' => $selected,
            'own' => $own,
            'max' => $max,
            'open' => $open,
            'tester' => $tester,
            'pageScripts' => ['registration.js'],
        ]);
    }

    /** POST /{school}/einschreibung */
    public function store(array $params): string
    {
        $this->requireSchool($params['school']);
        $user = $this->requireEnrolmentAccess();
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();

        $editionId = (int) $edition['id'];
        $userId = (int) $user['id'];
        $back = $this->ctx->schoolUrl('/einschreibung');
        $max = self::maxRegistrations($edition);

        if (!self::isRegistrationOpen($edition) && $user['role'] === 'student') {
            $this->flash('error', 'Die Einschreibung ist aktuell nicht geöffnet.');
            $this->redirect($back);
        }

        // Eingabe: priority[<aussteller-id>] = '' | '1' | '2' | ...
        $raw = $_POST['priority'] ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }

        $selection = [];
        $usedPriorities = [];
        foreach ($raw as $exhibitorId => $priority) {
            $exhibitorId = (int) $exhibitorId;
            $priority = is_scalar($priority) ? trim((string) $priority) : '';
            if ($exhibitorId <= 0 || $priority === '' || !ctype_digit($priority)) {
                continue;
            }
            $priority = (int) $priority;
            if ($priority < 1 || $priority > $max) {
                $this->flash('error', 'Ungültige Priorität. Erlaubt sind 1 bis ' . $max . '.');
                $this->redirect($back);
            }
            if (isset($usedPriorities[$priority])) {
                $this->flash('error', 'Jede Priorität darf nur einmal vergeben werden.');
                $this->redirect($back);
            }
            $usedPriorities[$priority] = true;
            $selection[$exhibitorId] = $priority;
        }

        if (count($selection) > $max) {
            $this->flash('error', 'Du darfst höchstens ' . $max . ' Aussteller wählen.');
            $this->redirect($back);
        }

        // Nur aktive Aussteller der aktuellen Edition sind wählbar
        $valid = [];
        foreach ($this->ctx->db->fetchAll(
            'SELECT id FROM exhibitors WHERE edition_id = ? AND active = 1',
            [$editionId],
        ) as $row) {
            $valid[(int) $row['id']] = true;
        }
        foreach (array_keys($selection) as $exhibitorId) {
            if (!isset($valid[$exhibitorId])) {
                $this->flash('error', 'Mindestens ein gewählter Aussteller steht nicht (mehr) zur Verfügung.');
                $this->redirect($back);
            }
        }

        $existing = [];
        foreach ($this->ownRegistrations($userId, $editionId) as $row) {
            $existing[(int) $row['exhibitor_id']] = $row;
        }

        $added = 0;
        $changed = 0;
        $removed = 0;
        $locked = 0;

        try {
            $this->ctx->db->transaction(function () use (
                $selection, $existing, $valid, $userId, $editionId,
                &$added, &$changed, &$removed, &$locked
            ): void {
                // Abgewählte Wünsche entfernen — außer es wurde bereits zugeteilt.
                // Anmeldungen bei inzwischen deaktivierten Ausstellern stehen nicht
                // im Formular und bleiben deshalb unangetastet.
                foreach ($existing as $exhibitorId => $row) {
                    if (isset($selection[$exhibitorId]) || !isset($valid[$exhibitorId])) {
                        continue;
                    }
                    if ($row['timeslot_id'] !== null) {
                        $locked++;
                        continue;
                    }
                    $this->ctx->db->run(
                        'DELETE FROM registrations WHERE id = ? AND user_id = ? AND edition_id = ?',
                        [(int) $row['id'], $userId, $editionId],
                    );
                    $removed++;
                }

                foreach ($selection as $exhibitorId => $priority) {
                    if (isset($existing[$exhibitorId])) {
                        $current = $existing[$exhibitorId]['priority'];
                        if ($current !== null && (int) $current === $priority) {
                            continue;
                        }
                        $this->ctx->db->run(
                            'UPDATE registrations SET priority = ?
                             WHERE id = ? AND user_id = ? AND edition_id = ?',
                            [$priority, (int) $existing[$exhibitorId]['id'], $userId, $editionId],
                        );
                        $changed++;
                        continue;
                    }

                    // ON DUPLICATE KEY: respektiert UNIQUE (user, exhibitor) ohne harten Fehler
                    $this->ctx->db->run(
                        'INSERT INTO registrations
                            (edition_id, user_id, exhibitor_id, timeslot_id, registration_type, priority)
                         VALUES (?, ?, ?, NULL, \'manual\', ?)
                         ON DUPLICATE KEY UPDATE priority = VALUES(priority)',
                        [$editionId, $userId, $exhibitorId, $priority],
                    );
                    $added++;
                }
            });
        } catch (\PDOException) {
            $this->flash('error', 'Deine Auswahl konnte nicht gespeichert werden. Bitte versuche es erneut.');
            $this->redirect($back);
        }

        $this->ctx->audit->log(
            'Einschreibung gespeichert',
            'info',
            sprintf('Neu: %d, geändert: %d, entfernt: %d', $added, $changed, $removed),
            $this->ctx->schoolId(),
        );

        if ($locked > 0) {
            $this->flash('warning', $locked . ' Anmeldung(en) konnten nicht entfernt werden, weil dir dort bereits ein Zeitslot zugeteilt wurde.');
        }
        $this->flash('success', 'Deine Auswahl wurde gespeichert.');
        $this->redirect($this->ctx->schoolUrl('/meine-anmeldungen'));
    }

    // ---------- Eigene Anmeldungen ----------

    /** GET /{school}/meine-anmeldungen */
    public function mine(array $params): string
    {
        $this->requireSchool($params['school']);
        $user = $this->requireEnrolmentAccess();
        $edition = $this->ctx->requireEdition();

        return $this->render('pages/registration/mine', [
            'title' => 'Meine Anmeldungen',
            'edition' => $edition,
            'registrations' => $this->ownRegistrations((int) $user['id'], (int) $edition['id']),
            'max' => self::maxRegistrations($edition),
            'open' => self::isRegistrationOpen($edition),
        ]);
    }

    /** POST /{school}/meine-anmeldungen/abmelden */
    public function destroy(array $params): string
    {
        $this->requireSchool($params['school']);
        $user = $this->requireEnrolmentAccess();
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();

        $back = $this->ctx->schoolUrl('/meine-anmeldungen');
        $registrationId = (int) ($_POST['registration_id'] ?? 0);

        $row = $this->ctx->db->fetchOne(
            'SELECT r.*, e.name AS exhibitor_name
             FROM registrations r
             JOIN exhibitors e ON e.id = r.exhibitor_id
             WHERE r.id = ? AND r.user_id = ? AND r.edition_id = ?',
            [$registrationId, (int) $user['id'], (int) $edition['id']],
        );
        if ($row === null) {
            $this->flash('error', 'Diese Anmeldung wurde nicht gefunden.');
            $this->redirect($back);
        }

        // Abmelden geht, solange der Zeitraum läuft ODER noch nichts zugeteilt ist
        if (!self::isRegistrationOpen($edition) && $row['timeslot_id'] !== null) {
            $this->flash('error', 'Diese Anmeldung ist bereits zugeteilt und der Einschreibezeitraum ist beendet.');
            $this->redirect($back);
        }

        $freedSlot = $row['timeslot_id'] !== null ? (int) $row['timeslot_id'] : null;

        $this->ctx->db->run(
            'DELETE FROM registrations WHERE id = ? AND user_id = ? AND edition_id = ?',
            [$registrationId, (int) $user['id'], (int) $edition['id']],
        );

        // War der Platz zugeteilt, rückt die/der nächste Wartende nach.
        if ($freedSlot !== null) {
            (new Waitlist(
                $this->ctx->db,
                new Capacity($this->ctx->db),
                new Notifications($this->ctx->db),
            ))->promote(
                (int) $edition['id'],
                (int) $row['exhibitor_id'],
                $freedSlot,
                $this->ctx->schoolId(),
            );
        }

        $this->ctx->audit->log(
            'Anmeldung abgemeldet',
            'info',
            'Aussteller: ' . (string) $row['exhibitor_name'],
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Du hast dich abgemeldet.');
        $this->redirect($back);
    }

    // ---------- Gemeinsame Helfer (auch von StudentController genutzt) ----------

    /** Läuft der Einschreibezeitraum der Edition gerade? */
    public static function isRegistrationOpen(array $edition, ?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable();

        $start = $edition['registration_start'] ?? null;
        if (is_string($start) && $start !== '' && $now < new DateTimeImmutable($start)) {
            return false;
        }

        $end = $edition['registration_end'] ?? null;
        if (is_string($end) && $end !== '' && $now > new DateTimeImmutable($end)) {
            return false;
        }

        return true;
    }

    /** Maximale Anzahl wählbarer Aussteller laut Edition. */
    public static function maxRegistrations(array $edition): int
    {
        $max = (int) ($edition['max_registrations_per_student'] ?? 3);

        return max(1, min($max, self::MAX_PRIORITIES));
    }

    /**
     * Anmeldungen eines Schülers inkl. Aussteller-, Raum- und Slot-Angaben.
     *
     * @return list<array<string, mixed>>
     */
    private function ownRegistrations(int $userId, int $editionId): array
    {
        return $this->ctx->db->fetchAll(
            'SELECT r.id, r.exhibitor_id, r.timeslot_id, r.priority, r.registration_type, r.registered_at,
                    e.name AS exhibitor_name, e.short_description,
                    rm.room_number, rm.room_name, rm.building,
                    t.slot_number, t.slot_name, t.start_time, t.end_time
             FROM registrations r
             JOIN exhibitors e ON e.id = r.exhibitor_id
             LEFT JOIN rooms rm ON rm.id = e.room_id
             LEFT JOIN timeslots t ON t.id = r.timeslot_id
             WHERE r.user_id = ? AND r.edition_id = ?
             ORDER BY t.slot_number IS NULL, t.slot_number, r.priority IS NULL, r.priority, e.name',
            [$userId, $editionId],
        );
    }

    /**
     * Zugriff auf den Einschreibebereich: Schüler:innen — und testweise
     * Admins/Schul-Admins mit dem Recht, Anmeldungen zu erstellen.
     *
     * @return array<string, mixed>
     */
    private function requireEnrolmentAccess(): array
    {
        $user = $this->requireLogin();

        if ($user['role'] === 'student') {
            // Konten früherer Editionen dürfen sich nicht in die laufende
            // Messe einschreiben: Auffüll-Lauf und Auswertung filtern auf die
            // Edition des Kontos, solche Anmeldungen tauchten dort nie auf und
            // führten zu Zahlen, die nicht zusammenpassen.
            $edition = $this->ctx->requireEdition();
            $userEdition = $user['edition_id'] !== null ? (int) $user['edition_id'] : null;
            if ($userEdition !== null && $userEdition !== (int) $edition['id']) {
                throw new HttpException(
                    403,
                    'Dein Zugang gehört zu einer früheren Messe. Bitte wende dich an die Organisation.',
                );
            }

            return $user;
        }
        if (in_array($user['role'], ['admin', 'school_admin'], true)
            && $this->ctx->auth->can(Permissions::ANMELDUNGEN_ERSTELLEN, $this->ctx->schoolId())) {
            return $user;
        }

        throw new HttpException(403, 'Dieser Bereich ist für Schülerinnen und Schüler.');
    }
}
