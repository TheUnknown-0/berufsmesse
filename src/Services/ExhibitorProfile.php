<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Gemeinsame Regeln für Aussteller-Profile.
 *
 * Admin-Formular und Aussteller-Portal schreiben dieselben Spalten. Laufen
 * ihre Regeln auseinander, gehen Daten still verloren: Kennt das Portal einen
 * Angebotstyp nicht, verschwindet er beim nächsten Speichern; ist ein Limit
 * kleiner, wird Text abgeschnitten. Deshalb stehen Auswahl, Längen und
 * Dateitypen ausschließlich hier.
 */
final class ExhibitorProfile
{
    /** Auswählbare Angebotstypen; zusätzlich ist ein Freitext möglich. */
    public const OFFER_TYPES = [
        'Ausbildung',
        'Duales Studium',
        'Studium',
        'Praktikum',
        'Werkstudent',
        'Hospitation',
        'Sonstiges',
    ];

    /** Höchstlängen der Textfelder (Zeichen). */
    public const MAX_LENGTHS = [
        'name' => 200,
        'short_description' => 500,
        'description' => 20000,
        'contact_person' => 200,
        'email' => 255,
        'phone' => 50,
        'website' => 255,
        'jobs' => 20000,
        'features' => 20000,
        'offer_types_custom' => 500,
        'equipment' => 500,
    ];

    /** Für Logos zugelassene Dateiendungen (SVG bewusst nicht: Script-Risiko). */
    public const LOGO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** Zugelassene Dateiendungen für Aussteller-Dokumente. */
    public const DOCUMENT_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'gif', 'webp',
    ];

    /** Getrimmter Text, auf die Feldlänge gekürzt; leer wird null. */
    public static function text(mixed $value, string $field): ?string
    {
        $text = trim(is_string($value) ? $value : '');
        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, self::MAX_LENGTHS[$field]);
    }

    /** Website mit Schema; ohne Angabe wird https:// vorangestellt. */
    public static function website(mixed $value): ?string
    {
        $website = trim(is_string($value) ? $value : '');
        if ($website === '') {
            return null;
        }
        if (!preg_match('#^https?://#i', $website)) {
            $website = 'https://' . $website;
        }

        return mb_substr($website, 0, self::MAX_LENGTHS['website']);
    }

    /**
     * offer_types als JSON: nur bekannte Auswahl plus Freitext; nichts davon ergibt null.
     *
     * @param mixed $selected Rohwert aus dem Formular (Array erwartet)
     */
    public static function encodeOffers(mixed $selected, mixed $custom): ?string
    {
        $chosen = [];
        foreach ((array) $selected as $value) {
            if (is_string($value) && in_array($value, self::OFFER_TYPES, true)) {
                $chosen[] = $value;
            }
        }
        $chosen = array_values(array_unique($chosen));
        $customText = self::text($custom, 'offer_types_custom') ?? '';

        if ($chosen === [] && $customText === '') {
            return null;
        }

        return json_encode(['selected' => $chosen, 'custom' => $customText], JSON_UNESCAPED_UNICODE);
    }
}
