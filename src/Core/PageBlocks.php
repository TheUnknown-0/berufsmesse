<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\Customization;

/**
 * Seiten-Blöcke: macht die Abschnitte jeder Seite pro Schule anordbar
 * und ausblendbar (WordPress-artiger „Anordnen"-Modus).
 *
 * Anordnungen können ZUSÄTZLICH pro Rollengruppe gespeichert werden:
 *   Basis:            Setting "page_layout:{pageKey}"          (gilt für alle)
 *   Rollen-Override:  Setting "page_layout:{pageKey}:{gruppe}" (geht vor)
 * Gruppen: student, teacher, exhibitor, admin (= admin/school_admin/orga).
 *
 * Anordnen-Modus: ?anordnen=1 (nur admin/school_admin), Ziel-Rolle über
 * ?rolle=student|teacher|exhibitor|admin (ohne = Basis für alle Rollen).
 *
 * Template-Pattern siehe ARCHITECTURE.md („Seiten-Blöcke").
 */
final class PageBlocks
{
    public const ROLE_GROUPS = ['student', 'teacher', 'exhibitor', 'admin'];

    private static ?Context $ctx = null;
    private static ?string $pageKey = null;
    private static bool $arranging = false;

    /** Ziel-Rollengruppe im Anordnen-Modus ('' = Basis für alle Rollen). */
    private static string $targetRole = '';

    /** @var list<string> Keys, die aktuell ausgeblendet sind (für den Editor). */
    private static array $hidden = [];

    public static function init(Context $ctx): void
    {
        self::$ctx = $ctx;
        self::$pageKey = null;
        self::$arranging = false;
        self::$targetRole = '';
        self::$hidden = [];
    }

    /** Ordnet eine konkrete Rolle ihrer Layout-Gruppe zu. */
    public static function groupForRole(?string $role): string
    {
        return match ($role) {
            'teacher' => 'teacher',
            'exhibitor' => 'exhibitor',
            'admin', 'school_admin', 'orga' => 'admin',
            default => 'student',
        };
    }

    /**
     * Registriert die Blöcke einer Seite und liefert sie in der wirksamen
     * Reihenfolge zurück (key => label). Im Anordnen-Modus werden auch
     * ausgeblendete Blöcke geliefert.
     *
     * @param array<string, string> $blocks key => Label (Standard-Reihenfolge)
     * @return array<string, string>
     */
    public static function begin(string $pageKey, array $blocks): array
    {
        $ctx = self::$ctx;
        self::$pageKey = $pageKey;

        $isAdmin = $ctx !== null
            && in_array($ctx->auth->role(), ['admin', 'school_admin'], true);

        self::$arranging = $ctx !== null
            && $ctx->school !== null
            && $pageKey !== 'darstellung'
            && ($_GET['anordnen'] ?? '') === '1'
            && $isAdmin;

        $requestedRole = (string) ($_GET['rolle'] ?? '');
        self::$targetRole = self::$arranging && in_array($requestedRole, self::ROLE_GROUPS, true)
            ? $requestedRole
            : '';

        $layout = [];
        if ($ctx !== null && $ctx->school !== null) {
            if (self::$arranging) {
                // Bearbeitet wird die Ziel-Rolle; als Ausgangspunkt dient die
                // Basis, falls für die Rolle noch nichts gespeichert ist.
                $layout = self::loadLayout($pageKey, self::$targetRole)
                    ?? self::loadLayout($pageKey, '')
                    ?? [];
            } else {
                // Wirksames Layout des Betrachters: Rollen-Override vor Basis.
                $group = self::groupForRole($ctx->auth->role());
                $layout = self::loadLayout($pageKey, $group)
                    ?? self::loadLayout($pageKey, '')
                    ?? [];
            }
        }

        self::$hidden = array_values(array_filter(
            is_array($layout['hidden'] ?? null) ? $layout['hidden'] : [],
            'is_string',
        ));

        if (self::$arranging) {
            // Alle Blöcke zeigen (auch ausgeblendete), nur Reihenfolge anwenden
            return Customization::applyLayout($blocks, ['order' => $layout['order'] ?? []]);
        }

        return Customization::applyLayout($blocks, $layout);
    }

    /** Öffnet den Wrapper eines Blocks. */
    public static function open(string $key, string $label): string
    {
        $hidden = in_array($key, self::$hidden, true);

        if (!self::$arranging) {
            return '<section class="page-block" data-block="' . htmlspecialchars($key, ENT_QUOTES) . '">';
        }

        return '<section class="page-block is-arranging' . ($hidden ? ' block-hidden' : '') . '"'
            . ' data-block="' . htmlspecialchars($key, ENT_QUOTES) . '" draggable="true">'
            . '<div class="block-toolbar">'
            . '<span class="drag-handle" aria-hidden="true">⠿</span>'
            . '<span class="block-label">' . htmlspecialchars($label, ENT_QUOTES) . '</span>'
            . '<button type="button" class="btn btn-sm" data-block-toggle aria-pressed="' . ($hidden ? 'true' : 'false') . '">'
            . ($hidden ? '🚫 ausgeblendet' : '👁 sichtbar') . '</button>'
            . '</div><div class="block-content">';
    }

    public static function close(): string
    {
        return self::$arranging ? '</div></section>' : '</section>';
    }

    public static function isArranging(): bool
    {
        return self::$arranging;
    }

    /** Ziel-Rollengruppe des Anordnen-Modus ('' = Basis). */
    public static function targetRole(): string
    {
        return self::$targetRole;
    }

    /** Seitenkey der aktuellen Seite — null, wenn die Seite keine Blöcke deklariert. */
    public static function pageKey(): ?string
    {
        return self::$pageKey;
    }

    /** Darf der aktuelle Nutzer diese Seite anordnen? (für den Topbar-Button) */
    public static function canArrange(): bool
    {
        $ctx = self::$ctx;

        return $ctx !== null
            && $ctx->school !== null
            && self::$pageKey !== null
            && self::$pageKey !== 'darstellung'
            && in_array($ctx->auth->role(), ['admin', 'school_admin'], true);
    }

    /**
     * Erlaubt Vorschau-Zugriff: Controller rollenfremder Seiten (Schüler-,
     * Portal-Seiten) lassen admin/school_admin durch, damit diese die Seite
     * anordnen können. Die Datenabfragen laufen normal (ggf. leer).
     */
    public static function adminPreviewAllowed(): bool
    {
        $ctx = self::$ctx;

        return $ctx !== null
            && in_array($ctx->auth->role(), ['admin', 'school_admin'], true);
    }

    /** @return array{order?: mixed, hidden?: mixed}|null */
    private static function loadLayout(string $pageKey, string $roleGroup): ?array
    {
        $ctx = self::$ctx;
        if ($ctx === null || $ctx->school === null) {
            return null;
        }

        $key = 'page_layout:' . $pageKey . ($roleGroup !== '' ? ':' . $roleGroup : '');
        $raw = $ctx->settings->get($key, $ctx->schoolId());
        if ($raw === null) {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
