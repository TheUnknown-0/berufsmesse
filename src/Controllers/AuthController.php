<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Services\Audit;
use App\Services\EmailService;
use App\Services\LoginCodeService;
use App\Services\LoginThrottle;
use App\Services\Mailer;

/**
 * Login (global & schulspezifisch), Logout, Seitenpasswort,
 * Passwortwechsel, Anmeldung per E-Mail-Code, Schüler-Selbstregistrierung.
 */
final class AuthController extends Controller
{
    public const MIN_PASSWORD_LENGTH = 8;

    private const CODE_SESSION_KEY = '_login_code_username';
    private const CODE_LOGIN_FLAG = '_login_via_code';

    // ---------- Login ----------

    /** GET /login und GET /{school}/login */
    public function showLogin(array $params): string
    {
        if (isset($params['school'])) {
            $this->ctx->loadSchool($params['school']);
        }
        if ($this->ctx->auth->check()) {
            $this->redirect($this->afterLoginUrl($this->ctx->auth->user()));
        }

        return $this->render('pages/auth/login', [
            'title' => 'Anmelden',
            'redirect' => $this->safeRedirectTarget($_GET['redirect'] ?? null),
            'codeLoginAvailable' => (string) ($this->ctx->config['mail']['host'] ?? '') !== '',
        ], 'minimal');
    }

    /** POST /login und POST /{school}/login */
    public function login(array $params): string
    {
        $school = isset($params['school']) ? $this->ctx->loadSchool($params['school']) : null;
        $this->requireCsrf();

        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $ip = Audit::clientIp();

        $throttle = new LoginThrottle($this->ctx->db);
        if ($username === '' || $password === '') {
            return $this->loginFailed('Bitte Benutzername und Passwort eingeben.');
        }
        if ($throttle->isBlocked($username, $ip)) {
            $this->ctx->audit->log('Login blockiert (Rate-Limit)', 'warning', "Benutzer: {$username}", $school['id'] ?? null);

            return $this->loginFailed('Zu viele Fehlversuche. Bitte warte 5 Minuten.');
        }

        $user = $this->findLoginUser($username, $school);

        if ($user === null || $user['password'] === null || !password_verify($password, (string) $user['password'])) {
            $throttle->recordFailure($username, $ip);
            $this->ctx->audit->log('Login fehlgeschlagen', 'warning', "Benutzer: {$username}", $school['id'] ?? null);

            return $this->loginFailed('Benutzername oder Passwort ist falsch.');
        }

        // Bcrypt-Rehash bei veraltetem Algorithmus/Kostenfaktor
        if (password_needs_rehash((string) $user['password'], PASSWORD_DEFAULT)) {
            $this->ctx->db->run(
                'UPDATE users SET password = ? WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), (int) $user['id']],
            );
        }

        $throttle->clear($username);
        $this->ctx->auth->loginAs($user);
        $this->ctx->audit->log(
            'Login erfolgreich',
            'info',
            "Benutzer: {$username} (Rolle: {$user['role']})",
            $user['school_id'] !== null ? (int) $user['school_id'] : ($school['id'] ?? null),
            (int) $user['id'],
            $username,
        );

        $redirect = $this->safeRedirectTarget($_POST['redirect'] ?? null);
        $this->redirect($redirect ?? $this->afterLoginUrl($user));
    }

    // ---------- Anmeldung per Code ----------

    /** GET /login-code und GET /{school}/login-code */
    public function showCodeRequest(array $params): string
    {
        $school = isset($params['school']) ? $this->ctx->loadSchool($params['school']) : null;
        if ($this->ctx->auth->check()) {
            $this->redirect($this->afterLoginUrl($this->ctx->auth->user()));
        }

        return $this->render('pages/auth/login-code', [
            'title' => 'Anmeldung per Code',
            'redirect' => $this->safeRedirectTarget($_GET['redirect'] ?? null),
            'username' => (string) ($this->ctx->session->get(self::CODE_SESSION_KEY) ?? ''),
        ], 'minimal');
    }

    /** POST /login-code und POST /{school}/login-code */
    public function requestCode(array $params): string
    {
        $school = isset($params['school']) ? $this->ctx->loadSchool($params['school']) : null;
        $this->requireCsrf();

        $username = trim((string) ($_POST['username'] ?? ''));
        $redirect = $this->safeRedirectTarget($_POST['redirect'] ?? null);
        $ip = Audit::clientIp();
        $back = $school !== null ? $this->ctx->schoolUrl('/login-code') : $this->ctx->url('/login-code');

        if ($username === '') {
            $this->flash('error', 'Bitte einen Benutzernamen eingeben.');
            $this->redirect($back);
        }

        $throttle = new LoginThrottle($this->ctx->db);
        if ($throttle->isBlocked($username, $ip)) {
            $this->ctx->audit->log('Code-Anmeldung blockiert (Rate-Limit)', 'warning', "Benutzer: {$username}", $school['id'] ?? null);
            $this->flash('error', 'Zu viele Versuche. Bitte warte 5 Minuten.');
            $this->redirect($back);
        }
        // Jede Anfrage zählt als "Fehlversuch" für das Rate-Limit — sonst
        // könnte man beliebig oft Codes an ein fremdes Postfach schicken.
        $throttle->recordFailure($username, $ip);

        $user = $this->findLoginUser($username, $school);
        if ($user !== null && !empty($user['email'])) {
            $codeService = new LoginCodeService($this->ctx->db);
            if (!$codeService->withinCooldown((int) $user['id'])) {
                $code = $codeService->issue((int) $user['id'], $ip);
                $this->emailService()->sendLoginCode($user, $code);
                $this->ctx->audit->log(
                    'Anmeldecode angefordert',
                    'info',
                    "Benutzer: {$username}",
                    $user['school_id'] !== null ? (int) $user['school_id'] : ($school['id'] ?? null),
                    (int) $user['id'],
                    $username,
                );
            } else {
                // Bewusst kein Hinweis auf den Cooldown nach außen — sonst liesse sich
                // daraus ablesen, dass das Konto (mit E-Mail) existiert.
                $this->ctx->audit->log('Anmeldecode-Anfrage übersprungen (Cooldown)', 'info', "Benutzer: {$username}", $school['id'] ?? null, (int) $user['id'], $username);
            }
        } else {
            // Absichtlich keine Unterscheidung nach außen: unbekannter Benutzername
            // oder fehlende E-Mail sehen für den Aufrufer gleich aus.
            $this->ctx->audit->log('Anmeldecode angefordert (kein Versand möglich)', 'info', "Benutzer: {$username}", $school['id'] ?? null);
        }

        $this->ctx->session->set(self::CODE_SESSION_KEY, $username);
        $this->flash('info', 'Falls das Konto existiert und eine E-Mail-Adresse hinterlegt ist, haben wir einen Code verschickt.');
        $verifyUrl = $school !== null ? $this->ctx->schoolUrl('/login-code/bestaetigen') : $this->ctx->url('/login-code/bestaetigen');
        $this->redirect($verifyUrl . ($redirect !== null ? '?redirect=' . rawurlencode($redirect) : ''));
    }

    /** GET /login-code/bestaetigen und GET /{school}/login-code/bestaetigen */
    public function showCodeVerify(array $params): string
    {
        $school = isset($params['school']) ? $this->ctx->loadSchool($params['school']) : null;
        if ($this->ctx->auth->check()) {
            $this->redirect($this->afterLoginUrl($this->ctx->auth->user()));
        }
        $username = (string) ($this->ctx->session->get(self::CODE_SESSION_KEY) ?? '');
        if ($username === '') {
            $this->redirect($school !== null ? $this->ctx->schoolUrl('/login-code') : $this->ctx->url('/login-code'));
        }

        return $this->render('pages/auth/login-code-verify', [
            'title' => 'Code eingeben',
            'username' => $username,
            'redirect' => $this->safeRedirectTarget($_GET['redirect'] ?? null),
        ], 'minimal');
    }

    /** POST /login-code/bestaetigen und POST /{school}/login-code/bestaetigen */
    public function verifyCode(array $params): string
    {
        $school = isset($params['school']) ? $this->ctx->loadSchool($params['school']) : null;
        $this->requireCsrf();

        $username = (string) ($this->ctx->session->get(self::CODE_SESSION_KEY) ?? '');
        $code = trim((string) ($_POST['code'] ?? ''));
        $ip = Audit::clientIp();
        $verifyUrl = $school !== null ? $this->ctx->schoolUrl('/login-code/bestaetigen') : $this->ctx->url('/login-code/bestaetigen');

        if ($username === '') {
            $this->redirect($school !== null ? $this->ctx->schoolUrl('/login-code') : $this->ctx->url('/login-code'));
        }

        $throttle = new LoginThrottle($this->ctx->db);
        if ($throttle->isBlocked($username, $ip)) {
            $this->ctx->audit->log('Code-Anmeldung blockiert (Rate-Limit)', 'warning', "Benutzer: {$username}", $school['id'] ?? null);
            $this->flash('error', 'Zu viele Versuche. Bitte warte 5 Minuten.');
            $this->redirect($verifyUrl);
        }

        $user = $this->findLoginUser($username, $school);
        $valid = $code !== '' && $user !== null
            && (new LoginCodeService($this->ctx->db))->verify((int) $user['id'], $code);

        if (!$valid) {
            $throttle->recordFailure($username, $ip);
            $this->ctx->audit->log('Code-Anmeldung fehlgeschlagen', 'warning', "Benutzer: {$username}", $school['id'] ?? null);
            $this->flash('error', 'Der Code ist ungültig oder abgelaufen.');
            $this->redirect($verifyUrl);
        }

        $throttle->clear($username);
        $this->ctx->session->remove(self::CODE_SESSION_KEY);
        $this->ctx->auth->loginAs($user);
        // Merkt sich für diese Session, dass die Anmeldung per Code kam — damit
        // in /passwort-aendern ein neues Passwort ohne Kenntnis des alten
        // (eben vergessenen) gesetzt werden kann.
        $this->ctx->session->set(self::CODE_LOGIN_FLAG, true);
        $this->ctx->audit->log(
            'Code-Anmeldung erfolgreich',
            'info',
            "Benutzer: {$username} (Rolle: {$user['role']})",
            $user['school_id'] !== null ? (int) $user['school_id'] : ($school['id'] ?? null),
            (int) $user['id'],
            $username,
        );

        $redirect = $this->safeRedirectTarget($_POST['redirect'] ?? null);
        $this->redirect($redirect ?? $this->afterLoginUrl($user));
    }

    /** POST /logout */
    public function logout(array $params): string
    {
        $this->requireCsrf();
        $user = $this->ctx->auth->user();
        if ($user !== null) {
            $this->ctx->audit->log('Logout', 'info', null, $user['school_id'] !== null ? (int) $user['school_id'] : null);
        }
        $this->ctx->auth->logout();
        $this->redirect($this->ctx->url('/'));
    }

    // ---------- Seitenpasswort ----------

    /** GET /zugang */
    public function showSitePassword(array $params): string
    {
        if (!$this->ctx->settings->getBool('site_password_enabled')
            || $this->ctx->session->get('site_authenticated') === true) {
            $this->redirect($this->ctx->url('/'));
        }

        return $this->render('pages/auth/site-password', [
            'title' => 'Zugangscode',
            'redirect' => $this->safeRedirectTarget($_GET['redirect'] ?? null),
        ], 'minimal');
    }

    /** POST /zugang */
    public function sitePassword(array $params): string
    {
        $this->requireCsrf();
        $hash = $this->ctx->settings->get('site_password');
        $code = (string) ($_POST['code'] ?? '');

        if ($hash === null || !password_verify($code, $hash)) {
            $this->flash('error', 'Der Zugangscode ist falsch.');
            $this->redirect($this->ctx->url('/zugang'));
        }

        $this->ctx->session->set('site_authenticated', true);
        $this->redirect($this->safeRedirectTarget($_POST['redirect'] ?? null) ?? $this->ctx->url('/'));
    }

    // ---------- Passwortwechsel ----------

    /** GET /passwort-aendern */
    public function showChangePassword(array $params): string
    {
        $user = $this->ctx->auth->user();
        if ($user === null) {
            $this->redirect($this->ctx->url('/login'));
        }

        return $this->render('pages/auth/change-password', [
            'title' => 'Passwort ändern',
            'forced' => (int) $user['must_change_password'] === 1,
            'viaCode' => $this->ctx->session->get(self::CODE_LOGIN_FLAG) === true,
        ], 'minimal');
    }

    /** POST /passwort-aendern */
    public function changePassword(array $params): string
    {
        $user = $this->ctx->auth->user();
        if ($user === null) {
            $this->redirect($this->ctx->url('/login'));
        }
        $this->requireCsrf();

        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');

        $forced = (int) $user['must_change_password'] === 1;
        // Wer per Code angemeldet ist, hat sein Passwort per Definition vergessen —
        // das alte Passwort abzufragen wäre hier sinnlos.
        $skipCurrent = $forced || $this->ctx->session->get(self::CODE_LOGIN_FLAG) === true;
        if (!$skipCurrent && ($user['password'] === null || !password_verify($current, (string) $user['password']))) {
            $this->flash('error', 'Das aktuelle Passwort ist falsch.');
            $this->redirect($this->ctx->url('/passwort-aendern'));
        }
        if ($error = $this->validateNewPassword($new, $confirm)) {
            $this->flash('error', $error);
            $this->redirect($this->ctx->url('/passwort-aendern'));
        }

        $this->ctx->db->run(
            'UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?',
            [password_hash($new, PASSWORD_DEFAULT), (int) $user['id']],
        );
        $this->ctx->audit->log('Passwort geändert', 'info', null, $user['school_id'] !== null ? (int) $user['school_id'] : null);
        $this->flash('success', 'Dein Passwort wurde geändert.');
        $this->redirect($this->afterLoginUrl(array_merge($user, ['must_change_password' => 0])));
    }

    // ---------- Schüler-Selbstregistrierung ----------

    /** GET /{school}/registrieren */
    public function showRegister(array $params): string
    {
        $school = $this->ctx->loadSchool($params['school']);
        $this->assertRegistrationOpen((int) $school['id']);

        return $this->render('pages/auth/register', ['title' => 'Registrieren'], 'minimal');
    }

    /** POST /{school}/registrieren */
    public function register(array $params): string
    {
        $school = $this->ctx->loadSchool($params['school']);
        $this->assertRegistrationOpen((int) $school['id']);
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();

        $username = trim((string) ($_POST['username'] ?? ''));
        $firstname = trim((string) ($_POST['firstname'] ?? ''));
        $lastname = trim((string) ($_POST['lastname'] ?? ''));
        $class = trim((string) ($_POST['class'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');

        $back = $this->ctx->schoolUrl('/registrieren');
        $this->ctx->session->rememberInput($_POST);

        // Rate-Limit gegen Massenregistrierung (gleiche Mechanik wie Login)
        $throttle = new LoginThrottle($this->ctx->db);
        $ip = Audit::clientIp();
        if ($throttle->isIpBlocked($ip)) {
            $this->flash('error', 'Zu viele Registrierungen. Bitte warte einen Moment.');
            $this->redirect($back);
        }

        if ($username === '' || $firstname === '' || $lastname === '') {
            $this->flash('error', 'Bitte fülle alle Pflichtfelder aus.');
            $this->redirect($back);
        }
        if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username)) {
            $this->flash('error', 'Der Benutzername darf 3–50 Zeichen lang sein (Buchstaben, Zahlen, Punkt, Minus, Unterstrich).');
            $this->redirect($back);
        }
        if ($error = $this->validateNewPassword($password, $confirm)) {
            $this->flash('error', $error);
            $this->redirect($back);
        }

        $exists = $this->ctx->db->fetchValue(
            'SELECT 1 FROM users WHERE username = ? AND school_key = ?',
            [$username, (int) $school['id']],
        );
        if ($exists !== null) {
            $throttle->recordFailure(LoginThrottle::REGISTRATION_MARKER, $ip);
            $this->flash('error', 'Dieser Benutzername ist bereits vergeben.');
            $this->redirect($back);
        }

        // Rolle ist bei Selbstregistrierung IMMER student.
        $this->ctx->db->run(
            'INSERT INTO users (school_id, edition_id, username, firstname, lastname, class, role, password)
             VALUES (?, ?, ?, ?, ?, ?, \'student\', ?)',
            [
                (int) $school['id'],
                (int) $edition['id'],
                $username, $firstname, $lastname,
                $class !== '' ? $class : null,
                password_hash($password, PASSWORD_DEFAULT),
            ],
        );
        $userId = $this->ctx->db->lastInsertId();
        $this->ctx->audit->log('Selbstregistrierung', 'info', "Benutzer: {$username}", (int) $school['id'], $userId, $username);

        $user = $this->ctx->db->fetchOne('SELECT * FROM users WHERE id = ?', [$userId]);
        $this->ctx->auth->loginAs($user);
        $this->flash('success', 'Willkommen! Dein Konto wurde erstellt.');
        $this->redirect($this->ctx->schoolUrl('/'));
    }

    // ---------- Helfer ----------

    /**
     * Sucht den passenden Benutzer für den Login-Kontext.
     * Schul-Login: Nutzer dieser Schule oder globale Admins.
     * Globaler Login: globale Nutzer (admin/exhibitor).
     */
    private function findLoginUser(string $username, ?array $school): ?array
    {
        if ($school !== null) {
            // Auch globale Konten (Admins UND Aussteller) dürfen sich am Schul-Login
            // anmelden — der Schulzugriff wird danach über hasSchoolAccess() geprüft.
            return $this->ctx->db->fetchOne(
                'SELECT * FROM users
                 WHERE username = ? AND (school_id = ? OR (school_id IS NULL AND role IN (\'admin\', \'exhibitor\')))
                 ORDER BY school_id IS NULL LIMIT 1',
                [$username, (int) $school['id']],
            );
        }

        return $this->ctx->db->fetchOne(
            'SELECT * FROM users WHERE username = ? AND school_id IS NULL LIMIT 1',
            [$username],
        );
    }

    private function afterLoginUrl(array $user): string
    {
        // Schulgebundene Nutzer → in ihre Schule
        if ($user['school_id'] !== null) {
            $slug = $this->ctx->db->fetchValue('SELECT slug FROM schools WHERE id = ?', [(int) $user['school_id']]);
            if (is_string($slug)) {
                return $this->ctx->url('/' . $slug . '/');
            }
        }
        if ($user['role'] === 'admin') {
            return $this->ctx->url('/global-admin');
        }
        if ($user['role'] === 'exhibitor') {
            // Erste zugängliche Schule des Aussteller-Kontos
            $slug = $this->ctx->db->fetchValue(
                'SELECT s.slug
                 FROM exhibitor_users eu
                 JOIN exhibitors e ON e.id = eu.exhibitor_id
                 JOIN messe_editions me ON me.id = e.edition_id
                 JOIN schools s ON s.id = me.school_id
                 WHERE eu.user_id = ? AND eu.status = \'active\' AND eu.invite_accepted = 1
                 ORDER BY eu.assigned_at LIMIT 1',
                [(int) $user['id']],
            );
            if (is_string($slug)) {
                return $this->ctx->url('/' . $slug . '/portal');
            }
        }

        return $this->ctx->url('/');
    }

    /** Erlaubt nur relative Pfade innerhalb der App als Redirect-Ziel. */
    private function safeRedirectTarget(mixed $target): ?string
    {
        if (!is_string($target) || $target === '' || !str_starts_with($target, '/') || str_starts_with($target, '//')) {
            return null;
        }

        return $target;
    }

    private function loginFailed(string $message): string
    {
        $this->flash('error', $message);
        $target = $this->ctx->school !== null
            ? $this->ctx->schoolUrl('/login')
            : $this->ctx->url('/login');
        $this->redirect($target);
    }

    private function emailService(): EmailService
    {
        return new EmailService($this->ctx->db, new Mailer($this->ctx->config['mail']), $this->ctx->view);
    }

    private function validateNewPassword(string $password, string $confirm): ?string
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return 'Das Passwort muss mindestens ' . self::MIN_PASSWORD_LENGTH . ' Zeichen lang sein.';
        }
        if ($password !== $confirm) {
            return 'Die Passwörter stimmen nicht überein.';
        }

        return null;
    }

    private function assertRegistrationOpen(int $schoolId): void
    {
        if (!$this->ctx->settings->getBool('registration_page_enabled', $schoolId)) {
            throw new HttpException(404, 'Die Registrierung ist nicht freigeschaltet.');
        }
        if ($this->ctx->edition === null) {
            throw new HttpException(404, 'Für diese Schule ist keine aktive Messe eingerichtet.');
        }
    }
}
