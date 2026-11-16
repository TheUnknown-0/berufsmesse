<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions;
use App\Services\HelpCalls;

/**
 * Hilfe-Ruf am Messetag (Web und App).
 *
 * Die JSON-Aktionen sind doppelt registriert: unter /{school}/api/hilfe/… für
 * die Web-Seiten (Cookie-Sitzung, CSRF) und unter /api/v1/{school}/hilfe/… für
 * die App (Bearer-Token). Jede schreibende Aktion antwortet mit dem vollständigen
 * Zustand wie status(), damit die Oberfläche sofort stimmt.
 *
 * Regeln:
 *  - Rufen: Recht hilfe_rufen, nur für Räume, denen man im Aufsichtsplan zugeteilt
 *    ist, nur am Messetag der aktiven Edition, höchstens ein offener Ruf pro Raum.
 *  - Zusagen („Ich komme“): Recht hilfe_rufe_empfangen, nicht beim eigenen Ruf.
 *  - Mehr Hilfe und Schließen: der Rufende und alle, die demselben Raum zugeteilt
 *    sind; Schließen zusätzlich hilfe_rufe_verwalten.
 *  - Probealarm, Kategorien, Verlauf: hilfe_rufe_verwalten.
 */
final class HelpCallController extends Controller
{
    /** Abfrage-Intervall der Web-Seiten (die App fragt im Dienst alle 5 s). */
    private const WEB_POLL_SECONDS = 10;

    private ?HelpCalls $service = null;

    /** @var list<int>|null */
    private ?array $myRoomIds = null;

    // ---------------------------------------------------------------- Seiten

    /** GET /{school}/hilfe */
    public function page(array $params): string
    {
        $this->setup($params);

        return $this->render('pages/help/index', [
            'title' => 'Hilfe-Ruf',
            'statusUrl' => $this->ctx->schoolUrl('/api/hilfe'),
            'canManage' => $this->can(Permissions::HILFE_RUFE_VERWALTEN),
        ]);
    }

    /** GET /{school}/admin/hilfe */
    public function admin(array $params): string
    {
        $calls = $this->setup($params, Permissions::HILFE_RUFE_VERWALTEN);

        return $this->render('pages/help/admin', [
            'title' => 'Hilfe-Ruf verwalten',
            'edition' => $calls->edition(),
            'eventDay' => $calls->isEventDay(),
            'categories' => $calls->categories(),
            'textEnabled' => $calls->textEnabled(),
            'history' => $calls->history(),
            'onDuty' => $calls->onDuty(),
            'openTest' => array_values(array_filter(
                $calls->openCalls(),
                static fn (array $call): bool => (int) $call['is_test'] === 1,
            ))[0] ?? null,
        ]);
    }

    /** POST /{school}/admin/hilfe (Einstellungen speichern oder Probealarm) */
    public function adminSave(array $params): never
    {
        $calls = $this->setup($params, Permissions::HILFE_RUFE_VERWALTEN);
        $this->requireCsrf();
        $target = $this->ctx->schoolUrl('/admin/hilfe');
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'einstellungen') {
            $categories = HelpCalls::cleanCategories(preg_split('/\R/u', (string) ($_POST['categories'] ?? '')) ?: []);
            if ($categories === []) {
                $this->flash('error', 'Bitte mindestens eine Kategorie angeben.');
                $this->redirect($target);
            }
            $textEnabled = ($_POST['text_enabled'] ?? '') === '1';
            $calls->saveSettings($categories, $textEnabled);
            $this->ctx->audit->log(
                'Hilfe-Ruf-Einstellungen geändert',
                'info',
                'Kategorien: ' . implode(', ', $categories) . '; Textfeld: ' . ($textEnabled ? 'an' : 'aus'),
                $this->ctx->schoolId(),
            );
            $this->flash('success', 'Einstellungen gespeichert.');
            $this->redirect($target);
        }

        if ($action === 'probealarm') {
            try {
                $this->startTest($calls, (string) ($_POST['message'] ?? ''));
                $this->flash('success', 'Probealarm ausgelöst. Alle, die in der App „Im Dienst“ sind, bekommen ihn jetzt.');
            } catch (\DomainException $e) {
                $this->flash('error', $e->getMessage());
            }
            $this->redirect($target);
        }

        if ($action === 'probealarm_beenden') {
            $callId = (int) ($_POST['call_id'] ?? 0);
            $call = $calls->find($callId);
            if ($call !== null && (int) $call['is_test'] === 1 && $call['status'] === 'open') {
                $calls->close($callId, (int) $this->ctx->auth->id());
                $this->logCall('Hilfe-Ruf geschlossen', $call);
                $this->flash('success', 'Probealarm beendet.');
            }
            $this->redirect($target);
        }

        $this->flash('error', 'Unbekannte Aktion.');
        $this->redirect($target);
    }

    // ---------------------------------------------------------------- JSON

    /**
     * GET /{school}/api/hilfe, GET /api/v1/{school}/hilfe
     *
     * ?dienst=1 (nur App): Das Gerät ist „Im Dienst“ und meldet sich damit an.
     */
    public function status(array $params): array
    {
        $calls = $this->setup($params);
        if (($_GET['dienst'] ?? '') === '1' && $this->can(Permissions::HILFE_RUFE_EMPFANGEN)) {
            $calls->touchDuty($this->userId());
        }

        return $this->state($calls);
    }

    /** POST …/hilfe/rufen: {room_id, category, message?} */
    public function call(array $params): array
    {
        $calls = $this->setup($params, Permissions::HILFE_RUFEN);
        $this->requireCsrf();
        $in = $this->jsonInput();

        if (!$calls->isEventDay()) {
            return $this->jsonError('Hilfe rufen geht nur am Messetag.', 409);
        }
        $roomId = (int) ($in['room_id'] ?? 0);
        if (!in_array($roomId, $this->myRoomIds($calls), true)) {
            return $this->jsonError('Du bist diesem Raum nicht zugeteilt.', 403);
        }
        $category = trim((string) ($in['category'] ?? ''));
        if (!in_array($category, $calls->categories(), true)) {
            return $this->jsonError('Bitte eine Kategorie wählen.', 422);
        }
        $message = $calls->textEnabled() ? trim((string) ($in['message'] ?? '')) : '';

        try {
            $callId = $calls->create($this->userId(), $roomId, $category, $message !== '' ? $message : null);
        } catch (\DomainException $e) {
            return $this->jsonError($e->getMessage(), 409) + $this->state($calls);
        }

        $call = (array) $calls->find($callId);
        $this->logCall('Hilfe-Ruf ausgelöst', $call, 'warning');

        return ['call_id' => $callId] + $this->state($calls);
    }

    /** POST …/hilfe/probealarm: {message?} */
    public function test(array $params): array
    {
        $calls = $this->setup($params, Permissions::HILFE_RUFE_VERWALTEN);
        $this->requireCsrf();

        try {
            $callId = $this->startTest($calls, (string) ($this->jsonInput()['message'] ?? ''));
        } catch (\DomainException $e) {
            return $this->jsonError($e->getMessage(), 409) + $this->state($calls);
        }

        return ['call_id' => $callId] + $this->state($calls);
    }

    /** POST …/hilfe/{id}/kommen: „Ich komme“ bzw. „Ich komme auch“ */
    public function respond(array $params): array
    {
        $calls = $this->setup($params, Permissions::HILFE_RUFE_EMPFANGEN);
        $this->requireCsrf();
        $call = $this->openCall($calls, $params);

        if ((int) $call['caller_id'] === $this->userId()) {
            return $this->jsonError('Das ist dein eigener Ruf.', 409);
        }
        if ($calls->respond((int) $call['id'], $this->userId())) {
            $this->logCall('Hilfe-Ruf: Zusage', $call);
        }

        return $this->state($calls);
    }

    /** POST …/hilfe/{id}/mehr: „Brauche mehr Hilfe“ */
    public function more(array $params): array
    {
        $calls = $this->setup($params);
        $this->requireCsrf();
        $call = $this->openCall($calls, $params);
        if (!$this->mayEscalate($calls, $call)) {
            throw new HttpException(403, 'Nur wer im Raum eingeteilt ist, kann mehr Hilfe anfordern.');
        }

        $calls->requestMore((int) $call['id']);
        $this->logCall('Hilfe-Ruf: mehr Hilfe angefordert', $call, 'warning');

        return $this->state($calls);
    }

    /** POST …/hilfe/{id}/schliessen */
    public function close(array $params): array
    {
        $calls = $this->setup($params);
        $this->requireCsrf();
        $call = $this->openCall($calls, $params);
        if (!$this->mayEscalate($calls, $call) && !$this->can(Permissions::HILFE_RUFE_VERWALTEN)) {
            throw new HttpException(403, 'Diesen Ruf kannst du nicht schließen.');
        }

        $calls->close((int) $call['id'], $this->userId());
        $this->logCall('Hilfe-Ruf geschlossen', $call);

        return $this->state($calls);
    }

    /** POST /api/v1/{school}/hilfe/dienst: {on: bool}, nur App */
    public function duty(array $params): array
    {
        $calls = $this->setup($params, Permissions::HILFE_RUFE_EMPFANGEN);
        $on = (bool) ($this->jsonInput()['on'] ?? false);

        if ($on) {
            $wasOn = $calls->isOnDuty($this->userId());
            $calls->touchDuty($this->userId());
            if (!$wasOn) {
                $this->ctx->audit->log('Hilfe-Ruf: Dienst begonnen', 'info', 'App', $this->ctx->schoolId());
            }
        } elseif ($calls->endDuty($this->userId())) {
            $this->ctx->audit->log('Hilfe-Ruf: Dienst beendet', 'info', 'App', $this->ctx->schoolId());
        }

        return $this->state($calls);
    }

    // ---------------------------------------------------------------- intern

    /**
     * Schule, Anmeldung und Recht prüfen. Ohne $permission genügt eines der drei
     * Hilfe-Ruf-Rechte.
     */
    private function setup(array $params, ?string $permission = null): HelpCalls
    {
        $this->requireSchool($params['school']);

        $allowed = $permission !== null
            ? $this->can($permission)
            : $this->can(Permissions::HILFE_RUFEN)
                || $this->can(Permissions::HILFE_RUFE_EMPFANGEN)
                || $this->can(Permissions::HILFE_RUFE_VERWALTEN);
        if (!$allowed) {
            throw new HttpException(403);
        }

        return $this->service ??= new HelpCalls($this->ctx->db, $this->ctx->settings, (int) $this->ctx->schoolId());
    }

    private function can(string $permission): bool
    {
        return $this->ctx->auth->can($permission, $this->ctx->schoolId());
    }

    private function userId(): int
    {
        return (int) $this->ctx->auth->id();
    }

    /** @return list<int> */
    private function myRoomIds(HelpCalls $calls): array
    {
        if ($this->myRoomIds === null) {
            $this->myRoomIds = $this->can(Permissions::HILFE_RUFEN) ? $calls->assignedRoomIds($this->userId()) : [];
        }

        return $this->myRoomIds;
    }

    /** @return array<string, mixed> */
    private function openCall(HelpCalls $calls, array $params): array
    {
        $call = $calls->find((int) ($params['id'] ?? 0));
        if ($call === null || (int) $call['edition_id'] !== (int) ($calls->edition()['id'] ?? 0)) {
            throw new HttpException(404, 'Diesen Hilfe-Ruf gibt es nicht.');
        }
        if ($call['status'] !== 'open') {
            throw new HttpException(409, 'Dieser Hilfe-Ruf ist schon geschlossen.');
        }

        return $call;
    }

    /** Rufender, jemand aus demselben Raum, beim Probealarm die Verwaltung. */
    private function mayEscalate(HelpCalls $calls, array $call): bool
    {
        if ((int) $call['caller_id'] === $this->userId()) {
            return true;
        }
        if ((int) $call['is_test'] === 1) {
            return $this->can(Permissions::HILFE_RUFE_VERWALTEN);
        }

        return $call['room_id'] !== null && in_array((int) $call['room_id'], $this->myRoomIds($calls), true);
    }

    private function startTest(HelpCalls $calls, string $message): int
    {
        $callId = $calls->createTest($this->userId(), trim($message) !== '' ? $message : null);
        $this->logCall('Probealarm ausgelöst', (array) $calls->find($callId));

        return $callId;
    }

    private function logCall(string $action, array $call, string $severity = 'info'): void
    {
        $this->ctx->audit->log(
            $action,
            $severity,
            sprintf('Ruf #%d: %s', (int) $call['id'], HelpCalls::describe($call)),
            $this->ctx->schoolId(),
        );
    }

    /**
     * Gesamter Zustand für Web und App.
     *
     * @return array<string, mixed>
     */
    private function state(HelpCalls $calls): array
    {
        $userId = $this->userId();
        $canCall = $this->can(Permissions::HILFE_RUFEN);
        $canReceive = $this->can(Permissions::HILFE_RUFE_EMPFANGEN);
        $canManage = $this->can(Permissions::HILFE_RUFE_VERWALTEN);
        $rooms = $canCall ? $calls->assignedRooms($userId) : [];
        $this->myRoomIds ??= array_map(static fn (array $room): int => $room['id'], $rooms);
        $edition = $calls->edition();
        $eventDay = $calls->isEventDay();

        $list = [];
        foreach ($calls->openCalls() as $call) {
            $mine = (int) $call['caller_id'] === $userId
                || ($call['room_id'] !== null && in_array((int) $call['room_id'], $this->myRoomIds, true));
            if (!$mine && !$canReceive && !$canManage) {
                continue;
            }
            $list[] = $this->callJson($calls, $call, $canReceive, $canManage);
        }

        return [
            'success' => true,
            'now' => date('c'),
            'edition' => $edition !== null ? [
                'id' => (int) $edition['id'],
                'name' => (string) $edition['name'],
                'event_date' => $edition['event_date'],
            ] : null,
            'event_day' => $eventDay,
            'can_call' => $canCall && $rooms !== [],
            'call_enabled' => $canCall && $rooms !== [] && $eventDay,
            'can_receive' => $canReceive,
            'can_manage' => $canManage,
            'rooms' => $rooms,
            'categories' => $calls->categories(),
            'text_enabled' => $calls->textEnabled(),
            'max_text' => HelpCalls::MAX_MESSAGE,
            'calls' => $list,
            'on_duty' => count($calls->onDuty()),
            'i_am_on_duty' => $canReceive && $calls->isOnDuty($userId),
            'duty_until' => HelpCalls::dutyUntil(),
            'unanswered_seconds' => HelpCalls::UNANSWERED_SECONDS,
            'web_poll_seconds' => self::WEB_POLL_SECONDS,
        ];
    }

    /** @return array<string, mixed> */
    private function callJson(HelpCalls $calls, array $call, bool $canReceive, bool $canManage): array
    {
        $userId = $this->userId();
        $effective = HelpCalls::effective($call);
        $isTest = (int) $call['is_test'] === 1;
        $isCaller = (int) $call['caller_id'] === $userId;
        $inMyRoom = $call['room_id'] !== null && in_array((int) $call['room_id'], (array) $this->myRoomIds, true);
        $responders = array_map(
            static fn (array $r): array => ['id' => $r['id'], 'name' => $r['name']],
            $call['responses'],
        );
        $iRespond = in_array($userId, array_column($responders, 'id'), true);
        $mayEscalate = $isCaller || ($isTest ? $canManage : $inMyRoom);

        return [
            'id' => (int) $call['id'],
            'is_test' => $isTest,
            'title' => $isTest ? 'TEST: ' . HelpCalls::TEST_CATEGORY : 'Raum ' . (string) $call['room_label'],
            'room' => $call['room_id'] !== null ? [
                'id' => (int) $call['room_id'],
                'label' => (string) $call['room_label'],
            ] : null,
            'category' => (string) $call['category'],
            'message' => $call['message'],
            'caller' => $call['caller_id'] !== null ? [
                'id' => (int) $call['caller_id'],
                'name' => HelpCalls::name($call['caller_firstname'] ?? null, $call['caller_lastname'] ?? null),
            ] : null,
            'created_at' => date('c', (int) strtotime((string) $call['created_at'])),
            'age_seconds' => (int) $call['age_seconds'],
            'status' => (string) $call['status'],
            'round' => $effective['round'],
            'reason' => $effective['reason'],
            'alert_age_seconds' => $effective['alert_age'],
            'answered_since_alert' => $effective['answered_since_alert'],
            'nobody_coming' => $effective['nobody_coming'],
            'more_count' => (int) $call['more_count'],
            'responders' => $responders,
            'i_am_caller' => $isCaller,
            'mine' => $isCaller || $inMyRoom,
            'i_respond' => $iRespond,
            'may_respond' => $canReceive && !$isCaller && !$iRespond,
            'may_escalate' => $mayEscalate,
            'may_close' => $mayEscalate || $canManage,
        ];
    }
}
