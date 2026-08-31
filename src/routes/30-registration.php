<?php

declare(strict_types=1);

use App\Controllers\RegistrationController;
use App\Controllers\StudentController;
use App\Core\Router;

/**
 * Schüler-Modul: Einschreibung, eigene Anmeldungen, Übersicht,
 * Tagesplan und Druckansicht.
 */
return static function (Router $r): void {
    // Einschreibung
    $r->get('/{school}/einschreibung', [RegistrationController::class, 'index']);
    $r->post('/{school}/einschreibung', [RegistrationController::class, 'store']);

    // Eigene Anmeldungen
    $r->get('/{school}/meine-anmeldungen', [RegistrationController::class, 'mine']);
    $r->post('/{school}/meine-anmeldungen/abmelden', [RegistrationController::class, 'destroy']);

    // Dashboard, Tagesplan, Druckansicht
    $r->get('/{school}/uebersicht', [StudentController::class, 'dashboard']);
    $r->get('/{school}/tagesplan', [StudentController::class, 'schedule']);
    $r->get('/{school}/drucken', [StudentController::class, 'print']);
};
