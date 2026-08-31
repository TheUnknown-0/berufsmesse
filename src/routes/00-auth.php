<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\HomeController;
use App\Controllers\SetupController;
use App\Core\Router;

/**
 * Globale Routen: Landing, Auth, Setup.
 * WICHTIG: Globale Pfade müssen VOR den /{school}/-Routen registriert
 * werden (Dateiname 00- sorgt für die Reihenfolge beim Laden).
 */
return static function (Router $r): void {
    $r->get('/', [HomeController::class, 'landing']);

    $r->get('/setup', [SetupController::class, 'show']);
    $r->post('/setup', [SetupController::class, 'run']);

    $r->get('/login', [AuthController::class, 'showLogin']);
    $r->post('/login', [AuthController::class, 'login']);
    $r->post('/logout', [AuthController::class, 'logout']);

    $r->get('/zugang', [AuthController::class, 'showSitePassword']);
    $r->post('/zugang', [AuthController::class, 'sitePassword']);

    $r->get('/passwort-aendern', [AuthController::class, 'showChangePassword']);
    $r->post('/passwort-aendern', [AuthController::class, 'changePassword']);

    // Schulspezifische Auth-Routen
    $r->get('/{school}/login', [AuthController::class, 'showLogin']);
    $r->post('/{school}/login', [AuthController::class, 'login']);
    $r->get('/{school}/registrieren', [AuthController::class, 'showRegister']);
    $r->post('/{school}/registrieren', [AuthController::class, 'register']);

    // Rollenabhängiger Einstieg je Schule
    $r->get('/{school}/', [HomeController::class, 'schoolHome']);
};
