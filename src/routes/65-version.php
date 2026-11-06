<?php

declare(strict_types=1);

use App\Controllers\VersionController;
use App\Core\Router;

/** Versionsdialog: Update-Prüfung für Global-Admins. */
return static function (Router $r): void {
    $r->post('/api/version/pruefen', [VersionController::class, 'check']);
};
