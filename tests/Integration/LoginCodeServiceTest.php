<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\LoginCodeService;
use Tests\Support\DatabaseTestCase;

/**
 * Anmeldung per E-Mail-Code: Erzeugen, Prüfen, Rateversuche, Ablauf und
 * Anfrage-Cooldown.
 */
final class LoginCodeServiceTest extends DatabaseTestCase
{
    private const SCHOOL_ID = 1;
    private const USER_ID = 10;
    private const IP = '203.0.113.7';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->run("INSERT INTO schools (id, name, slug) VALUES (1, 'Testschule', 'test')");
        $this->db->run(
            "INSERT INTO users (id, school_id, username, email, firstname, lastname, role)
             VALUES (?, ?, 'schueler1', 'schueler1@example.test', 'Sina', 'Schuel', 'student')",
            [self::USER_ID, self::SCHOOL_ID],
        );
    }

    public function testGueltigerCodeWirdAkzeptiert(): void
    {
        $service = new LoginCodeService($this->db);
        $code = $service->issue(self::USER_ID, self::IP);

        self::assertTrue($service->verify(self::USER_ID, $code));
    }

    public function testCodeIstNurEinmalVerwendbar(): void
    {
        $service = new LoginCodeService($this->db);
        $code = $service->issue(self::USER_ID, self::IP);

        self::assertTrue($service->verify(self::USER_ID, $code));
        self::assertFalse($service->verify(self::USER_ID, $code), 'Ein verbrauchter Code darf kein zweites Mal gelten.');
    }

    public function testFalscherCodeErhoehtRateversucheUndSperrtNachFuenfVersuchen(): void
    {
        $service = new LoginCodeService($this->db);
        $code = $service->issue(self::USER_ID, self::IP);

        for ($i = 0; $i < 4; $i++) {
            self::assertFalse($service->verify(self::USER_ID, 'falsch1'));
        }
        // Der fünfte Fehlversuch erschöpft MAX_ATTEMPTS — selbst der korrekte
        // Code gilt danach nicht mehr, ganz ohne dass er erraten wurde.
        self::assertFalse($service->verify(self::USER_ID, 'falsch2'));
        self::assertFalse($service->verify(self::USER_ID, $code), 'Nach fünf Fehlversuchen muss der Code verbraucht sein.');
    }

    public function testAbgelaufenerCodeWirdNichtMehrAkzeptiert(): void
    {
        $service = new LoginCodeService($this->db);
        $code = $service->issue(self::USER_ID, self::IP);

        $this->db->run(
            'UPDATE login_codes SET expires_at = (NOW() - INTERVAL 1 MINUTE) WHERE user_id = ?',
            [self::USER_ID],
        );

        self::assertFalse($service->verify(self::USER_ID, $code));
    }

    public function testCooldownVerhindertSofortigeZweitanfrage(): void
    {
        $service = new LoginCodeService($this->db);
        $service->issue(self::USER_ID, self::IP);

        self::assertTrue($service->withinCooldown(self::USER_ID));

        $this->db->run(
            'UPDATE login_codes SET created_at = (NOW() - INTERVAL 61 SECOND) WHERE user_id = ?',
            [self::USER_ID],
        );

        self::assertFalse($service->withinCooldown(self::USER_ID));
    }

    public function testNeuerCodeMachtVorherigenUngueltig(): void
    {
        $service = new LoginCodeService($this->db);
        $first = $service->issue(self::USER_ID, self::IP);
        $second = $service->issue(self::USER_ID, self::IP);

        self::assertFalse($service->verify(self::USER_ID, $first), 'Der alte Code darf nach einem Neuversand nicht mehr gelten.');
        self::assertTrue($service->verify(self::USER_ID, $second));
    }
}
