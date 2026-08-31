<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Services\Customization;
use App\Services\Uploads;

/**
 * „Darstellung" — schulspezifisches Customizing für Schul-Admins:
 * Logo, Farben, Login-Hintergrund sowie Anordnung/Sichtbarkeit der
 * Navigation und der Schüler-Startseite (Drag & Drop).
 */
final class CustomizeController extends Controller
{
    /** GET /{school}/admin/darstellung */
    public function index(array $params): string
    {
        $school = $this->requireSchoolAdmin($params['school']);
        $customization = new Customization($this->ctx->settings, (int) $school['id']);

        return $this->render('pages/customize/index', [
            'title' => 'Darstellung',
            'theme' => $customization->theme(),
            'navLayout' => $customization->navLayout(),
            'navSections' => self::navCatalog(),
            'pageScripts' => ['customize.js'],
        ]);
    }

    /** POST /{school}/admin/darstellung/farben */
    public function saveColors(array $params): string
    {
        $school = $this->requireSchoolAdmin($params['school']);
        $this->requireCsrf();
        $schoolId = (int) $school['id'];
        $back = $this->ctx->schoolUrl('/admin/darstellung');

        if (isset($_POST['reset'])) {
            $this->ctx->settings->delete('custom_primary', $schoolId);
            $this->ctx->settings->delete('custom_bg', $schoolId);
            $this->ctx->audit->log('Darstellung: Farben zurückgesetzt', 'info', null, $schoolId);
            $this->flash('success', 'Farben auf den Standard zurückgesetzt.');
            $this->redirect($back);
        }

        foreach (['custom_primary' => 'primary', 'custom_bg' => 'bg'] as $key => $field) {
            $value = trim((string) ($_POST[$field] ?? ''));
            if ($value === '' || (isset($_POST[$field . '_default']) && $_POST[$field . '_default'] === '1')) {
                $this->ctx->settings->delete($key, $schoolId);
            } elseif (preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1) {
                $this->ctx->settings->set($key, strtolower($value), $schoolId);
            } else {
                $this->flash('error', 'Bitte gültige Farben im Format #rrggbb wählen.');
                $this->redirect($back);
            }
        }

        $this->ctx->audit->log('Darstellung: Farben geändert', 'info', null, $schoolId);
        $this->flash('success', 'Farben gespeichert.');
        $this->redirect($back);
    }

    /** POST /{school}/admin/darstellung/logo — hochladen oder entfernen. */
    public function saveLogo(array $params): string
    {
        $school = $this->requireSchoolAdmin($params['school']);
        $this->requireCsrf();
        $schoolId = (int) $school['id'];
        $back = $this->ctx->schoolUrl('/admin/darstellung');
        $uploads = new Uploads($this->ctx->config['uploads']['dir']);

        if (isset($_POST['remove'])) {
            if (!empty($school['logo'])) {
                $uploads->delete('logos', (string) $school['logo']);
                $this->ctx->db->run('UPDATE schools SET logo = NULL WHERE id = ?', [$schoolId]);
            }
            $this->ctx->audit->log('Darstellung: Logo entfernt', 'info', null, $schoolId);
            $this->flash('success', 'Logo entfernt.');
            $this->redirect($back);
        }

        $stored = $uploads->store(
            $_FILES['logo'] ?? [],
            'logos',
            ['png', 'jpg', 'jpeg', 'webp', 'svg'],
            2 * 1024 * 1024,
        );
        if (!empty($school['logo'])) {
            $uploads->delete('logos', (string) $school['logo']);
        }
        $this->ctx->db->run('UPDATE schools SET logo = ? WHERE id = ?', [$stored['filename'], $schoolId]);

        $this->ctx->audit->log('Darstellung: Logo hochgeladen', 'info', $stored['original_name'], $schoolId);
        $this->flash('success', 'Logo gespeichert.');
        $this->redirect($back);
    }

    /** POST /{school}/admin/darstellung/hintergrund — Login-Hintergrundbild. */
    public function saveBackground(array $params): string
    {
        $school = $this->requireSchoolAdmin($params['school']);
        $this->requireCsrf();
        $schoolId = (int) $school['id'];
        $back = $this->ctx->schoolUrl('/admin/darstellung');
        $uploads = new Uploads($this->ctx->config['uploads']['dir']);
        $current = $this->ctx->settings->get('custom_login_image', $schoolId);

        if (isset($_POST['remove'])) {
            if ($current !== null) {
                $uploads->delete('branding', $current);
                $this->ctx->settings->delete('custom_login_image', $schoolId);
            }
            $this->ctx->audit->log('Darstellung: Login-Hintergrund entfernt', 'info', null, $schoolId);
            $this->flash('success', 'Hintergrundbild entfernt.');
            $this->redirect($back);
        }

        $stored = $uploads->store(
            $_FILES['background'] ?? [],
            'branding',
            ['jpg', 'jpeg', 'png', 'webp'],
            4 * 1024 * 1024,
        );
        if ($current !== null) {
            $uploads->delete('branding', $current);
        }
        $this->ctx->settings->set('custom_login_image', $stored['filename'], $schoolId);

        $this->ctx->audit->log('Darstellung: Login-Hintergrund gesetzt', 'info', $stored['original_name'], $schoolId);
        $this->flash('success', 'Hintergrundbild gespeichert.');
        $this->redirect($back);
    }

    /** POST /{school}/api/darstellung/navigation — Anordnung speichern (JSON). */
    public function saveNav(array $params): array
    {
        $school = $this->requireSchoolAdmin($params['school']);
        $this->requireCsrf();

        $payload = $this->jsonBody();
        $clean = [];
        foreach (Customization::NAV_SECTIONS as $section) {
            $valid = array_keys(self::navCatalog()[$section][1] ?? []);
            $clean[$section] = $this->cleanLayout($payload[$section] ?? [], $valid);
        }

        $this->ctx->settings->set('nav_layout', json_encode($clean, JSON_UNESCAPED_UNICODE), (int) $school['id']);
        $this->ctx->audit->log('Darstellung: Navigation angepasst', 'info', null, (int) $school['id']);

        return ['success' => true];
    }

    /**
     * POST /{school}/api/darstellung/seite — Block-Anordnung einer Seite
     * speichern (JSON: {page, order, hidden}); kommt aus dem Anordnen-Modus.
     */
    public function savePageLayout(array $params): array
    {
        $school = $this->requireSchoolAdmin($params['school']);
        $this->requireCsrf();
        $payload = $this->jsonBody();

        $page = (string) ($payload['page'] ?? '');
        if (!preg_match('/^[a-z0-9-]{1,64}$/', $page) || $page === 'darstellung') {
            return $this->jsonError('Ungültige Seite.');
        }

        // Optionale Ziel-Rollengruppe ('' = Basis für alle Rollen)
        $role = (string) ($payload['role'] ?? '');
        if ($role !== '' && !in_array($role, \App\Core\PageBlocks::ROLE_GROUPS, true)) {
            return $this->jsonError('Ungültige Rolle.');
        }
        $settingKey = 'page_layout:' . $page . ($role !== '' ? ':' . $role : '');

        $order = [];
        $hidden = [];
        foreach ((array) ($payload['order'] ?? []) as $key) {
            if (is_string($key) && preg_match('/^[a-z0-9-]{1,40}$/', $key) && !in_array($key, $order, true)) {
                $order[] = $key;
            }
        }
        foreach ((array) ($payload['hidden'] ?? []) as $key) {
            if (is_string($key) && preg_match('/^[a-z0-9-]{1,40}$/', $key) && !in_array($key, $hidden, true)) {
                $hidden[] = $key;
            }
        }

        $schoolId = (int) $school['id'];
        $detail = 'Seite: ' . $page . ($role !== '' ? ' (Rolle: ' . $role . ')' : ' (Basis)');
        if (isset($payload['reset']) && $payload['reset'] === true) {
            $this->ctx->settings->delete($settingKey, $schoolId);
            $this->ctx->audit->log('Darstellung: Seiten-Anordnung zurückgesetzt', 'info', $detail, $schoolId);

            return ['success' => true];
        }

        $this->ctx->settings->set(
            $settingKey,
            json_encode(['order' => $order, 'hidden' => $hidden], JSON_UNESCAPED_UNICODE),
            $schoolId,
        );
        $this->ctx->audit->log('Darstellung: Seiten-Anordnung geändert', 'info', $detail, $schoolId);

        return ['success' => true];
    }

    /** POST /{school}/admin/darstellung/zuruecksetzen — alles auf Standard. */
    public function resetAll(array $params): string
    {
        $school = $this->requireSchoolAdmin($params['school']);
        $this->requireCsrf();
        $schoolId = (int) $school['id'];

        foreach (['custom_primary', 'custom_bg', 'nav_layout', 'dashboard_layout'] as $key) {
            $this->ctx->settings->delete($key, $schoolId);
        }
        // Alle gespeicherten Seiten-Anordnungen der Schule entfernen
        $this->ctx->db->run(
            'DELETE FROM settings WHERE school_key = ? AND setting_key LIKE \'page_layout:%\'',
            [$schoolId],
        );
        $this->ctx->audit->log('Darstellung: komplett zurückgesetzt', 'info', null, $schoolId);
        $this->flash('success', 'Darstellung auf den Standard zurückgesetzt (Logo und Hintergrundbild bleiben erhalten).');
        $this->redirect($this->ctx->schoolUrl('/admin/darstellung'));
    }

    // ---------------------------------------------------------- Helfer

    /**
     * Katalog aller anpassbaren Navigationseinträge je Bereich —
     * muss mit templates/partials/sidebar.php übereinstimmen.
     *
     * @return array<string, array{string, array<string, array{string, string}>}>
     */
    public static function navCatalog(): array
    {
        return [
            'student' => ['Schüler:innen', [
                'uebersicht' => ['🏠', 'Übersicht'],
                'aussteller' => ['🏢', 'Aussteller'],
                'einschreibung' => ['📝', 'Einschreibung'],
                'meine-anmeldungen' => ['⭐', 'Meine Anmeldungen'],
                'tagesplan' => ['🗓️', 'Tagesplan'],
                'checkin' => ['📷', 'Check-in'],
            ]],
            'teacher' => ['Lehrkräfte', [
                'uebersicht' => ['🏠', 'Übersicht'],
                'aussteller' => ['🏢', 'Aussteller'],
                'klassen' => ['🧑‍🏫', 'Klassen'],
                'scan' => ['📷', 'Scanner'],
            ]],
            'exhibitor' => ['Aussteller-Portal', [
                'portal' => ['🏠', 'Übersicht'],
                'slots' => ['🗓️', 'Slots & Anmeldungen'],
                'ausstattung' => ['🔌', 'Ausstattung'],
                'dokumente' => ['📄', 'Dokumente'],
            ]],
            'admin' => ['Verwaltung', [
                'dashboard' => ['📊', 'Dashboard'],
                'aussteller' => ['🏢', 'Aussteller'],
                'raeume' => ['🚪', 'Räume'],
                'kapazitaeten' => ['📐', 'Kapazitäten'],
                'anmeldungen' => ['📝', 'Anmeldungen'],
                'benutzer' => ['👥', 'Benutzer'],
                'berechtigungen' => ['🔑', 'Berechtigungen'],
                'qr-codes' => ['🔳', 'QR-Codes'],
                'anwesenheit' => ['✅', 'Anwesenheit'],
                'aufsicht' => ['🦺', 'Aufsichtsplan'],
                'druckzentrale' => ['🖨️', 'Druckzentrale'],
                'ausstattung' => ['🔌', 'Ausstattung'],
                'ankuendigungen' => ['📣', 'Ankündigungen'],
                'einstellungen' => ['⚙️', 'Einstellungen'],
                'darstellung' => ['🎨', 'Darstellung'],
                'audit-log' => ['📜', 'Audit-Log'],
            ]],
        ];
    }

    /** Nur admin/school_admin der Schule dürfen die Darstellung anpassen. */
    private function requireSchoolAdmin(string $slug): array
    {
        $school = $this->requireSchool($slug);
        if (!in_array($this->ctx->auth->role(), ['admin', 'school_admin'], true)) {
            throw new HttpException(403);
        }

        return $school;
    }

    /** @return array<string, mixed> */
    private function jsonBody(): array
    {
        $decoded = json_decode((string) file_get_contents('php://input'), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Bereinigt {order, hidden} gegen die Liste gültiger Keys.
     *
     * @param list<string> $validKeys
     * @return array{order: list<string>, hidden: list<string>}
     */
    private function cleanLayout(mixed $layout, array $validKeys): array
    {
        $order = [];
        $hidden = [];
        if (is_array($layout)) {
            foreach ((array) ($layout['order'] ?? []) as $key) {
                if (is_string($key) && in_array($key, $validKeys, true) && !in_array($key, $order, true)) {
                    $order[] = $key;
                }
            }
            foreach ((array) ($layout['hidden'] ?? []) as $key) {
                if (is_string($key) && in_array($key, $validKeys, true) && !in_array($key, $hidden, true)) {
                    $hidden[] = $key;
                }
            }
        }

        return ['order' => $order, 'hidden' => $hidden];
    }
}
