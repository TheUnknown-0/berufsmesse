<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Uploads;

/**
 * Auslieferung öffentlicher Medien.
 *
 * Logos und Branding-Bilder sind bewusst OHNE Login abrufbar: Sie erscheinen
 * bereits auf der Login-Seite (Schullogo, Hintergrund) und auf der
 * Schulauswahl. Die Dateinamen sind zufällig (128 Bit) und nicht erratbar;
 * es werden ausschließlich Bildformate ausgeliefert.
 * Dokumente laufen weiterhin über die permissions-geprüften API-Endpunkte.
 */
final class FileController extends Controller
{
    /** GET /medien/logos/{file} — Schul- und Aussteller-Logos. */
    public function logo(array $params): string
    {
        $uploads = new Uploads($this->ctx->config['uploads']['dir']);
        $uploads->stream('logos', $params['file']);
    }

    /** GET /medien/branding/{file} — Login-Hintergrundbilder. */
    public function branding(array $params): string
    {
        $uploads = new Uploads($this->ctx->config['uploads']['dir']);
        $uploads->stream('branding', $params['file']);
    }
}
