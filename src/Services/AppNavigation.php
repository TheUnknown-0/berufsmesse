<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Context;
use App\Core\Permissions as P;

/**
 * Funktionen, die ein Konto in einer Schule sehen darf, für die App.
 *
 * Bildet dieselben Regeln ab wie die Seitenleiste (templates/partials/sidebar.php):
 * Rolle, granulare Rechte, offene Feedback-Bögen, Orga-Zugang und die
 * schulspezifische Anordnung (nav_layout). Wer hier etwas ändert, ändert es
 * dort mit.
 *
 * Pfade sind relativ zur Anwendung (ohne BASE_URL), z. B. "/musterschule/aussteller".
 */
final class AppNavigation
{
    public function __construct(private readonly Context $ctx)
    {
    }

    /**
     * @return list<array{key: string, title: string, items: list<array{key: string, label: string, icon: string, path: string, badge: int}>}>
     */
    public function sections(): array
    {
        $ctx = $this->ctx;
        $auth = $ctx->auth;
        $user = $auth->user();
        if ($user === null || $ctx->school === null) {
            return [];
        }

        $schoolId = (int) $ctx->school['id'];
        $role = (string) $user['role'];
        $can = static fn (string $p): bool => $auth->can($p, $schoolId);
        $s = fn (string $path): string => '/' . $ctx->school['slug'] . ($path === '/' ? '/' : $path);

        $openFeedback = 0;
        if ($ctx->editionId() !== null) {
            $openFeedback = (new FeedbackService($ctx->db))->openCountForRole((int) $ctx->editionId(), $role);
        }

        // key => [Pfad, Icon, Label, sichtbar?, Zähler]
        $sections = [];

        if ($role === 'student') {
            $sections['student'] = ['Meine Messe', [
                'uebersicht' => [$s('/'), '🏠', 'Übersicht', true],
                'aussteller' => [$s('/aussteller'), '🏢', 'Aussteller', true],
                'einschreibung' => [$s('/einschreibung'), '📝', 'Einschreibung', true],
                'meine-anmeldungen' => [$s('/meine-anmeldungen'), '⭐', 'Meine Anmeldungen', true],
                'tagesplan' => [$s('/tagesplan'), '🗓️', 'Tagesplan', true],
                'checkin' => [$s('/checkin'), '📷', 'Check-in', true],
                'feedback' => [$s('/feedback'), '💬', 'Feedback', $openFeedback > 0],
            ]];
        }

        if ($role === 'teacher') {
            $sections['teacher'] = ['Unterricht', [
                'uebersicht' => [$s('/'), '🏠', 'Übersicht', true],
                'aussteller' => [$s('/aussteller'), '🏢', 'Aussteller', true],
                'klassen' => [$s('/klassen'), '🧑‍🏫', 'Klassen', true],
                'scan' => [$s('/scan'), '📷', 'Scanner', true],
                'feedback' => [$s('/feedback'), '💬', 'Feedback', $openFeedback > 0],
            ]];
        }

        if ($role === 'exhibitor') {
            $sections['exhibitor'] = ['Aussteller-Portal', [
                'portal' => [$s('/portal'), '🏠', 'Übersicht', true],
                'slots' => [$s('/portal/slots'), '🗓️', 'Slots & Anmeldungen', true],
                'ausstattung' => [$s('/portal/ausstattung'), '🔌', 'Ausstattung', true],
                'dokumente' => [$s('/portal/dokumente'), '📄', 'Dokumente', true],
                'feedback' => [$s('/feedback'), '💬', 'Feedback', $openFeedback > 0],
            ]];
        }

        $orgaSection = null;
        if (in_array($role, OrgaAccess::ROLES, true) && $ctx->editionId() !== null) {
            $orga = new OrgaAccess($ctx->db, $auth, $schoolId, (int) $ctx->editionId());
            $orgaUnread = $orga->unreadCounts();
            $teamUnread = array_sum(array_filter(
                $orgaUnread,
                static fn (string $key): bool => str_starts_with($key, 't'),
                ARRAY_FILTER_USE_KEY,
            ));
            $orgaSection = ['Orga-Tool', [
                'uebersicht' => [$s('/orga'), '🧩', 'Übersicht', true, $teamUnread],
                'meine-aufgaben' => [$s('/orga/meine-aufgaben'), '✅', 'Meine Aufgaben', true, 0],
                'gesamtchat' => [$s('/orga/chat/gesamt'), '📢', 'Gesamtchat', true, $orgaUnread['all'] ?? 0],
                'leitungsrunde' => [$s('/orga/chat/leitung'), '🧭', 'Leitungsrunde', array_key_exists('leads', $orgaUnread), $orgaUnread['leads'] ?? 0],
                'verwaltung' => [$s('/orga/verwaltung'), '⚙️', 'Orga-Verwaltung', $orga->manages(), 0],
            ]];
            if ($role === 'orga') {
                $sections['orga'] = $orgaSection;
            }
        }

        if (in_array($role, ['admin', 'school_admin', 'orga', 'teacher'], true)) {
            $isSchoolAdmin = in_array($role, ['admin', 'school_admin'], true);
            $sections['admin'] = ['Verwaltung', [
                'dashboard' => [$s('/admin/dashboard'), '📊', 'Dashboard', $can(P::DASHBOARD_SEHEN)],
                'aussteller' => [$s('/admin/aussteller'), '🏢', 'Aussteller', $can(P::AUSSTELLER_SEHEN)],
                'raeume' => [$s('/admin/raeume'), '🚪', 'Räume', $can(P::RAEUME_SEHEN)],
                'kapazitaeten' => [$s('/admin/kapazitaeten'), '📐', 'Kapazitäten', $can(P::KAPAZITAETEN_SEHEN)],
                'anmeldungen' => [$s('/admin/anmeldungen'), '📝', 'Anmeldungen', $can(P::ANMELDUNGEN_SEHEN)],
                'benutzer' => [$s('/admin/benutzer'), '👥', 'Benutzer', $can(P::BENUTZER_SEHEN)],
                'berechtigungen' => [$s('/admin/berechtigungen'), '🔑', 'Berechtigungen', $can(P::BERECHTIGUNGEN_SEHEN)],
                'qr-codes' => [$s('/admin/qr-codes'), '🔳', 'QR-Codes', $can(P::QR_CODES_SEHEN)],
                'anwesenheit' => [$s('/admin/anwesenheit'), '✅', 'Anwesenheit', $can(P::ANWESENHEIT_SEHEN)],
                'leitstand' => [$s('/admin/leitstand'), '🛰️', 'Leitstand', $can(P::ANWESENHEIT_SEHEN)],
                'aufsicht' => [$s('/admin/aufsicht'), '🦺', 'Aufsichtsplan', $can(P::AUFSICHTSPLAN_SEHEN)],
                'druckzentrale' => [$s('/admin/druckzentrale'), '🖨️', 'Druckzentrale', $can(P::BERICHTE_SEHEN)],
                'jahresvergleich' => [$s('/admin/jahresvergleich'), '📈', 'Jahresvergleich', $can(P::BERICHTE_SEHEN)],
                'editionen' => [$s('/admin/editionen'), '🗂️', 'Editionen', $can(P::EDITIONEN_SEHEN)],
                'ausstattung' => [$s('/admin/ausstattung'), '🔌', 'Ausstattung', $can(P::AUSSTATTUNG_SEHEN)],
                'feedback' => [$s('/admin/feedback'), '💬', 'Feedback', $can(P::FEEDBACK_SEHEN)],
                'ankuendigungen' => [$s('/admin/ankuendigungen'), '📣', 'Ankündigungen', $can(P::ANKUENDIGUNGEN_VERWALTEN)],
                'einstellungen' => [$s('/admin/einstellungen'), '⚙️', 'Einstellungen', $can(P::EINSTELLUNGEN_SEHEN)],
                'darstellung' => [$s('/admin/darstellung'), '🎨', 'Darstellung', $isSchoolAdmin],
                'audit-log' => [$s('/admin/audit-log'), '📜', 'Audit-Log', $can(P::AUDIT_LOGS_SEHEN)],
            ]];
        }

        if ($orgaSection !== null && $role !== 'orga') {
            $sections['orga'] = $orgaSection;
        }

        $navLayout = (new Customization($ctx->settings, $schoolId))->navLayout();
        $result = [];
        foreach ($sections as $sectionKey => [$title, $items]) {
            $items = array_filter($items, static fn (array $item): bool => $item[3]);
            $items = Customization::applyLayout($items, $navLayout[$sectionKey] ?? [], ['darstellung']);
            if ($items === []) {
                continue;
            }
            $list = [];
            foreach ($items as $key => $item) {
                $list[] = [
                    'key' => $sectionKey . '.' . $key,
                    'label' => $item[2],
                    'icon' => $item[1],
                    'path' => $item[0],
                    'badge' => (int) ($item[4] ?? 0),
                ];
            }
            $result[] = ['key' => $sectionKey, 'title' => $title, 'items' => $list];
        }

        if ($role === 'admin') {
            $result[] = ['key' => 'system', 'title' => 'System', 'items' => [[
                'key' => 'system.global-admin',
                'label' => 'Global-Admin',
                'icon' => '🌐',
                'path' => '/global-admin',
                'badge' => 0,
            ]]];
        }

        return $result;
    }
}
