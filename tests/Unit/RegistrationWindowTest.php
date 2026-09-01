<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\RegistrationController;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Einschreibezeitraum und Wunschanzahl — reine Rechenlogik ohne Datenbank.
 */
final class RegistrationWindowTest extends TestCase
{
    private function now(string $time = '2026-05-10 12:00:00'): DateTimeImmutable
    {
        return new DateTimeImmutable($time);
    }

    public function testInnerhalbDesZeitraumsIstOffen(): void
    {
        $edition = ['registration_start' => '2026-05-01 00:00:00', 'registration_end' => '2026-05-31 23:59:59'];

        self::assertTrue(RegistrationController::isRegistrationOpen($edition, $this->now()));
    }

    public function testVorDemStartIstGeschlossen(): void
    {
        $edition = ['registration_start' => '2026-06-01 00:00:00', 'registration_end' => '2026-06-30 23:59:59'];

        self::assertFalse(RegistrationController::isRegistrationOpen($edition, $this->now()));
    }

    public function testNachDemEndeIstGeschlossen(): void
    {
        $edition = ['registration_start' => '2026-04-01 00:00:00', 'registration_end' => '2026-04-30 23:59:59'];

        self::assertFalse(RegistrationController::isRegistrationOpen($edition, $this->now()));
    }

    /** Ohne gesetzte Grenzen ist die Einschreibung dauerhaft offen. */
    public function testOhneZeitraumIstOffen(): void
    {
        self::assertTrue(RegistrationController::isRegistrationOpen([], $this->now()));
        self::assertTrue(RegistrationController::isRegistrationOpen(
            ['registration_start' => null, 'registration_end' => ''],
            $this->now(),
        ));
    }

    /** Nur eine Grenze gesetzt: die andere Seite bleibt offen. */
    public function testEinseitigeGrenzen(): void
    {
        self::assertTrue(RegistrationController::isRegistrationOpen(
            ['registration_start' => '2026-05-01 00:00:00'],
            $this->now(),
        ));
        self::assertFalse(RegistrationController::isRegistrationOpen(
            ['registration_end' => '2026-05-09 23:59:59'],
            $this->now(),
        ));
    }

    public function testGenauAufDerStartgrenzeIstOffen(): void
    {
        $edition = ['registration_start' => '2026-05-10 12:00:00', 'registration_end' => '2026-05-31 23:59:59'];

        self::assertTrue(RegistrationController::isRegistrationOpen($edition, $this->now()));
    }

    public function testAnzahlWuenscheWirdBegrenzt(): void
    {
        self::assertSame(3, RegistrationController::maxRegistrations([]));
        self::assertSame(2, RegistrationController::maxRegistrations(['max_registrations_per_student' => 2]));
        self::assertSame(1, RegistrationController::maxRegistrations(['max_registrations_per_student' => 0]));
        self::assertGreaterThanOrEqual(
            1,
            RegistrationController::maxRegistrations(['max_registrations_per_student' => 99]),
        );
    }
}
