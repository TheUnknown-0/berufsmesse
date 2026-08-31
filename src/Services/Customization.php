<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Schulspezifische Anpassung von Design und Anordnung.
 *
 * Gespeichert als school-gebundene Settings:
 *   custom_primary      — Hex-Primärfarbe (#rrggbb)
 *   custom_bg           — Hex-Hintergrundfarbe des hellen Themes
 *   custom_login_image  — Dateiname (uploads/branding) für den Login-Hintergrund
 *   nav_layout          — JSON: { bereich: {order: [keys], hidden: [keys]} }
 *   dashboard_layout    — JSON: { order: [keys], hidden: [keys] }
 * Das Schullogo liegt direkt in schools.logo.
 */
final class Customization
{
    public const NAV_SECTIONS = ['student', 'teacher', 'exhibitor', 'admin'];

    public function __construct(
        private readonly Settings $settings,
        private readonly ?int $schoolId,
    ) {
    }

    // ---------------------------------------------------------- Theme

    /** @return array{primary: ?string, bg: ?string, login_image: ?string} */
    public function theme(): array
    {
        return [
            'primary' => $this->hexOrNull($this->settings->get('custom_primary', $this->schoolId)),
            'bg' => $this->hexOrNull($this->settings->get('custom_bg', $this->schoolId)),
            'login_image' => $this->settings->get('custom_login_image', $this->schoolId),
        ];
    }

    /** CSS-Variablen-Overrides für beide Themes (leer, wenn nichts angepasst). */
    public function themeCss(): string
    {
        $theme = $this->theme();
        $css = '';

        if ($theme['primary'] !== null) {
            $p = $theme['primary'];
            $css .= ":root{--primary:{$p};--primary-strong:color-mix(in srgb,{$p} 80%,#000);"
                . "--primary-soft:color-mix(in srgb,{$p} 14%,#fff);"
                . "--accent:{$p};--accent-soft:color-mix(in srgb,{$p} 14%,#fff);"
                . "--accent-ink:color-mix(in srgb,{$p} 65%,#000);}";
            $css .= "[data-theme=\"dark\"]{--primary:color-mix(in srgb,{$p} 65%,#fff);"
                . "--primary-strong:color-mix(in srgb,{$p} 50%,#fff);"
                . "--primary-soft:color-mix(in srgb,{$p} 30%,#111827);"
                . "--accent:color-mix(in srgb,{$p} 65%,#fff);"
                . "--accent-soft:color-mix(in srgb,{$p} 30%,#111827);"
                . "--accent-ink:color-mix(in srgb,{$p} 45%,#fff);}";
        }
        if ($theme['bg'] !== null) {
            // Hintergrund nur im hellen Theme überschreiben — Dunkel bleibt neutral lesbar.
            $css .= ":root:not([data-theme=\"dark\"]){--bg:{$theme['bg']};"
                . "--bg-sunken:color-mix(in srgb,{$theme['bg']} 92%,#000);}";
        }

        return $css;
    }

    // ---------------------------------------------------------- Layouts

    /** @return array<string, array{order: list<string>, hidden: list<string>}> */
    public function navLayout(): array
    {
        return $this->jsonSetting('nav_layout');
    }

    /**
     * Wendet eine gespeicherte Anordnung auf eine keyed Item-Liste an:
     * sortiert nach order (unbekannte Keys hinten in Originalreihenfolge)
     * und filtert versteckte aus. $protected-Keys sind nie versteckbar.
     *
     * @template T
     * @param array<string, T> $items
     * @param array{order?: mixed, hidden?: mixed} $layout
     * @param list<string> $protected
     * @return array<string, T>
     */
    public static function applyLayout(array $items, array $layout, array $protected = []): array
    {
        $order = is_array($layout['order'] ?? null) ? array_values(array_filter($layout['order'], 'is_string')) : [];
        $hidden = is_array($layout['hidden'] ?? null) ? array_values(array_filter($layout['hidden'], 'is_string')) : [];

        $result = [];
        foreach ($order as $key) {
            if (array_key_exists($key, $items)) {
                $result[$key] = $items[$key];
            }
        }
        foreach ($items as $key => $item) {
            if (!array_key_exists($key, $result)) {
                $result[$key] = $item;
            }
        }
        foreach ($hidden as $key) {
            if (!in_array($key, $protected, true)) {
                unset($result[$key]);
            }
        }

        return $result;
    }

    // ---------------------------------------------------------- Intern

    private function hexOrNull(?string $value): ?string
    {
        return $value !== null && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? $value : null;
    }

    /** @return array<string, mixed> */
    private function jsonSetting(string $key): array
    {
        $raw = $this->settings->get($key, $this->schoolId);
        if ($raw === null) {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
