<?php

declare(strict_types=1);

/**
 * Globale Template-Helfer.
 */

/** HTML-Escaping für Ausgaben in Templates. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Formatiert ein Datum (Y-m-d oder DateTime) als deutsches Datum. */
function format_date(mixed $date, string $format = 'd.m.Y'): string
{
    if ($date === null || $date === '') {
        return '';
    }
    if (!$date instanceof \DateTimeInterface) {
        try {
            $date = new \DateTimeImmutable((string) $date);
        } catch (\Exception) {
            return (string) $date;
        }
    }

    return $date->format($format);
}

/** Formatiert Datum + Uhrzeit als deutsches Datum mit Uhrzeit. */
function format_datetime(mixed $date): string
{
    return format_date($date, 'd.m.Y H:i');
}

/**
 * Registriert die anordbaren Blöcke einer Seite und liefert sie in der
 * schulspezifischen Reihenfolge (key => Label). Siehe Core\PageBlocks.
 *
 * @param array<string, string> $blocks
 * @return array<string, string>
 */
function page_blocks(string $pageKey, array $blocks): array
{
    return \App\Core\PageBlocks::begin($pageKey, $blocks);
}

/** Öffnet den Wrapper eines Seiten-Blocks (immer mit block_close() schließen). */
function block_open(string $key, string $label): string
{
    return \App\Core\PageBlocks::open($key, $label);
}

function block_close(): string
{
    return \App\Core\PageBlocks::close();
}
