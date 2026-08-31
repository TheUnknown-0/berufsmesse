<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * Global-Admin: schulübergreifende Übersicht und systemweite
 * Aussteller-Konten. Ausschließlich für die Rolle admin (requireAdmin).
 */
final class GlobalAdminController extends Controller
{
    /** GET /global-admin */
    public function index(array $params): string
    {
        $this->requireAdmin();

        $schools = $this->ctx->db->fetchAll(
            'SELECT s.*,
                    (SELECT me.id FROM messe_editions me
                      WHERE me.school_id = s.id AND me.status = \'active\'
                      ORDER BY me.year DESC, me.id DESC LIMIT 1) AS edition_id,
                    (SELECT me.name FROM messe_editions me
                      WHERE me.school_id = s.id AND me.status = \'active\'
                      ORDER BY me.year DESC, me.id DESC LIMIT 1) AS edition_name,
                    (SELECT COUNT(*) FROM users u WHERE u.school_id = s.id AND u.role = \'student\') AS student_count
             FROM schools s
             ORDER BY s.is_active DESC, s.name',
        );

        foreach ($schools as $i => $school) {
            $editionId = $school['edition_id'] !== null ? (int) $school['edition_id'] : 0;
            $schools[$i]['exhibitor_count'] = $editionId > 0
                ? (int) $this->ctx->db->fetchValue(
                    'SELECT COUNT(*) FROM exhibitors WHERE edition_id = ? AND active = 1',
                    [$editionId],
                )
                : 0;
            $schools[$i]['registration_count'] = $editionId > 0
                ? (int) $this->ctx->db->fetchValue(
                    'SELECT COUNT(*) FROM registrations WHERE edition_id = ?',
                    [$editionId],
                )
                : 0;
        }

        $system = [
            'schools' => (int) $this->ctx->db->fetchValue('SELECT COUNT(*) FROM schools'),
            'schools_active' => (int) $this->ctx->db->fetchValue('SELECT COUNT(*) FROM schools WHERE is_active = 1'),
            'users' => (int) $this->ctx->db->fetchValue('SELECT COUNT(*) FROM users'),
            'editions_active' => (int) $this->ctx->db->fetchValue('SELECT COUNT(*) FROM messe_editions WHERE status = \'active\''),
            'exhibitor_accounts' => (int) $this->ctx->db->fetchValue('SELECT COUNT(*) FROM users WHERE role = \'exhibitor\''),
            'pending_cancellations' => (int) $this->ctx->db->fetchValue('SELECT COUNT(*) FROM cancellation_requests WHERE status = \'pending\''),
        ];

        return $this->render('pages/global/index', [
            'title' => 'Global-Admin',
            'schools' => $schools,
            'system' => $system,
            'publicBaseUrl' => $this->ctx->settings->get('public_base_url') ?? '',
        ]);
    }

    /** POST /global-admin/einstellungen — systemweite Einstellungen. */
    public function saveSettings(array $params): string
    {
        $this->requireAdmin();
        $this->requireCsrf();

        $url = trim((string) ($_POST['public_base_url'] ?? ''));
        if ($url === '') {
            $this->ctx->settings->delete('public_base_url');
        } else {
            if (!preg_match('#^https?://[^\s/]+#i', $url)) {
                $this->flash('error', 'Die Basis-URL muss mit http:// oder https:// beginnen, z. B. https://messe.meine-schule.de');
                $this->redirect($this->ctx->url('/global-admin'));
            }
            $this->ctx->settings->set('public_base_url', rtrim($url, '/'));
        }

        $this->ctx->audit->log('Öffentliche Basis-URL geändert', 'info', $url !== '' ? $url : '(entfernt)');
        $this->flash('success', 'Einstellungen gespeichert.');
        $this->redirect($this->ctx->url('/global-admin'));
    }

    /** GET /global-admin/aussteller-konten */
    public function accounts(array $params): string
    {
        $this->requireAdmin();

        $filter = (string) ($_GET['status'] ?? 'alle');
        $search = trim((string) ($_GET['q'] ?? ''));

        $sql = 'SELECT eu.*, u.username, u.email, u.firstname, u.lastname,
                       e.name AS exhibitor_name, me.name AS edition_name, me.year,
                       s.name AS school_name, s.slug AS school_slug
                FROM exhibitor_users eu
                JOIN users u ON u.id = eu.user_id
                JOIN exhibitors e ON e.id = eu.exhibitor_id
                JOIN messe_editions me ON me.id = e.edition_id
                JOIN schools s ON s.id = me.school_id
                WHERE 1 = 1';
        $args = [];

        if ($filter === 'aktiv') {
            $sql .= ' AND eu.status = \'active\' AND eu.invite_accepted = 1';
        } elseif ($filter === 'einladung') {
            $sql .= ' AND eu.invite_accepted = 0 AND eu.status = \'active\'';
        } elseif ($filter === 'abgesagt') {
            $sql .= ' AND eu.status <> \'active\'';
        }

        if ($search !== '') {
            $sql .= ' AND (u.username LIKE ? OR u.firstname LIKE ? OR u.lastname LIKE ? OR e.name LIKE ? OR s.name LIKE ?)';
            $like = '%' . $search . '%';
            $args = [$like, $like, $like, $like, $like];
        }

        $sql .= ' ORDER BY s.name, e.name, u.username LIMIT 500';

        // Einmalig anzuzeigendes, frisch generiertes Passwort (aus resetAccountPassword)
        $oneTimePassword = $this->ctx->session->get('global_konto_pw');
        $this->ctx->session->remove('global_konto_pw');

        return $this->render('pages/global/konten', [
            'title' => 'Aussteller-Konten (systemweit)',
            'links' => $this->ctx->db->fetchAll($sql, $args),
            'filter' => $filter,
            'search' => $search,
            'inviteBaseUrl' => $this->ctx->publicUrl('/aussteller-einladung?token='),
            'oneTimePassword' => is_array($oneTimePassword) ? $oneTimePassword : null,
        ]);
    }

    // ------------------------------------------------ Konten bearbeiten

    /** POST /global-admin/aussteller-konten/{id} — Stammdaten des Kontos. */
    public function updateAccount(array $params): string
    {
        $this->requireAdmin();
        $this->requireCsrf();
        $account = $this->requireExhibitorAccount((int) $params['id']);
        $back = $this->ctx->url('/global-admin/aussteller-konten');

        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $firstname = trim((string) ($_POST['firstname'] ?? ''));
        $lastname = trim((string) ($_POST['lastname'] ?? ''));

        if (!preg_match('/^[a-zA-Z0-9._@+-]{3,100}$/', $username)) {
            $this->flash('error', 'Der Benutzername darf 3–100 Zeichen lang sein (Buchstaben, Zahlen, . _ @ + -).');
            $this->redirect($back);
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->flash('error', 'Bitte gib eine gültige E-Mail-Adresse an.');
            $this->redirect($back);
        }
        $taken = $this->ctx->db->fetchValue(
            'SELECT 1 FROM users WHERE username = ? AND school_id IS NULL AND id <> ?',
            [$username, (int) $account['id']],
        );
        if ($taken !== null) {
            $this->flash('error', 'Dieser Benutzername ist bereits vergeben.');
            $this->redirect($back);
        }

        $this->ctx->db->run(
            'UPDATE users SET username = ?, email = ?, firstname = ?, lastname = ? WHERE id = ?',
            [$username, $email !== '' ? $email : null, $firstname, $lastname, (int) $account['id']],
        );
        $this->ctx->audit->log('Aussteller-Konto bearbeitet', 'info', 'Benutzer: ' . $username);
        $this->flash('success', 'Konto gespeichert.');
        $this->redirect($back);
    }

    /** POST /global-admin/aussteller-konten/{id}/passwort — Zufallspasswort setzen oder Passwort entfernen. */
    public function resetAccountPassword(array $params): string
    {
        $this->requireAdmin();
        $this->requireCsrf();
        $account = $this->requireExhibitorAccount((int) $params['id']);
        $back = $this->ctx->url('/global-admin/aussteller-konten');

        if (($_POST['mode'] ?? '') === 'entfernen') {
            $this->ctx->db->run('UPDATE users SET password = NULL WHERE id = ?', [(int) $account['id']]);
            $this->ctx->audit->log('Aussteller-Konto: Passwort entfernt', 'warning', 'Benutzer: ' . (string) $account['username']);
            $this->flash('success', 'Passwort entfernt — das Konto kann sich erst nach einer neuen Einladung wieder anmelden.');
            $this->redirect($back);
        }

        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $password = '';
        for ($i = 0; $i < 10; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $this->ctx->db->run(
            'UPDATE users SET password = ?, must_change_password = 1 WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT), (int) $account['id']],
        );
        $this->ctx->session->set('global_konto_pw', [
            'username' => (string) $account['username'],
            'password' => $password,
        ]);
        $this->ctx->audit->log('Aussteller-Konto: Passwort zurückgesetzt', 'warning', 'Benutzer: ' . (string) $account['username']);
        $this->flash('success', 'Neues Passwort gesetzt — es wird nur EINMAL angezeigt.');
        $this->redirect($back);
    }

    /** POST /global-admin/aussteller-konten/{id}/loeschen — Konto samt Verknüpfungen löschen. */
    public function deleteAccount(array $params): string
    {
        $this->requireAdmin();
        $this->requireCsrf();
        $account = $this->requireExhibitorAccount((int) $params['id']);

        $this->ctx->db->run('DELETE FROM users WHERE id = ?', [(int) $account['id']]);
        $this->ctx->audit->log('Aussteller-Konto gelöscht', 'warning', 'Benutzer: ' . (string) $account['username']);
        $this->flash('success', 'Konto und alle Verknüpfungen wurden gelöscht.');
        $this->redirect($this->ctx->url('/global-admin/aussteller-konten'));
    }

    /** POST /global-admin/aussteller-konten/verknuepfung/{id}/erneuern — neuen Einladungslink erzeugen. */
    public function renewInvite(array $params): string
    {
        $this->requireAdmin();
        $this->requireCsrf();
        $link = $this->requireLinkRow((int) $params['id']);

        $token = bin2hex(random_bytes(32));
        $expires = (new \DateTimeImmutable('now'))->modify('+14 days')->format('Y-m-d H:i:s');
        $this->ctx->db->run(
            'UPDATE exhibitor_users
             SET invite_token = ?, invite_expires = ?, invite_accepted = 0,
                 status = \'active\', cancelled_at = NULL, cancel_reason = NULL
             WHERE id = ?',
            [$token, $expires, (int) $link['id']],
        );
        $this->ctx->audit->log(
            'Aussteller-Einladung erneuert',
            'info',
            'Benutzer: ' . (string) $link['username'] . ' — Unternehmen: ' . (string) $link['exhibitor_name'],
        );
        $this->flash('success', 'Neuer Einladungslink erzeugt — er steht in der Liste zum Kopieren bereit.');
        $this->redirect($this->ctx->url('/global-admin/aussteller-konten'));
    }

    /** POST /global-admin/aussteller-konten/verknuepfung/{id}/entfernen */
    public function removeLink(array $params): string
    {
        $this->requireAdmin();
        $this->requireCsrf();
        $link = $this->requireLinkRow((int) $params['id']);

        $this->ctx->db->run(
            'UPDATE exhibitor_users
             SET status = \'removed_by_admin\', cancelled_at = NOW(), invite_token = NULL, invite_expires = NULL
             WHERE id = ?',
            [(int) $link['id']],
        );
        $this->ctx->audit->log(
            'Aussteller-Verknüpfung entfernt',
            'warning',
            'Benutzer: ' . (string) $link['username'] . ' — Unternehmen: ' . (string) $link['exhibitor_name'],
        );
        $this->flash('success', 'Verknüpfung entfernt.');
        $this->redirect($this->ctx->url('/global-admin/aussteller-konten'));
    }

    /** @return array<string, mixed> Globales Aussteller-Konto oder 404. */
    private function requireExhibitorAccount(int $id): array
    {
        $account = $this->ctx->db->fetchOne(
            'SELECT * FROM users WHERE id = ? AND role = \'exhibitor\' AND school_id IS NULL',
            [$id],
        );
        if ($account === null) {
            throw new \App\Core\HttpException(404, 'Aussteller-Konto nicht gefunden.');
        }

        return $account;
    }

    /** @return array<string, mixed> Verknüpfung inkl. Benutzer-/Unternehmensname oder 404. */
    private function requireLinkRow(int $id): array
    {
        $link = $this->ctx->db->fetchOne(
            'SELECT eu.*, u.username, e.name AS exhibitor_name
             FROM exhibitor_users eu
             JOIN users u ON u.id = eu.user_id
             JOIN exhibitors e ON e.id = eu.exhibitor_id
             WHERE eu.id = ?',
            [$id],
        );
        if ($link === null) {
            throw new \App\Core\HttpException(404, 'Verknüpfung nicht gefunden.');
        }

        return $link;
    }
}
