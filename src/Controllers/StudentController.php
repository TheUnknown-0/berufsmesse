<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions;
use App\Services\Announcements;
use App\Services\HeldRegistrations;
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

        $announcements = (new Announcements($this->ctx->db))
            ->activeFor((int) $this->ctx->schoolId(), 'student');

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

    /**
     * GET /api/v1/{school}/schueler — Tagesplan, Anmeldungen und Messe-Eckdaten
     * für die App (dieselben Daten wie Übersicht, Tagesplan und Meine Anmeldungen).
     */
    public function apiPlan(array $params): array
    {
        $this->requireSchool($params['school']);
        $user = $this->requireStudentAccess();
        $edition = $this->ctx->requireEdition();
        $userId = (int) $user['id'];
        $editionId = (int) $edition['id'];

        $time = static fn (mixed $t): ?string => is_string($t) && $t !== '' ? substr($t, 0, 5) : null;
        $room = static fn (array $r): ?string => ($label = \App\Services\AttendanceService::roomLabel($r['room_number'] ?? null, $r['room_name'] ?? null)) !== '' ? $label : null;

        $timeline = array_map(static function (array $slot) use ($time, $room): array {
            $reg = $slot['registration'];
            $held = $slot['held'];

            return [
                'slot_number' => (int) $slot['slot_number'],
                'slot_name' => (string) ($slot['slot_name'] ?: ('Slot ' . $slot['slot_number'])),
                'start' => $time($slot['start_time']),
                'end' => $time($slot['end_time']),
                'kind' => $slot['kind'],
                'exhibitor' => $reg !== null ? (string) $reg['exhibitor_name'] : null,
                'description' => $reg !== null ? $reg['short_description'] : null,
                'room' => $reg !== null ? $room($reg) : null,
                'building' => $reg !== null ? $reg['building'] : null,
                'held_exhibitor' => $held !== null ? (string) ($held['exhibitor_name'] ?? '') : null,
            ];
        }, $this->timeline($userId, $editionId));

        $registrations = array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'exhibitor' => (string) $r['exhibitor_name'],
            'description' => $r['short_description'],
            'priority' => $r['priority'] !== null ? (int) $r['priority'] : null,
            'type' => (string) $r['registration_type'],
            'slot' => $r['timeslot_id'] !== null ? (string) ($r['slot_name'] ?: ('Slot ' . $r['slot_number'])) : null,
            'start' => $time($r['start_time']),
            'end' => $time($r['end_time']),
            'room' => $room($r),
        ], (new RegistrationController($this->ctx))->ownRegistrations($userId, $editionId));

        return [
            'success' => true,
            'edition' => [
                'name' => (string) $edition['name'],
                'event_date' => $edition['event_date'] ?? null,
                'days_until' => self::daysUntil($edition['event_date'] ?? null),
                'registration_open' => RegistrationController::isRegistrationOpen($edition),
                'registration_end' => $edition['registration_end'] ?? null,
                'max_registrations' => RegistrationController::maxRegistrations($edition),
            ],
            'timeline' => $timeline,
            'registrations' => $registrations,
            'checkin_enabled' => $this->ctx->settings->getBool('checkin_self_scan_enabled', $this->ctx->schoolId(), true),
        ];
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

        // Ruhende Buchungen (Aussteller hat abgesagt), damit der Slot nicht
        // kommentarlos leer erscheint.
        $heldBySlot = [];
        foreach ((new HeldRegistrations($this->ctx->db))->forUser($editionId, $userId) as $row) {
            if ($row['timeslot_id'] !== null) {
                $heldBySlot[(int) $row['timeslot_id']] = $row;
            }
        }

        $timeline = [];
        foreach ($slots as $slot) {
            $slotId = (int) $slot['id'];
            $slot['kind'] = (int) $slot['is_break'] === 1
                ? 'break'
                : ((int) $slot['is_managed'] === 1 ? 'managed' : 'free');
            $slot['registration'] = $bySlot[$slotId] ?? null;
            $slot['held'] = $slot['registration'] === null ? ($heldBySlot[$slotId] ?? null) : null;
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
