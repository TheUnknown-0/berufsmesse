<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\Audit;
use App\Services\Settings;

/**
 * Anwendungs-Kontext: hält alle Kern-Dienste sowie die aus der URL
 * aufgelöste aktuelle Schule und deren aktive Messe-Edition.
 */
final class Context
{
    /** @var array<string, mixed>|null Aktuelle Schule (aus dem URL-Slug). */
    public ?array $school = null;

    /** @var array<string, mixed>|null Aktive Edition der aktuellen Schule. */
    public ?array $edition = null;

    public function __construct(
        public readonly array $config,
        public readonly Database $db,
        public readonly Session $session,
        public readonly Csrf $csrf,
        public readonly Auth $auth,
        public readonly View $view,
        public readonly Settings $settings,
        public readonly Audit $audit,
    ) {
    }

    /**
     * Lädt die Schule zum Slug und deren aktive Edition in den Kontext.
     * Wirft 404 bei unbekanntem/inaktivem Slug.
     */
    public function loadSchool(string $slug): array
    {
        $school = $this->db->fetchOne(
            'SELECT * FROM schools WHERE slug = ? AND is_active = 1',
            [$slug],
        );
        if ($school === null) {
            throw new HttpException(404, 'Diese Schule existiert nicht oder ist nicht aktiv.');
        }

        $this->school = $school;
        $this->edition = $this->db->fetchOne(
            'SELECT * FROM messe_editions WHERE school_id = ? AND status = \'active\'
             ORDER BY year DESC, id DESC LIMIT 1',
            [(int) $school['id']],
        );

        return $school;
    }

    public function schoolId(): ?int
    {
        return $this->school !== null ? (int) $this->school['id'] : null;
    }

    public function editionId(): ?int
    {
        return $this->edition !== null ? (int) $this->edition['id'] : null;
    }

    /** Aktive Edition, die für Kernfunktionen zwingend vorhanden sein muss. */
    public function requireEdition(): array
    {
        if ($this->edition === null) {
            throw new HttpException(404, 'Für diese Schule ist keine aktive Messe eingerichtet.');
        }

        return $this->edition;
    }

    /** Basis-URL-bewusste absolute URL (Pfad muss mit / beginnen). */
    public function url(string $path = '/'): string
    {
        return ($this->config['app']['base_url'] ?? '') . $path;
    }

    /**
     * Konfigurierte öffentliche Basis-Adresse (Schema + Host, ohne Pfad),
     * oder null, wenn keine hinterlegt ist.
     *
     * EINZIGE Quelle für alle Links, die die App verlassen — Einladungen wie
     * QR-Codes. Reihenfolge: Schul-Setting public_base_url → globales Setting
     * public_base_url → Schul-Setting qr_code_url (historisch, wurde früher
     * nur für QR-Codes ausgewertet).
     */
    public function configuredPublicBase(): ?string
    {
        $candidates = [];
        if ($this->school !== null) {
            $candidates[] = $this->settings->get('public_base_url', (int) $this->school['id']);
        }
        $candidates[] = $this->settings->get('public_base_url');
        if ($this->school !== null) {
            $candidates[] = $this->settings->get('qr_code_url', (int) $this->school['id']);
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return rtrim(trim($candidate), '/');
            }
        }

        return null;
    }

    /**
     * Öffentliche Adresse der Anwendungswurzel — Schema, Host UND das
     * Verzeichnis, in dem die Anwendung liegt. An das Ergebnis wird nur noch
     * der Pfad innerhalb der Anwendung gehängt, nie erneut BASE_URL.
     *
     * Ein hinterlegter Wert gilt vollständig, wie eingetragen: Wer die
     * Anwendung unter `https://schule.de/messe` betreibt, trägt genau das ein.
     * Nur der Rückfall auf den Request-Host muss BASE_URL selbst ergänzen,
     * weil der Host-Header das Verzeichnis nicht kennt.
     *
     * Der Rückfall ist eine Notlösung für die Einrichtungsphase — er hängt
     * davon ab, welchen Host-Header der aufrufende Browser geschickt hat.
     * Im Produktivbetrieb gehört die Adresse hinterlegt; darauf weist die
     * Oberfläche über baseIsGuessed() hin.
     */
    public function publicBase(): string
    {
        $base = $this->configuredPublicBase();
        if ($base !== null) {
            return $base;
        }

        $scheme = (($_SERVER['HTTPS'] ?? 'off') !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return $scheme . '://' . $host . ($this->config['app']['base_url'] ?? '');
    }

    /**
     * Wird die öffentliche Adresse gerade nur aus dem Request geraten?
     *
     * Trifft das zu, hängen QR-Codes und Einladungslinks davon ab, über
     * welchen Host sie erzeugt wurden — auf gedruckten Codes ein Problem.
     */
    public function baseIsGuessed(): bool
    {
        return $this->configuredPublicBase() === null;
    }

    /**
     * Absolute, öffentlich erreichbare URL — für Links, die die App verlassen
     * (Einladungs-Links, QR-Codes). Der Pfad ist anwendungsintern und beginnt
     * mit „/“; das Anwendungsverzeichnis steckt bereits in publicBase().
     */
    public function publicUrl(string $path = '/'): string
    {
        return $this->publicBase() . $path;
    }

    /** URL innerhalb der aktuellen Schule, z. B. schoolUrl('/admin/benutzer'). */
    public function schoolUrl(string $path = '/'): string
    {
        if ($this->school === null) {
            return $this->url($path);
        }

        return $this->url('/' . $this->school['slug'] . ($path === '/' ? '/' : $path));
    }
}
