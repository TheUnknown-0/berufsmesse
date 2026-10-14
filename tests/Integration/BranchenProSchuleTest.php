<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;

/**
 * Branchen gehören einer Schule.
 *
 * Früher war die Liste global: Schule A konnte die Branchen von Schule B
 * umbenennen oder löschen. Abgesichert wird das Schema: Derselbe Name darf
 * in zwei Schulen existieren, innerhalb einer Schule nur einmal, und eine
 * Branche verschwindet mit ihrer Schule.
 */
final class BranchenProSchuleTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db->run("INSERT INTO schools (id, name, slug) VALUES (1, 'Schule A', 'a'), (2, 'Schule B', 'b')");
    }

    public function testGleicherNameInZweiSchulenErlaubt(): void
    {
        $this->db->run("INSERT INTO industries (school_id, name) VALUES (1, 'Handwerk'), (2, 'Handwerk')");

        self::assertSame(2, (int) $this->db->fetchValue("SELECT COUNT(*) FROM industries WHERE name = 'Handwerk'"));
    }

    public function testDoppelterNameInEinerSchuleVerboten(): void
    {
        $this->db->run("INSERT INTO industries (school_id, name) VALUES (1, 'Handwerk')");

        $this->expectException(\PDOException::class);
        $this->db->run("INSERT INTO industries (school_id, name) VALUES (1, 'Handwerk')");
    }

    public function testBrancheOhneSchuleNichtMoeglich(): void
    {
        $this->expectException(\PDOException::class);
        $this->db->run("INSERT INTO industries (school_id, name) VALUES (NULL, 'Global')");
    }
}
