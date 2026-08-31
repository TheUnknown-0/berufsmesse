<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Permissions as P;

/**
 * Landingpage (Schulauswahl) und rollenabhängiger Einstieg je Schule.
 */
final class HomeController extends Controller
{
    /** GET / — Schulauswahl bzw. Weiterleitung zum Setup beim ersten Start. */
    public function landing(array $params): string
    {
        if ($this->ctx->db->fetchValue('SELECT 1 FROM users LIMIT 1') === null) {
            $this->redirect($this->ctx->url('/setup'));
        }

        $schools = $this->ctx->db->fetchAll(
            'SELECT * FROM schools WHERE is_active = 1 ORDER BY name',
        );

        return $this->render('pages/schools', [
            'title' => 'Schulauswahl',
            'schools' => $schools,
        ], 'minimal');
    }

    /** GET /{school}/ — leitet je nach Rolle zur passenden Startseite. */
    public function schoolHome(array $params): string
    {
        $this->requireSchool($params['school']);
        $role = $this->ctx->auth->role();

        $target = match ($role) {
            'exhibitor' => '/portal',
            'teacher' => '/klassen',
            'student' => '/uebersicht',
            default => $this->firstPermittedAdminPage(),
        };

        $this->redirect($this->ctx->schoolUrl($target));
    }

    /**
     * Erste Admin-Seite, die der Nutzer laut Berechtigungen sehen darf
     * (gleiche Reihenfolge wie die Sidebar). Ohne jedes Admin-Recht landet
     * er auf der für alle sichtbaren Ausstellerübersicht.
     */
    private function firstPermittedAdminPage(): string
    {
        $candidates = [
            [P::DASHBOARD_SEHEN, '/admin/dashboard'],
            [P::AUSSTELLER_SEHEN, '/admin/aussteller'],
            [P::RAEUME_SEHEN, '/admin/raeume'],
            [P::ANMELDUNGEN_SEHEN, '/admin/anmeldungen'],
            [P::BENUTZER_SEHEN, '/admin/benutzer'],
            [P::QR_CODES_SEHEN, '/admin/qr-codes'],
            [P::ANWESENHEIT_SEHEN, '/admin/anwesenheit'],
            [P::AUFSICHTSPLAN_SEHEN, '/admin/aufsicht'],
            [P::BERICHTE_SEHEN, '/admin/druckzentrale'],
            [P::AUSSTATTUNG_SEHEN, '/admin/ausstattung'],
            [P::ANKUENDIGUNGEN_VERWALTEN, '/admin/ankuendigungen'],
            [P::EINSTELLUNGEN_SEHEN, '/admin/einstellungen'],
            [P::AUDIT_LOGS_SEHEN, '/admin/audit-log'],
        ];

        foreach ($candidates as [$permission, $path]) {
            if ($this->ctx->auth->can($permission, $this->ctx->schoolId())) {
                return $path;
            }
        }

        return '/aussteller';
    }
}
