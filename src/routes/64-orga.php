<?php

declare(strict_types=1);

use App\Controllers\OrgaAdminController;
use App\Controllers\OrgaChatController;
use App\Controllers\OrgaController;
use App\Controllers\OrgaNotesController;
use App\Controllers\OrgaTeamController;
use App\Core\Router;

/**
 * Modul „Orga-Tool“: Teams, Ziele, Organisationspunkte, Chats (Team,
 * Gesamt, Leitungsrunde), Beschlüsse & Protokolle, Meine Aufgaben.
 * Räume im URL-Segment {space}: „gesamt“, „leitung“ oder „team-<id>“.
 */
return static function (Router $r): void {
    // ---------- Übersicht ----------
    $r->get('/{school}/orga', [OrgaController::class, 'index']);
    $r->get('/{school}/orga/meine-aufgaben', [OrgaController::class, 'myTasks']);

    // ---------- Verwaltung ----------
    $r->get('/{school}/orga/verwaltung', [OrgaAdminController::class, 'index']);
    $r->post('/{school}/orga/verwaltung/termin', [OrgaAdminController::class, 'saveDeadline']);
    $r->post('/{school}/orga/verwaltung/teams', [OrgaAdminController::class, 'store']);
    $r->get('/{school}/orga/verwaltung/teams/{id}', [OrgaAdminController::class, 'edit']);
    $r->post('/{school}/orga/verwaltung/teams/{id}', [OrgaAdminController::class, 'update']);
    $r->post('/{school}/orga/verwaltung/teams/{id}/mitglieder', [OrgaAdminController::class, 'saveMembers']);
    $r->post('/{school}/orga/verwaltung/teams/{id}/loeschen', [OrgaAdminController::class, 'destroy']);

    // ---------- Team, Ziele, Organisationspunkte ----------
    $r->get('/{school}/orga/teams/{id}', [OrgaTeamController::class, 'show']);
    $r->get('/{school}/orga/teams/{id}/aufgaben', [OrgaTeamController::class, 'tasks']);
    $r->post('/{school}/orga/teams/{id}/aufgaben', [OrgaTeamController::class, 'storeTask']);
    $r->post('/{school}/orga/teams/{id}/ziele', [OrgaTeamController::class, 'storeGoal']);

    $r->post('/{school}/orga/ziele/{id}', [OrgaTeamController::class, 'updateGoal']);
    $r->post('/{school}/orga/ziele/{id}/abhaken', [OrgaTeamController::class, 'toggleGoal']);
    $r->post('/{school}/orga/ziele/{id}/loeschen', [OrgaTeamController::class, 'destroyGoal']);

    $r->get('/{school}/orga/aufgaben/{id}', [OrgaTeamController::class, 'task']);
    $r->post('/{school}/orga/aufgaben/{id}', [OrgaTeamController::class, 'updateTask']);
    $r->post('/{school}/orga/aufgaben/{id}/status', [OrgaTeamController::class, 'setStatus']);
    $r->post('/{school}/orga/aufgaben/{id}/loeschen', [OrgaTeamController::class, 'destroyTask']);
    $r->post('/{school}/orga/aufgaben/{id}/checkliste', [OrgaTeamController::class, 'addChecklistItem']);
    $r->post('/{school}/orga/aufgaben/{id}/kommentare', [OrgaTeamController::class, 'addComment']);
    $r->post('/{school}/orga/checkliste/{id}/umschalten', [OrgaTeamController::class, 'toggleChecklistItem']);
    $r->post('/{school}/orga/checkliste/{id}/loeschen', [OrgaTeamController::class, 'destroyChecklistItem']);
    $r->post('/{school}/api/orga/aufgaben/{id}/status', [OrgaTeamController::class, 'apiStatus']);

    // ---------- Beschlüsse & Protokolle ----------
    $r->get('/{school}/orga/protokolle/{space}', [OrgaNotesController::class, 'index']);
    $r->post('/{school}/orga/protokolle/{space}', [OrgaNotesController::class, 'store']);
    $r->post('/{school}/orga/protokolle/eintrag/{id}', [OrgaNotesController::class, 'update']);
    $r->post('/{school}/orga/protokolle/eintrag/{id}/loeschen', [OrgaNotesController::class, 'destroy']);

    // ---------- Chat ----------
    $r->get('/{school}/orga/chat/{space}', [OrgaChatController::class, 'show']);
    $r->get('/{school}/orga/anhang/{id}', [OrgaChatController::class, 'attachment']);
    $r->get('/{school}/api/orga/chat/{space}', [OrgaChatController::class, 'apiMessages']);
    $r->post('/{school}/api/orga/chat/{space}/senden', [OrgaChatController::class, 'apiSend']);
    $r->post('/{school}/api/orga/nachrichten/{id}/bearbeiten', [OrgaChatController::class, 'apiEdit']);
    $r->post('/{school}/api/orga/nachrichten/{id}/loeschen', [OrgaChatController::class, 'apiDelete']);
    $r->post('/{school}/api/orga/nachrichten/{id}/anheften', [OrgaChatController::class, 'apiPin']);
    $r->post('/{school}/api/orga/nachrichten/{id}/reaktion', [OrgaChatController::class, 'apiReact']);
};
