<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\ExhibitorInvitations;
use Tests\Support\DatabaseTestCase;

/**
 * Einladungen gehören zum Unternehmen, nicht zu einem Konto.
 *
 * Abgesichert wird: Ein Link gilt genau einmal, zurückgezogene und
 * abgelaufene Links sind tot, und das Annehmen lässt das Passwort eines
 * bestehenden Kontos unangetastet. Früher setzte jeder offene Link das
 * Passwort neu; wer einen zweiten Link besaß, konnte so ein bereits
 * eingerichtetes Konto übernehmen.
 */
final class EinladungenTest extends DatabaseTestCase
{
    private ExhibitorInvitations $invitations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->run("INSERT INTO schools (id, name, slug) VALUES (1, 'Schule A', 'a'), (2, 'Schule B', 'b')");
        $this->db->run(
            "INSERT INTO messe_editions (id, school_id, name, year, status)
             VALUES (1, 1, 'Messe A', 2026, 'active'), (2, 2, 'Messe B', 2026, 'active')",
        );
        $this->db->run(
            "INSERT INTO exhibitors (id, edition_id, name) VALUES (1, 1, 'Firma bei A'), (2, 2, 'Firma bei B')",
        );
        $this->db->run(
            "INSERT INTO users (id, school_id, username, password, role)
             VALUES (10, NULL, 'firma', ?, 'exhibitor')",
            [password_hash('altes-passwort', PASSWORD_DEFAULT)],
        );

        $this->invitations = new ExhibitorInvitations($this->db);
    }

    public function testAnnahmeVerknuepftKontoUndEntwertetLink(): void
    {
        $token = $this->invitations->create(1, 'an Frau Muster', null);
        $invite = $this->invitations->findValid($token);
        self::assertNotNull($invite);
        self::assertSame('Schule A', $invite['school_name']);

        self::assertTrue($this->invitations->accept((int) $invite['id'], 10, false));

        self::assertSame(1, (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM exhibitor_users WHERE user_id = 10 AND exhibitor_id = 1
               AND status = 'active' AND invite_accepted = 1",
        ));
        self::assertNull($this->invitations->findValid($token), 'Ein Link darf nur einmal gelten.');
        self::assertFalse($this->invitations->accept((int) $invite['id'], 10, false));
    }

    public function testAnnahmeAendertPasswortNicht(): void
    {
        $before = $this->db->fetchValue('SELECT password FROM users WHERE id = 10');

        $tokenA = $this->invitations->create(1, null, null);
        $tokenB = $this->invitations->create(2, null, null);
        $this->invitations->accept((int) $this->invitations->findValid($tokenA)['id'], 10, false);
        $this->invitations->accept((int) $this->invitations->findValid($tokenB)['id'], 10, false);

        self::assertSame($before, $this->db->fetchValue('SELECT password FROM users WHERE id = 10'));
        self::assertTrue(password_verify('altes-passwort', (string) $before));
    }

    public function testZurueckgezogenerUndAbgelaufenerLinkSindUngueltig(): void
    {
        $revoked = $this->invitations->create(1, null, null);
        $this->invitations->revoke((int) $this->invitations->findValid($revoked)['id']);
        self::assertNull($this->invitations->findValid($revoked));

        $expired = $this->invitations->create(1, null, null);
        $this->db->run('UPDATE exhibitor_invitations SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE token = ?', [$expired]);
        self::assertNull($this->invitations->findValid($expired));

        self::assertNull($this->invitations->findValid('kein-gueltiger-token'));
    }

    public function testAnnahmeReaktiviertBeendeteVerknuepfung(): void
    {
        $this->db->run(
            "INSERT INTO exhibitor_users (user_id, exhibitor_id, invite_accepted, status, cancel_reason)
             VALUES (10, 1, 1, 'removed_by_admin', 'aus Versehen entfernt')",
        );

        $token = $this->invitations->create(1, null, null);
        $this->invitations->accept((int) $this->invitations->findValid($token)['id'], 10, false);

        $link = $this->db->fetchOne('SELECT status, cancel_reason FROM exhibitor_users WHERE user_id = 10 AND exhibitor_id = 1');
        self::assertSame('active', $link['status']);
        self::assertNull($link['cancel_reason']);
    }

    public function testOffeneEinladungenNurDerEigenenEdition(): void
    {
        $this->invitations->create(1, 'A', null);
        $this->invitations->create(2, 'B', null);

        $open = $this->invitations->openForEdition(1);
        self::assertCount(1, $open);
        self::assertSame('A', $open[0]['note']);
    }
}
