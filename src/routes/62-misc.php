<?php

declare(strict_types=1);

use App\Controllers\AnnouncementsController;
use App\Controllers\AuditLogController;
use App\Controllers\NotificationsController;
use App\Core\Router;

/**
 * Ankündigungen, schulinternes Audit-Log und die Benachrichtigungs-API.
 */
return static function (Router $r): void {
    // Ankündigungen
    $r->get('/{school}/admin/ankuendigungen', [AnnouncementsController::class, 'index']);
    $r->post('/{school}/admin/ankuendigungen', [AnnouncementsController::class, 'store']);
    $r->post('/{school}/admin/ankuendigungen/{id}', [AnnouncementsController::class, 'update']);
    $r->post('/{school}/admin/ankuendigungen/{id}/umschalten', [AnnouncementsController::class, 'toggle']);
    $r->post('/{school}/admin/ankuendigungen/{id}/loeschen', [AnnouncementsController::class, 'delete']);

    // Audit-Log der Schule
    $r->get('/{school}/admin/audit-log', [AuditLogController::class, 'index']);
    $r->get('/{school}/admin/audit-log/export', [AuditLogController::class, 'export']);

    // Benachrichtigungen (global, ohne Schulkontext)
    $r->get('/api/benachrichtigungen', [NotificationsController::class, 'listUnread']);
    $r->post('/api/benachrichtigungen/gelesen', [NotificationsController::class, 'markRead']);
};
