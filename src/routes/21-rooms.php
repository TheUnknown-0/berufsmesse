<?php

declare(strict_types=1);

use App\Controllers\CapacitiesController;
use App\Controllers\EquipmentAdminController;
use App\Controllers\RoomsController;
use App\Core\Router;

/**
 * Räume, Aussteller↔Raum-Zuteilung, Slot-Kapazitäten und Ausstattung.
 * Literale Pfade werden VOR den {id}-Mustern registriert.
 */
return static function (Router $r): void {
    // ---------- Räume ----------
    $r->get('/{school}/admin/raeume', [RoomsController::class, 'index']);
    $r->post('/{school}/admin/raeume/neu', [RoomsController::class, 'store']);
    $r->post('/{school}/admin/raeume/zuteilen', [RoomsController::class, 'assign']);
    $r->post('/{school}/admin/raeume/zuteilung-loesen', [RoomsController::class, 'unassign']);
    $r->post('/{school}/admin/raeume/zuteilungen-aufheben', [RoomsController::class, 'clearAssignments']);
    $r->post('/{school}/admin/raeume/{id}', [RoomsController::class, 'update']);
    $r->post('/{school}/admin/raeume/{id}/loeschen', [RoomsController::class, 'destroy']);

    // ---------- Slot-Kapazitäten ----------
    $r->get('/{school}/admin/kapazitaeten', [CapacitiesController::class, 'index']);
    $r->post('/{school}/admin/kapazitaeten', [CapacitiesController::class, 'save']);

    // ---------- Ausstattung ----------
    $r->get('/{school}/admin/ausstattung', [EquipmentAdminController::class, 'index']);
    $r->post('/{school}/admin/ausstattung/optionen/neu', [EquipmentAdminController::class, 'storeOption']);
    $r->post('/{school}/admin/ausstattung/optionen/{id}', [EquipmentAdminController::class, 'updateOption']);
    $r->post('/{school}/admin/ausstattung/optionen/{id}/loeschen', [EquipmentAdminController::class, 'destroyOption']);
    $r->post('/{school}/admin/ausstattung/anfragen/{id}', [EquipmentAdminController::class, 'updateRequest']);
};
