<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions;
use DateTimeImmutable;

/**
 * Schüler-Bereich: Übersicht (Dashboard), Tagesplan und Druckansicht.
 *
 * Der Tagesablauf entsteht immer aus allen timeslots der Edition; die Art
 * eines Slots ergibt sich ausschließlich aus is_break / is_managed.
 */
final class StudentController extends Controller
{
    /** GET /{school}/uebersicht */
    public function dashboard(array $params): string
    {
        $this->requireSchool($params['school']);
        $user = $this->requireStudentAccess();
        $edition = $this->ctx->requireEdition();

        $userId = (int) $user['id'];
        $editionId = (int) $edition['id'];

        $timeline = $this->timeline($userId, $editionId);
        $max = RegistrationController::maxRegistrations($edition);

        $chosen = (int) $this->ctx->db->fetchValue(
            'SELECT COUNT(*) FROM registrations WHERE user_id = ? AND edition_id = ?',
            [$userId, $editionId],
        );
        $assigned = (int) $this->ctx->db->fetchValue(
            'SELECT COUNT(*) FROM registrations r
             JOIN timeslots t ON t.id = r.timeslot_id
             WHERE r.user_id = ? AND r.edition_id = ? AND t.is_managed = 1 AND t.is_break = 0',
            [$userId, $editionId],
        );
        $managedCount = (int) $this->ctx->db->fetchValue(
            'SELECT COUNT(*) FROM timeslots WHERE edition_id = ? AND is_managed = 1 AND is_break = 0',
            [$editionId],
        );

        $announcements = $this->ctx->db->fetchAll(
            'SELECT id, title, body, type FROM announcements
             WHERE school_id = ? AND is_active = 1
               AND target_role IN (\'all\', \'student\')
               AND (expires_at IS NULL OR expires_at > NOW())
             ORDER BY created_at DESC',
            [(int) $this->ctx->schoolId()],
        );

        return $this->render('pages/student/dashboard', [
            'title' => 'Übersicht',
            'edition' => $edition,
            'student' => $user,
            'timeline' => $timeline,
            'chosen' => $chosen,
            'assigned' => $assigned,
            'managedCount' => $managedCount,
            'max' => $max,
            'open' => RegistrationController::isRegistrationOpen($edition),
            'daysUntil' => self::daysUntil($edition['event_date'] ?? null),
            'announcements' => $announcements,
        ]);
    }

    /** GET /{school}/tagesplan */
    public function schedule(array $params): string
    {
        $this->requireSchool($params['school']);
        $user = $this->requireStudentAccess();
        $edition = $this->ctx->requireEdition();

        return $this->render('pages/student/schedule', [
            'title' => 'Tagesplan',
            'edition' => $edition,
            'timeline' => $this->timeline((int) $user['id'], (int) $edition['id']),
        ]);
    }

    /** GET /{school}/drucken */
    public function print(array $params): string
    {
        $this->requireSchool($params['school']);
        $user = $this->requireStudentAccess();
        $edition = $this->ctx->requireEdition();

        return $this->render('pages/student/print', [
            'title' => 'Plan drucken',
            'edition' => $edition,
            'student' => $user,
            'timeline' => $this->timeline((int) $user['id'], (int) $edition['id']),
            'pageScripts' => ['registration.js'],
        ]);
    }

    // ---------- Helfer ----------

    /**
     * Tagesablauf: alle Zeitslots der Edition inkl. eigener Zuteilung.
     *
     * @return list<array<string, mixed>> Slot-Daten + 'kind' (break|managed|free)
     *                                    + 'registration' (oder null)
     */
    private function timeline(int $userId, int $editionId): array
    {
        $slots = $this->ctx->db->fetchAll(
            'SELECT id, slot_number, slot_name, start_time, end_time, is_managed, is_break
             FROM timeslots WHERE edition_id = ?
             ORDER BY start_time, slot_number',
            [$editionId],
        );

        $bySlot = [];
        $rows = $this->ctx->db->fetchAll(
            'SELECT r.timeslot_id, r.registration_type, r.priority,
                    e.name AS exhibitor_name, e.short_description,
                    rm.room_number, rm.room_name, rm.building, rm.floor
             FROM registrations r
             JOIN exhibitors e ON e.id = r.exhibitor_id
             LEFT JOIN rooms rm ON rm.id = e.room_id
             WHERE r.user_id = ? AND r.edition_id = ? AND r.timeslot_id IS NOT NULL',
            [$userId, $editionId],
        );
        foreach ($rows as $row) {
            $bySlot[(int) $row['timeslot_id']] = $row;
        }

        $timeline = [];
        foreach ($slots as $slot) {
            $slotId = (int) $slot['id'];
            $slot['kind'] = (int) $slot['is_break'] === 1
                ? 'break'
                : ((int) $slot['is_managed'] === 1 ? 'managed' : 'free');
            $slot['registration'] = $bySlot[$slotId] ?? null;
            $timeline[] = $slot;
        }

        return $timeline;
    }

    /** Tage bis zum Messetag; null wenn kein Datum gesetzt. */
    public static function daysUntil(mixed $eventDate): ?int
    {
        if (!is_string($eventDate) || $eventDate === '') {
            return null;
        }

        try {
            $target = new DateTimeImmutable($eventDate);
        } catch (\Exception) {
            return null;
        }

        $today = new DateTimeImmutable('today');

        return (int) $today->diff($target->setTime(0, 0))->format('%r%a');
    }

    /**
     * Schüler-Seiten: Schüler:innen sowie testende Admins/Schul-Admins.
     *
     * @return array<string, mixed>
     */
    private function requireStudentAccess(): array
    {
        $user = $this->requireLogin();

        if ($user['role'] === 'student') {
            return $user;
        }
        if (in_array($user['role'], ['admin', 'school_admin'], true)
            && $this->ctx->auth->can(Permissions::ANMELDUNGEN_SEHEN, $this->ctx->schoolId())) {
            return $user;
        }

        throw new HttpException(403, 'Dieser Bereich ist für Schülerinnen und Schüler.');
    }
}
