<?php

declare(strict_types=1);

use App\Controllers\AttendanceController;
use App\Controllers\FailoverController;
use App\Controllers\OpsBoardController;
use App\Controllers\CheckinController;
use App\Controllers\QrAdminController;
use App\Controllers\SupervisionController;
use App\Controllers\TeacherScanController;
use App\Core\Router;

/**
 * Modul: QR-Codes, Check-in & Anwesenheit.
 */
return static function (Router $r): void {
    // ---------- QR-Codes (Verwaltung) ----------
    $r->get('/{school}/admin/qr-codes', [QrAdminController::class, 'index']);
    $r->post('/{school}/admin/qr-codes/generieren', [QrAdminController::class, 'generate']);
    $r->get('/{school}/admin/qr-codes/druck/{exhibitor}', [QrAdminController::class, 'printSheet']);
    $r->get('/{school}/admin/qr-codes/schueler', [QrAdminController::class, 'studentList']);
    $r->get('/{school}/admin/qr-codes/schueler/{user}', [QrAdminController::class, 'studentCard']);
    $r->get('/{school}/api/qr/bild', [QrAdminController::class, 'image']);

    // ---------- Selbst-Check-in (Schüler) ----------
    $r->get('/{school}/checkin', [CheckinController::class, 'index']);
    $r->post('/{school}/api/checkin', [CheckinController::class, 'apiCheckin']);

    // ---------- Lehrer-Scanner ----------
    $r->get('/{school}/scan', [TeacherScanController::class, 'index']);
    $r->post('/{school}/api/scan/checkin', [TeacherScanController::class, 'apiCheckin']);
    $r->get('/{school}/api/scan/roster', [TeacherScanController::class, 'apiRoster']);

    // ---------- Anwesenheit ----------
    $r->get('/{school}/admin/anwesenheit', [AttendanceController::class, 'index']);
    $r->post('/{school}/api/anwesenheit/setzen', [AttendanceController::class, 'apiSet']);
    $r->get('/{school}/admin/anwesenheit-live', [AttendanceController::class, 'live']);
    $r->get('/{school}/api/anwesenheit/live', [AttendanceController::class, 'apiLive']);
    $r->get('/{school}/admin/anwesenheit-bericht', [AttendanceController::class, 'report']);

    // Leitstand: Gesamtsicht auf den Messetag
    $r->get('/{school}/admin/leitstand', [OpsBoardController::class, 'index']);
    $r->get('/{school}/api/leitstand', [OpsBoardController::class, 'apiState']);

    // Kurzfristiger Ausfall eines Ausstellers
    $r->get('/{school}/admin/ausfall', [FailoverController::class, 'index']);
    $r->get('/{school}/admin/ausfall/{id}', [FailoverController::class, 'preview']);
    $r->post('/{school}/admin/ausfall/{id}/umbuchen', [FailoverController::class, 'execute']);

    // ---------- Aufsichtsplan ----------
    $r->get('/{school}/admin/aufsicht', [SupervisionController::class, 'index']);
    $r->post('/{school}/admin/aufsicht', [SupervisionController::class, 'store']);
};
