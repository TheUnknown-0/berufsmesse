<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Services\ApiTokens;
use App\Services\AppApi;
use App\Services\AppNavigation;
use App\Services\Audit;
use App\Services\Changelog;
use App\Services\Customization;
use App\Services\LoginThrottle;

/**
 * App-Schnittstelle, Version 1. Anmeldung per Bearer-Token (siehe Services\AppApi).
 *
 * Alle Pfade in Antworten sind relativ zur Anwendung (ohne BASE_URL). Die App
 * hängt sie an die Server-Adresse, die der Nutzer eingegeben hat.
 *
 * Kein CSRF-Token: Der Bearer-Header wird vom Browser nie von selbst
 * mitgeschickt, und die Anfragen laufen ohne Cookie-Sitzung.
 */
final class V1Controller extends Controller
{
    /** GET /api/v1/info — Erkennung des Servers in der App, ohne Anmeldung. */
    public function info(array $params): array
    {
        return [
            'success' => true,
            'app' => 'Berufsmesse',
            'api' => 1,
            'version' => Changelog::currentVersion(),
            'site_password' => $this->ctx->settings->getBool('site_password_enabled'),
        ];
    }

    /** GET /api/v1/schulen — aktive Schulen (dieselbe Liste wie die Startseite). */
    public function schools(array $params): array
    {
        $rows = $this->ctx->db->fetchAll('SELECT * FROM schools WHERE is_active = 1 ORDER BY name');

        return ['success' => true, 'schools' => array_map($this->schoolJson(...), $rows)];
    }

    /** POST /api/v1/login — {school?, username, password, device?, site_password?} */
    public function login(array $params): array
    {
        $in = $this->jsonInput();
        $username = trim((string) ($in['username'] ?? ''));
        $password = (string) ($in['password'] ?? '');
        $slug = trim((string) ($in['school'] ?? ''));
        $ip = Audit::clientIp();

        if ($this->ctx->settings->getBool('site_password_enabled')) {
            $hash = $this->ctx->settings->get('site_password');
            if ($hash === null || !password_verify((string) ($in['site_password'] ?? ''), $hash)) {
                return $this->jsonError('Der Zugangscode der Seite ist falsch.', 403) + ['code' => 'site_password'];
            }
        }

        if ($username === '' || $password === '') {
            return $this->jsonError('Bitte Benutzername und Passwort eingeben.', 422);
        }

        $school = null;
        if ($slug !== '') {
            $school = $this->ctx->db->fetchOne('SELECT * FROM schools WHERE slug = ? AND is_active = 1', [$slug]);
            if ($school === null) {
                return $this->jsonError('Diese Schule gibt es nicht.', 404);
            }
        }

        $throttle = new LoginThrottle($this->ctx->db);
        if ($throttle->isBlocked($username, $ip)) {
            $this->ctx->audit->log('App-Login blockiert (Rate-Limit)', 'warning', "Benutzer: {$username}", $school['id'] ?? null);

            return $this->jsonError('Zu viele Fehlversuche. Bitte warte 5 Minuten.', 429);
        }

        // Gleiche Suche wie AuthController::findLoginUser: am Schul-Login auch globale Konten.
        $user = $school !== null
            ? $this->ctx->db->fetchOne(
                'SELECT * FROM users
                 WHERE username = ? AND (school_id = ? OR (school_id IS NULL AND role IN (\'admin\', \'exhibitor\')))
                 ORDER BY school_id IS NULL LIMIT 1',
                [$username, (int) $school['id']],
            )
            : $this->ctx->db->fetchOne('SELECT * FROM users WHERE username = ? AND school_id IS NULL LIMIT 1', [$username]);

        if ($user === null || $user['password'] === null || !password_verify($password, (string) $user['password'])) {
            $throttle->recordFailure($username, $ip);
            $this->ctx->audit->log('App-Login fehlgeschlagen', 'warning', "Benutzer: {$username}", $school['id'] ?? null);

            return $this->jsonError('Benutzername oder Passwort ist falsch.', 401);
        }

        if (password_needs_rehash((string) $user['password'], PASSWORD_DEFAULT)) {
            $this->ctx->db->run(
                'UPDATE users SET password = ? WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), (int) $user['id']],
            );
        }

        $throttle->clear($username);
        $device = trim((string) ($in['device'] ?? '')) ?: 'App';
        $token = (new ApiTokens($this->ctx->db))->issue((int) $user['id'], $device, $ip);
        $this->ctx->audit->log(
            'App-Login erfolgreich',
            'info',
            "Benutzer: {$username} (Rolle: {$user['role']}, Gerät: {$device})",
            $user['school_id'] !== null ? (int) $user['school_id'] : ($school['id'] ?? null),
            (int) $user['id'],
            $username,
        );

        return [
            'success' => true,
            'token' => $token,
            'expires_in_days' => ApiTokens::TOKEN_DAYS,
        ];
    }

    /** POST /api/v1/logout — widerruft das Token dieser Anfrage. */
    public function logout(array $params): array
    {
        $tokenId = $this->ctx->session->get(AppApi::TOKEN_ID_KEY);
        if (is_int($tokenId)) {
            (new ApiTokens($this->ctx->db))->revoke($tokenId);
        }

        return ['success' => true];
    }

    /**
     * GET /api/v1/ich — Konto, Schulen und Hinweise für die App.
     * Bewusst ohne requireLogin(): Auch wer das Passwort ändern muss, bekommt
     * eine Antwort (must_change_password) und wird von der App weitergeleitet.
     */
    public function me(array $params): array
    {
        $user = $this->currentUser();
        $role = (string) $user['role'];

        if ($role === 'admin') {
            $schools = $this->ctx->db->fetchAll('SELECT * FROM schools WHERE is_active = 1 ORDER BY name');
        } elseif ($role === 'exhibitor') {
            $schools = $this->ctx->db->fetchAll(
                'SELECT DISTINCT s.*
                 FROM exhibitor_users eu
                 JOIN exhibitors e ON e.id = eu.exhibitor_id
                 JOIN messe_editions me ON me.id = e.edition_id
                 JOIN schools s ON s.id = me.school_id
                 WHERE eu.user_id = ? AND eu.status = \'active\' AND eu.invite_accepted = 1 AND s.is_active = 1
                 ORDER BY s.name',
                [(int) $user['id']],
            );
        } else {
            $schools = $user['school_id'] !== null
                ? $this->ctx->db->fetchAll('SELECT * FROM schools WHERE id = ? AND is_active = 1', [(int) $user['school_id']])
                : [];
        }

        return [
            'success' => true,
            'user' => [
                'id' => (int) $user['id'],
                'username' => (string) $user['username'],
                'firstname' => (string) $user['firstname'],
                'lastname' => (string) $user['lastname'],
                'email' => $user['email'],
                'class' => $user['class'],
                'role' => $role,
                'role_label' => self::roleLabel($role),
                'must_change_password' => (int) $user['must_change_password'] === 1,
            ],
            'home_school' => $user['school_id'] !== null
                ? $this->ctx->db->fetchValue('SELECT slug FROM schools WHERE id = ?', [(int) $user['school_id']])
                : null,
            'schools' => array_map($this->schoolJson(...), $schools),
            'global_admin' => $role === 'admin' ? '/global-admin' : null,
        ];
    }

    /** GET /api/v1/{school}/funktionen — alles, was das Konto in dieser Schule sehen darf. */
    public function features(array $params): array
    {
        $school = $this->requireSchool($params['school']);
        $user = $this->ctx->auth->user();
        $edition = $this->ctx->edition;

        return [
            'success' => true,
            'school' => $this->schoolJson($school),
            'edition' => $edition !== null ? [
                'id' => (int) $edition['id'],
                'name' => (string) $edition['name'],
                'year' => (int) $edition['year'],
                'status' => (string) $edition['status'],
            ] : null,
            'permissions' => in_array($user['role'], ['admin', 'school_admin'], true)
                ? ['*']
                : $this->ctx->auth->permissions(),
            'sections' => (new AppNavigation($this->ctx))->sections(),
        ];
    }

    /**
     * POST /api/v1/web-sitzung — {path, theme?}. Liefert einen Einmal-Link, der in
     * der App eine angemeldete Web-Sitzung öffnet und zu path weiterleitet.
     */
    public function webSession(array $params): array
    {
        $user = $this->currentUser();
        $path = ApiTokens::safePath($this->jsonInput()['path'] ?? null);
        if ($path === null) {
            return $this->jsonError('Ungültiges Ziel.', 422);
        }

        $tokenId = $this->ctx->session->get(AppApi::TOKEN_ID_KEY);
        $theme = $this->jsonInput()['theme'] ?? null;
        $code = (new ApiTokens($this->ctx->db))->createHandoff(
            (int) $user['id'],
            $path,
            is_int($tokenId) ? $tokenId : null,
            is_string($theme) ? $theme : null,
        );

        return [
            'success' => true,
            'path' => '/app/uebergabe/' . $code,
            'expires_in' => ApiTokens::HANDOFF_SECONDS,
        ];
    }

    // ---------------------------------------------------------- Hilfen

    /** @return array<string, mixed> */
    private function currentUser(): array
    {
        $user = $this->ctx->auth->user();
        if ($user === null) {
            throw new HttpException(401, 'Deine Anmeldung ist abgelaufen. Bitte neu anmelden.');
        }

        return $user;
    }

    /** @param array<string, mixed> $school */
    private function schoolJson(array $school): array
    {
        $theme = (new Customization($this->ctx->settings, (int) $school['id']))->theme();

        return [
            'slug' => (string) $school['slug'],
            'name' => (string) $school['name'],
            'logo' => !empty($school['logo']) ? '/medien/logos/' . $school['logo'] : null,
            'primary_color' => $theme['primary'],
        ];
    }

    private static function roleLabel(string $role): string
    {
        return match ($role) {
            'admin' => 'Global-Admin',
            'school_admin' => 'Schul-Admin',
            'orga' => 'Orga-Team',
            'teacher' => 'Lehrkraft',
            'student' => 'Schüler/in',
            'exhibitor' => 'Aussteller',
            default => $role,
        };
    }
}
