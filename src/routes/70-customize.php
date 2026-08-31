<?php

declare(strict_types=1);

use App\Controllers\CustomizeController;
use App\Core\Router;

return static function (Router $r): void {
    $r->get('/{school}/admin/darstellung', [CustomizeController::class, 'index']);
    $r->post('/{school}/admin/darstellung/farben', [CustomizeController::class, 'saveColors']);
    $r->post('/{school}/admin/darstellung/logo', [CustomizeController::class, 'saveLogo']);
    $r->post('/{school}/admin/darstellung/hintergrund', [CustomizeController::class, 'saveBackground']);
    $r->post('/{school}/admin/darstellung/zuruecksetzen', [CustomizeController::class, 'resetAll']);
    $r->post('/{school}/api/darstellung/navigation', [CustomizeController::class, 'saveNav']);
    $r->post('/{school}/api/darstellung/seite', [CustomizeController::class, 'savePageLayout']);
};
