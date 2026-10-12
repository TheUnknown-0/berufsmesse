<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Announcements;
use Tests\Support\DatabaseTestCase;

/**
 * Ankündigungen erreichen jede Rolle auf ihrer Startseite.
 *
 * Regressionstest: Früher las nur die Schüler-Übersicht Ankündigungen aus.
 * Die Zielgruppen „Lehrkräfte“ und „Verwaltung“ waren wählbar, erschienen
 * aber nirgends.
 */
final class AnkuendigungenTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->db->run("INSERT INTO schools (id, name, slug) VALUES (1, 'Testschule', 'test'), (2, 'Andere', 'andere')");
        $this->db->run(
            "INSERT INTO announcements (school_id, title, body, type, target_role, expires_at, is_active)
             VALUES (1, 'Für alle', NULL, 'info', 'all', NULL, 1),
                    (1, 'Für Schüler', NULL, 'info', 'student', NULL, 1),
                    (1, 'Für Lehrkräfte', NULL, 'info', 'teacher', NULL, 1),
                    (1, 'Für Verwaltung', NULL, 'info', 'admin', NULL, 1),
                    (1, 'Inaktiv', NULL, 'info', 'all', NULL, 0),
                    (1, 'Abgelaufen', NULL, 'info', 'all', '2000-01-01 00:00:00', 1),
                    (2, 'Andere Schule', NULL, 'info', 'all', NULL, 1)",
        );
    }

    /** @return list<string> */
    private function titlesFor(?string $role): array
    {
        $titles = array_map(
            static fn (array $row): string => (string) $row['title'],
            (new Announcements($this->db))->activeFor(1, $role),
        );
        sort($titles);

        return $titles;
    }

    public function testSchuelerSehenAlleUndSchueler(): void
    {
        self::assertSame(['Für Schüler', 'Für alle'], $this->titlesFor('student'));
    }

    public function testLehrkraefteSehenAlleUndLehrkraefte(): void
    {
        self::assertSame(['Für Lehrkräfte', 'Für alle'], $this->titlesFor('teacher'));
    }

    /** „Verwaltung“ umfasst alle Rollen mit Verwaltungszugang. */
    public function testVerwaltungsrollenSehenAlleUndVerwaltung(): void
    {
        foreach (['admin', 'school_admin', 'orga'] as $role) {
            self::assertSame(['Für Verwaltung', 'Für alle'], $this->titlesFor($role), $role);
        }
    }

    /** Aussteller gehören nicht zur Schulgemeinschaft und sind keine Zielgruppe. */
    public function testAusstellerSehenKeineAnkuendigungen(): void
    {
        self::assertSame([], $this->titlesFor('exhibitor'));
        self::assertSame([], $this->titlesFor(null));
    }
}
