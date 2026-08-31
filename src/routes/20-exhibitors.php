<?php

declare(strict_types=1);

use App\Controllers\ExhibitorDocumentsController;
use App\Controllers\ExhibitorsAdminController;
use App\Controllers\ExhibitorsController;
use App\Core\Router;

/**
 * Aussteller: Schüler-/Allgemeinsicht, Admin-Verwaltung, Branchen, Dokumente.
 * Literale Pfade werden VOR den {id}-Mustern registriert.
 */
return static function (Router $r): void {
    // ---------- Admin: Branchen ----------
    $r->get('/{school}/admin/branchen', [ExhibitorsAdminController::class, 'industries']);
    $r->post('/{school}/admin/branchen', [ExhibitorsAdminController::class, 'storeIndustry']);
    $r->post('/{school}/admin/branchen/{id}', [ExhibitorsAdminController::class, 'updateIndustry']);
    $r->post('/{school}/admin/branchen/{id}/loeschen', [ExhibitorsAdminController::class, 'destroyIndustry']);

    // ---------- Admin: Aussteller ----------
    $r->get('/{school}/admin/aussteller', [ExhibitorsAdminController::class, 'index']);
    $r->get('/{school}/admin/aussteller/neu', [ExhibitorsAdminController::class, 'create']);
    $r->post('/{school}/admin/aussteller/neu', [ExhibitorsAdminController::class, 'store']);
    $r->get('/{school}/admin/aussteller/{id}', [ExhibitorsAdminController::class, 'edit']);
    $r->post('/{school}/admin/aussteller/{id}', [ExhibitorsAdminController::class, 'update']);
    $r->post('/{school}/admin/aussteller/{id}/loeschen', [ExhibitorsAdminController::class, 'destroy']);
    $r->post('/{school}/admin/aussteller/{id}/logo-loeschen', [ExhibitorsAdminController::class, 'deleteLogo']);

    // ---------- Admin: Dokumente je Aussteller ----------
    $r->post('/{school}/admin/aussteller/{id}/dokumente', [ExhibitorDocumentsController::class, 'upload']);
    $r->post('/{school}/admin/dokumente/{id}/sichtbarkeit', [ExhibitorDocumentsController::class, 'toggleVisibility']);
    $r->post('/{school}/admin/dokumente/{id}/loeschen', [ExhibitorDocumentsController::class, 'destroy']);

    // ---------- Dokument-Download (permissions-geprüft) ----------
    $r->get('/{school}/api/dokumente/download/{id}', [ExhibitorDocumentsController::class, 'download']);

    // ---------- Schüler-/Allgemeinsicht ----------
    $r->get('/{school}/aussteller', [ExhibitorsController::class, 'index']);
    $r->get('/{school}/aussteller/{id}', [ExhibitorsController::class, 'show']);
};
