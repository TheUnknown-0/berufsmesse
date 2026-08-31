<?php

declare(strict_types=1);

use App\Controllers\PermissionsController;
use App\Controllers\UsersController;
use App\Core\Router;

/**
 * Modul „Benutzer & Berechtigungen“.
 * Statische Segmente (neu, import, gruppen) werden vor den {id}-Routen
 * registriert, damit sie zuerst greifen.
 */
return static function (Router $r): void {
    // ---------- Benutzerverwaltung ----------
    $r->get('/{school}/admin/benutzer', [UsersController::class, 'index']);

    $r->get('/{school}/admin/benutzer/neu', [UsersController::class, 'create']);
    $r->post('/{school}/admin/benutzer/neu', [UsersController::class, 'store']);

    $r->get('/{school}/admin/benutzer/import', [UsersController::class, 'showImport']);
    $r->post('/{school}/admin/benutzer/import', [UsersController::class, 'import']);

    $r->get('/{school}/admin/benutzer/{id}/bearbeiten', [UsersController::class, 'edit']);
    $r->post('/{school}/admin/benutzer/{id}/bearbeiten', [UsersController::class, 'update']);
    $r->post('/{school}/admin/benutzer/{id}/loeschen', [UsersController::class, 'destroy']);
    $r->post('/{school}/admin/benutzer/{id}/passwort', [UsersController::class, 'resetPassword']);

    // JSON-API für andere Module
    $r->get('/{school}/api/benutzer/suche', [UsersController::class, 'apiSearch']);

    // ---------- Berechtigungen ----------
    $r->get('/{school}/admin/berechtigungen', [PermissionsController::class, 'index']);
    $r->post('/{school}/admin/berechtigungen/speichern', [PermissionsController::class, 'save']);
    $r->post('/{school}/admin/berechtigungen/gruppen-zuweisen', [PermissionsController::class, 'assignGroups']);

    $r->get('/{school}/admin/berechtigungen/gruppen', [PermissionsController::class, 'groups']);
    $r->get('/{school}/admin/berechtigungen/gruppen/neu', [PermissionsController::class, 'createGroup']);
    $r->post('/{school}/admin/berechtigungen/gruppen/neu', [PermissionsController::class, 'storeGroup']);
    $r->get('/{school}/admin/berechtigungen/gruppen/{id}/bearbeiten', [PermissionsController::class, 'editGroup']);
    $r->post('/{school}/admin/berechtigungen/gruppen/{id}/bearbeiten', [PermissionsController::class, 'updateGroup']);
    $r->post('/{school}/admin/berechtigungen/gruppen/{id}/loeschen', [PermissionsController::class, 'deleteGroup']);
};
