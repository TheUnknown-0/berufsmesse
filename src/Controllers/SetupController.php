<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;

/**
 * Erst-Einrichtung: Solange kein Benutzer existiert, führt /setup durch
 * das Anlegen des Admin-Kontos, der ersten Schule und der ersten Edition.
 * (Ersetzt den Original-Weg „registrieren + Rolle per SQL setzen".)
 */
final class SetupController extends Controller
{
    public function show(array $params): string
    {
        $this->assertSetupNeeded();

        return $this->render('pages/auth/setup', ['title' => 'Einrichtung', 'wide' => true], 'minimal');
    }

    public function run(array $params): string
    {
        $this->assertSetupNeeded();
        $this->requireCsrf();

        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        $schoolName = trim((string) ($_POST['school_name'] ?? ''));
        $schoolSlug = strtolower(trim((string) ($_POST['school_slug'] ?? '')));
        $editionName = trim((string) ($_POST['edition_name'] ?? ''));
        $year = (int) ($_POST['year'] ?? date('Y'));

        $back = $this->ctx->url('/setup');
        $this->ctx->session->rememberInput($_POST);

        if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username)) {
            $this->flash('error', 'Der Admin-Benutzername darf 3–50 Zeichen lang sein (Buchstaben, Zahlen, Punkt, Minus, Unterstrich).');
            $this->redirect($back);
        }
        if (mb_strlen($password) < AuthController::MIN_PASSWORD_LENGTH || $password !== $confirm) {
            $this->flash('error', 'Das Passwort muss mindestens 8 Zeichen lang sein und beide Eingaben müssen übereinstimmen.');
            $this->redirect($back);
        }
        if ($schoolName === '' || !preg_match('/^[a-z0-9-]{2,100}$/', $schoolSlug)) {
            $this->flash('error', 'Bitte Schulname und einen gültigen URL-Namen (nur Kleinbuchstaben, Zahlen, Minus) angeben.');
            $this->redirect($back);
        }
        if (in_array($schoolSlug, self::reservedSlugs(), true)) {
            $this->flash('error', 'Dieser URL-Name ist reserviert. Bitte wähle einen anderen.');
            $this->redirect($back);
        }

        $this->ctx->db->transaction(function () use ($username, $password, $schoolName, $schoolSlug, $editionName, $year): void {
            $this->ctx->db->run(
                'INSERT INTO schools (name, slug) VALUES (?, ?)',
                [$schoolName, $schoolSlug],
            );
            $schoolId = $this->ctx->db->lastInsertId();

            $this->ctx->db->run(
                'INSERT INTO messe_editions (school_id, name, year, status) VALUES (?, ?, ?, \'active\')',
                [$schoolId, $editionName !== '' ? $editionName : ('Berufsmesse ' . $year), $year],
            );

            $this->ctx->db->run(
                'INSERT INTO users (username, password, role, firstname, lastname)
                 VALUES (?, ?, \'admin\', \'Admin\', \'\')',
                [$username, password_hash($password, PASSWORD_DEFAULT)],
            );
        });

        $this->ctx->audit->log('Erst-Einrichtung abgeschlossen', 'info', "Admin: {$username}, Schule: {$schoolName}");

        $user = $this->ctx->db->fetchOne('SELECT * FROM users WHERE username = ? AND school_id IS NULL', [$username]);
        $this->ctx->auth->loginAs($user);
        $this->flash('success', 'Einrichtung abgeschlossen. Willkommen!');
        $this->redirect($this->ctx->url('/' . $schoolSlug . '/admin/dashboard'));
    }

    /** Slugs, die mit globalen Routen kollidieren würden. */
    public static function reservedSlugs(): array
    {
        return ['login', 'logout', 'zugang', 'setup', 'registrieren', 'passwort-aendern',
                'global-admin', 'api', 'assets', 'aussteller-einladung', 'uploads', 'medien'];
    }

    private function assertSetupNeeded(): void
    {
        if ($this->ctx->db->fetchValue('SELECT 1 FROM users LIMIT 1') !== null) {
            throw new HttpException(404);
        }
    }
}
