<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;

/**
 * Global-Administratoren (Rolle `admin`).
 *
 * Konten dieser Rolle werden AUSSCHLIESSLICH hier angelegt und geändert —
 * im Schulkontext (/{school}/admin/benutzer) sind sie weder vergebbar noch
 * verwaltbar (siehe UsersController::assignableRoles/assertMayManage).
 * Die optionale Heimatschule ist rein organisatorisch: ein globaler Admin
 * hat ohnehin überall Zugriff (Auth::hasSchoolAccess). Sie entscheidet nur,
 * in welcher Benutzerliste das Konto auftaucht — und das auch nur, wenn
 * `visible_in_school_list` gesetzt ist.
 */
final class GlobalAdminsController extends Controller
{
    /** GET /global-admin/administratoren */
    public function index(array $params): string
    {
        $this->requireAdmin();

        $admins = $this->ctx->db->fetchAll(
            "SELECT u.id, u.username, u.firstname, u.lastname, u.email, u.school_id,
                    u.visible_in_school_list, u.must_change_password, u.created_at,
                    u.password IS NULL AS no_password,
                    s.name AS school_name, s.slug AS school_slug
             FROM users u
             LEFT JOIN schools s ON s.id = u.school_id
             WHERE u.role = 'admin'
             ORDER BY u.lastname, u.firstname, u.username",
        );

        $generated = $this->ctx->session->get('_generated_password');
        $this->ctx->session->remove('_generated_password');

        return $this->render('pages/global/administratoren', [
            'title' => 'Administratoren',
            'admins' => $admins,
            'generated' => is_array($generated) ? $generated : null,
            'pageScripts' => ['users.js'],
        ]);
    }

    /** GET /global-admin/administratoren/neu */
    public function create(array $params): string
    {
        $this->requireAdmin();

        return $this->render('pages/global/administrator-form', [
            'title' => 'Neuer Administrator',
            'admin' => null,
            'old' => $this->ctx->session->pullOldInput(),
            'schools' => $this->schools(),
            'action' => $this->ctx->url('/global-admin/administratoren/neu'),
        ]);
    }

    /** POST /global-admin/administratoren/neu */
    public function store(array $params): string
    {
        $this->requireAdmin();
        $this->requireCsrf();
        $back = $this->ctx->url('/global-admin/administratoren/neu');

        $data = $this->readForm();
        $this->ctx->session->rememberInput($_POST);

        if ($error = $this->validate($data, null)) {
            $this->flash('error', $error);
            $this->redirect($back);
        }

        $password = (string) ($_POST['password'] ?? '');
        if ($password !== '' && mb_strlen($password) < AuthController::MIN_PASSWORD_LENGTH) {
            $this->flash('error', 'Das Passwort muss mindestens ' . AuthController::MIN_PASSWORD_LENGTH . ' Zeichen lang sein.');
            $this->redirect($back);
        }

        $hash = $password === '' ? null : password_hash($password, PASSWORD_DEFAULT);
        $mustChange = $password === '' || isset($_POST['must_change_password']) ? 1 : 0;

        $this->ctx->db->run(
            "INSERT INTO users (school_id, username, email, password, firstname, lastname, role, visible_in_school_list, must_change_password)
             VALUES (?, ?, ?, ?, ?, ?, 'admin', ?, ?)",
            [
                $data['school_id'],
                $data['username'],
                $data['email'],
                $hash,
                $data['firstname'],
                $data['lastname'],
                $data['visible'],
                $mustChange,
            ],
        );
        $newId = $this->ctx->db->lastInsertId();

        $this->ctx->session->pullOldInput();
        $this->ctx->audit->log(
            'Global-Administrator erstellt',
            'warning',
            sprintf(
                'Konto #%d "%s" (%s %s, %s, in Schulliste: %s)',
                $newId,
                $data['username'],
                $data['firstname'],
                $data['lastname'],
                $hash === null ? 'ohne Passwort' : 'mit Passwort',
                $data['visible'] === 1 ? 'ja' : 'nein',
            ),
        );
        $this->flash('success', 'Der Administrator wurde angelegt.');
        $this->redirect($this->ctx->url('/global-admin/administratoren'));
    }

    /** GET /global-admin/administratoren/{id}/bearbeiten */
    public function edit(array $params): string
    {
        $this->requireAdmin();
        $admin = $this->loadAdmin((int) $params['id']);

        return $this->render('pages/global/administrator-form', [
            'title' => 'Administrator bearbeiten',
            'admin' => $admin,
            'old' => $this->ctx->session->pullOldInput(),
            'schools' => $this->schools(),
            'action' => $this->ctx->url('/global-admin/administratoren/' . (int) $admin['id'] . '/bearbeiten'),
        ]);
    }

    /** POST /global-admin/administratoren/{id}/bearbeiten */
    public function update(array $params): string
    {
        $this->requireAdmin();
        $this->requireCsrf();

        $admin = $this->loadAdmin((int) $params['id']);
        $adminId = (int) $admin['id'];
        $back = $this->ctx->url('/global-admin/administratoren/' . $adminId . '/bearbeiten');

        $data = $this->readForm();
        $this->ctx->session->rememberInput($_POST);

        if ($error = $this->validate($data, $adminId)) {
            $this->flash('error', $error);
            $this->redirect($back);
        }

        $this->ctx->db->run(
            "UPDATE users
                SET username = ?, email = ?, firstname = ?, lastname = ?,
                    school_id = ?, visible_in_school_list = ?
              WHERE id = ? AND role = 'admin'",
            [
                $data['username'],
                $data['email'],
                $data['firstname'],
                $data['lastname'],
                $data['school_id'],
                $data['visible'],
                $adminId,
            ],
        );

        $changes = [];
        foreach (['username' => 'Benutzername', 'firstname' => 'Vorname', 'lastname' => 'Nachname'] as $key => $label) {
            if ((string) ($admin[$key] ?? '') !== (string) $data[$key]) {
                $changes[] = sprintf('%s: %s → %s', $label, (string) ($admin[$key] ?? ''), (string) $data[$key]);
            }
        }
        if ((int) ($admin['visible_in_school_list'] ?? 0) !== $data['visible']) {
            $changes[] = 'In Schulliste: ' . ($data['visible'] === 1 ? 'ja' : 'nein');
        }
        if (($admin['school_id'] !== null ? (int) $admin['school_id'] : null) !== $data['school_id']) {
            $changes[] = 'Schulzuordnung geändert';
        }
        if ((string) ($admin['email'] ?? '') !== (string) ($data['email'] ?? '')) {
            $changes[] = 'E-Mail geändert';
        }

        $this->ctx->session->pullOldInput();
        $this->ctx->audit->log(
            'Global-Administrator bearbeitet',
            'warning',
            sprintf('Konto #%d "%s"%s', $adminId, (string) $admin['username'], $changes === [] ? ' (keine Änderung)' : ' — ' . implode(', ', $changes)),
        );
        $this->flash('success', 'Die Änderungen wurden gespeichert.');
        $this->redirect($this->ctx->url('/global-admin/administratoren'));
    }

    /** POST /global-admin/administratoren/{id}/passwort */
    public function resetPassword(array $params): string
    {
        $this->requireAdmin();
        $this->requireCsrf();

        $admin = $this->loadAdmin((int) $params['id']);
        $adminId = (int) $admin['id'];
        $list = $this->ctx->url('/global-admin/administratoren');

        $mode = (string) ($_POST['modus'] ?? 'zufaellig');
        if (!in_array($mode, ['zufaellig', 'leeren'], true)) {
            $this->flash('error', 'Unbekannter Modus für den Passwort-Reset.');
            $this->redirect($list);
        }

        if ($mode === 'leeren') {
            if ($this->ctx->auth->id() === $adminId) {
                $this->flash('error', 'Du kannst dein eigenes Passwort hier nicht entfernen.');
                $this->redirect($list);
            }
            $this->ctx->db->run(
                "UPDATE users SET password = NULL, must_change_password = 1 WHERE id = ? AND role = 'admin'",
                [$adminId],
            );
            $this->ctx->audit->log(
                'Passwort eines Global-Administrators geleert',
                'warning',
                sprintf('Konto #%d "%s" — Login gesperrt, bis ein neues Passwort gesetzt wird', $adminId, (string) $admin['username']),
            );
            $this->flash('success', 'Das Passwort wurde entfernt. Das Konto kann sich bis zur Neuvergabe nicht anmelden.');
            $this->redirect($list);
        }

        $password = self::generatePassword();
        $this->ctx->db->run(
            "UPDATE users SET password = ?, must_change_password = 1 WHERE id = ? AND role = 'admin'",
            [password_hash($password, PASSWORD_DEFAULT), $adminId],
        );
        $this->ctx->session->set('_generated_password', [
            'username' => (string) $admin['username'],
            'name' => trim((string) $admin['firstname'] . ' ' . (string) $admin['lastname']),
            'password' => $password,
        ]);
        $this->ctx->audit->log(
            'Passwort eines Global-Administrators zurückgesetzt',
            'warning',
            sprintf('Konto #%d "%s" — neues Zufallspasswort, Wechsel beim nächsten Login erzwungen', $adminId, (string) $admin['username']),
        );
        $this->redirect($list);
    }

    /** POST /global-admin/administratoren/{id}/loeschen */
    public function destroy(array $params): string
    {
        $this->requireAdmin();
        $this->requireCsrf();

        $admin = $this->loadAdmin((int) $params['id']);
        $adminId = (int) $admin['id'];
        $list = $this->ctx->url('/global-admin/administratoren');

        if ($this->ctx->auth->id() === $adminId) {
            $this->flash('error', 'Du kannst dein eigenes Konto nicht löschen.');
            $this->redirect($list);
        }

        $remaining = (int) $this->ctx->db->fetchValue(
            "SELECT COUNT(*) FROM users WHERE role = 'admin' AND id <> ?",
            [$adminId],
        );
        if ($remaining === 0) {
            $this->flash('error', 'Das ist das letzte Administrator-Konto und kann nicht gelöscht werden.');
            $this->redirect($list);
        }

        $this->ctx->db->run("DELETE FROM users WHERE id = ? AND role = 'admin'", [$adminId]);

        $this->ctx->audit->log(
            'Global-Administrator gelöscht',
            'warning',
            sprintf('Konto #%d "%s" (%s %s)', $adminId, (string) $admin['username'], (string) $admin['firstname'], (string) $admin['lastname']),
        );
        $this->flash('success', 'Der Administrator wurde gelöscht.');
        $this->redirect($list);
    }

    // ---------- Helfer ----------

    /** @return array<string, mixed> */
    private function loadAdmin(int $id): array
    {
        $admin = $this->ctx->db->fetchOne(
            "SELECT * FROM users WHERE id = ? AND role = 'admin'",
            [$id],
        );
        if ($admin === null) {
            throw new HttpException(404, 'Dieses Administrator-Konto existiert nicht.');
        }

        return $admin;
    }

    /** @return list<array<string, mixed>> */
    private function schools(): array
    {
        return $this->ctx->db->fetchAll('SELECT id, name, slug FROM schools ORDER BY name');
    }

    /** @return array<string, mixed> */
    private function readForm(): array
    {
        $email = trim((string) ($_POST['email'] ?? ''));
        $schoolId = (int) ($_POST['school_id'] ?? 0);

        return [
            'username' => trim((string) ($_POST['username'] ?? '')),
            'firstname' => trim((string) ($_POST['firstname'] ?? '')),
            'lastname' => trim((string) ($_POST['lastname'] ?? '')),
            'email' => $email !== '' ? $email : null,
            'school_id' => $schoolId > 0 ? $schoolId : null,
            'visible' => isset($_POST['visible_in_school_list']) ? 1 : 0,
        ];
    }

    /** @param array<string, mixed> $data */
    private function validate(array $data, ?int $exceptId): ?string
    {
        if ($data['username'] === '' || $data['firstname'] === '' || $data['lastname'] === '') {
            return 'Benutzername, Vorname und Nachname sind Pflichtfelder.';
        }
        if (preg_match('/^[a-zA-Z0-9._-]{3,50}$/', (string) $data['username']) !== 1) {
            return 'Der Benutzername darf 3–50 Zeichen lang sein (Buchstaben, Zahlen, Punkt, Minus, Unterstrich).';
        }
        if (mb_strlen((string) $data['firstname']) > 100 || mb_strlen((string) $data['lastname']) > 100) {
            return 'Vor- und Nachname dürfen höchstens 100 Zeichen lang sein.';
        }
        if ($data['email'] !== null && filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
            return 'Die E-Mail-Adresse ist ungültig.';
        }
        if ($data['school_id'] !== null
            && $this->ctx->db->fetchValue('SELECT 1 FROM schools WHERE id = ?', [$data['school_id']]) === null) {
            return 'Die gewählte Schule existiert nicht.';
        }
        if ($data['visible'] === 1 && $data['school_id'] === null) {
            return 'Für die Anzeige in einer Schulliste muss eine Schule zugeordnet sein.';
        }

        // Benutzernamen sind je Schule eindeutig (users.school_key).
        $schoolKey = $data['school_id'] ?? 0;
        $taken = $exceptId === null
            ? $this->ctx->db->fetchValue(
                'SELECT 1 FROM users WHERE username = ? AND school_key = ? LIMIT 1',
                [$data['username'], $schoolKey],
            )
            : $this->ctx->db->fetchValue(
                'SELECT 1 FROM users WHERE username = ? AND school_key = ? AND id <> ? LIMIT 1',
                [$data['username'], $schoolKey, $exceptId],
            );
        if ($taken !== null) {
            return 'Dieser Benutzername ist im gewählten Bereich bereits vergeben.';
        }

        return null;
    }

    private static function generatePassword(int $length = 12): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max = strlen($alphabet) - 1;
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, $max)];
        }

        return $password;
    }
}
