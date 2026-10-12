<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\View;

/**
 * Orchestriert den E-Mail-Versand: Anmeldecode und Test-Mail (Global-Admin).
 *
 * Jeder Versand wird in email_log protokolliert — macht Fehlschläge
 * nachvollziehbar, ohne dass Klartext-Codes oder Zugangsdaten gespeichert
 * werden.
 */
final class EmailService
{
    public function __construct(
        private readonly Database $db,
        private readonly Mailer $mailer,
        private readonly View $view,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->mailer->isConfigured();
    }

    /** @param array<string, mixed> $user Erwartet id, school_id, email, firstname, lastname. */
    public function sendLoginCode(array $user, string $code): bool
    {
        $name = trim((string) $user['firstname'] . ' ' . (string) $user['lastname']);
        $subject = 'Dein Anmeldecode';
        $text = "Hallo,\n\ndein Anmeldecode lautet: {$code}\n\n"
            . "Er ist 10 Minuten gültig und nur einmal verwendbar. "
            . "Falls du diese Anmeldung nicht angefordert hast, kannst du diese Mail ignorieren.";
        $html = $this->view->renderPartial('emails/login-code', ['name' => $name, 'code' => $code]);

        $schoolId = isset($user['school_id']) && $user['school_id'] !== null ? (int) $user['school_id'] : null;

        try {
            $this->mailer->send((string) $user['email'], $name, $subject, $text, $html);
            $this->log($schoolId, (int) $user['id'], (string) $user['email'], $subject, 'login_code', 'sent', null);

            return true;
        } catch (\RuntimeException $e) {
            $this->log($schoolId, (int) $user['id'], (string) $user['email'], $subject, 'login_code', 'failed', mb_substr($e->getMessage(), 0, 500));

            return false;
        }
    }

    /** @return array{success: bool, error: ?string} */
    public function sendTest(string $toEmail): array
    {
        $subject = 'Test-Mail — Berufsmesse';
        $text = "Das ist eine Test-Mail vom Berufsmesse-System.\n\n"
            . 'Wenn du diese Mail liest, ist der E-Mail-Versand korrekt eingerichtet.';
        $html = '<p>Das ist eine Test-Mail vom Berufsmesse-System.</p>'
            . '<p>Wenn du diese Mail liest, ist der E-Mail-Versand korrekt eingerichtet.</p>';

        try {
            $this->mailer->send($toEmail, '', $subject, $text, $html);
            $this->log(null, null, $toEmail, $subject, 'test', 'sent', null);

            return ['success' => true, 'error' => null];
        } catch (\RuntimeException $e) {
            $this->log(null, null, $toEmail, $subject, 'test', 'failed', mb_substr($e->getMessage(), 0, 500));

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return list<array<string, mixed>> */
    public function recentLog(int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));

        return $this->db->fetchAll(
            'SELECT el.*, u.firstname, u.lastname FROM email_log el
             LEFT JOIN users u ON u.id = el.user_id
             ORDER BY el.created_at DESC LIMIT ' . $limit,
        );
    }

    // ---------- Helfer ----------

    private function log(?int $schoolId, ?int $userId, string $recipient, string $subject, string $template, string $status, ?string $error): void
    {
        $this->db->run(
            'INSERT INTO email_log (school_id, user_id, recipient, subject, template, status, error) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$schoolId, $userId, $recipient, mb_substr($subject, 0, 255), $template, $status, $error],
        );
    }
}
