<?php

declare(strict_types=1);

use App\Controllers\Api\V1Controller;
use App\Controllers\AppHandoffController;
use App\Controllers\CheckinController;
use App\Controllers\NotificationsController;
use App\Controllers\OrgaChatController;
use App\Controllers\StudentController;
use App\Controllers\TeacherScanController;
use App\Core\Router;

/**
 * App-Schnittstelle (Android-App). Anmeldung per Bearer-Token, siehe
 * Services\AppApi. Globale Pfade, deshalb vor den /{school}/-Routen geladen.
 */
return static function (Router $r): void {
    $r->get('/api/v1/info', [V1Controller::class, 'info']);
    $r->get('/api/v1/schulen', [V1Controller::class, 'schools']);
    $r->post('/api/v1/login', [V1Controller::class, 'login']);
    $r->post('/api/v1/logout', [V1Controller::class, 'logout']);
    $r->get('/api/v1/ich', [V1Controller::class, 'me']);
    $r->post('/api/v1/web-sitzung', [V1Controller::class, 'webSession']);
    $r->get('/api/v1/{school}/funktionen', [V1Controller::class, 'features']);

    // Native Bildschirme der App. Dieselben Aktionen wie die Web-Seiten, damit die
    // Fachlogik nur an einer Stelle steht; nur die Anmeldung läuft hier per Token.
    $r->get('/api/v1/benachrichtigungen', [NotificationsController::class, 'listUnread']);
    $r->post('/api/v1/benachrichtigungen/gelesen', [NotificationsController::class, 'markRead']);
    $r->get('/api/v1/{school}/schueler', [StudentController::class, 'apiPlan']);
    $r->post('/api/v1/{school}/checkin', [CheckinController::class, 'apiCheckin']);
    $r->get('/api/v1/{school}/scanner', [TeacherScanController::class, 'apiSetup']);
    $r->get('/api/v1/{school}/scanner/liste', [TeacherScanController::class, 'apiRoster']);
    $r->post('/api/v1/{school}/scanner/checkin', [TeacherScanController::class, 'apiCheckin']);
    $r->get('/api/v1/{school}/chat', [OrgaChatController::class, 'apiSpaces']);
    $r->get('/api/v1/{school}/chat/{space}', [OrgaChatController::class, 'apiMessages']);
    $r->post('/api/v1/{school}/chat/{space}/senden', [OrgaChatController::class, 'apiSend']);

    // Kein /api/-Pfad: Hier entsteht die normale Cookie-Sitzung der WebView.
    $r->get('/app/uebergabe/{code}', [AppHandoffController::class, 'enter']);
};
