<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Einladungen von Unternehmen ins Aussteller-Portal.
 *
 * Eine Einladung gehört zu einem Aussteller, nicht zu einem Konto. Die
 * Schule erzeugt nur den Link; welches Konto ihn annimmt, entscheidet der
 * Empfänger (anmelden oder neu anlegen). Deshalb sieht eine Schule kein
 * Konto, bevor es ihre Einladung angenommen hat, und kann keine fremden
 * Konten verknüpfen.
 *
 * Ein Link gilt genau einmal: Beim Annehmen wird der Token gelöscht. Das
 * Annehmen ändert nie das Passwort eines bestehenden Kontos.
 */
final class ExhibitorInvitations
{
    public const VALID_DAYS = 14;

    public function __construct(private readonly Database $db)
    {
    }

    /** Legt eine Einladung an und gibt den Token zurück. */
    public function create(int $exhibitorId, ?string $note, ?int $createdBy): string
    {
        $token = bin2hex(random_bytes(32));
        $this->db->run(
            'INSERT INTO exhibitor_invitations (exhibitor_id, token, expires_at, note, created_by)
             VALUES (?, ?, NOW() + INTERVAL ' . self::VALID_DAYS . ' DAY, ?, ?)',
            [$exhibitorId, $token, $note, $createdBy],
        );

        return $token;
    }

    /**
     * Offene, gültige Einladung zum Token, samt Unternehmen und Schule.
     *
     * @return array<string, mixed>|null
     */
    public function findValid(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT i.id, i.exhibitor_id, i.expires_at, e.name AS exhibitor_name,
                    s.id AS school_id, s.name AS school_name, s.slug AS school_slug
             FROM exhibitor_invitations i
             JOIN exhibitors e ON e.id = i.exhibitor_id
             JOIN messe_editions me ON me.id = e.edition_id
             JOIN schools s ON s.id = me.school_id
             WHERE i.token = ? AND i.accepted_at IS NULL AND i.revoked_at IS NULL
               AND i.expires_at > NOW()
             LIMIT 1',
            [$token],
        );
    }

    /**
     * Löst die Einladung für ein Aussteller-Konto ein: Token entwerten und
     * die Verknüpfung anlegen bzw. wieder aktivieren.
     *
     * @return bool false, wenn der Link inzwischen schon eingelöst wurde
     */
    public function accept(int $invitationId, int $userId, bool $createdAccount): bool
    {
        return $this->db->transaction(function () use ($invitationId, $userId, $createdAccount): bool {
            // Der Token-Check im WHERE verhindert, dass zwei gleichzeitige
            // Annahmen desselben Links beide durchgehen.
            $consumed = $this->db->run(
                'UPDATE exhibitor_invitations
                 SET token = NULL, accepted_at = NOW(), accepted_by = ?, created_account = ?
                 WHERE id = ? AND token IS NOT NULL AND accepted_at IS NULL AND revoked_at IS NULL',
                [$userId, $createdAccount ? 1 : 0, $invitationId],
            )->rowCount();
            if ($consumed !== 1) {
                return false;
            }

            $exhibitorId = (int) $this->db->fetchValue(
                'SELECT exhibitor_id FROM exhibitor_invitations WHERE id = ?',
                [$invitationId],
            );
            $existing = $this->db->fetchValue(
                'SELECT id FROM exhibitor_users WHERE user_id = ? AND exhibitor_id = ?',
                [$userId, $exhibitorId],
            );
            if ($existing === null) {
                $this->db->run(
                    'INSERT INTO exhibitor_users (user_id, exhibitor_id, invite_accepted, status)
                     VALUES (?, ?, 1, \'active\')',
                    [$userId, $exhibitorId],
                );
            } else {
                $this->db->run(
                    'UPDATE exhibitor_users
                     SET invite_accepted = 1, status = \'active\', cancelled_at = NULL, cancel_reason = NULL
                     WHERE id = ?',
                    [(int) $existing],
                );
            }

            return true;
        });
    }

    /** Zieht eine offene Einladung zurück; der Link wird sofort ungültig. */
    public function revoke(int $invitationId): void
    {
        $this->db->run(
            'UPDATE exhibitor_invitations SET token = NULL, revoked_at = NOW()
             WHERE id = ? AND accepted_at IS NULL AND revoked_at IS NULL',
            [$invitationId],
        );
    }

    /**
     * Offene Einladungen (auch abgelaufene) der Aussteller einer Edition.
     *
     * @return list<array<string, mixed>>
     */
    public function openForEdition(int $editionId): array
    {
        return $this->db->fetchAll(
            'SELECT i.*, e.name AS exhibitor_name, u.username AS created_by_name,
                    (i.expires_at <= NOW()) AS expired
             FROM exhibitor_invitations i
             JOIN exhibitors e ON e.id = i.exhibitor_id
             LEFT JOIN users u ON u.id = i.created_by
             WHERE e.edition_id = ? AND i.accepted_at IS NULL AND i.revoked_at IS NULL
             ORDER BY e.name, i.created_at DESC',
            [$editionId],
        );
    }
}
