<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\LoginThrottle;
use Tests\Support\DatabaseTestCase;

/**
 * Sichert das Verhalten ab, das eine Schule hinter einer gemeinsamen
 * NAT-Adresse betriebsfähig hält: Fehlversuche zählen getrennt je Konto und
 * je IP, und ein erfolgreicher Login räumt nur das eigene Konto frei.
 */
final class LoginThrottleTest extends DatabaseTestCase
{
    private const IP = '203.0.113.7';

    public function testKontoWirdNachZehnFehlversuchenGesperrt(): void
    {
        $throttle = new LoginThrottle($this->db);

        for ($i = 0; $i < 9; $i++) {
            $throttle->recordFailure('schueler1', self::IP);
        }
        self::assertFalse($throttle->isBlocked('schueler1', self::IP), 'Neun Versuche dürfen noch nicht sperren.');

        $throttle->recordFailure('schueler1', self::IP);
        self::assertTrue($throttle->isBlocked('schueler1', self::IP), 'Der zehnte Versuch muss sperren.');
    }

    /**
     * Der eigentliche Regressionstest: Früher zählte isBlocked() Konto UND IP
     * über ODER zusammen — zehn Tippfehler einer Klasse sperrten damit alle
     * anderen hinter derselben Schul-IP mit.
     */
    public function testFehlversucheFremderKontenSperrenNichtDieGanzeSchule(): void
    {
        $throttle = new LoginThrottle($this->db);

        // Zwölf verschiedene Schüler:innen vertippen sich, alle über dieselbe IP.
        for ($i = 1; $i <= 12; $i++) {
            $throttle->recordFailure('schueler' . $i, self::IP);
        }

        self::assertFalse(
            $throttle->isBlocked('lehrer1', self::IP),
            'Ein unbeteiligtes Konto darf hinter derselben IP nicht mitgesperrt werden.',
        );
    }

    public function testSehrVieleFehlversucheSperrenDieIpDennoch(): void
    {
        $throttle = new LoginThrottle($this->db);

        for ($i = 1; $i <= 60; $i++) {
            $throttle->recordFailure('konto' . $i, self::IP);
        }

        self::assertTrue(
            $throttle->isBlocked('nochjemand', self::IP),
            'Automatisiertes Durchprobieren muss die IP-Grenze auslösen.',
        );
    }

    /**
     * Zweiter Regressionstest: clear() räumte früher auch nach IP ab. Ein
     * beliebiges gültiges Konto konnte damit den Schutz aller anderen Konten
     * derselben IP aufheben.
     */
    public function testErfolgreicherLoginRaeumtNurDasEigeneKonto(): void
    {
        $throttle = new LoginThrottle($this->db);

        for ($i = 0; $i < 10; $i++) {
            $throttle->recordFailure('opfer', self::IP);
        }
        $throttle->recordFailure('angreifer', self::IP);

        $throttle->clear('angreifer');

        self::assertTrue(
            $throttle->isBlocked('opfer', self::IP),
            'Die Sperre des angegriffenen Kontos muss bestehen bleiben.',
        );
    }

    public function testRegistrierungHatEineEigeneIpGrenze(): void
    {
        $throttle = new LoginThrottle($this->db);

        for ($i = 0; $i < LoginThrottle::MAX_REGISTRATIONS_PER_IP - 1; $i++) {
            $throttle->recordFailure(LoginThrottle::REGISTRATION_MARKER, self::IP);
        }
        self::assertFalse($throttle->isIpBlocked(self::IP));

        $throttle->recordFailure(LoginThrottle::REGISTRATION_MARKER, self::IP);
        self::assertTrue($throttle->isIpBlocked(self::IP));
    }
}
