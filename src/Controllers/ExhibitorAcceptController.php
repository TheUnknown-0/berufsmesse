<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Audit;
use App\Services\ExhibitorInvitations;
use App\Services\LoginThrottle;

/**
 * Annahme einer Aussteller-Einladung über den Einladungslink.
 *
 * Bewusst OHNE Schulkontext (ein Aussteller-Konto ist global, kann aber an
 * mehreren Schulen ausstellen) und im Minimal-Layout. Es wird keine E-Mail
 * versendet: Den Link verteilt die Schule selbst.
 *
 * Wer den Link öffnet, nimmt ihn auf einem von drei Wegen an:
 *  - bereits als Aussteller angemeldet: mit diesem Konto,
 *  - mit Benutzername und Passwort eines bestehenden Aussteller-Kontos,
 *  - mit einem neu angelegten Konto.
 * Das Passwort eines bestehenden Kontos wird dabei nie verändert. Wer mit
 * einer anderen Rolle angemeldet ist (Schulpersonal), kann nicht annehmen.
 */
final class ExhibitorAcceptController extends Controller
{
    /** GET /aussteller-einladung */
    public function show(array $params): string
    {
        $token = (string) ($_GET['token'] ?? '');

        return $this->render('pages/portal/einladung', [
            'title' => 'Einladung annehmen',
            'invite' => $this->invitations()->findValid($token),
            'token' => $token,
            'currentUser' => $this->ctx->auth->user(),
            'old' => $this->ctx->session->pullOldInput(),
        ], 'minimal');
    }

    /** POST /aussteller-einladung */
    public function accept(array $params): string
    {
        $this->requireCsrf();

        $token = (string) ($_POST['token'] ?? '');
        $invite = $this->invitations()->findValid($token);
        $back = $this->ctx->url('/aussteller-einladung?token=' . urlencode($token));

        if ($invite === null) {
            $this->flash('error', 'Dieser Einladungslink ist ungültig, bereits eingelöst oder abgelaufen.');
            $this->redirect($back);
        }

        $current = $this->ctx->auth->user();
        if ($current !== null && $current['role'] !== 'exhibitor') {
            $this->flash('error', 'Einladungen können nur Aussteller-Konten annehmen. Bitte melde dich zuerst ab.');
            $this->redirect($back);
        }

        $mode = (string) ($_POST['mode'] ?? '');
        $createdAccount = false;

        if ($current !== null) {
            $user = $current;
        } elseif ($mode === 'anmelden') {
            $user = $this->verifyExistingAccount($back);
        } elseif ($mode === 'neu') {
            $user = $this->createAccount($back);
            $createdAccount = true;
        } else {
            $this->flash('error', 'Bitte melde dich an oder lege ein Konto an.');
            $this->redirect($back);
        }

        if (!$this->invitations()->accept((int) $invite['id'], (int) $user['id'], $createdAccount)) {
            // Gleichzeitig eingelöst: ein frisch angelegtes Konto bleibt ohne
            // Verknüpfung, kann sich aber anmelden und weitere Links annehmen.
            $this->flash('error', 'Dieser Einladungslink wurde gerade eben schon eingelöst.');
            $this->redirect($back);
        }

        $this->ctx->audit->log(
            'Aussteller-Einladung angenommen',
            'info',
            sprintf(
                'Unternehmen: %s, Konto: %s%s',
                (string) $invite['exhibitor_name'],
                (string) $user['username'],
                $createdAccount ? ' (dabei neu angelegt)' : '',
            ),
            (int) $invite['school_id'],
            (int) $user['id'],
            (string) $user['username'],
        );

        if ($current === null) {
            $this->ctx->auth->loginAs($user);
        }

        $this->flash('success', 'Willkommen! Du betreust jetzt ' . (string) $invite['exhibitor_name'] . '.');
        $this->redirect($this->ctx->url('/' . (string) $invite['school_slug'] . '/portal'));
    }

    /**
     * Prüft Benutzername und Passwort eines bestehenden Aussteller-Kontos
     * (mit derselben Fehlversuch-Sperre wie der Login).
     *
     * @return array<string, mixed>
     */
    private function verifyExistingAccount(string $back): array
    {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $ip = Audit::clientIp();
        $throttle = new LoginThrottle($this->ctx->db);

        if ($username === '' || $password === '') {
            $this->failWith($back, 'Bitte Benutzername und Passwort eingeben.', ['mode' => 'anmelden', 'username' => $username]);
        }
        if ($throttle->isBlocked($username, $ip)) {
            $this->failWith($back, 'Zu viele Fehlversuche. Bitte warte 5 Minuten.', ['mode' => 'anmelden', 'username' => $username]);
        }

        $user = $this->ctx->db->fetchOne(
            'SELECT * FROM users WHERE username = ? AND school_id IS NULL AND role = \'exhibitor\'',
            [$username],
        );
        if ($user === null || $user['password'] === null || !password_verify($password, (string) $user['password'])) {
            $throttle->recordFailure($username, $ip);
            $this->ctx->audit->log('Einladung: Anmeldung fehlgeschlagen', 'warning', 'Benutzer: ' . $username);
            $this->failWith($back, 'Benutzername oder Passwort ist falsch.', ['mode' => 'anmelden', 'username' => $username]);
        }

        $throttle->clear($username);

        return $user;
    }

    /**
     * Legt ein neues, globales Aussteller-Konto an.
     *
     * @return array<string, mixed>
     */
    private function createAccount(string $back): array
    {
        $username = trim((string) ($_POST['username'] ?? ''));
        $firstname = mb_substr(trim((string) ($_POST['firstname'] ?? '')), 0, 100);
        $lastname = mb_substr(trim((string) ($_POST['lastname'] ?? '')), 0, 100);
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        $keep = ['mode' => 'neu', 'username' => $username, 'firstname' => $firstname, 'lastname' => $lastname, 'email' => $email];

        if (preg_match('/^[a-zA-Z0-9._@+-]{3,100}$/', $username) !== 1) {
            $this->failWith($back, 'Der Benutzername darf 3 bis 100 Zeichen lang sein (Buchstaben, Zahlen, . _ @ + -).', $keep);
        }
        if ($firstname === '' || $lastname === '') {
            $this->failWith($back, 'Bitte gib Vor- und Nachnamen an.', $keep);
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->failWith($back, 'Bitte gib eine gültige E-Mail-Adresse an.', $keep);
        }
        if (mb_strlen($password) < AuthController::MIN_PASSWORD_LENGTH) {
            $this->failWith($back, 'Das Passwort muss mindestens ' . AuthController::MIN_PASSWORD_LENGTH . ' Zeichen lang sein.', $keep);
        }
        if ($password !== $confirm) {
            $this->failWith($back, 'Die Passwörter stimmen nicht überein.', $keep);
        }

        $taken = $this->ctx->db->fetchValue(
            'SELECT 1 FROM users WHERE username = ? AND school_id IS NULL',
            [$username],
        );
        if ($taken !== null) {
            $this->failWith($back, 'Dieser Benutzername ist schon vergeben. Hast du bereits ein Konto? Dann melde dich an.', $keep);
        }

        $this->ctx->db->run(
            'INSERT INTO users (school_id, username, email, password, firstname, lastname, role)
             VALUES (NULL, ?, ?, ?, ?, ?, \'exhibitor\')',
            [$username, $email !== '' ? mb_substr($email, 0, 255) : null, password_hash($password, PASSWORD_DEFAULT), $firstname, $lastname],
        );
        $user = $this->ctx->db->fetchOne('SELECT * FROM users WHERE id = ?', [$this->ctx->db->lastInsertId()]);
        if ($user === null) {
            $this->failWith($back, 'Das Konto konnte nicht angelegt werden.', $keep);
        }

        return $user;
    }

    /** Fehlermeldung, Eingaben merken (nie Passwörter), zurück zur Einladung. */
    private function failWith(string $back, string $message, array $keep): never
    {
        $this->ctx->session->rememberInput($keep);
        $this->flash('error', $message);
        $this->redirect($back);
    }

    private function invitations(): ExhibitorInvitations
    {
        return new ExhibitorInvitations($this->ctx->db);
    }
}
