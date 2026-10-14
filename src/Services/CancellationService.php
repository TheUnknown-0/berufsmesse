<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Context;

/**
 * Zentraler Absage-Ablauf für Aussteller.
 *
 * JEDE Absage läuft hier durch, egal wer sie auslöst: das Unternehmen im
 * Portal, die Schule (Knopf „Absage durch Schule“ oder Pipeline auf einen
 * unsichtbaren Status) oder das Ausfall-Management am Messetag. So hat eine
 * Absage überall dieselben Folgen:
 *  - Aussteller unsichtbar, Pipeline-Status passend gesetzt,
 *  - Buchungen der Schüler:innen werden zu ruhenden Buchungen
 *    (Services\HeldRegistrations); gelöscht wird erst, wenn die Schule neu
 *    zuteilt,
 *  - Verknüpfungen der Firmenkonten werden beendet,
 *  - Schüler:innen und Firmenkonten erfahren es in der App.
 *
 * restore() nimmt eine Absage zurück und holt die ruhenden Buchungen zurück.
 *
 * Kurzfristige Absagen aus dem Portal (weniger als NOTICE_DAYS Tage vor dem
 * Messetag) muss die Schule bestätigen; bis dahin bleibt alles, wie es ist.
 */
final class CancellationService
{
    /** Frist in Tagen, ab der eine Absage bestätigt werden muss. */
    public const NOTICE_DAYS = 7;

    private Notifications $notifications;
    private HeldRegistrations $held;

    public function __construct(private readonly Context $ctx)
    {
        $this->notifications = new Notifications($ctx->db);
        $this->held = new HeldRegistrations($ctx->db);
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
     * Führt die Absage aus (siehe Klassenkommentar).
     *
     * @param 'cancelled_by_exhibitor'|'cancelled_by_school'|'failover' $cause
     * @param string $pipelineStatus Zielstatus in der Pipeline (unsichtbare Stufe)
     * @param bool $notifyStudents false, wenn der Aufrufer selbst informiert (Ausfall mit Umbuchung)
     * @return int Anzahl der betroffenen Schüler:innen
     */
    public function execute(
        array $exhibitor,
        array $edition,
        int $schoolId,
        string $reason,
        string $cause = 'cancelled_by_exhibitor',
        ?int $actorId = null,
        string $pipelineStatus = 'cancelled',
        bool $notifyStudents = true,
    ): int {
        $exhibitorId = (int) $exhibitor['id'];
        $editionId = (int) $edition['id'];
        $cause = in_array($cause, HeldRegistrations::CAUSES, true) ? $cause : 'cancelled_by_school';
        $linkStatus = $cause === 'cancelled_by_exhibitor' ? 'cancelled_by_exhibitor' : 'cancelled_by_school';
        $pipelineStatus = in_array($pipelineStatus, ['lead', 'contacted', 'declined', 'cancelled'], true)
            ? $pipelineStatus
            : 'cancelled';

        [$affected, $accounts] = $this->ctx->db->transaction(function () use (
            $exhibitorId,
            $editionId,
            $reason,
            $cause,
            $linkStatus,
            $actorId,
            $pipelineStatus,
        ): array {
            $accounts = array_map(
                static fn (array $r): int => (int) $r['user_id'],
                $this->ctx->db->fetchAll(
                    'SELECT user_id FROM exhibitor_users WHERE exhibitor_id = ? AND status = \'active\'',
                    [$exhibitorId],
                ),
            );

            $students = $this->held->hold($editionId, $exhibitorId, $cause, $reason, $actorId);

            // Sichtbarkeit und Pipeline gehören zusammen, sonst stünde ein
            // abgesagter Aussteller in der Pipeline weiter auf „Zugesagt“.
            $this->ctx->db->run(
                'UPDATE exhibitors SET active = 0, pipeline_status = ? WHERE id = ? AND edition_id = ?',
                [$pipelineStatus, $exhibitorId, $editionId],
            );
            $this->ctx->db->run(
                'UPDATE exhibitor_users
                 SET status = ?, cancelled_at = NOW(), cancel_reason = ?
                 WHERE exhibitor_id = ? AND status = \'active\'',
                [$linkStatus, mb_substr($reason, 0, 500), $exhibitorId],
            );
            $this->ctx->db->run(
                'UPDATE cancellation_requests
                 SET status = \'confirmed\', confirmed_at = NOW(), confirmed_by = ?
                 WHERE exhibitor_id = ? AND status = \'pending\'',
                [$actorId, $exhibitorId],
            );

            return [$students, $accounts];
        });

        $name = (string) $exhibitor['name'];
        if ($notifyStudents) {
            $this->notifications->sendMany(
                $affected,
                $schoolId,
                ($cause === 'cancelled_by_exhibitor'
                    ? 'Der Aussteller „' . $name . '“ hat abgesagt.'
                    : 'Der Aussteller „' . $name . '“ nimmt nicht an der Messe teil.')
                . ' Deine Buchung dort ruht vorerst. Die Schule teilt dir einen neuen Termin zu;'
                . ' bitte sieh in deinem Tagesplan nach.',
                'exhibitor_cancelled',
                $exhibitorId,
                $this->schoolUrl($schoolId, '/tagesplan'),
            );
        }

        // Hat die Schule beendet, erfährt es auch das Unternehmen selbst.
        if ($cause !== 'cancelled_by_exhibitor') {
            $this->notifications->sendMany(
                $accounts,
                $schoolId,
                'Die Schule hat die Teilnahme von „' . $name . '“ beendet.'
                    . ($reason !== '' ? ' Begründung: ' . $reason : ''),
                'school_cancelled',
                $exhibitorId,
            );
        }

        $this->ctx->audit->log(
            'Aussteller abgesagt',
            'warning',
            sprintf(
                'Aussteller: %s (ID %d), ausgelöst durch: %s, %d Schüler:innen mit ruhender Buchung, Begründung: %s',
                $name,
                $exhibitorId,
                match ($cause) {
                    'cancelled_by_exhibitor' => 'Unternehmen',
                    'failover' => 'Ausfall am Messetag',
                    default => 'Schule',
                },
                count($affected),
                $reason,
            ),
            $schoolId,
        );

        return count($affected);
    }

    /**
     * Nimmt eine Absage zurück: Aussteller wieder sichtbar und „Zugesagt“,
     * ruhende Buchungen und beendete Firmenkonten-Verknüpfungen wieder aktiv.
     * Von Hand entfernte Verknüpfungen (removed_by_admin) bleiben entfernt.
     *
     * @return array{restored: int, blocked: int}
     */
    public function restore(array $exhibitor, array $edition, int $schoolId): array
    {
        $exhibitorId = (int) $exhibitor['id'];
        $editionId = (int) $edition['id'];

        [$result, $accounts] = $this->ctx->db->transaction(function () use ($exhibitorId, $editionId): array {
            $accounts = array_map(
                static fn (array $r): int => (int) $r['user_id'],
                $this->ctx->db->fetchAll(
                    'SELECT user_id FROM exhibitor_users
                     WHERE exhibitor_id = ? AND status IN (\'cancelled_by_exhibitor\', \'cancelled_by_school\')',
                    [$exhibitorId],
                ),
            );

            $this->ctx->db->run(
                'UPDATE exhibitors SET active = 1, pipeline_status = \'confirmed\' WHERE id = ? AND edition_id = ?',
                [$exhibitorId, $editionId],
            );
            $this->ctx->db->run(
                'UPDATE exhibitor_users
                 SET status = \'active\', cancelled_at = NULL, cancel_reason = NULL
                 WHERE exhibitor_id = ? AND status IN (\'cancelled_by_exhibitor\', \'cancelled_by_school\')',
                [$exhibitorId],
            );

            return [$this->held->restore($editionId, $exhibitorId), $accounts];
        });

        $name = (string) $exhibitor['name'];
        $this->notifications->sendMany(
            $result['restored'],
            $schoolId,
            'Gute Nachricht: „' . $name . '“ ist doch dabei. Deine Buchung dort gilt wieder.',
            'info',
            $exhibitorId,
            $this->schoolUrl($schoolId, '/tagesplan'),
        );
        $this->notifications->sendMany(
            $accounts,
            $schoolId,
            'Die Absage von „' . $name . '“ wurde zurückgenommen. Du hast wieder Zugriff im Portal.',
            'info',
            $exhibitorId,
            $this->schoolUrl($schoolId, '/portal'),
        );

        $this->ctx->audit->log(
            'Absage zurückgenommen',
            'warning',
            sprintf(
                'Aussteller: %s (ID %d), %d Schüler:innen wieder gebucht, %d Buchungen bleiben ruhend (Slot inzwischen belegt)',
                $name,
                $exhibitorId,
                count($result['restored']),
                $result['blocked'],
            ),
            $schoolId,
        );

        return ['restored' => count($result['restored']), 'blocked' => $result['blocked']];
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

    /** Absolute URL innerhalb einer Schule (unabhängig vom aktuellen Kontext). */
    private function schoolUrl(int $schoolId, string $path): string
    {
        $slug = $this->ctx->db->fetchValue('SELECT slug FROM schools WHERE id = ?', [$schoolId]);

        return is_string($slug) ? $this->ctx->url('/' . $slug . $path) : $this->ctx->url('/');
    }
}
