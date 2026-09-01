<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions;
use App\Services\AttendanceService;
use App\Services\QrService;

/**
 * QR-Code-Verwaltung: Token-Matrix (Aussteller × Slot), Druckbögen,
 * Schüler-QR-Karten und der lokale QR-Bild-Endpunkt.
 */
final class QrAdminController extends Controller
{
    /** GET /{school}/admin/qr-codes */
    public function index(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::QR_CODES_SEHEN);
        $edition = $this->ctx->requireEdition();
        $editionId = (int) $edition['id'];

        $attendance = new AttendanceService($this->ctx->db);
        $slots = $attendance->slots($editionId);

        $exhibitors = $this->ctx->db->fetchAll(
            'SELECT e.id, e.name, e.room_id, r.room_number, r.room_name
             FROM exhibitors e
             LEFT JOIN rooms r ON r.id = e.room_id AND r.edition_id = e.edition_id
             WHERE e.edition_id = ? AND e.active = 1
             ORDER BY e.name',
            [$editionId],
        );

        $tokenRows = $this->ctx->db->fetchAll(
            'SELECT exhibitor_id, timeslot_id, token, expires_at FROM qr_tokens WHERE edition_id = ?',
            [$editionId],
        );

        $qr = new QrService($this->ctx->db, $this->ctx->settings);
        $slug = (string) $this->ctx->school['slug'];
        $base = $this->ctx->publicBase();

        $tokens = [];
        foreach ($tokenRows as $row) {
            $row['url'] = $qr->checkinUrl($slug, (string) $row['token'], $base);
            $tokens[(int) $row['exhibitor_id']][(int) $row['timeslot_id']] = $row;
        }

        $expected = count($exhibitors) * count($slots);

        return $this->render('pages/qr/index', [
            'title' => 'QR-Codes',
            'edition' => $edition,
            'slots' => $slots,
            'exhibitors' => $exhibitors,
            'tokens' => $tokens,
            'tokenCount' => count($tokenRows),
            'expectedCount' => $expected,
            'canCreate' => $this->ctx->auth->can(Permissions::QR_CODES_ERSTELLEN, $this->ctx->schoolId()),
            'canSeeStudents' => $this->ctx->auth->can(Permissions::ANWESENHEIT_SEHEN, $this->ctx->schoolId()),
            'qrBase' => $base,
            'baseIsGuessed' => $this->ctx->baseIsGuessed(),
            'schoolSlug' => $slug,
        ]);
    }

    /** POST /{school}/admin/qr-codes/generieren */
    public function generate(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::QR_CODES_ERSTELLEN);
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();
        $editionId = (int) $edition['id'];

        $qr = new QrService($this->ctx->db, $this->ctx->settings);
        $scope = (string) ($_POST['scope'] ?? 'all');
        $force = ($_POST['force'] ?? '') === '1';

        if ($scope === 'single') {
            $exhibitorId = (int) ($_POST['exhibitor_id'] ?? 0);
            $timeslotId = (int) ($_POST['timeslot_id'] ?? 0);
            $token = $qr->tokenFor($exhibitorId, $timeslotId, $editionId, $force);

            if ($token === null) {
                $this->flash('error', 'Aussteller oder Zeitslot gehört nicht zu dieser Messe.');
            } else {
                $this->ctx->audit->log(
                    'QR-Token erzeugt',
                    'info',
                    sprintf('Aussteller #%d, Slot #%d%s', $exhibitorId, $timeslotId, $force ? ' (erneuert)' : ''),
                    $this->ctx->schoolId(),
                );
                $this->flash('success', 'QR-Code erzeugt.');
            }
        } else {
            $created = $qr->generateAll($editionId, $force);
            $this->ctx->audit->log(
                'QR-Tokens gesammelt erzeugt',
                'info',
                sprintf('%d neue Tokens%s', $created, $force ? ' (alle erneuert)' : ''),
                $this->ctx->schoolId(),
            );
            $this->flash('success', $force
                ? 'Alle QR-Codes wurden neu erzeugt.'
                : sprintf('%d neue QR-Codes erzeugt.', $created));
        }

        $this->redirect($this->ctx->schoolUrl('/admin/qr-codes'));
    }

    /** GET /{school}/admin/qr-codes/druck/{exhibitor} — Druckbogen je Aussteller */
    public function printSheet(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::QR_CODES_SEHEN);
        $edition = $this->ctx->requireEdition();
        $editionId = (int) $edition['id'];

        $exhibitorId = (int) ($params['exhibitor'] ?? 0);
        $exhibitor = $this->ctx->db->fetchOne(
            'SELECT e.id, e.name, e.room_id, r.room_number, r.room_name
             FROM exhibitors e
             LEFT JOIN rooms r ON r.id = e.room_id AND r.edition_id = e.edition_id
             WHERE e.id = ? AND e.edition_id = ?',
            [$exhibitorId, $editionId],
        );
        if ($exhibitor === null) {
            throw new HttpException(404, 'Aussteller nicht gefunden.');
        }

        $attendance = new AttendanceService($this->ctx->db);
        $qr = new QrService($this->ctx->db, $this->ctx->settings);
        $slug = (string) $this->ctx->school['slug'];
        $base = $this->ctx->publicBase();

        $sheets = [];
        foreach ($attendance->slots($editionId) as $slot) {
            $token = $this->ctx->db->fetchValue(
                'SELECT token FROM qr_tokens WHERE exhibitor_id = ? AND timeslot_id = ? AND edition_id = ?',
                [$exhibitorId, (int) $slot['id'], $editionId],
            );
            $sheets[] = [
                'slot' => $slot,
                'token' => is_string($token) ? $token : null,
                'url' => is_string($token)
                    ? $qr->checkinUrl($slug, $token, $base)
                    : null,
            ];
        }

        return $this->render('pages/qr/print', [
            'title' => 'QR-Druckbogen · ' . (string) $exhibitor['name'],
            'exhibitor' => $exhibitor,
            'sheets' => $sheets,
            'edition' => $edition,
        ]);
    }

    /** GET /{school}/admin/qr-codes/schueler — Auswahl für Schüler-QR-Karten */
    public function studentList(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::ANWESENHEIT_SEHEN);
        $edition = $this->ctx->requireEdition();

        $class = trim((string) ($_GET['klasse'] ?? ''));
        $search = trim((string) ($_GET['q'] ?? ''));

        $sql = 'SELECT id, firstname, lastname, class, username FROM users
                WHERE school_id = ? AND role = \'student\'';
        $args = [$this->ctx->schoolId()];

        if ($class !== '') {
            $sql .= ' AND class = ?';
            $args[] = $class;
        }
        if ($search !== '') {
            $sql .= ' AND (firstname LIKE ? OR lastname LIKE ? OR username LIKE ?)';
            $like = '%' . $search . '%';
            $args[] = $like;
            $args[] = $like;
            $args[] = $like;
        }
        $sql .= ' ORDER BY class, lastname, firstname LIMIT 300';

        $students = $this->ctx->db->fetchAll($sql, $args);

        $classes = $this->ctx->db->fetchAll(
            'SELECT DISTINCT class FROM users
             WHERE school_id = ? AND role = \'student\' AND class IS NOT NULL AND class <> \'\'
             ORDER BY class',
            [$this->ctx->schoolId()],
        );

        return $this->render('pages/qr/students', [
            'title' => 'Schüler-QR-Karten',
            'students' => $students,
            'classes' => array_column($classes, 'class'),
            'filterClass' => $class,
            'filterSearch' => $search,
            'edition' => $edition,
        ]);
    }

    /** GET /{school}/admin/qr-codes/schueler/{user} — persönliche QR-Karte */
    public function studentCard(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::ANWESENHEIT_SEHEN);
        $edition = $this->ctx->requireEdition();
        $editionId = (int) $edition['id'];

        $userId = (int) ($params['user'] ?? 0);
        $student = $this->ctx->db->fetchOne(
            'SELECT id, firstname, lastname, class, username FROM users
             WHERE id = ? AND school_id = ? AND role = \'student\'',
            [$userId, $this->ctx->schoolId()],
        );
        if ($student === null) {
            throw new HttpException(404, 'Schüler:in nicht gefunden.');
        }

        $qr = new QrService($this->ctx->db, $this->ctx->settings);
        $token = $qr->studentTokenFor($userId, $editionId);

        return $this->render('pages/qr/student-card', [
            'title' => 'QR-Karte · ' . trim((string) $student['firstname'] . ' ' . (string) $student['lastname']),
            'student' => $student,
            'token' => $token,
            'edition' => $edition,
        ]);
    }

    /**
     * GET /{school}/api/qr/bild?data=…&scale=6 — QR als SVG (lokal erzeugt).
     * Es verlässt kein Token den Server über Dritt-Dienste.
     */
    public function image(array $params): string
    {
        $this->requireSchool($params['school']);
        $schoolId = $this->ctx->schoolId();
        if (!$this->ctx->auth->can(Permissions::QR_CODES_SEHEN, $schoolId)
            && !$this->ctx->auth->can(Permissions::ANWESENHEIT_SEHEN, $schoolId)) {
            throw new HttpException(403);
        }

        $data = (string) ($_GET['data'] ?? '');
        if ($data === '' || strlen($data) > 800) {
            throw new HttpException(400, 'Ungültige QR-Daten.');
        }
        $scale = max(2, min(20, (int) ($_GET['scale'] ?? 6)));

        require_once dirname(__DIR__, 2) . '/lib/qr.php';

        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: private, max-age=300');

        return qrSvg($data, $scale, 4);
    }
}
