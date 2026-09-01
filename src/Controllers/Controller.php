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
            // API-Routen bekommen 401 statt einer Weiterleitung: Ein fetch()
            // folgt dem Redirect und erhält HTML — der Aufrufer könnte das
            // nicht von einer fachlichen Ablehnung unterscheiden und würde
            // gepufferte Scans verwerfen.
            if ($this->isApiRequest()) {
                throw new HttpException(401, 'Deine Anmeldung ist abgelaufen. Bitte neu anmelden.');
            }

            $target = $this->ctx->school !== null
                ? $this->ctx->schoolUrl('/login')
                : $this->ctx->url('/login');
            $this->redirect($target . '?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
        }

        if ((int) ($user['must_change_password'] ?? 0) === 1
            && !str_contains($_SERVER['REQUEST_URI'] ?? '', '/passwort-aendern')) {
            if ($this->isApiRequest()) {
                throw new HttpException(401, 'Bitte zuerst das Passwort ändern.');
            }
            $this->redirect($this->ctx->url('/passwort-aendern'));
        }

        return $user;
    }

    /** Erwartet der Aufrufer JSON (API-Route oder Accept-Header)? */
    protected function isApiRequest(): bool
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '';
        if (str_contains($path, '/api/')) {
            return true;
        }

        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
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

    /**
     * Prüft einen vom Gerät gelieferten Erfassungszeitpunkt (offline
     * gepufferte Scans).
     *
     * Der Wert kommt vom Client und ist damit manipulierbar — deshalb wird
     * er eng begrenzt: nur Vergangenheit, höchstens sechs Stunden zurück.
     * Alles andere wird verworfen, dann gilt „jetzt“.
     */
    protected function offlineTimestamp(mixed $raw): ?string
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $timestamp = strtotime($raw);
        if ($timestamp === false) {
            return null;
        }

        $now = time();
        if ($timestamp > $now || $timestamp < $now - 6 * 3600) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Anfragedaten einer JSON-Schnittstelle.
     *
     * Der Scanner und die Check-in-Seite senden JSON, klassische Formulare
     * senden $_POST — beides muss dieselbe Aktion bedienen.
     *
     * @return array<string, mixed>
     */
    protected function jsonInput(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return $_POST;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : $_POST;
    }

    /** JSON-Fehlerantwort als Array (vom Front-Controller serialisiert). */
    protected function jsonError(string $message, int $status = 400): array
    {
        http_response_code($status);

        return ['success' => false, 'error' => $message];
    }
}
