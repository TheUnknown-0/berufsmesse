<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Services\Audit;
use App\Services\AttendanceService;
use App\Services\QrService;

/**
 * Selbst-Check-in der Schüler:innen: Kamera-Scanner + manuelle Token-Eingabe.
 */
final class CheckinController extends Controller
{
    /** GET /{school}/checkin */
    public function index(array $params): string
    {
        $this->requireSchool($params['school']);
        $user = $this->requireLogin();
        $edition = $this->ctx->requireEdition();

        // Seite: Schüler:innen — Schul-Admins zusätzlich zur Vorschau/Anordnung
        // (der Check-in-API-Endpunkt selbst bleibt Schüler:innen vorbehalten).
        if (($user['role'] ?? '') !== 'student' && !\App\Core\PageBlocks::adminPreviewAllowed()) {
            throw new HttpException(403, 'Der Selbst-Check-in steht nur Schüler:innen offen.');
        }

        $enabled = $this->ctx->settings->getBool('checkin_self_scan_enabled', $this->ctx->schoolId(), true);

        $today = $this->ctx->db->fetchAll(
            'SELECT t.slot_name, t.start_time, t.end_time, t.is_managed,
                    e.name AS exhibitor_name, r.room_number, r.room_name,
                    a.id AS attendance_id, a.checked_in_at
             FROM registrations reg
             JOIN exhibitors e ON e.id = reg.exhibitor_id
             JOIN timeslots t ON t.id = reg.timeslot_id
             LEFT JOIN rooms r ON r.id = e.room_id
             LEFT JOIN attendance a ON a.user_id = reg.user_id
                   AND a.exhibitor_id = reg.exhibitor_id
                   AND a.timeslot_id = reg.timeslot_id
                   AND a.edition_id = reg.edition_id
             WHERE reg.user_id = ? AND reg.edition_id = ? AND reg.timeslot_id IS NOT NULL
             ORDER BY t.start_time, t.slot_number',
            [(int) $user['id'], (int) $edition['id']],
        );

        return $this->render('pages/checkin/index', [
            'title' => 'Check-in',
            'enabled' => $enabled,
            'edition' => $edition,
            'plan' => $today,
            'prefillToken' => QrService::extractToken((string) ($_GET['token'] ?? '')),
            'pageScripts' => ['vendor/jsqr.min.js', 'qr-camera.js', 'checkin.js'],
        ]);
    }

    /** POST /{school}/api/checkin */
    public function apiCheckin(array $params): array
    {
        $this->requireSchool($params['school']);
        $user = $this->requireLogin();
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();
        $editionId = (int) $edition['id'];

        if (($user['role'] ?? '') !== 'student') {
            return $this->jsonError('Der Selbst-Check-in steht nur Schüler:innen offen.', 403);
        }
        if (!$this->ctx->settings->getBool('checkin_self_scan_enabled', $this->ctx->schoolId(), true)) {
            return $this->jsonError('Der Selbst-Check-in ist an deiner Schule deaktiviert. Bitte wende dich an die Aufsicht.', 403);
        }

        $userId = (int) $user['id'];
        $ip = Audit::clientIp();
        $qr = new QrService($this->ctx->db, $this->ctx->settings);
        $attendance = new AttendanceService($this->ctx->db);

        if ($qr->isRateLimited($userId, $ip)) {
            return $this->jsonError('Zu viele Fehlversuche. Bitte warte einen Moment.', 429);
        }

        $input = $this->jsonInput();
        $token = QrService::extractToken((string) ($input['token'] ?? ''));
        if ($token === '') {
            $qr->recordAttempt($userId, $ip, false);

            return $this->jsonError('Es wurde kein QR-Code erkannt.');
        }

        $slotToken = $qr->resolveSlotToken($token, $editionId);
        if ($slotToken === null || (int) $slotToken['active'] !== 1) {
            $qr->recordAttempt($userId, $ip, false);

            return $this->jsonError('Dieser QR-Code gehört nicht zu dieser Messe.');
        }
        if ($slotToken['expires_at'] !== null && strtotime((string) $slotToken['expires_at']) < time()) {
            $qr->recordAttempt($userId, $ip, false);

            return $this->jsonError('Dieser QR-Code ist abgelaufen.');
        }

        $windowError = $qr->windowError($slotToken, $editionId, false);
        if ($windowError !== null) {
            $qr->recordAttempt($userId, $ip, false);

            return $this->jsonError($windowError);
        }

        $exhibitorId = (int) $slotToken['exhibitor_id'];
        $timeslotId = (int) $slotToken['timeslot_id'];
        $roomId = $slotToken['room_id'] !== null ? (int) $slotToken['room_id'] : null;
        $exhibitorName = (string) $slotToken['exhibitor_name'];
        $slotName = (string) ($slotToken['slot_name'] ?? ('Slot ' . (string) $slotToken['slot_number']));

        if ($attendance->isFreeSlot($slotToken)) {
            $error = $attendance->ensureFreeSlotRegistration($userId, $exhibitorId, $roomId, $timeslotId, $editionId);
            if ($error !== null) {
                $qr->recordAttempt($userId, $ip, false);

                return $this->jsonError($error);
            }
        } elseif (!$attendance->hasRegistration($userId, $exhibitorId, $timeslotId, $editionId)) {
            $qr->recordAttempt($userId, $ip, false);
            $target = $attendance->slotRegistration($userId, $timeslotId, $editionId);

            if ($target !== null) {
                $room = AttendanceService::roomLabel(
                    $target['room_number'] ?? null,
                    $target['room_name'] ?? null,
                );

                return $this->jsonError(sprintf(
                    'Du bist in %s bei %s%s eingetragen — nicht bei %s.',
                    $slotName,
                    (string) $target['exhibitor_name'],
                    $room !== '' ? ' in Raum ' . $room : '',
                    $exhibitorName,
                ));
            }

            return $this->jsonError(sprintf(
                'Du bist für %s in %s nicht eingetragen.',
                $exhibitorName,
                $slotName,
            ));
        }

        $existing = $attendance->existingCheckin($userId, $exhibitorId, $timeslotId, $editionId);
        if ($existing !== null) {
            $qr->recordAttempt($userId, $ip, true);

            return [
                'success' => true,
                'already' => true,
                'exhibitor' => $exhibitorName,
                'slot' => $slotName,
                'message' => sprintf(
                    'Alles klar — du bist bei %s bereits seit %s Uhr eingecheckt.',
                    $exhibitorName,
                    date('H:i', strtotime((string) $existing['checked_in_at'])),
                ),
            ];
        }

        $attendance->recordCheckin(
            $editionId,
            $userId,
            $exhibitorId,
            $timeslotId,
            'self_scan',
            $token,
            null,
            $roomId,
        );
        $qr->recordAttempt($userId, $ip, true);

        return [
            'success' => true,
            'already' => false,
            'exhibitor' => $exhibitorName,
            'slot' => $slotName,
            'room' => AttendanceService::roomLabel(
                $slotToken['room_number'] ?? null,
                $slotToken['room_name'] ?? null,
            ),
            'message' => sprintf('Check-in bestätigt: %s (%s).', $exhibitorName, $slotName),
        ];
    }

    /** @return array<string, mixed> JSON-Body des Requests. */
    private function jsonInput(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return $_POST;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : $_POST;
    }
}
