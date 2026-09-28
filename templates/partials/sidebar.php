<?php
/**
 * Sidebar-Navigation — rollen- und berechtigungsabhängig.
 * Reihenfolge und Sichtbarkeit sind pro Schule anpassbar (nav_layout,
 * siehe Services\Customization); Berechtigungen filtern IMMER zusätzlich.
 */

use App\Core\Permissions as P;
use App\Services\Customization;
use App\Services\FeedbackService;
use App\Services\OrgaAccess;

$user = $auth->user();
$schoolId = $ctx->schoolId();
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

$navLink = static function (string $url, string $icon, string $label, int $count = 0) use ($currentPath): string {
    $active = $currentPath === $url || ($url !== '/' && str_starts_with($currentPath, rtrim($url, '/') . '/'));

    return '<a class="nav-link' . ($active ? ' active' : '') . '" href="' . e($url) . '">'
        . '<span class="nav-icon" aria-hidden="true">' . $icon . '</span>' . e($label)
        . ($count > 0 ? '<span class="nav-count" aria-label="' . e($count . ' ungelesen') . '">' . e((string) $count) . '</span>' : '')
        . '</a>';
};

$s = static fn (string $path): string => $ctx->schoolUrl($path);
$customization = new Customization($ctx->settings, $schoolId);
$navLayout = $ctx->school !== null ? $customization->navLayout() : [];

// Feedback taucht in der Rollen-Navigation nur auf, wenn gerade ein Bogen offen ist.
$openFeedback = 0;
if ($user !== null && $ctx->editionId() !== null) {
    $openFeedback = (new FeedbackService($ctx->db))
        ->openCountForRole((int) $ctx->editionId(), (string) $user['role']);
}
?>
<aside class="sidebar">
    <a class="sidebar-brand" href="<?= e($ctx->url('/')) ?>">
        <?php if ($ctx->school !== null && !empty($ctx->school['logo'])): ?>
            <img class="brand-logo" src="<?= e($ctx->url('/medien/logos/' . $ctx->school['logo'])) ?>" alt="">
        <?php else: ?>
            <span class="brand-mark">B</span>
        <?php endif; ?>
        <span>Berufsmesse</span>
    </a>
    <?php if ($ctx->school !== null): ?>
        <div class="sidebar-school">
            <?= e($ctx->school['name']) ?>
            <?php if ($ctx->edition !== null): ?>
                · <?= e($ctx->edition['name']) ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <nav class="sidebar-nav">
        <?php if ($user !== null && $ctx->school !== null): ?>
            <?php
            $role = $user['role'];
            $can = static fn (string $p): bool => $auth->can($p, $schoolId);

            // Alle Bereichs-Definitionen: key => [URL, Icon, Label, sichtbar?]
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

            // Orga-Tool: Rolle orga und Verwaltung, sobald eine Edition aktiv ist.
            if (in_array($role, OrgaAccess::ROLES, true) && $ctx->editionId() !== null && $schoolId !== null) {
                $orga = new OrgaAccess($ctx->db, $auth, (int) $schoolId, (int) $ctx->editionId());
                $orgaUnread = $orga->unreadCounts();
                // Ungelesenes aus Team-Chats hängt an der Übersicht, dort stehen alle Teams.
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
                // Für Orga-Mitglieder ist es der Hauptbereich, für Admins ein Bereich unter vielen.
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
                    'ausstattung' => [$s('/admin/ausstattung'), '🔌', 'Ausstattung', $can(P::AUSSTATTUNG_SEHEN)],
                    'feedback' => [$s('/admin/feedback'), '💬', 'Feedback', $can(P::FEEDBACK_SEHEN)],
                    'ankuendigungen' => [$s('/admin/ankuendigungen'), '📣', 'Ankündigungen', $can(P::ANKUENDIGUNGEN_VERWALTEN)],
                    'einstellungen' => [$s('/admin/einstellungen'), '⚙️', 'Einstellungen', $can(P::EINSTELLUNGEN_SEHEN)],
                    'darstellung' => [$s('/admin/darstellung'), '🎨', 'Darstellung', $isSchoolAdmin],
                    'audit-log' => [$s('/admin/audit-log'), '📜', 'Audit-Log', $can(P::AUDIT_LOGS_SEHEN)],
                ]];
            }

            if (isset($orgaSection) && $role !== 'orga') {
                $sections['orga'] = $orgaSection;
            }

            foreach ($sections as $sectionKey => [$sectionTitle, $items]) {
                // Erst Berechtigungsfilter, dann die schulspezifische Anordnung
                $items = array_filter($items, static fn (array $item): bool => $item[3]);
                $items = Customization::applyLayout($items, $navLayout[$sectionKey] ?? [], ['darstellung']);
                if ($items === []) {
                    continue;
                }
                echo '<div class="nav-section">' . e($sectionTitle) . '</div>';
                foreach ($items as $item) {
                    echo $navLink($item[0], $item[1], $item[2], (int) ($item[4] ?? 0));
                }
            }
            ?>
        <?php endif; ?>

        <?php if ($user !== null && $user['role'] === 'admin'): ?>
            <div class="nav-section">System</div>
            <?= $navLink($ctx->url('/global-admin'), '🌐', 'Global-Admin') ?>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        Berufsmesse
    </div>
</aside>
