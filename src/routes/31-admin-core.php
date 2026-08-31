<?php

declare(strict_types=1);

use App\Controllers\AdminDashboardController;
use App\Controllers\AdminRegistrationsController;
use App\Controllers\SettingsController;
use App\Controllers\TeacherController;
use App\Core\Router;

/**
 * Verwaltungs-Kern: Dashboard inkl. Auto-Zuteilung, Anmeldungsverwaltung,
 * Einstellungen und Lehrer-/Klassenbereich.
 */
return static function (Router $r): void {
    // Dashboard & Auto-Zuteilung
    $r->get('/{school}/admin/dashboard', [AdminDashboardController::class, 'index']);
    $r->get('/{school}/api/dashboard/stats', [AdminDashboardController::class, 'apiStats']);
    $r->post('/{school}/admin/zuteilung/ausfuehren', [AdminDashboardController::class, 'runAssign']);
    $r->post('/{school}/admin/zuteilung/auffuellen', [AdminDashboardController::class, 'runFill']);
    $r->post('/{school}/admin/zuteilung/zuruecksetzen', [AdminDashboardController::class, 'resetAssign']);

    // Anmeldungen verwalten
    $r->get('/{school}/admin/anmeldungen', [AdminRegistrationsController::class, 'index']);
    $r->post('/{school}/admin/anmeldungen/hinzufuegen', [AdminRegistrationsController::class, 'store']);
    $r->post('/{school}/admin/anmeldungen/slot', [AdminRegistrationsController::class, 'updateSlot']);
    $r->post('/{school}/admin/anmeldungen/entfernen', [AdminRegistrationsController::class, 'destroy']);

    // Einstellungen
    $r->get('/{school}/admin/einstellungen', [SettingsController::class, 'index']);
    $r->post('/{school}/admin/einstellungen/messe', [SettingsController::class, 'saveEdition']);
    $r->post('/{school}/admin/einstellungen/zeitslots', [SettingsController::class, 'saveTimeslot']);
    $r->post('/{school}/admin/einstellungen/zeitslots/loeschen', [SettingsController::class, 'deleteTimeslot']);
    $r->post('/{school}/admin/einstellungen/zugang', [SettingsController::class, 'saveAccess']);
    $r->post('/{school}/admin/einstellungen/qr', [SettingsController::class, 'saveQr']);
    $r->post('/{school}/admin/einstellungen/wartung', [SettingsController::class, 'maintenance']);

    // Lehrer-/Klassenbereich
    $r->get('/{school}/klassen', [TeacherController::class, 'index']);
    $r->get('/{school}/klassen/{class}', [TeacherController::class, 'show']);
};
