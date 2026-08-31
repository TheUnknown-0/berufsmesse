<?php

declare(strict_types=1);

use App\Controllers\PrintController;
use App\Core\Router;

/**
 * Druckzentrale: Übersichtsseite, PDF-Berichte und Exporte.
 *
 * PDFs/Exporte sind lesende GET-Endpunkte (kein CSRF nötig). Einzige Ausnahme
 * ist das Zugangsdaten-PDF: es setzt Passwörter und läuft deshalb per POST
 * mit CSRF-Prüfung.
 */
return static function (Router $r): void {
    $r->get('/{school}/admin/druckzentrale', [PrintController::class, 'index']);

    $r->get('/{school}/admin/druckzentrale/persoenlicher-plan', [PrintController::class, 'personalPlan']);
    $r->get('/{school}/admin/druckzentrale/klassenliste', [PrintController::class, 'classList']);
    $r->get('/{school}/admin/druckzentrale/raumplan', [PrintController::class, 'roomPlan']);
    $r->get('/{school}/admin/druckzentrale/raumzuteilung', [PrintController::class, 'roomAssignment']);
    $r->get('/{school}/admin/druckzentrale/abwesenheit', [PrintController::class, 'absent']);
    $r->get('/{school}/admin/druckzentrale/qr-karten', [PrintController::class, 'qrCards']);
    $r->get('/{school}/admin/druckzentrale/export', [PrintController::class, 'export']);

    $r->post('/{school}/admin/druckzentrale/zugangsdaten', [PrintController::class, 'passwords']);
};
