<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;

/**
 * Schulverwaltung im Global-Admin (/global-admin/schulen).
 *
 * Das Löschen einer Schule kaskadiert über Fremdschlüssel durch das gesamte
 * Datenmodell — deshalb ist eine doppelte Bestätigung (Name eintippen) nötig.
 */
final class SchoolsAdminController extends Controller
{
    /** GET /global-admin/schulen */
    public function index(array $params): string
    {
        $this->requireAdmin();

        $schools = $this->ctx->db->fetchAll(
            'SELECT s.*,
                    (SELECT COUNT(*) FROM messe_editions me WHERE me.school_id = s.id) AS edition_count,
                    (SELECT COUNT(*) FROM users u WHERE u.school_id = s.id) AS user_count
             FROM schools s
             ORDER BY s.name',
        );

        // Schulspezifische Basis-URL-Overrides für die Bearbeiten-Formulare
        $baseUrls = [];
        foreach ($this->ctx->db->fetchAll(
            'SELECT school_id, setting_value FROM settings WHERE setting_key = \'public_base_url\' AND school_id IS NOT NULL',
        ) as $row) {
            $baseUrls[(int) $row['school_id']] = (string) $row['setting_value'];
        }

        return $this->render('pages/global/schulen', [
            'title' => 'Schulen',
            'schools' => $schools,
            'baseUrls' => $baseUrls,
            'old' => $this->ctx->session->pullOldInput(),
        ]);
    }

    /** POST /global-admin/schulen */
    public function store(array $params): string
    {
        $this->requireAdmin();
        $this->requireCsrf();

        $back = $this->ctx->url('/global-admin/schulen');
        $this->ctx->session->rememberInput($_POST);

        $name = trim((string) ($_POST['name'] ?? ''));
        $slug = strtolower(trim((string) ($_POST['slug'] ?? '')));

        $this->validateSlug($name, $slug, $back);
        if ($this->ctx->db->fetchValue('SELECT 1 FROM schools WHERE slug = ?', [$slug]) !== null) {
            $this->flash('error', 'Dieser URL-Name ist bereits vergeben.');
            $this->redirect($back);
        }

        $this->ctx->db->run(
            'INSERT INTO schools (name, slug, address, contact_email, contact_phone, is_active)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $name,
                $slug,
                $this->text('address', 500),
                $this->email($back),
                $this->text('contact_phone', 50),
                isset($_POST['is_active']) ? 1 : 0,
            ],
        );

        $this->ctx->session->pullOldInput();
        $this->ctx->audit->log('Schule erstellt', 'info', $name . ' (/' . $slug . '/)', $this->ctx->db->lastInsertId());
        $this->flash('success', 'Schule angelegt.');
        $this->redirect($back);
    }

    /** POST /global-admin/schulen/{id} */
    public function update(array $params): string
    {
        $this->requireAdmin();
        $this->requireCsrf();

        $back = $this->ctx->url('/global-admin/schulen');
        $school = $this->requireSchoolRow((int) $params['id']);

        $name = trim((string) ($_POST['name'] ?? ''));
        $slug = strtolower(trim((string) ($_POST['slug'] ?? '')));

        $this->validateSlug($name, $slug, $back);
        if ($this->ctx->db->fetchValue('SELECT 1 FROM schools WHERE slug = ? AND id <> ?', [$slug, (int) $school['id']]) !== null) {
            $this->flash('error', 'Dieser URL-Name ist bereits vergeben.');
            $this->redirect($back);
        }

        $this->ctx->db->run(
            'UPDATE schools
             SET name = ?, slug = ?, address = ?, contact_email = ?, contact_phone = ?, is_active = ?
             WHERE id = ?',
            [
                $name,
                $slug,
                $this->text('address', 500),
                $this->email($back),
                $this->text('contact_phone', 50),
                isset($_POST['is_active']) ? 1 : 0,
                (int) $school['id'],
            ],
        );

        // Optionale schulspezifische Basis-URL (überschreibt die globale)
        $baseUrl = trim((string) ($_POST['public_base_url'] ?? ''));
        if ($baseUrl === '') {
            $this->ctx->settings->delete('public_base_url', (int) $school['id']);
        } elseif (preg_match('#^https?://[^\s/]+#i', $baseUrl)) {
            $this->ctx->settings->set('public_base_url', rtrim($baseUrl, '/'), (int) $school['id']);
        } else {
            $this->flash('warning', 'Die Basis-URL wurde nicht übernommen — sie muss mit http:// oder https:// beginnen.');
        }

        $this->ctx->audit->log('Schule bearbeitet', 'info', $name . ' (/' . $slug . '/)', (int) $school['id']);
        $this->flash('success', 'Schule gespeichert.');
        $this->redirect($back);
    }

    /** POST /global-admin/schulen/{id}/loeschen */
    public function delete(array $params): string
    {
        $this->requireAdmin();
        $this->requireCsrf();

        $back = $this->ctx->url('/global-admin/schulen');
        $school = $this->requireSchoolRow((int) $params['id']);

        $typed = trim((string) ($_POST['confirm_name'] ?? ''));
        if ($typed !== (string) $school['name']) {
            $this->flash('error', 'Der eingegebene Name stimmt nicht mit dem Schulnamen überein. Es wurde nichts gelöscht.');
            $this->redirect($back);
        }

        $this->ctx->db->run('DELETE FROM schools WHERE id = ?', [(int) $school['id']]);

        // school_id im Audit-Log ist ON DELETE SET NULL → Eintrag ohne Schulbezug
        $this->ctx->audit->log(
            'Schule gelöscht',
            'error',
            'Schule: ' . (string) $school['name'] . ' (/' . (string) $school['slug'] . '/) inklusive aller Daten',
        );
        $this->flash('success', 'Die Schule und alle zugehörigen Daten wurden gelöscht.');
        $this->redirect($back);
    }

    // ---------- Helfer ----------

    private function requireSchoolRow(int $id): array
    {
        $school = $this->ctx->db->fetchOne('SELECT * FROM schools WHERE id = ?', [$id]);
        if ($school === null) {
            throw new HttpException(404, 'Diese Schule existiert nicht.');
        }

        return $school;
    }

    /** Name + Slug prüfen (Format und Kollision mit globalen Routen). */
    private function validateSlug(string $name, string $slug, string $back): void
    {
        if ($name === '') {
            $this->flash('error', 'Bitte gib einen Schulnamen an.');
            $this->redirect($back);
        }
        if (!preg_match('/^[a-z0-9-]{2,100}$/', $slug)) {
            $this->flash('error', 'Der URL-Name darf 2–100 Zeichen lang sein (nur Kleinbuchstaben, Zahlen, Minus).');
            $this->redirect($back);
        }
        if (in_array($slug, SetupController::reservedSlugs(), true)) {
            $this->flash('error', 'Dieser URL-Name ist für interne Seiten reserviert. Bitte wähle einen anderen.');
            $this->redirect($back);
        }
    }

    private function text(string $key, int $maxLength): ?string
    {
        $value = trim((string) ($_POST[$key] ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    private function email(string $back): ?string
    {
        $value = $this->text('contact_email', 255);
        if ($value !== null && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->flash('error', 'Bitte gib eine gültige Kontakt-E-Mail-Adresse an.');
            $this->redirect($back);
        }

        return $value;
    }
}
