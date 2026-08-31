<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Context;

/**
 * Absage-Workflow für Aussteller.
 *
 * Kurzfristige Absagen (weniger als NOTICE_DAYS Tage vor dem Messetag)
 * müssen von der Schule bestätigt werden; bis dahin bleibt der Aussteller
 * aktiv. Alle anderen Absagen greifen sofort: Aussteller wird deaktiviert,
 * bestehende Anmeldungen werden gelöst und die betroffenen Schüler:innen
 * bekommen eine In-App-Benachrichtigung.
 */
final class CancellationService
{
    /** Frist in Tagen, ab der eine Absage bestätigt werden muss. */
    public const NOTICE_DAYS = 7;

    private Notifications $notifications;

    public function __construct(private readonly Context $ctx)
    {
        $this->notifications = new Notifications($ctx->db);
    }

    /** Tage bis zum Messetag (negativ = vorbei); null, wenn kein Datum hinterlegt ist. */
    public function daysUntilEvent(array $edition): ?int
    {
        $date = $edition['event_date'] ?? null;
        if (!is_string($date) || $date === '') {
            return null;
        }

        try {
            $event = new \DateTimeImmutable($date . ' 00:00:00');
        } catch (\Exception) {
            return null;
        }

        $today = new \DateTimeImmutable('today');
        $diff = $today->diff($event);

        return (int) $diff->days * ($diff->invert === 1 ? -1 : 1);
    }

    /** Muss die Absage von der Schule bestätigt werden? */
    public function requiresApproval(array $edition): bool
    {
        $days = $this->daysUntilEvent($edition);

        return $days !== null && $days < self::NOTICE_DAYS;
    }

    /** Offene Absage-Anfrage eines Ausstellers (oder null). */
    public function pendingRequest(int $exhibitorId): ?array
    {
        return $this->ctx->db->fetchOne(
            'SELECT * FROM cancellation_requests
             WHERE exhibitor_id = ? AND status = \'pending\'
             ORDER BY id DESC LIMIT 1',
            [$exhibitorId],
        );
    }

    /**
     * Legt eine bestätigungspflichtige Absage-Anfrage an und benachrichtigt
     * die Verwaltung der Schule. Gibt die ID der Anfrage zurück.
     */
    public function requestCancellation(array $exhibitor, int $schoolId, int $userId, string $reason): int
    {
        $existing = $this->pendingRequest((int) $exhibitor['id']);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $this->ctx->db->run(
            'INSERT INTO cancellation_requests (exhibitor_id, user_id, school_id, requested_by, reason, status)
             VALUES (?, ?, ?, \'exhibitor\', ?, \'pending\')',
            [(int) $exhibitor['id'], $userId, $schoolId, mb_substr($reason, 0, 500)],
        );
        $requestId = $this->ctx->db->lastInsertId();

        $url = $this->schoolUrl($schoolId, '/admin/aussteller-konten');
        $recipients = $this->ctx->db->fetchAll(
            'SELECT id FROM users
             WHERE (school_id = ? AND role IN (\'admin\', \'school_admin\'))
                OR (school_id IS NULL AND role = \'admin\')',
            [$schoolId],
        );

        $this->notifications->sendMany(
            array_map(static fn (array $r): int => (int) $r['id'], $recipients),
            $schoolId,
            'Absage-Anfrage: „' . $exhibitor['name'] . '" möchte kurzfristig absagen. Bitte prüfen und bestätigen.',
            'cancellation_request',
            $requestId,
            $url,
        );

        $this->ctx->audit->log(
            'Absage-Anfrage eingegangen',
            'warning',
            'Aussteller: ' . $exhibitor['name'] . ' — Begründung: ' . $reason,
            $schoolId,
        );

        return $requestId;
    }

    /**
     * Führt die Absage endgültig aus: Aussteller deaktivieren, Verknüpfungen
     * abschließen, Anmeldungen lösen, Schüler:innen benachrichtigen.
     *
     * @param 'cancelled_by_exhibitor'|'cancelled_by_school' $linkStatus
     * @return int Anzahl der betroffenen Schüler:innen
     */
    public function execute(
        array $exhibitor,
        array $edition,
        int $schoolId,
        string $reason,
        string $linkStatus = 'cancelled_by_exhibitor',
        ?int $confirmedBy = null,
    ): int {
        $exhibitorId = (int) $exhibitor['id'];
        $editionId = (int) $edition['id'];
        $linkStatus = $linkStatus === 'cancelled_by_school' ? 'cancelled_by_school' : 'cancelled_by_exhibitor';

        $affected = $this->ctx->db->transaction(function () use (
            $exhibitorId,
            $editionId,
            $reason,
            $linkStatus,
            $confirmedBy,
        ): array {
            $students = $this->ctx->db->fetchAll(
                'SELECT DISTINCT user_id FROM registrations WHERE exhibitor_id = ? AND edition_id = ?',
                [$exhibitorId, $editionId],
            );

            $this->ctx->db->run(
                'UPDATE exhibitors SET active = 0 WHERE id = ? AND edition_id = ?',
                [$exhibitorId, $editionId],
            );
            $this->ctx->db->run(
                'UPDATE exhibitor_users
                 SET status = ?, cancelled_at = NOW(), cancel_reason = ?
                 WHERE exhibitor_id = ? AND status = \'active\'',
                [$linkStatus, mb_substr($reason, 0, 500), $exhibitorId],
            );
            $this->ctx->db->run(
                'DELETE FROM registrations WHERE exhibitor_id = ? AND edition_id = ?',
                [$exhibitorId, $editionId],
            );
            $this->ctx->db->run(
                'UPDATE cancellation_requests
                 SET status = \'confirmed\', confirmed_at = NOW(), confirmed_by = ?
                 WHERE exhibitor_id = ? AND status = \'pending\'',
                [$confirmedBy, $exhibitorId],
            );

            return array_map(static fn (array $r): int => (int) $r['user_id'], $students);
        });

        $registrationOpen = $this->registrationOpen($edition);
        $message = 'Der Aussteller „' . $exhibitor['name'] . '" hat abgesagt. Deine Anmeldung wurde entfernt. '
            . ($registrationOpen
                ? 'Bitte wähle einen neuen Aussteller.'
                : 'Du wirst automatisch einem anderen Aussteller zugeteilt.');

        $this->notifications->sendMany(
            $affected,
            $schoolId,
            $message,
            'exhibitor_cancelled',
            $exhibitorId,
            $this->schoolUrl($schoolId, $registrationOpen ? '/einschreibung' : '/meine-anmeldungen'),
        );

        $this->ctx->audit->log(
            'Aussteller abgesagt',
            'warning',
            sprintf(
                'Aussteller: %s (ID %d) — %d Anmeldung(en) entfernt — Begründung: %s',
                (string) $exhibitor['name'],
                $exhibitorId,
                count($affected),
                $reason,
            ),
            $schoolId,
        );

        return count($affected);
    }

    /** Lehnt eine offene Absage-Anfrage ab und benachrichtigt den Antragsteller. */
    public function rejectRequest(array $request, array $exhibitor, int $schoolId, ?int $confirmedBy): void
    {
        $this->ctx->db->run(
            'UPDATE cancellation_requests
             SET status = \'rejected\', confirmed_at = NOW(), confirmed_by = ?
             WHERE id = ? AND school_id = ? AND status = \'pending\'',
            [$confirmedBy, (int) $request['id'], $schoolId],
        );

        if ($request['user_id'] !== null) {
            $this->notifications->send(
                (int) $request['user_id'],
                $schoolId,
                'Deine Absage für „' . $exhibitor['name'] . '" wurde abgelehnt. Bitte melde dich bei der Schule.',
                'info',
                (int) $exhibitor['id'],
                $this->schoolUrl($schoolId, '/portal'),
            );
        }

        $this->ctx->audit->log(
            'Absage-Anfrage abgelehnt',
            'warning',
            'Aussteller: ' . (string) $exhibitor['name'],
            $schoolId,
        );
    }

    /** Läuft die Einschreibung aktuell noch? */
    private function registrationOpen(array $edition): bool
    {
        $end = $edition['registration_end'] ?? null;
        if (!is_string($end) || $end === '') {
            return true;
        }

        try {
            return new \DateTimeImmutable($end) >= new \DateTimeImmutable('now');
        } catch (\Exception) {
            return true;
        }
    }

    /** Absolute URL innerhalb einer Schule (unabhängig vom aktuellen Kontext). */
    private function schoolUrl(int $schoolId, string $path): string
    {
        $slug = $this->ctx->db->fetchValue('SELECT slug FROM schools WHERE id = ?', [$schoolId]);

        return is_string($slug) ? $this->ctx->url('/' . $slug . $path) : $this->ctx->url('/');
    }
}
