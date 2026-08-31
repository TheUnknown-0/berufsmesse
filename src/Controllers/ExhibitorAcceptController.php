<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * Annahme einer Aussteller-Einladung über den Einladungslink.
 *
 * Bewusst OHNE Schulkontext (ein Aussteller-Konto ist global, kann aber an
 * mehreren Schulen ausstellen) und im Minimal-Layout. Es wird keine E-Mail
 * versendet — den Link verteilt die Schule manuell.
 */
final class ExhibitorAcceptController extends Controller
{
    /** GET /aussteller-einladung */
    public function show(array $params): string
    {
        $token = (string) ($_GET['token'] ?? '');
        $invite = $this->findInvite($token);

        return $this->render('pages/portal/einladung', [
            'title' => 'Einladung annehmen',
            'invite' => $invite,
            'token' => $token,
        ], 'minimal');
    }

    /** POST /aussteller-einladung */
    public function accept(array $params): string
    {
        $this->requireCsrf();

        $token = (string) ($_POST['token'] ?? '');
        $invite = $this->findInvite($token);
        $back = $this->ctx->url('/aussteller-einladung?token=' . urlencode($token));

        if ($invite === null) {
            $this->flash('error', 'Dieser Einladungslink ist ungültig oder abgelaufen.');
            $this->redirect($back);
        }

        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');

        if (mb_strlen($password) < AuthController::MIN_PASSWORD_LENGTH) {
            $this->flash('error', 'Das Passwort muss mindestens ' . AuthController::MIN_PASSWORD_LENGTH . ' Zeichen lang sein.');
            $this->redirect($back);
        }
        if ($password !== $confirm) {
            $this->flash('error', 'Die Passwörter stimmen nicht überein.');
            $this->redirect($back);
        }

        $userId = (int) $invite['user_id'];
        $this->ctx->db->transaction(function () use ($invite, $userId, $password): void {
            $this->ctx->db->run(
                'UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), $userId],
            );
            $this->ctx->db->run(
                'UPDATE exhibitor_users
                 SET invite_accepted = 1, invite_token = NULL, invite_expires = NULL, status = \'active\'
                 WHERE id = ?',
                [(int) $invite['id']],
            );
        });

        $this->ctx->audit->log(
            'Aussteller-Einladung angenommen',
            'info',
            'Unternehmen: ' . (string) $invite['exhibitor_name'],
            $invite['school_id'] !== null ? (int) $invite['school_id'] : null,
            $userId,
            (string) $invite['username'],
        );

        $user = $this->ctx->db->fetchOne('SELECT * FROM users WHERE id = ?', [$userId]);
        if ($user !== null) {
            $this->ctx->auth->loginAs($user);
        }

        $this->flash('success', 'Willkommen! Dein Zugang ist eingerichtet.');
        $this->redirect(is_string($invite['school_slug'])
            ? $this->ctx->url('/' . $invite['school_slug'] . '/portal')
            : $this->ctx->url('/'));
    }

    /** Gültige, offene Einladung zum Token — sonst null. */
    private function findInvite(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        return $this->ctx->db->fetchOne(
            'SELECT eu.id, eu.user_id, u.username, e.name AS exhibitor_name,
                    s.name AS school_name, s.slug AS school_slug, s.id AS school_id
             FROM exhibitor_users eu
             JOIN users u ON u.id = eu.user_id
             JOIN exhibitors e ON e.id = eu.exhibitor_id
             JOIN messe_editions me ON me.id = e.edition_id
             JOIN schools s ON s.id = me.school_id
             WHERE eu.invite_token = ?
               AND eu.invite_accepted = 0
               AND eu.status = \'active\'
               AND (eu.invite_expires IS NULL OR eu.invite_expires > NOW())
             LIMIT 1',
            [$token],
        );
    }
}
