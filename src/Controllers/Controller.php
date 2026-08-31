<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Context;
use App\Core\Csrf;
use App\Core\HttpException;

/**
 * Basisklasse aller Controller.
 *
 * Konventionen:
 *  - Aktionen geben einen String (HTML) oder ein Array (JSON) zurück,
 *    oder beenden den Request per redirect().
 *  - Guards (requireLogin/requireSchool/requirePermission/requireCsrf)
 *    werden am Anfang der Aktion aufgerufen und werfen HttpException.
 */
abstract class Controller
{
    public function __construct(protected readonly Context $ctx)
    {
    }

    // ---------- Guards ----------

    /** @return array<string, mixed> Der eingeloggte Benutzer. */
    protected function requireLogin(): array
    {
        $user = $this->ctx->auth->user();
        if ($user === null) {
            $target = $this->ctx->school !== null
                ? $this->ctx->schoolUrl('/login')
                : $this->ctx->url('/login');
            $this->redirect($target . '?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
        }

        if ((int) ($user['must_change_password'] ?? 0) === 1
            && !str_contains($_SERVER['REQUEST_URI'] ?? '', '/passwort-aendern')) {
            $this->redirect($this->ctx->url('/passwort-aendern'));
        }

        return $user;
    }

    /**
     * Lädt die Schule aus dem Routenparameter in den Kontext und prüft,
     * dass der eingeloggte Benutzer Zugriff auf diese Schule hat.
     *
     * @return array<string, mixed> Die Schule.
     */
    protected function requireSchool(string $slug): array
    {
        $school = $this->ctx->school ?? $this->ctx->loadSchool($slug);
        $this->requireLogin();

        if (!$this->ctx->auth->hasSchoolAccess((int) $school['id'])) {
            throw new HttpException(403, 'Du hast keinen Zugriff auf diese Schule.');
        }

        return $school;
    }

    /** Prüft eine granulare Berechtigung im Kontext der aktuellen Schule. */
    protected function requirePermission(string $permission): void
    {
        if (!$this->ctx->auth->can($permission, $this->ctx->schoolId())) {
            throw new HttpException(403);
        }
    }

    /** Nur globale Admins. */
    protected function requireAdmin(): void
    {
        $this->requireLogin();
        if (!$this->ctx->auth->isAdmin()) {
            throw new HttpException(403);
        }
    }

    /** CSRF-Prüfung für schreibende Requests (POST-Feld oder X-CSRF-Token-Header). */
    protected function requireCsrf(): void
    {
        $token = $_POST[Csrf::FIELD] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!$this->ctx->csrf->validate(is_string($token) ? $token : null)) {
            throw new HttpException(419);
        }
    }

    // ---------- Antworten ----------

    /** Rendert ein Seiten-Template im Standard-Layout. */
    protected function render(string $template, array $data = [], string $layout = 'app'): string
    {
        return $this->ctx->view->render($template, $data, $layout);
    }

    protected function redirect(string $url): never
    {
        header('Location: ' . $url, true, 303);
        exit;
    }

    protected function flash(string $type, string $message): void
    {
        $this->ctx->session->flash($type, $message);
    }

    /** JSON-Fehlerantwort als Array (vom Front-Controller serialisiert). */
    protected function jsonError(string $message, int $status = 400): array
    {
        http_response_code($status);

        return ['success' => false, 'error' => $message];
    }
}
