<?php

declare(strict_types=1);

use App\Controllers\FeedbackAdminController;
use App\Controllers\FeedbackController;
use App\Core\Router;

/**
 * Modul „Feedback“ — Bögen im Admin-Bereich bauen und auswerten,
 * Ausfüllen durch Schüler:innen, Lehrkräfte und Aussteller.
 * Statische Segmente (neu) stehen vor den {id}-Routen.
 */
return static function (Router $r): void {
    // ---------- Verwaltung ----------
    $r->get('/{school}/admin/feedback', [FeedbackAdminController::class, 'index']);

    $r->get('/{school}/admin/feedback/neu', [FeedbackAdminController::class, 'create']);
    $r->post('/{school}/admin/feedback/neu', [FeedbackAdminController::class, 'store']);

    $r->get('/{school}/admin/feedback/{id}/bearbeiten', [FeedbackAdminController::class, 'edit']);
    $r->post('/{school}/admin/feedback/{id}/bearbeiten', [FeedbackAdminController::class, 'update']);
    $r->post('/{school}/admin/feedback/{id}/status', [FeedbackAdminController::class, 'setStatus']);
    $r->post('/{school}/admin/feedback/{id}/loeschen', [FeedbackAdminController::class, 'destroy']);
    $r->get('/{school}/admin/feedback/{id}/vorschau', [FeedbackAdminController::class, 'preview']);
    $r->get('/{school}/admin/feedback/{id}/auswertung', [FeedbackAdminController::class, 'results']);
    $r->get('/{school}/admin/feedback/{id}/export', [FeedbackAdminController::class, 'export']);

    // ---------- Ausfüllen ----------
    $r->get('/{school}/feedback', [FeedbackController::class, 'index']);
    $r->get('/{school}/feedback/{id}', [FeedbackController::class, 'show']);
    $r->post('/{school}/feedback/{id}', [FeedbackController::class, 'submit']);
};
