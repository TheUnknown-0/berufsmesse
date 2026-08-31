<?php

declare(strict_types=1);

use App\Controllers\AuditLogController;
use App\Controllers\EditionsController;
use App\Controllers\GlobalAdminController;
use App\Controllers\GlobalAdminsController;
use App\Controllers\SchoolsAdminController;
use App\Core\Router;

/**
 * Global-Admin (nur Rolle admin): Übersicht, Schulen, Editionen,
 * systemweite Aussteller-Konten, Global-Administratoren und
 * schulübergreifendes Audit-Log.
 */
return static function (Router $r): void {
    $r->get('/global-admin', [GlobalAdminController::class, 'index']);
    $r->post('/global-admin/einstellungen', [GlobalAdminController::class, 'saveSettings']);
    $r->get('/global-admin/aussteller-konten', [GlobalAdminController::class, 'accounts']);
    $r->post('/global-admin/aussteller-konten/verknuepfung/{id}/erneuern', [GlobalAdminController::class, 'renewInvite']);
    $r->post('/global-admin/aussteller-konten/verknuepfung/{id}/entfernen', [GlobalAdminController::class, 'removeLink']);
    $r->post('/global-admin/aussteller-konten/{id}', [GlobalAdminController::class, 'updateAccount']);
    $r->post('/global-admin/aussteller-konten/{id}/passwort', [GlobalAdminController::class, 'resetAccountPassword']);
    $r->post('/global-admin/aussteller-konten/{id}/loeschen', [GlobalAdminController::class, 'deleteAccount']);
    $r->get('/global-admin/logs', [AuditLogController::class, 'globalIndex']);

    // Global-Administratoren — hier und NUR hier werden admin-Konten verwaltet.
    $r->get('/global-admin/administratoren', [GlobalAdminsController::class, 'index']);
    $r->get('/global-admin/administratoren/neu', [GlobalAdminsController::class, 'create']);
    $r->post('/global-admin/administratoren/neu', [GlobalAdminsController::class, 'store']);
    $r->get('/global-admin/administratoren/{id}/bearbeiten', [GlobalAdminsController::class, 'edit']);
    $r->post('/global-admin/administratoren/{id}/bearbeiten', [GlobalAdminsController::class, 'update']);
    $r->post('/global-admin/administratoren/{id}/passwort', [GlobalAdminsController::class, 'resetPassword']);
    $r->post('/global-admin/administratoren/{id}/loeschen', [GlobalAdminsController::class, 'destroy']);

    $r->get('/global-admin/schulen', [SchoolsAdminController::class, 'index']);
    $r->post('/global-admin/schulen', [SchoolsAdminController::class, 'store']);
    $r->post('/global-admin/schulen/{id}', [SchoolsAdminController::class, 'update']);
    $r->post('/global-admin/schulen/{id}/loeschen', [SchoolsAdminController::class, 'delete']);

    $r->get('/global-admin/editionen', [EditionsController::class, 'index']);
    $r->post('/global-admin/editionen', [EditionsController::class, 'store']);
    $r->post('/global-admin/editionen/{id}', [EditionsController::class, 'update']);
    $r->post('/global-admin/editionen/{id}/status', [EditionsController::class, 'status']);
};
