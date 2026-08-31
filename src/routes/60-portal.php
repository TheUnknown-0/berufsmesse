<?php

declare(strict_types=1);

use App\Controllers\ExhibitorAcceptController;
use App\Controllers\PortalController;
use App\Core\Router;

/**
 * Aussteller: Einladungslink (global), Portal und die Verwaltungsseite
 * „Aussteller-Konten" innerhalb einer Schule.
 */
return static function (Router $r): void {
    // Einladung annehmen (global, ohne Schulkontext)
    $r->get('/aussteller-einladung', [ExhibitorAcceptController::class, 'show']);
    $r->post('/aussteller-einladung', [ExhibitorAcceptController::class, 'accept']);

    // Portal
    $r->get('/{school}/portal', [PortalController::class, 'index']);
    $r->post('/{school}/portal/absage/{id}', [PortalController::class, 'cancel']);

    $r->get('/{school}/portal/profil/{id}', [PortalController::class, 'profile']);
    $r->post('/{school}/portal/profil/{id}', [PortalController::class, 'saveProfile']);

    $r->get('/{school}/portal/slots', [PortalController::class, 'slots']);

    $r->get('/{school}/portal/ausstattung', [PortalController::class, 'equipment']);
    $r->post('/{school}/portal/ausstattung', [PortalController::class, 'equipmentStore']);
    $r->post('/{school}/portal/ausstattung/{id}/stornieren', [PortalController::class, 'equipmentCancel']);

    $r->get('/{school}/portal/dokumente', [PortalController::class, 'documents']);
    $r->post('/{school}/portal/dokumente', [PortalController::class, 'documentUpload']);
    $r->get('/{school}/portal/dokumente/{id}/download', [PortalController::class, 'documentDownload']);
    $r->post('/{school}/portal/dokumente/{id}/loeschen', [PortalController::class, 'documentDelete']);
    $r->post('/{school}/portal/dokumente/{id}/sichtbarkeit', [PortalController::class, 'documentToggle']);

    // Aussteller-Konten & Einladungen (Admin-Seite, nicht in der Sidebar verlinkt)
    $r->get('/{school}/admin/aussteller-konten', [PortalController::class, 'accounts']);
    $r->post('/{school}/admin/aussteller-konten/einladen', [PortalController::class, 'invite']);
    $r->post('/{school}/admin/aussteller-konten/{id}/rechte', [PortalController::class, 'updateRights']);
    $r->post('/{school}/admin/aussteller-konten/{id}/entfernen', [PortalController::class, 'removeLink']);
    $r->post('/{school}/admin/aussteller-konten/absage/{id}/bestaetigen', [PortalController::class, 'confirmCancellation']);
    $r->post('/{school}/admin/aussteller-konten/absage/{id}/ablehnen', [PortalController::class, 'rejectCancellation']);
};
