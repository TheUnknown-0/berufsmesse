<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Auth;
use App\Core\Context;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;
use App\Services\Audit;
use App\Services\QrService;
use App\Services\Settings;
use Tests\Support\DatabaseTestCase;

/**
 * Öffentliche Basis-Adresse für Links, die die Anwendung verlassen.
 *
 * Früher gab es dafür zwei getrennte Quellen — public_base_url für
 * Einladungen, qr_code_url für QR-Codes — und beide fielen unabhängig
 * voneinander auf den Host des gerade laufenden Requests zurück. Gedruckte
 * QR-Codes konnten damit auf eine andere Adresse zeigen als versendete
 * Einladungen.
 */
final class PublicBaseUrlTest extends DatabaseTestCase
{
    private Context $ctx;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->run("INSERT INTO schools (id, name, slug) VALUES (1, 'Testschule', 'test')");

        $settings = new Settings($this->db);
        $session = new Session();
        $auth = new Auth($session, $this->db);

        $this->ctx = new Context(
            ['app' => ['env' => 'production', 'base_url' => '', 'secure_cookies' => false]],
            $this->db,
            $session,
            new Csrf($session),
            $auth,
            new View(dirname(__DIR__, 2) . '/templates'),
            $settings,
            new Audit($this->db, $auth),
        );

        $_SERVER['HTTP_HOST'] = 'zufaelliger-host.invalid';
        unset($_SERVER['HTTPS']);
    }

    /** Baut einen Kontext mit abweichendem Anwendungsverzeichnis (BASE_URL). */
    private function contextWithBaseUrl(string $baseUrl): Context
    {
        $settings = new Settings($this->db);
        $session = new Session();
        $auth = new Auth($session, $this->db);

        return new Context(
            ['app' => ['env' => 'production', 'base_url' => $baseUrl, 'secure_cookies' => false]],
            $this->db,
            $session,
            new Csrf($session),
            $auth,
            new View(dirname(__DIR__, 2) . '/templates'),
            $settings,
            new Audit($this->db, $auth),
        );
    }

    private function setSetting(string $key, string $value, ?int $schoolId = null): void
    {
        $this->db->run(
            'INSERT INTO settings (school_id, setting_key, setting_value) VALUES (?, ?, ?)',
            [$schoolId, $key, $value],
        );
    }

    public function testOhneEinstellungWirdDerRequestHostGeraten(): void
    {
        self::assertNull($this->ctx->configuredPublicBase());
        self::assertSame('http://zufaelliger-host.invalid', $this->ctx->publicBase());
        self::assertTrue(
            $this->ctx->baseIsGuessed(),
            'Ohne hinterlegte Adresse muss die Oberflaeche warnen.',
        );
    }

    public function testGlobaleEinstellungSchlaegtDenRequestHost(): void
    {
        $this->setSetting('public_base_url', 'https://messe.example.org');

        self::assertSame('https://messe.example.org', $this->ctx->publicBase());
        self::assertFalse($this->ctx->baseIsGuessed());
    }

    public function testSchulEinstellungSchlaegtDieGlobale(): void
    {
        $this->setSetting('public_base_url', 'https://global.example.org');
        $this->setSetting('public_base_url', 'https://schule.example.org', 1);
        $this->ctx->loadSchool('test');

        self::assertSame('https://schule.example.org', $this->ctx->publicBase());
    }

    /** Bestehende Installationen haben ihre Adresse evtl. nur unter qr_code_url. */
    public function testAltesQrSettingWirdWeiterhinBeruecksichtigt(): void
    {
        $this->setSetting('qr_code_url', 'https://alt.example.org', 1);
        $this->ctx->loadSchool('test');

        self::assertSame('https://alt.example.org', $this->ctx->publicBase());
        self::assertFalse($this->ctx->baseIsGuessed());
    }

    /** Einladungslink und QR-Code müssen dieselbe Basis benutzen. */
    public function testEinladungUndQrTeilenSichDieBasis(): void
    {
        $this->setSetting('public_base_url', 'https://messe.example.org');
        $this->ctx->loadSchool('test');

        $einladung = $this->ctx->publicUrl('/aussteller-einladung?token=abc');

        self::assertSame('https://messe.example.org/aussteller-einladung?token=abc', $einladung);
        self::assertStringStartsWith($this->ctx->publicBase(), $einladung);
    }

    public function testAbschliessenderSchraegstrichWirdEntfernt(): void
    {
        $this->setSetting('public_base_url', 'https://messe.example.org/');

        self::assertSame('https://messe.example.org', $this->ctx->publicBase());
    }

    /**
     * Regressionstest: Läuft die Anwendung in einem Unterverzeichnis, muss das
     * Verzeichnis in jedem nach außen gegebenen Link stecken — und zwar genau
     * einmal. Vorher fiel es bei QR-Codes weg und stand bei Einladungen doppelt.
     */
    public function testUnterverzeichnisStecktGenauEinmalInDerAdresse(): void
    {
        $ctx = $this->contextWithBaseUrl('/messe');

        // Ohne Einstellung: Verzeichnis kommt aus der Konfiguration dazu.
        self::assertSame('http://zufaelliger-host.invalid/messe', $ctx->publicBase());
        self::assertSame(
            'http://zufaelliger-host.invalid/messe/aussteller-einladung',
            $ctx->publicUrl('/aussteller-einladung'),
        );

        // Mit Einstellung gilt der eingetragene Wert vollständig, ohne Dopplung.
        $this->setSetting('public_base_url', 'https://schule.example.org/messe');
        $ctx = $this->contextWithBaseUrl('/messe');

        self::assertSame('https://schule.example.org/messe', $ctx->publicBase());
        self::assertSame(
            'https://schule.example.org/messe/aussteller-einladung',
            $ctx->publicUrl('/aussteller-einladung'),
        );
    }

    /** Der Check-in-Link im QR-Code hängt an derselben Basis. */
    public function testCheckinAdresseNutztDieselbeBasis(): void
    {
        $this->setSetting('public_base_url', 'https://schule.example.org/messe');
        $ctx = $this->contextWithBaseUrl('/messe');

        $qr = new QrService($this->db, new Settings($this->db));

        self::assertSame(
            'https://schule.example.org/messe/test/checkin?token=ABC',
            $qr->checkinUrl('test', 'ABC', $ctx->publicBase()),
        );
    }
}
