<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions;
use DateTimeImmutable;

/**
 * Einstellungen der Schule/Messe in fünf Bereichen:
 * Messe (Edition), Zeitslots, Zugang, QR/Check-in und Wartung.
 *
 * Einschreibezeitraum, Messedatum und Anmelde-Maximum werden ausschließlich
 * auf messe_editions gespeichert; alle übrigen Schalter in settings.
 * Das Seitenpasswort ist GLOBAL (school_id NULL) und nur für Rolle admin.
 */
final class SettingsController extends Controller
{
    private const TABS = ['messe', 'zeitslots', 'zugang', 'qr', 'wartung'];

    /** GET /{school}/admin/einstellungen */
    public function index(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::EINSTELLUNGEN_SEHEN);
        $edition = $this->ctx->requireEdition();

        $editionId = (int) $edition['id'];
        $schoolId = (int) $this->ctx->schoolId();

        $canMaintain = in_array((string) $this->ctx->auth->role(), ['admin', 'school_admin'], true);

        $tab = (string) ($_GET['tab'] ?? 'messe');
        if (!in_array($tab, self::TABS, true) || ($tab === 'wartung' && !$canMaintain)) {
            $tab = 'messe';
        }

        $timeslots = $this->ctx->db->fetchAll(
            'SELECT t.id, t.slot_number, t.slot_name, t.start_time, t.end_time, t.is_managed, t.is_break,
                    (SELECT COUNT(*) FROM registrations r WHERE r.timeslot_id = t.id) AS anmeldungen,
                    (SELECT COUNT(*) FROM attendance a WHERE a.timeslot_id = t.id) AS anwesenheiten
             FROM timeslots t
             WHERE t.edition_id = ?
             ORDER BY t.slot_number',
            [$editionId],
        );

        $settings = [
            'auto_close_registration' => $this->ctx->settings->getBool('auto_close_registration', $schoolId),
            'registration_page_enabled' => $this->ctx->settings->getBool('registration_page_enabled', $schoolId),
            'checkin_self_scan_enabled' => $this->ctx->settings->getBool('checkin_self_scan_enabled', $schoolId, true),
            'checkin_teacher_scan_enabled' => $this->ctx->settings->getBool('checkin_teacher_scan_enabled', $schoolId, true),
            'qr_validity_enabled' => $this->ctx->settings->getBool('qr_validity_enabled', $schoolId),
            'qr_validity_before' => $this->ctx->settings->getInt('qr_validity_before', $schoolId, 10),
            'qr_validity_after' => $this->ctx->settings->getInt('qr_validity_after', $schoolId, 15),
            'qr_validity_teacher_enabled' => $this->ctx->settings->getBool('qr_validity_teacher_enabled', $schoolId),
            'qr_validity_teacher_before' => $this->ctx->settings->getInt('qr_validity_teacher_before', $schoolId, 20),
            'qr_validity_teacher_after' => $this->ctx->settings->getInt('qr_validity_teacher_after', $schoolId, 30),
            'site_password_enabled' => $this->ctx->settings->getBool('site_password_enabled'),
            'site_password_set' => $this->ctx->settings->get('site_password') !== null,
        ];

        return $this->render('pages/settings/index', [
            'title' => 'Einstellungen',
            'tab' => $tab,
            'edition' => $edition,
            'timeslots' => $timeslots,
            'settings' => $settings,
            'canEdit' => $this->ctx->auth->can(Permissions::EINSTELLUNGEN_BEARBEITEN, $schoolId),
            'isGlobalAdmin' => $this->ctx->auth->isAdmin(),
            'canMaintain' => $canMaintain,
            'attendanceCount' => (int) $this->ctx->db->fetchValue(
                'SELECT COUNT(*) FROM attendance WHERE edition_id = ?',
                [$editionId],
            ),
            'tokenCount' => (int) $this->ctx->db->fetchValue(
                'SELECT COUNT(*) FROM student_qr_tokens WHERE edition_id = ?',
                [$editionId],
            ),
            'assignedCount' => (int) $this->ctx->db->fetchValue(
                'SELECT COUNT(*) FROM registrations WHERE edition_id = ? AND timeslot_id IS NOT NULL',
                [$editionId],
            ),
            'minPasswordLength' => AuthController::MIN_PASSWORD_LENGTH,
        ]);
    }

    // ---------- Tab 1: Messe ----------

    /** POST /{school}/admin/einstellungen/messe */
    public function saveEdition(array $params): string
    {
        $edition = $this->beginWrite($params);
        $back = $this->tabUrl('messe');

        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            $this->flash('error', 'Bitte gib einen Namen für die Messe an.');
            $this->redirect($back);
        }

        $start = $this->parseDateTime($_POST['registration_start'] ?? '', 'Beginn des Einschreibezeitraums', $back);
        $end = $this->parseDateTime($_POST['registration_end'] ?? '', 'Ende des Einschreibezeitraums', $back);
        $eventDate = $this->parseDate($_POST['event_date'] ?? '', 'Datum der Messe', $back);

        if ($start !== null && $end !== null && $start > $end) {
            $this->flash('error', 'Das Ende des Einschreibezeitraums muss nach dem Beginn liegen.');
            $this->redirect($back);
        }

        $max = (int) ($_POST['max_registrations_per_student'] ?? 3);
        $max = max(1, min(10, $max));

        $this->ctx->db->run(
            'UPDATE messe_editions
             SET name = ?, registration_start = ?, registration_end = ?, event_date = ?,
                 max_registrations_per_student = ?
             WHERE id = ? AND school_id = ?',
            [$name, $start, $end, $eventDate, $max, (int) $edition['id'], (int) $this->ctx->schoolId()],
        );

        $this->ctx->settings->set(
            'auto_close_registration',
            isset($_POST['auto_close_registration']) ? '1' : '0',
            $this->ctx->schoolId(),
        );

        // Bei geändertem Messetag die Ablaufzeiten vorhandener QR-Tokens nachziehen
        // (gedruckte Codes bleiben gültig, nur das Zeitfenster wandert mit).
        if (($edition['event_date'] ?? null) !== $eventDate) {
            $qr = new \App\Services\QrService($this->ctx->db, $this->ctx->settings);
            $qr->refreshExpirations((int) $edition['id']);
        }

        $this->ctx->audit->log(
            'Messe-Einstellungen geändert',
            'info',
            sprintf('Zeitraum: %s bis %s, Messetag: %s, max. %d Anmeldungen',
                $start ?? '—', $end ?? '—', $eventDate ?? '—', $max),
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Die Messe-Einstellungen wurden gespeichert.');
        $this->redirect($back);
    }

    // ---------- Tab 2: Zeitslots ----------

    /** POST /{school}/admin/einstellungen/zeitslots — anlegen oder ändern. */
    public function saveTimeslot(array $params): string
    {
        $edition = $this->beginWrite($params);
        $editionId = (int) $edition['id'];
        $back = $this->tabUrl('zeitslots');

        $slotId = (int) ($_POST['slot_id'] ?? 0);
        $slotNumber = (int) ($_POST['slot_number'] ?? 0);
        $slotName = trim((string) ($_POST['slot_name'] ?? ''));
        $startTime = $this->parseTime($_POST['start_time'] ?? '', 'Startzeit', $back);
        $endTime = $this->parseTime($_POST['end_time'] ?? '', 'Endzeit', $back);
        $isManaged = isset($_POST['is_managed']) ? 1 : 0;
        $isBreak = isset($_POST['is_break']) ? 1 : 0;

        if ($slotNumber < 1 || $slotNumber > 255) {
            $this->flash('error', 'Die Slot-Nummer muss zwischen 1 und 255 liegen.');
            $this->redirect($back);
        }
        if ($startTime === null || $endTime === null) {
            $this->flash('error', 'Bitte gib Start- und Endzeit an.');
            $this->redirect($back);
        }
        if ($startTime >= $endTime) {
            $this->flash('error', 'Die Endzeit muss nach der Startzeit liegen.');
            $this->redirect($back);
        }
        // Eine Pause ist nie ein fester Zuteilungsslot
        if ($isBreak === 1) {
            $isManaged = 0;
        }

        $conflict = $this->ctx->db->fetchValue(
            'SELECT 1 FROM timeslots WHERE edition_id = ? AND slot_number = ? AND id <> ?',
            [$editionId, $slotNumber, $slotId],
        );
        if ($conflict !== null) {
            $this->flash('error', 'Es gibt bereits einen Zeitslot mit dieser Nummer.');
            $this->redirect($back);
        }

        if ($slotId > 0) {
            $existing = $this->ctx->db->fetchOne(
                'SELECT t.*, (SELECT COUNT(*) FROM registrations r WHERE r.timeslot_id = t.id) AS anmeldungen
                 FROM timeslots t WHERE t.id = ? AND t.edition_id = ?',
                [$slotId, $editionId],
            );
            if ($existing === null) {
                $this->flash('error', 'Dieser Zeitslot wurde nicht gefunden.');
                $this->redirect($back);
            }

            $this->ctx->db->run(
                'UPDATE timeslots
                 SET slot_number = ?, slot_name = ?, start_time = ?, end_time = ?, is_managed = ?, is_break = ?
                 WHERE id = ? AND edition_id = ?',
                [
                    $slotNumber,
                    $slotName !== '' ? $slotName : null,
                    $startTime, $endTime, $isManaged, $isBreak,
                    $slotId, $editionId,
                ],
            );

            if ((int) $existing['is_managed'] !== $isManaged && (int) $existing['anmeldungen'] > 0) {
                $this->flash(
                    'warning',
                    'Achtung: Für diesen Slot bestehen bereits ' . (int) $existing['anmeldungen']
                    . ' Zuteilungen. Prüfe die Zuteilung, ggf. neu ausführen.',
                );
            }
            $this->ctx->audit->log(
                'Zeitslot geändert',
                'info',
                sprintf('Slot %d (%s), fest: %d, Pause: %d', $slotNumber, $slotName !== '' ? $slotName : '—', $isManaged, $isBreak),
                $this->ctx->schoolId(),
            );
            $this->flash('success', 'Der Zeitslot wurde gespeichert.');
            $this->redirect($back);
        }

        $this->ctx->db->run(
            'INSERT INTO timeslots (edition_id, slot_number, slot_name, start_time, end_time, is_managed, is_break)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$editionId, $slotNumber, $slotName !== '' ? $slotName : null, $startTime, $endTime, $isManaged, $isBreak],
        );
        $this->ctx->audit->log(
            'Zeitslot angelegt',
            'info',
            sprintf('Slot %d (%s) %s–%s', $slotNumber, $slotName !== '' ? $slotName : '—', $startTime, $endTime),
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Der Zeitslot wurde angelegt.');
        $this->redirect($back);
    }

    /** POST /{school}/admin/einstellungen/zeitslots/loeschen */
    public function deleteTimeslot(array $params): string
    {
        $edition = $this->beginWrite($params);
        $editionId = (int) $edition['id'];
        $back = $this->tabUrl('zeitslots');

        $slotId = (int) ($_POST['slot_id'] ?? 0);
        $slot = $this->ctx->db->fetchOne(
            'SELECT id, slot_number, slot_name FROM timeslots WHERE id = ? AND edition_id = ?',
            [$slotId, $editionId],
        );
        if ($slot === null) {
            $this->flash('error', 'Dieser Zeitslot wurde nicht gefunden.');
            $this->redirect($back);
        }

        $used = (int) $this->ctx->db->fetchValue(
            'SELECT (SELECT COUNT(*) FROM registrations WHERE timeslot_id = ?)
                  + (SELECT COUNT(*) FROM attendance WHERE timeslot_id = ?)',
            [$slotId, $slotId],
        );
        if ($used > 0) {
            $this->flash('error', 'Dieser Zeitslot kann nicht gelöscht werden, weil bereits Zuteilungen oder Anwesenheiten daran hängen.');
            $this->redirect($back);
        }

        $this->ctx->db->run('DELETE FROM timeslots WHERE id = ? AND edition_id = ?', [$slotId, $editionId]);
        $this->ctx->audit->log(
            'Zeitslot gelöscht',
            'warning',
            sprintf('Slot %d (%s)', (int) $slot['slot_number'], (string) ($slot['slot_name'] ?? '—')),
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Der Zeitslot wurde gelöscht.');
        $this->redirect($back);
    }

    // ---------- Tab 3: Zugang ----------

    /** POST /{school}/admin/einstellungen/zugang */
    public function saveAccess(array $params): string
    {
        $this->beginWrite($params);
        $back = $this->tabUrl('zugang');
        $schoolId = $this->ctx->schoolId();

        $this->ctx->settings->set(
            'registration_page_enabled',
            isset($_POST['registration_page_enabled']) ? '1' : '0',
            $schoolId,
        );

        // Seitenpasswort ist global und ausschließlich für Rolle admin
        if ($this->ctx->auth->isAdmin()) {
            $newPassword = (string) ($_POST['site_password'] ?? '');
            if ($newPassword !== '') {
                if (mb_strlen($newPassword) < AuthController::MIN_PASSWORD_LENGTH) {
                    $this->flash('error', 'Das Seitenpasswort muss mindestens '
                        . AuthController::MIN_PASSWORD_LENGTH . ' Zeichen lang sein.');
                    $this->redirect($back);
                }
                $this->ctx->settings->set('site_password', password_hash($newPassword, PASSWORD_BCRYPT), null);
                $this->ctx->audit->log('Seitenpasswort geändert', 'warning', null, null);
            }

            $enabled = isset($_POST['site_password_enabled']);
            if ($enabled && $this->ctx->settings->get('site_password') === null) {
                $this->flash('error', 'Bitte setze zuerst ein Seitenpasswort, bevor du den Schutz aktivierst.');
                $this->redirect($back);
            }
            $this->ctx->settings->set('site_password_enabled', $enabled ? '1' : '0', null);
        }

        $this->ctx->audit->log('Zugangs-Einstellungen geändert', 'info', null, $schoolId);
        $this->flash('success', 'Die Zugangs-Einstellungen wurden gespeichert.');
        $this->redirect($back);
    }

    // ---------- Tab 4: QR & Check-in ----------

    /** POST /{school}/admin/einstellungen/qr */
    public function saveQr(array $params): string
    {
        $this->beginWrite($params);
        $back = $this->tabUrl('qr');
        $schoolId = $this->ctx->schoolId();

        $flags = [
            'checkin_self_scan_enabled',
            'checkin_teacher_scan_enabled',
            'qr_validity_enabled',
            'qr_validity_teacher_enabled',
        ];
        foreach ($flags as $key) {
            $this->ctx->settings->set($key, isset($_POST[$key]) ? '1' : '0', $schoolId);
        }

        $minutes = [
            'qr_validity_before' => 10,
            'qr_validity_after' => 15,
            'qr_validity_teacher_before' => 20,
            'qr_validity_teacher_after' => 30,
        ];
        foreach ($minutes as $key => $default) {
            $raw = trim((string) ($_POST[$key] ?? ''));
            $value = $raw === '' || !ctype_digit($raw) ? $default : (int) $raw;
            $this->ctx->settings->set($key, (string) max(0, min(600, $value)), $schoolId);
        }

        $this->ctx->audit->log('QR-/Check-in-Einstellungen geändert', 'info', null, $schoolId);
        $this->flash('success', 'Die QR- und Check-in-Einstellungen wurden gespeichert.');
        $this->redirect($back);
    }

    // ---------- Tab 5: Wartung ----------

    /** POST /{school}/admin/einstellungen/wartung */
    public function maintenance(array $params): string
    {
        $edition = $this->beginWrite($params);
        if (!in_array((string) $this->ctx->auth->role(), ['admin', 'school_admin'], true)) {
            throw new HttpException(403, 'Wartungsaktionen sind Administrator:innen vorbehalten.');
        }

        $editionId = (int) $edition['id'];
        $back = $this->tabUrl('wartung');
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'reset_attendance') {
            $count = $this->ctx->db->run(
                'DELETE FROM attendance WHERE edition_id = ?',
                [$editionId],
            )->rowCount();
            $this->ctx->audit->log(
                'Anwesenheit zurückgesetzt',
                'warning',
                $count . ' Einträge gelöscht',
                $this->ctx->schoolId(),
            );
            $this->flash('success', $count . ' Anwesenheits-Einträge wurden gelöscht.');
            $this->redirect($back);
        }

        if ($action === 'regenerate_tokens') {
            $count = $this->ctx->db->run(
                'DELETE FROM student_qr_tokens WHERE edition_id = ?',
                [$editionId],
            )->rowCount();
            $this->ctx->audit->log(
                'Schüler-QR-Tokens neu generiert',
                'warning',
                $count . ' Tokens verworfen',
                $this->ctx->schoolId(),
            );
            $this->flash('success', $count . ' QR-Tokens wurden verworfen und werden bei Bedarf neu erzeugt.');
            $this->redirect($back);
        }

        $this->flash('error', 'Unbekannte Wartungsaktion.');
        $this->redirect($back);
    }

    // ---------- Helfer ----------

    /**
     * Gemeinsame Guards aller Schreibaktionen.
     *
     * @return array<string, mixed> Aktive Edition.
     */
    private function beginWrite(array $params): array
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::EINSTELLUNGEN_BEARBEITEN);
        $this->requireCsrf();

        return $this->ctx->requireEdition();
    }

    private function tabUrl(string $tab): string
    {
        return $this->ctx->schoolUrl('/admin/einstellungen?tab=' . $tab);
    }

    /** datetime-local → 'Y-m-d H:i:s'; leer → null. */
    private function parseDateTime(mixed $raw, string $label, string $back): ?string
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }

        $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value);
        if ($dt === false) {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $value);
        }
        if ($dt === false) {
            $this->flash('error', 'Ungültige Eingabe: ' . $label . '.');
            $this->redirect($back);
        }

        return $dt->format('Y-m-d H:i:s');
    }

    /** date → 'Y-m-d'; leer → null. */
    private function parseDate(mixed $raw, string $label, string $back): ?string
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }

        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($dt === false) {
            $this->flash('error', 'Ungültige Eingabe: ' . $label . '.');
            $this->redirect($back);
        }

        return $dt->format('Y-m-d');
    }

    /** time → 'H:i:s'; leer/ungültig → null. */
    private function parseTime(mixed $raw, string $label, string $back): ?string
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $value) !== 1) {
            $this->flash('error', 'Ungültige Eingabe: ' . $label . '.');
            $this->redirect($back);
        }

        return substr($value, 0, 5) . ':00';
    }
}
