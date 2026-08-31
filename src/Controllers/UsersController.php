<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions;
use PDO;

/**
 * Benutzerverwaltung einer Schule: Liste, Anlegen, Bearbeiten, Löschen,
 * Passwort-Reset, CSV-Import und Benutzersuche (JSON) für andere Module.
 *
 * Schul-Isolation: JEDE Query filtert auf users.school_id = aktuelle Schule.
 * Benutzer-IDs aus dem Request werden ausschließlich über loadUser() geladen.
 */
final class UsersController extends Controller
{
    /** Zeilen pro Seite in der Benutzerliste. */
    private const PER_PAGE = 50;

    /** Maximale Größe der Import-Datei (2 MB). */
    private const MAX_CSV_BYTES = 2 * 1024 * 1024;

    /** Spaltenreihenfolge der Import-CSV. */
    private const CSV_COLUMNS = ['firstname', 'lastname', 'username', 'email', 'role', 'class', 'password'];

    /** Anzeigenamen aller Rollen (auch der hier nicht vergebbaren). */
    public const ROLE_LABELS = [
        'admin' => 'Globaler Administrator',
        'school_admin' => 'Schul-Administrator',
        'orga' => 'Orga-Team',
        'teacher' => 'Lehrkraft',
        'student' => 'Schüler:in',
        'exhibitor' => 'Aussteller',
    ];

    // ---------- Liste ----------

    /** GET /{school}/admin/benutzer */
    public function index(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::BENUTZER_SEHEN);
        $schoolId = (int) $this->ctx->schoolId();

        $search = trim((string) ($_GET['q'] ?? ''));
        $role = (string) ($_GET['rolle'] ?? '');
        $class = trim((string) ($_GET['klasse'] ?? ''));
        $page = max(1, (int) ($_GET['seite'] ?? 1));

        // Global-Admins erscheinen hier nur, wenn sie im Global-Admin
        // ausdrücklich dafür freigegeben wurden.
        $where = ['u.school_id = ?', "(u.role <> 'admin' OR u.visible_in_school_list = 1)"];
        $args = [$schoolId];

        if ($role !== '' && isset(self::ROLE_LABELS[$role])) {
            $where[] = 'u.role = ?';
            $args[] = $role;
        } else {
            $role = '';
        }
        if ($class !== '') {
            $where[] = 'u.class = ?';
            $args[] = $class;
        }
        if ($search !== '') {
            $like = '%' . self::escapeLike($search) . '%';
            $where[] = '(u.username LIKE ? OR u.firstname LIKE ? OR u.lastname LIKE ?'
                . " OR CONCAT(u.firstname, ' ', u.lastname) LIKE ?)";
            array_push($args, $like, $like, $like, $like);
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) $this->ctx->db->fetchValue("SELECT COUNT(*) FROM users u WHERE {$whereSql}", $args);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $offset = ($page - 1) * self::PER_PAGE;

        // Sortierung wie im Original: Rollenrang, danach Name.
        $users = $this->fetchPaged(
            "SELECT u.id, u.username, u.firstname, u.lastname, u.email, u.class, u.role,
                    u.password IS NULL AS no_password, u.must_change_password, u.created_at
             FROM users u
             WHERE {$whereSql}
             ORDER BY CASE u.role
                          WHEN 'admin' THEN 1 WHEN 'school_admin' THEN 2 WHEN 'orga' THEN 3
                          WHEN 'exhibitor' THEN 4 WHEN 'teacher' THEN 5 ELSE 6 END,
                      u.lastname, u.firstname, u.username
             LIMIT ? OFFSET ?",
            $args,
            self::PER_PAGE,
            $offset,
        );

        $classes = array_column($this->ctx->db->fetchAll(
            "SELECT DISTINCT class FROM users
             WHERE school_id = ? AND class IS NOT NULL AND class <> ''
             ORDER BY class",
            [$schoolId],
        ), 'class');

        $counts = [];
        foreach ($this->ctx->db->fetchAll(
            "SELECT role, COUNT(*) AS anzahl FROM users
             WHERE school_id = ? AND (role <> 'admin' OR visible_in_school_list = 1)
             GROUP BY role",
            [$schoolId],
        ) as $row) {
            $counts[(string) $row['role']] = (int) $row['anzahl'];
        }

        $withoutPassword = (int) $this->ctx->db->fetchValue(
            "SELECT COUNT(*) FROM users
             WHERE school_id = ? AND password IS NULL
               AND (role <> 'admin' OR visible_in_school_list = 1)",
            [$schoolId],
        );

        // Einmalige Anzeige eines frisch erzeugten Passworts
        $generated = $this->ctx->session->get('_generated_password');
        $this->ctx->session->remove('_generated_password');

        return $this->render('pages/users/index', [
            'title' => 'Benutzer',
            'users' => $users,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'search' => $search,
            'roleFilter' => $role,
            'classFilter' => $class,
            'classes' => $classes,
            'counts' => $counts,
            'withoutPassword' => $withoutPassword,
            'roleLabels' => self::ROLE_LABELS,
            'generated' => is_array($generated) ? $generated : null,
            'canCreate' => $this->ctx->auth->can(Permissions::BENUTZER_ERSTELLEN, $schoolId),
            'canEdit' => $this->ctx->auth->can(Permissions::BENUTZER_BEARBEITEN, $schoolId),
            'canDelete' => $this->ctx->auth->can(Permissions::BENUTZER_LOESCHEN, $schoolId),
            'canImport' => $this->ctx->auth->can(Permissions::BENUTZER_IMPORTIEREN, $schoolId),
            'canReset' => $this->ctx->auth->can(Permissions::BENUTZER_PASSWORT_ZURUECKSETZEN, $schoolId),
            'pageScripts' => ['users.js'],
        ]);
    }

    // ---------- Anlegen ----------

    /** GET /{school}/admin/benutzer/neu */
    public function create(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::BENUTZER_ERSTELLEN);

        return $this->render('pages/users/form', [
            'title' => 'Neuer Benutzer',
            'user' => null,
            'old' => $this->ctx->session->pullOldInput(),
            'roles' => $this->assignableRoles(),
            'action' => $this->ctx->schoolUrl('/admin/benutzer/neu'),
        ]);
    }

    /** POST /{school}/admin/benutzer/neu */
    public function store(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::BENUTZER_ERSTELLEN);
        $this->requireCsrf();
        $schoolId = (int) $this->ctx->schoolId();
        $back = $this->ctx->schoolUrl('/admin/benutzer/neu');

        $data = $this->readForm();
        $this->ctx->session->rememberInput($_POST);

        if ($error = $this->validate($data)) {
            $this->flash('error', $error);
            $this->redirect($back);
        }
        if (!isset($this->assignableRoles()[$data['role']])) {
            $this->flash('error', 'Diese Rolle darfst du nicht vergeben.');
            $this->redirect($back);
        }
        if ($this->usernameTaken($data['username'], $schoolId, null)) {
            $this->flash('error', 'Dieser Benutzername ist in dieser Schule bereits vergeben.');
            $this->redirect($back);
        }

        $password = (string) ($_POST['password'] ?? '');
        if ($password !== '' && mb_strlen($password) < AuthController::MIN_PASSWORD_LENGTH) {
            $this->flash('error', 'Das Passwort muss mindestens ' . AuthController::MIN_PASSWORD_LENGTH . ' Zeichen lang sein.');
            $this->redirect($back);
        }

        // Leeres Passwort = Konto ohne Passwort; die Zugangsdaten kommen
        // später aus dem Zugangsdaten-PDF (eigenes Modul).
        $hash = $password === '' ? null : password_hash($password, PASSWORD_DEFAULT);
        // Ohne Passwort immer Wechsel erzwingen; mit Passwort nur, wenn angehakt.
        $mustChange = $password === '' || isset($_POST['must_change_password']) ? 1 : 0;

        $this->ctx->db->run(
            'INSERT INTO users (school_id, edition_id, username, email, password, firstname, lastname, class, role, must_change_password)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $schoolId,
                $this->ctx->editionId(),
                $data['username'],
                $data['email'],
                $hash,
                $data['firstname'],
                $data['lastname'],
                $data['class'],
                $data['role'],
                $mustChange,
            ],
        );
        $newId = $this->ctx->db->lastInsertId();

        $this->ctx->session->pullOldInput();
        $this->ctx->audit->log(
            'Benutzer erstellt',
            'info',
            sprintf(
                'Benutzer #%d "%s" (%s %s, Rolle: %s, %s)',
                $newId,
                $data['username'],
                $data['firstname'],
                $data['lastname'],
                $data['role'],
                $hash === null ? 'ohne Passwort' : 'mit Passwort',
            ),
            $schoolId,
        );
        $this->flash('success', 'Der Benutzer wurde angelegt.');
        $this->redirect($this->ctx->schoolUrl('/admin/benutzer'));
    }

    // ---------- Bearbeiten ----------

    /** GET /{school}/admin/benutzer/{id}/bearbeiten */
    public function edit(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::BENUTZER_BEARBEITEN);
        $user = $this->loadUser((int) $params['id']);
        $this->assertMayManage($user);

        return $this->render('pages/users/form', [
            'title' => 'Benutzer bearbeiten',
            'user' => $user,
            'old' => $this->ctx->session->pullOldInput(),
            'roles' => $this->assignableRoles($user),
            'action' => $this->ctx->schoolUrl('/admin/benutzer/' . (int) $user['id'] . '/bearbeiten'),
        ]);
    }

    /** POST /{school}/admin/benutzer/{id}/bearbeiten */
    public function update(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::BENUTZER_BEARBEITEN);
        $this->requireCsrf();
        $schoolId = (int) $this->ctx->schoolId();

        $user = $this->loadUser((int) $params['id']);
        $this->assertMayManage($user);
        $userId = (int) $user['id'];
        $back = $this->ctx->schoolUrl('/admin/benutzer/' . $userId . '/bearbeiten');

        $data = $this->readForm();
        $this->ctx->session->rememberInput($_POST);

        if ($error = $this->validate($data)) {
            $this->flash('error', $error);
            $this->redirect($back);
        }

        // Die eigene Rolle darf nicht verändert werden (Selbst-Aussperrung
        // bzw. Rechteausweitung).
        $isSelf = $this->ctx->auth->id() === $userId;
        $newRole = $data['role'];
        if ($isSelf && $newRole !== (string) $user['role']) {
            $this->flash('error', 'Du kannst deine eigene Rolle nicht ändern.');
            $this->redirect($back);
        }
        if ($newRole !== (string) $user['role'] && !isset($this->assignableRoles($user)[$newRole])) {
            $this->flash('error', 'Diese Rolle darfst du nicht vergeben.');
            $this->redirect($back);
        }
        if (!isset(self::ROLE_LABELS[$newRole])) {
            $this->flash('error', 'Ungültige Rolle.');
            $this->redirect($back);
        }
        if ($this->usernameTaken($data['username'], $schoolId, $userId)) {
            $this->flash('error', 'Dieser Benutzername ist in dieser Schule bereits vergeben.');
            $this->redirect($back);
        }

        $this->ctx->db->run(
            'UPDATE users SET username = ?, email = ?, firstname = ?, lastname = ?, class = ?, role = ?
             WHERE id = ? AND school_id = ?',
            [
                $data['username'],
                $data['email'],
                $data['firstname'],
                $data['lastname'],
                $data['class'],
                $newRole,
                $userId,
                $schoolId,
            ],
        );

        $changes = [];
        foreach (['username' => 'Benutzername', 'firstname' => 'Vorname', 'lastname' => 'Nachname', 'role' => 'Rolle'] as $key => $label) {
            if ((string) ($user[$key] ?? '') !== (string) $data[$key]) {
                $changes[] = sprintf('%s: %s → %s', $label, (string) ($user[$key] ?? ''), (string) $data[$key]);
            }
        }
        if ((string) ($user['class'] ?? '') !== (string) ($data['class'] ?? '')) {
            $changes[] = sprintf('Klasse: %s → %s', (string) ($user['class'] ?? '—'), (string) ($data['class'] ?? '—'));
        }
        if ((string) ($user['email'] ?? '') !== (string) ($data['email'] ?? '')) {
            $changes[] = 'E-Mail geändert';
        }

        $this->ctx->session->pullOldInput();
        $this->ctx->audit->log(
            'Benutzer bearbeitet',
            'info',
            sprintf('Benutzer #%d "%s"%s', $userId, (string) $user['username'], $changes === [] ? ' (keine Änderung)' : ' — ' . implode(', ', $changes)),
            $schoolId,
        );
        $this->flash('success', 'Die Änderungen wurden gespeichert.');
        $this->redirect($this->ctx->schoolUrl('/admin/benutzer'));
    }

    // ---------- Löschen ----------

    /** POST /{school}/admin/benutzer/{id}/loeschen */
    public function destroy(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::BENUTZER_LOESCHEN);
        $this->requireCsrf();
        $schoolId = (int) $this->ctx->schoolId();

        $user = $this->loadUser((int) $params['id']);
        $this->assertMayManage($user);
        $userId = (int) $user['id'];
        $list = $this->ctx->schoolUrl('/admin/benutzer');

        if ($this->ctx->auth->id() === $userId) {
            $this->flash('error', 'Du kannst dein eigenes Konto nicht löschen.');
            $this->redirect($list);
        }
        if ($this->isLastSchoolAdmin($user, $schoolId)) {
            $this->flash('error', 'Das ist das letzte Administrator-Konto dieser Schule und kann nicht gelöscht werden.');
            $this->redirect($list);
        }

        // Abhängige Datensätze hängen per FK ON DELETE CASCADE am Benutzer.
        $this->ctx->db->run('DELETE FROM users WHERE id = ? AND school_id = ?', [$userId, $schoolId]);

        $this->ctx->audit->log(
            'Benutzer gelöscht',
            'warning',
            sprintf('Benutzer #%d "%s" (%s %s, Rolle: %s)', $userId, (string) $user['username'], (string) $user['firstname'], (string) $user['lastname'], (string) $user['role']),
            $schoolId,
        );
        $this->flash('success', 'Der Benutzer wurde gelöscht.');
        $this->redirect($list);
    }

    // ---------- Passwort zurücksetzen ----------

    /** POST /{school}/admin/benutzer/{id}/passwort */
    public function resetPassword(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::BENUTZER_PASSWORT_ZURUECKSETZEN);
        $this->requireCsrf();
        $schoolId = (int) $this->ctx->schoolId();

        $user = $this->loadUser((int) $params['id']);
        $this->assertMayManage($user);
        $userId = (int) $user['id'];
        $list = $this->ctx->schoolUrl('/admin/benutzer');

        $mode = (string) ($_POST['modus'] ?? 'zufaellig');
        if (!in_array($mode, ['zufaellig', 'leeren'], true)) {
            $this->flash('error', 'Unbekannter Modus für den Passwort-Reset.');
            $this->redirect($list);
        }

        if ($mode === 'leeren') {
            $this->ctx->db->run(
                'UPDATE users SET password = NULL, must_change_password = 1 WHERE id = ? AND school_id = ?',
                [$userId, $schoolId],
            );
            $this->ctx->audit->log(
                'Passwort geleert',
                'warning',
                sprintf('Benutzer #%d "%s" — Konto ohne Passwort, Zugangsdaten kommen aus dem Zugangsdaten-PDF', $userId, (string) $user['username']),
                $schoolId,
            );
            $this->flash('success', 'Das Passwort wurde entfernt. Das Konto hat jetzt kein Passwort mehr.');
            $this->redirect($list);
        }

        $password = self::generatePassword();
        $this->ctx->db->run(
            'UPDATE users SET password = ?, must_change_password = 1 WHERE id = ? AND school_id = ?',
            [password_hash($password, PASSWORD_DEFAULT), $userId, $schoolId],
        );
        // Klartext nur für die einmalige Anzeige nach dem Redirect.
        $this->ctx->session->set('_generated_password', [
            'username' => (string) $user['username'],
            'name' => trim((string) $user['firstname'] . ' ' . (string) $user['lastname']),
            'password' => $password,
        ]);
        $this->ctx->audit->log(
            'Passwort zurückgesetzt',
            'warning',
            sprintf('Benutzer #%d "%s" — neues Zufallspasswort, Wechsel beim nächsten Login erzwungen', $userId, (string) $user['username']),
            $schoolId,
        );
        $this->redirect($list);
    }

    // ---------- CSV-Import ----------

    /** GET /{school}/admin/benutzer/import */
    public function showImport(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::BENUTZER_IMPORTIEREN);

        return $this->render('pages/users/import', [
            'title' => 'Benutzer importieren',
            'result' => null,
            'columns' => self::CSV_COLUMNS,
            'maxBytes' => self::MAX_CSV_BYTES,
            'importRoles' => $this->importableRoles(),
        ]);
    }

    /** POST /{school}/admin/benutzer/import */
    public function import(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::BENUTZER_IMPORTIEREN);
        $this->requireCsrf();
        $schoolId = (int) $this->ctx->schoolId();

        $view = fn (?array $result): string => $this->render('pages/users/import', [
            'title' => 'Benutzer importieren',
            'result' => $result,
            'columns' => self::CSV_COLUMNS,
            'maxBytes' => self::MAX_CSV_BYTES,
            'importRoles' => $this->importableRoles(),
        ]);

        $content = $this->readUploadedCsv();
        if (is_string($content) === false) {
            $this->flash('error', $content['error']);

            return $view(null);
        }

        $result = $this->processCsv($content, $schoolId);

        $this->ctx->audit->log(
            'Benutzer-CSV importiert',
            $result['errors'] === [] ? 'info' : 'warning',
            sprintf('%d importiert, %d übersprungen, %d Fehler', $result['imported'], $result['skipped'], count($result['errors'])),
            $schoolId,
        );

        if ($result['imported'] > 0) {
            $this->flash('success', sprintf('%d Benutzer importiert, %d übersprungen.', $result['imported'], $result['skipped']));
        } elseif ($result['errors'] !== []) {
            $this->flash('error', 'Der Import ist fehlgeschlagen — keine Zeile wurde übernommen.');
        } else {
            $this->flash('warning', 'Es wurde kein Benutzer importiert.');
        }

        return $view($result);
    }

    // ---------- JSON-API ----------

    /**
     * GET /{school}/api/benutzer/suche?q=…[&rolle=…]
     * Schlanke Benutzersuche für andere Module.
     */
    public function apiSearch(array $params): array
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::BENUTZER_SEHEN);
        $schoolId = (int) $this->ctx->schoolId();

        $query = trim((string) ($_GET['q'] ?? ''));
        if ($query === '') {
            return ['success' => true, 'users' => [], 'count' => 0];
        }

        $where = ['school_id = ?', "(role <> 'admin' OR visible_in_school_list = 1)"];
        $args = [$schoolId];

        $role = (string) ($_GET['rolle'] ?? '');
        if ($role !== '') {
            if (!isset(self::ROLE_LABELS[$role])) {
                return $this->jsonError('Unbekannte Rolle.', 422);
            }
            $where[] = 'role = ?';
            $args[] = $role;
        }

        $like = '%' . self::escapeLike($query) . '%';
        $where[] = "(username LIKE ? OR firstname LIKE ? OR lastname LIKE ? OR CONCAT(firstname, ' ', lastname) LIKE ?)";
        array_push($args, $like, $like, $like, $like);

        $rows = $this->fetchPaged(
            'SELECT id, username, firstname, lastname, class, role
             FROM users
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY lastname, firstname, username
             LIMIT ? OFFSET ?',
            $args,
            25,
            0,
        );

        $users = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'firstname' => (string) $row['firstname'],
            'lastname' => (string) $row['lastname'],
            'class' => $row['class'] !== null ? (string) $row['class'] : null,
            'role' => (string) $row['role'],
        ], $rows);

        return ['success' => true, 'users' => $users, 'count' => count($users)];
    }

    // ---------- Helfer: Formular & Validierung ----------

    /** @return array{username: string, firstname: string, lastname: string, email: ?string, class: ?string, role: string} */
    private function readForm(): array
    {
        $email = trim((string) ($_POST['email'] ?? ''));
        $class = trim((string) ($_POST['class'] ?? ''));

        return [
            'username' => trim((string) ($_POST['username'] ?? '')),
            'firstname' => trim((string) ($_POST['firstname'] ?? '')),
            'lastname' => trim((string) ($_POST['lastname'] ?? '')),
            'email' => $email !== '' ? $email : null,
            'class' => $class !== '' ? $class : null,
            'role' => (string) ($_POST['role'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $data @return string|null Fehlermeldung */
    private function validate(array $data): ?string
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
        if ($data['class'] !== null && mb_strlen((string) $data['class']) > 50) {
            return 'Die Klassenbezeichnung darf höchstens 50 Zeichen lang sein.';
        }
        if ($data['role'] === '') {
            return 'Bitte eine Rolle auswählen.';
        }

        return null;
    }

    /**
     * Rollen, die der eingeloggte Benutzer vergeben darf.
     * `admin` ist hier NIE vergebbar — globale Administratoren werden
     * ausschließlich unter /global-admin/administratoren verwaltet.
     * `school_admin` nur für admin/school_admin.
     * Die aktuelle Rolle des bearbeiteten Kontos bleibt immer wählbar, damit
     * ein Speichern ohne Rollenwechsel nicht scheitert.
     *
     * @return array<string, string>
     */
    private function assignableRoles(?array $user = null): array
    {
        $actorRole = (string) $this->ctx->auth->role();
        $roles = [];

        if (in_array($actorRole, ['admin', 'school_admin'], true)) {
            $roles['school_admin'] = self::ROLE_LABELS['school_admin'];
        }
        $roles['orga'] = self::ROLE_LABELS['orga'];
        $roles['teacher'] = self::ROLE_LABELS['teacher'];
        $roles['student'] = self::ROLE_LABELS['student'];

        if ($user !== null) {
            $current = (string) $user['role'];
            if ($current !== 'admin' && !isset($roles[$current]) && isset(self::ROLE_LABELS[$current])) {
                $roles = [$current => self::ROLE_LABELS[$current]] + $roles;
            }
        }

        return $roles;
    }

    /**
     * Rollen, die per CSV-Import vergeben werden dürfen.
     * school_admin ausschließlich für globale Admins; `admin` nie
     * (siehe assignableRoles()).
     *
     * @return list<string>
     */
    private function importableRoles(): array
    {
        return $this->ctx->auth->isAdmin()
            ? ['school_admin', 'orga', 'teacher', 'student']
            : ['orga', 'teacher', 'student'];
    }

    // ---------- Helfer: Zugriff ----------

    /** Lädt einen Benutzer strikt innerhalb der aktuellen Schule. */
    private function loadUser(int $id): array
    {
        $user = $this->ctx->db->fetchOne(
            'SELECT * FROM users WHERE id = ? AND school_id = ?',
            [$id, (int) $this->ctx->schoolId()],
        );
        if ($user === null) {
            throw new HttpException(404, 'Dieser Benutzer existiert in dieser Schule nicht.');
        }

        return $user;
    }

    /**
     * Verhindert, dass ein weniger privilegierter Benutzer Konten mit
     * höherer Rolle verwaltet (Rechteausweitung). Global-Administratoren
     * sind im Schulkontext grundsätzlich tabu — auch für andere globale
     * Admins: ihre Konten gehören ausschließlich in den Global-Admin.
     */
    private function assertMayManage(array $target): void
    {
        $actorRole = (string) $this->ctx->auth->role();
        $targetRole = (string) $target['role'];

        if ($targetRole === 'admin') {
            throw new HttpException(
                403,
                'Global-Administratoren werden ausschließlich im Global-Admin verwaltet.',
            );
        }
        if ($actorRole === 'admin' || $actorRole === 'school_admin') {
            return;
        }
        if ($targetRole === 'school_admin') {
            throw new HttpException(403, 'Konten von Schul-Administratoren können nur von Administratoren verwaltet werden.');
        }
    }

    /** Letztes admin/school_admin-Konto der Schule? */
    private function isLastSchoolAdmin(array $user, int $schoolId): bool
    {
        if (!in_array((string) $user['role'], ['admin', 'school_admin'], true)) {
            return false;
        }
        $remaining = (int) $this->ctx->db->fetchValue(
            "SELECT COUNT(*) FROM users WHERE school_id = ? AND role IN ('admin', 'school_admin') AND id <> ?",
            [$schoolId, (int) $user['id']],
        );

        return $remaining === 0;
    }

    private function usernameTaken(string $username, int $schoolId, ?int $exceptId): bool
    {
        if ($exceptId === null) {
            return $this->ctx->db->fetchValue(
                'SELECT 1 FROM users WHERE username = ? AND school_key = ? LIMIT 1',
                [$username, $schoolId],
            ) !== null;
        }

        return $this->ctx->db->fetchValue(
            'SELECT 1 FROM users WHERE username = ? AND school_key = ? AND id <> ? LIMIT 1',
            [$username, $schoolId, $exceptId],
        ) !== null;
    }

    // ---------- Helfer: CSV ----------

    /**
     * Liest die hochgeladene CSV. Gibt den Dateiinhalt (UTF-8, ohne BOM)
     * oder ein Fehler-Array zurück.
     *
     * @return string|array{error: string}
     */
    private function readUploadedCsv(): string|array
    {
        $file = $_FILES['csv'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['error' => 'Bitte eine CSV-Datei auswählen.'];
        }
        if ((int) $file['error'] === UPLOAD_ERR_INI_SIZE || (int) $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            return ['error' => 'Die Datei ist zu groß (maximal 2 MB).'];
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            return ['error' => 'Die Datei konnte nicht hochgeladen werden.'];
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_CSV_BYTES) {
            return ['error' => 'Die Datei ist zu groß (maximal 2 MB).'];
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['error' => 'Die Datei konnte nicht gelesen werden.'];
        }

        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'txt'], true)) {
            return ['error' => 'Ungültige Datei. Bitte eine CSV-Datei (.csv oder .txt) hochladen.'];
        }

        $content = file_get_contents($tmp);
        if ($content === false || trim($content) === '') {
            return ['error' => 'Die Datei ist leer.'];
        }

        // BOM entfernen und, falls nötig, aus Latin-1 (Excel-Export) konvertieren.
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = (string) mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
        }

        return $content;
    }

    /**
     * Verarbeitet den CSV-Inhalt und legt die Benutzer an.
     *
     * @return array{imported: int, skipped: int, errors: list<string>, notes: list<string>, created: list<string>}
     */
    private function processCsv(string $content, int $schoolId): array
    {
        $allowedRoles = $this->importableRoles();
        $editionId = $this->ctx->editionId();

        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $imported = 0;
        $skipped = 0;
        $errors = [];
        $notes = [];
        $created = [];
        $seen = [];

        foreach ($lines as $index => $line) {
            $rowNumber = $index + 1;
            if ($rowNumber === 1) {
                continue; // Kopfzeile
            }
            if (trim($line) === '') {
                continue;
            }

            $cells = str_getcsv($line, ';', '"', '\\');
            if (count($cells) < 6) {
                $errors[] = sprintf('Zeile %d: zu wenige Spalten (mindestens 6 erwartet).', $rowNumber);
                continue;
            }

            $row = [];
            foreach (self::CSV_COLUMNS as $position => $name) {
                $row[$name] = trim((string) ($cells[$position] ?? ''));
            }

            if ($row['firstname'] === '' || $row['lastname'] === '' || $row['username'] === '' || $row['role'] === '') {
                $errors[] = sprintf('Zeile %d: Pflichtfelder fehlen (Vorname, Nachname, Benutzername, Rolle).', $rowNumber);
                continue;
            }
            if (preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $row['username']) !== 1) {
                $errors[] = sprintf('Zeile %d: ungültiger Benutzername "%s".', $rowNumber, $row['username']);
                continue;
            }

            $key = mb_strtolower($row['username']);
            if (isset($seen[$key])) {
                $skipped++;
                $notes[] = sprintf('Zeile %d: "%s" kommt in der Datei mehrfach vor — übersprungen.', $rowNumber, $row['username']);
                continue;
            }
            if ($this->usernameTaken($row['username'], $schoolId, null)) {
                $skipped++;
                $notes[] = sprintf('Zeile %d: "%s" existiert in dieser Schule bereits — übersprungen.', $rowNumber, $row['username']);
                continue;
            }

            $role = mb_strtolower($row['role']);
            if (!isset(self::ROLE_LABELS[$role]) || $role === 'exhibitor') {
                $errors[] = sprintf('Zeile %d: ungültige Rolle "%s".', $rowNumber, $row['role']);
                continue;
            }
            if (!in_array($role, $allowedRoles, true)) {
                $skipped++;
                $notes[] = sprintf('Zeile %d: Rolle "%s" darf nur ein globaler Administrator vergeben — übersprungen.', $rowNumber, $role);
                continue;
            }

            $email = $row['email'] !== '' ? $row['email'] : null;
            if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $notes[] = sprintf('Zeile %d: E-Mail "%s" ist ungültig und wurde nicht übernommen.', $rowNumber, $email);
                $email = null;
            }

            $class = $row['class'] !== '' ? mb_substr($row['class'], 0, 50) : null;

            // Leeres Passwort → Konto ohne Passwort; Klartext-Zugangsdaten
            // kommen später aus dem Zugangsdaten-PDF.
            $password = $row['password'];
            if ($password !== '' && mb_strlen($password) < AuthController::MIN_PASSWORD_LENGTH) {
                $errors[] = sprintf('Zeile %d: Passwort zu kurz (mindestens %d Zeichen).', $rowNumber, AuthController::MIN_PASSWORD_LENGTH);
                continue;
            }
            $hash = $password === '' ? null : password_hash($password, PASSWORD_DEFAULT);

            try {
                $this->ctx->db->run(
                    'INSERT INTO users (school_id, edition_id, username, email, password, firstname, lastname, class, role, must_change_password)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
                    [
                        $schoolId,
                        $editionId,
                        $row['username'],
                        $email,
                        $hash,
                        mb_substr($row['firstname'], 0, 100),
                        mb_substr($row['lastname'], 0, 100),
                        $class,
                        $role,
                    ],
                );
            } catch (\PDOException) {
                $errors[] = sprintf('Zeile %d: konnte nicht gespeichert werden.', $rowNumber);
                continue;
            }

            $seen[$key] = true;
            $imported++;
            $created[] = $row['username'];
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
            'notes' => $notes,
            'created' => $created,
        ];
    }

    // ---------- Helfer: Sonstiges ----------

    /**
     * Führt eine Abfrage mit LIMIT/OFFSET aus. LIMIT-Parameter müssen als
     * Integer gebunden werden (native Prepares akzeptieren dort keine Strings).
     *
     * @param list<mixed> $args
     * @return list<array<string, mixed>>
     */
    private function fetchPaged(string $sql, array $args, int $limit, int $offset): array
    {
        $stmt = $this->ctx->db->pdo()->prepare($sql);
        $position = 1;
        foreach ($args as $value) {
            $stmt->bindValue($position++, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue($position++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($position, $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** Maskiert LIKE-Sonderzeichen in Suchbegriffen. */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /** Zufallspasswort ohne leicht verwechselbare Zeichen (0/O, 1/l/I). */
    private static function generatePassword(int $length = 10): string
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
