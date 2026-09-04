<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Katalog aller granularen Berechtigungen inkl. Abhängigkeitslogik.
 *
 * Regeln:
 *  - Beim ERTEILEN einer Berechtigung werden ihre Voraussetzungen
 *    rekursiv mit erteilt (z. B. braucht "aussteller_loeschen" auch
 *    "aussteller_sehen").
 *  - Beim ENTZIEHEN werden alle Berechtigungen, die davon abhängen,
 *    rekursiv mit entzogen.
 *
 * Berechtigungs-Keys sind ausschließlich die hier definierten Konstanten —
 * nie freie Strings (im Original führte ein veralteter Key zu einer
 * dauerhaft wirkungslosen Rechteprüfung).
 */
final class Permissions
{
    /**
     * Rollen, die überhaupt granulare Rechte tragen können.
     *
     * admin/school_admin haben ihre Rechte aus der Rollenlogik (siehe Auth),
     * student/exhibitor bekommen nie welche. Wer die Rolle wechselt, verliert
     * damit sofort alle zugewiesenen Rechte — auch dann, wenn in
     * user_permissions oder user_permission_groups noch Altbestände liegen.
     */
    public const GRANULAR_ROLES = ['orga', 'teacher'];

    // Dashboard & Berichte
    public const DASHBOARD_SEHEN = 'dashboard_sehen';
    public const BERICHTE_SEHEN = 'berichte_sehen';
    public const BERICHTE_DRUCKEN = 'berichte_drucken';

    // Aussteller
    public const AUSSTELLER_SEHEN = 'aussteller_sehen';
    public const AUSSTELLER_ERSTELLEN = 'aussteller_erstellen';
    public const AUSSTELLER_BEARBEITEN = 'aussteller_bearbeiten';
    public const AUSSTELLER_LOESCHEN = 'aussteller_loeschen';
    public const AUSSTELLER_DOKUMENTE_VERWALTEN = 'aussteller_dokumente_verwalten';
    public const AUSSTELLER_KONTEN_VERWALTEN = 'aussteller_konten_verwalten';

    // Branchen
    public const BRANCHEN_SEHEN = 'branchen_sehen';
    public const BRANCHEN_BEARBEITEN = 'branchen_bearbeiten';

    // Orga-Team
    public const ORGA_TEAM_SEHEN = 'orga_team_sehen';
    public const ORGA_TEAM_BEARBEITEN = 'orga_team_bearbeiten';

    // Räume & Kapazitäten
    public const RAEUME_SEHEN = 'raeume_sehen';
    public const RAEUME_ERSTELLEN = 'raeume_erstellen';
    public const RAEUME_BEARBEITEN = 'raeume_bearbeiten';
    public const RAEUME_LOESCHEN = 'raeume_loeschen';
    public const KAPAZITAETEN_SEHEN = 'kapazitaeten_sehen';
    public const KAPAZITAETEN_BEARBEITEN = 'kapazitaeten_bearbeiten';

    // Benutzer
    public const BENUTZER_SEHEN = 'benutzer_sehen';
    public const BENUTZER_ERSTELLEN = 'benutzer_erstellen';
    public const BENUTZER_BEARBEITEN = 'benutzer_bearbeiten';
    public const BENUTZER_LOESCHEN = 'benutzer_loeschen';
    public const BENUTZER_IMPORTIEREN = 'benutzer_importieren';
    public const BENUTZER_PASSWORT_ZURUECKSETZEN = 'benutzer_passwort_zuruecksetzen';

    // Berechtigungen
    public const BERECHTIGUNGEN_SEHEN = 'berechtigungen_sehen';
    public const BERECHTIGUNGEN_VERGEBEN = 'berechtigungen_vergeben';
    public const BERECHTIGUNGSGRUPPEN_VERWALTEN = 'berechtigungsgruppen_verwalten';

    // Einstellungen
    public const EINSTELLUNGEN_SEHEN = 'einstellungen_sehen';
    public const EINSTELLUNGEN_BEARBEITEN = 'einstellungen_bearbeiten';

    // Anmeldungen & Zuteilung
    public const ANMELDUNGEN_SEHEN = 'anmeldungen_sehen';
    public const ANMELDUNGEN_ERSTELLEN = 'anmeldungen_erstellen';
    public const ANMELDUNGEN_LOESCHEN = 'anmeldungen_loeschen';
    public const ZUTEILUNG_AUSFUEHREN = 'zuteilung_ausfuehren';
    public const ZUTEILUNG_ZURUECKSETZEN = 'zuteilung_zuruecksetzen';

    // QR & Anwesenheit
    public const QR_CODES_SEHEN = 'qr_codes_sehen';
    public const QR_CODES_ERSTELLEN = 'qr_codes_erstellen';
    public const ANWESENHEIT_SEHEN = 'anwesenheit_sehen';
    public const ANWESENHEIT_BEARBEITEN = 'anwesenheit_bearbeiten';

    // Aufsichtsplan
    public const AUFSICHTSPLAN_SEHEN = 'aufsichtsplan_sehen';
    public const AUFSICHTSPLAN_VERWALTEN = 'aufsichtsplan_verwalten';

    // Ausstattung
    public const AUSSTATTUNG_SEHEN = 'ausstattung_sehen';
    public const AUSSTATTUNG_VERWALTEN = 'ausstattung_verwalten';

    // Feedback-Bögen
    public const FEEDBACK_SEHEN = 'feedback_sehen';
    public const FEEDBACK_ERSTELLEN = 'feedback_erstellen';
    public const FEEDBACK_BEARBEITEN = 'feedback_bearbeiten';
    public const FEEDBACK_LOESCHEN = 'feedback_loeschen';
    public const FEEDBACK_FREISCHALTEN = 'feedback_freischalten';
    public const FEEDBACK_AUSWERTEN = 'feedback_auswerten';

    // Sonstiges
    public const ANKUENDIGUNGEN_VERWALTEN = 'ankuendigungen_verwalten';
    public const AUDIT_LOGS_SEHEN = 'audit_logs_sehen';

    /**
     * Anzeige-Katalog, gruppiert für die Rechtevergabe-UI.
     *
     * @return array<string, array<string, string>> Gruppe => [Key => Label]
     */
    public static function catalog(): array
    {
        return [
            'Dashboard & Berichte' => [
                self::DASHBOARD_SEHEN => 'Admin-Dashboard sehen',
                self::BERICHTE_SEHEN => 'Berichte sehen',
                self::BERICHTE_DRUCKEN => 'Berichte drucken (PDF/Export)',
            ],
            'Aussteller' => [
                self::AUSSTELLER_SEHEN => 'Aussteller sehen',
                self::AUSSTELLER_ERSTELLEN => 'Aussteller erstellen',
                self::AUSSTELLER_BEARBEITEN => 'Aussteller bearbeiten',
                self::AUSSTELLER_LOESCHEN => 'Aussteller löschen',
                self::AUSSTELLER_DOKUMENTE_VERWALTEN => 'Aussteller-Dokumente verwalten',
                self::AUSSTELLER_KONTEN_VERWALTEN => 'Aussteller-Konten & Einladungen verwalten',
            ],
            'Branchen' => [
                self::BRANCHEN_SEHEN => 'Branchen sehen',
                self::BRANCHEN_BEARBEITEN => 'Branchen bearbeiten',
            ],
            'Orga-Team' => [
                self::ORGA_TEAM_SEHEN => 'Orga-Team sehen',
                self::ORGA_TEAM_BEARBEITEN => 'Orga-Team bearbeiten',
            ],
            'Räume & Kapazitäten' => [
                self::RAEUME_SEHEN => 'Räume sehen',
                self::RAEUME_ERSTELLEN => 'Räume erstellen',
                self::RAEUME_BEARBEITEN => 'Räume bearbeiten',
                self::RAEUME_LOESCHEN => 'Räume löschen',
                self::KAPAZITAETEN_SEHEN => 'Slot-Kapazitäten sehen',
                self::KAPAZITAETEN_BEARBEITEN => 'Slot-Kapazitäten bearbeiten',
            ],
            'Benutzer' => [
                self::BENUTZER_SEHEN => 'Benutzer sehen',
                self::BENUTZER_ERSTELLEN => 'Benutzer erstellen',
                self::BENUTZER_BEARBEITEN => 'Benutzer bearbeiten',
                self::BENUTZER_LOESCHEN => 'Benutzer löschen',
                self::BENUTZER_IMPORTIEREN => 'Benutzer importieren (CSV)',
                self::BENUTZER_PASSWORT_ZURUECKSETZEN => 'Passwörter zurücksetzen',
            ],
            'Berechtigungen' => [
                self::BERECHTIGUNGEN_SEHEN => 'Berechtigungen sehen',
                self::BERECHTIGUNGEN_VERGEBEN => 'Berechtigungen vergeben',
                self::BERECHTIGUNGSGRUPPEN_VERWALTEN => 'Berechtigungsgruppen verwalten',
            ],
            'Einstellungen' => [
                self::EINSTELLUNGEN_SEHEN => 'Einstellungen sehen',
                self::EINSTELLUNGEN_BEARBEITEN => 'Einstellungen bearbeiten',
            ],
            'Anmeldungen & Zuteilung' => [
                self::ANMELDUNGEN_SEHEN => 'Anmeldungen sehen',
                self::ANMELDUNGEN_ERSTELLEN => 'Anmeldungen erstellen',
                self::ANMELDUNGEN_LOESCHEN => 'Anmeldungen löschen',
                self::ZUTEILUNG_AUSFUEHREN => 'Automatische Zuteilung ausführen',
                self::ZUTEILUNG_ZURUECKSETZEN => 'Zuteilung zurücksetzen',
            ],
            'QR & Anwesenheit' => [
                self::QR_CODES_SEHEN => 'QR-Codes sehen',
                self::QR_CODES_ERSTELLEN => 'QR-Codes erstellen',
                self::ANWESENHEIT_SEHEN => 'Anwesenheit sehen',
                self::ANWESENHEIT_BEARBEITEN => 'Anwesenheit bearbeiten',
            ],
            'Aufsichtsplan' => [
                self::AUFSICHTSPLAN_SEHEN => 'Aufsichtsplan sehen',
                self::AUFSICHTSPLAN_VERWALTEN => 'Aufsichtsplan verwalten',
            ],
            'Ausstattung' => [
                self::AUSSTATTUNG_SEHEN => 'Ausstattungsanfragen sehen',
                self::AUSSTATTUNG_VERWALTEN => 'Ausstattung verwalten',
            ],
            'Feedback' => [
                self::FEEDBACK_SEHEN => 'Feedback-Bögen sehen',
                self::FEEDBACK_ERSTELLEN => 'Feedback-Bögen erstellen',
                self::FEEDBACK_BEARBEITEN => 'Feedback-Bögen bearbeiten',
                self::FEEDBACK_LOESCHEN => 'Feedback-Bögen löschen',
                self::FEEDBACK_FREISCHALTEN => 'Feedback-Bögen freischalten & schließen',
                self::FEEDBACK_AUSWERTEN => 'Feedback auswerten & exportieren',
            ],
            'Sonstiges' => [
                self::ANKUENDIGUNGEN_VERWALTEN => 'Ankündigungen verwalten',
                self::AUDIT_LOGS_SEHEN => 'Audit-Log sehen',
            ],
        ];
    }

    /** @return list<string> Alle gültigen Keys. */
    public static function all(): array
    {
        $keys = [];
        foreach (self::catalog() as $group) {
            $keys = [...$keys, ...array_keys($group)];
        }

        return $keys;
    }

    public static function exists(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }

    /** Darf diese Rolle granulare Rechte tragen? */
    public static function allowsGranular(?string $role): bool
    {
        return $role !== null && in_array($role, self::GRANULAR_ROLES, true);
    }

    /**
     * Voraussetzungen: Key => Liste direkt benötigter Berechtigungen.
     *
     * @return array<string, list<string>>
     */
    public static function dependencies(): array
    {
        return [
            self::BERICHTE_DRUCKEN => [self::BERICHTE_SEHEN],

            self::AUSSTELLER_ERSTELLEN => [self::AUSSTELLER_SEHEN],
            self::AUSSTELLER_BEARBEITEN => [self::AUSSTELLER_SEHEN],
            self::AUSSTELLER_LOESCHEN => [self::AUSSTELLER_SEHEN],
            self::AUSSTELLER_DOKUMENTE_VERWALTEN => [self::AUSSTELLER_SEHEN],
            self::AUSSTELLER_KONTEN_VERWALTEN => [self::AUSSTELLER_SEHEN],

            self::BRANCHEN_BEARBEITEN => [self::BRANCHEN_SEHEN],

            self::ORGA_TEAM_SEHEN => [self::AUSSTELLER_SEHEN],
            self::ORGA_TEAM_BEARBEITEN => [self::ORGA_TEAM_SEHEN],

            self::RAEUME_ERSTELLEN => [self::RAEUME_SEHEN],
            self::RAEUME_BEARBEITEN => [self::RAEUME_SEHEN],
            self::RAEUME_LOESCHEN => [self::RAEUME_SEHEN],
            self::KAPAZITAETEN_SEHEN => [self::RAEUME_SEHEN],
            self::KAPAZITAETEN_BEARBEITEN => [self::KAPAZITAETEN_SEHEN],

            self::BENUTZER_ERSTELLEN => [self::BENUTZER_SEHEN],
            self::BENUTZER_BEARBEITEN => [self::BENUTZER_SEHEN],
            self::BENUTZER_LOESCHEN => [self::BENUTZER_SEHEN],
            self::BENUTZER_IMPORTIEREN => [self::BENUTZER_ERSTELLEN],
            self::BENUTZER_PASSWORT_ZURUECKSETZEN => [self::BENUTZER_SEHEN],

            self::BERECHTIGUNGEN_SEHEN => [self::BENUTZER_SEHEN],
            self::BERECHTIGUNGEN_VERGEBEN => [self::BERECHTIGUNGEN_SEHEN],
            self::BERECHTIGUNGSGRUPPEN_VERWALTEN => [self::BERECHTIGUNGEN_SEHEN],

            self::EINSTELLUNGEN_BEARBEITEN => [self::EINSTELLUNGEN_SEHEN],

            self::ANMELDUNGEN_ERSTELLEN => [self::ANMELDUNGEN_SEHEN],
            self::ANMELDUNGEN_LOESCHEN => [self::ANMELDUNGEN_SEHEN],
            self::ZUTEILUNG_AUSFUEHREN => [self::ANMELDUNGEN_SEHEN],
            self::ZUTEILUNG_ZURUECKSETZEN => [self::ANMELDUNGEN_SEHEN],

            self::QR_CODES_ERSTELLEN => [self::QR_CODES_SEHEN],
            self::ANWESENHEIT_BEARBEITEN => [self::ANWESENHEIT_SEHEN],

            self::AUFSICHTSPLAN_VERWALTEN => [self::AUFSICHTSPLAN_SEHEN],

            self::AUSSTATTUNG_VERWALTEN => [self::AUSSTATTUNG_SEHEN],

            self::FEEDBACK_ERSTELLEN => [self::FEEDBACK_SEHEN],
            self::FEEDBACK_BEARBEITEN => [self::FEEDBACK_SEHEN],
            self::FEEDBACK_LOESCHEN => [self::FEEDBACK_SEHEN],
            self::FEEDBACK_FREISCHALTEN => [self::FEEDBACK_BEARBEITEN],
            self::FEEDBACK_AUSWERTEN => [self::FEEDBACK_SEHEN],
        ];
    }

    /**
     * Alle Voraussetzungen einer Berechtigung, rekursiv aufgelöst
     * (ohne die Berechtigung selbst).
     *
     * @return list<string>
     */
    public static function requiredFor(string $permission): array
    {
        $deps = self::dependencies();
        $result = [];
        $stack = $deps[$permission] ?? [];

        while ($stack !== []) {
            $current = array_pop($stack);
            if (in_array($current, $result, true)) {
                continue;
            }
            $result[] = $current;
            foreach ($deps[$current] ?? [] as $next) {
                $stack[] = $next;
            }
        }

        return $result;
    }

    /**
     * Alle Berechtigungen, die (direkt oder indirekt) von der gegebenen
     * abhängen — diese müssen beim Entziehen mit entzogen werden.
     *
     * @return list<string>
     */
    public static function dependentsOf(string $permission): array
    {
        // Umgekehrten Graphen aufbauen
        $reverse = [];
        foreach (self::dependencies() as $key => $requires) {
            foreach ($requires as $req) {
                $reverse[$req][] = $key;
            }
        }

        $result = [];
        $stack = $reverse[$permission] ?? [];

        while ($stack !== []) {
            $current = array_pop($stack);
            if (in_array($current, $result, true)) {
                continue;
            }
            $result[] = $current;
            foreach ($reverse[$current] ?? [] as $next) {
                $stack[] = $next;
            }
        }

        return $result;
    }

    /**
     * Standard-Berechtigungen je Rolle (zusätzlich zur Rollenlogik in Auth;
     * admin/school_admin haben implizit alle Rechte).
     *
     * @return list<string>
     */
    public static function defaultsForRole(string $role): array
    {
        return match ($role) {
            'teacher' => [
                self::ANWESENHEIT_SEHEN,
                self::BERICHTE_SEHEN,
                self::BERICHTE_DRUCKEN,
                self::AUFSICHTSPLAN_SEHEN,
            ],
            default => [],
        };
    }
}
