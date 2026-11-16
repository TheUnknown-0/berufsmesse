<?php

declare(strict_types=1);

use App\Controllers\HelpCallController;
use App\Core\Router;

/**
 * Hilfe-Ruf am Messetag: Seite zum Rufen und Zusagen, Verwaltung und die
 * JSON-Aktionen der Web-Seiten. Die App nutzt dieselben Aktionen unter
 * /api/v1/{school}/hilfe/… (siehe 02-app-api.php).
 */
return static function (Router $r): void {
    $r->get('/{school}/hilfe', [HelpCallController::class, 'page']);
    $r->get('/{school}/admin/hilfe', [HelpCallController::class, 'admin']);
    $r->post('/{school}/admin/hilfe', [HelpCallController::class, 'adminSave']);

    $r->get('/{school}/api/hilfe', [HelpCallController::class, 'status']);
    $r->post('/{school}/api/hilfe/rufen', [HelpCallController::class, 'call']);
    $r->post('/{school}/api/hilfe/probealarm', [HelpCallController::class, 'test']);
    $r->post('/{school}/api/hilfe/{id}/kommen', [HelpCallController::class, 'respond']);
    $r->post('/{school}/api/hilfe/{id}/mehr', [HelpCallController::class, 'more']);
    $r->post('/{school}/api/hilfe/{id}/schliessen', [HelpCallController::class, 'close']);
};
