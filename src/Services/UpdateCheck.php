<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Context;
use App\Core\Database;

/**
 * Fragt nach, ob es eine neuere Version der Berufsmesse gibt.
 *
 * Quelle ist CHANGELOG.md im öffentlichen Repo (Konfiguration updates.url,
 * Umgebungsvariable UPDATE_CHECK_URL, "off" schaltet ab). Die oberste Version
 * dort ist die neueste. Übertragen wird nichts außer der Anfrage selbst.
 *
 * Wann geprüft wird: bei jeder Anmeldung eines Global-Admins, einmal täglich,
 * sobald ein Global-Admin eine Seite öffnet, und auf Knopfdruck im
 * Versionsdialog. Es gibt keinen Hintergrunddienst: Die Seite stößt die
 * Prüfung per POST /api/version/pruefen an (public/assets/js/version.js),
 * damit keine Seite auf GitHub warten muss. Das Ergebnis liegt in den
 * globalen Einstellungen.
 *
 * Schonung von GitHub (Rate-Limits): Abgefragt wird raw.githubusercontent.com,
 * nicht die REST-API mit ihren 60 Anfragen pro Stunde. Dazu höchstens eine
 * Abfrage pro Minute (LOGIN_GAP, MANUAL_GAP), höchstens HOURLY_CAP Abfragen
 * pro Stunde für die ganze Installation, bedingte Abfragen per ETag (unveränderte Datei = 304
 * ohne Inhalt) und eine Pause nach 429/403 gemäß Retry-After.
 *
 * Eine neue Version geht genau einmal als In-App-Benachrichtigung an alle
 * Global-Admins. Einspielen lässt sie sich nur auf dem Server (README,
 * Abschnitt „Versionen und Updates“).
 */
final class UpdateCheck
{
    /** Sitzungsmarke: Global-Admin hat sich gerade angemeldet. */
    public const SESSION_DUE = '_update_check_due';

    public const DAILY = 86400;
    /** Höchstens eine Abfrage pro Minute: nach einer Anmeldung ... */
    public const LOGIN_GAP = 60;
    /** ... und über den Knopf „Nach neuen Versionen suchen“. */
    public const MANUAL_GAP = 60;
    /** Sicherheitsnetz: höchstens so viele Abfragen pro Stunde, gleich aus welchem Anlass. */
    public const HOURLY_CAP = 60;
    /** Pause nach 429/403 ohne Retry-After. */
    public const DEFAULT_BACKOFF = 3600;
    private const MAX_BACKOFF = 86400;
    private const MAX_BYTES = 262144;
    private const MAX_STORED = 20;

    private const S_CHECKED = 'update_checked_at';
    private const S_LATEST = 'update_latest_version';
    private const S_ENTRIES = 'update_remote_entries';
    private const S_ERROR = 'update_error';
    private const S_NOTIFIED = 'update_notified_version';
    private const S_ETAG = 'update_etag';
    private const S_BLOCKED_UNTIL = 'update_blocked_until';
    private const S_WINDOW = 'update_window';

    /** @var (\Closure(string, ?string): array{status: int, body: string, etag: ?string, retryAfter: ?int})|null */
    private ?\Closure $fetcher;

    /**
     * @param string $actionUrl Ziel der Benachrichtigung (öffnet den Versionsdialog)
     * @param (\Closure(string, ?string): array{status: int, body: string, etag: ?string, retryAfter: ?int})|null $fetcher
     *        Für Tests: Abruf mit (URL, ETag); wirft \RuntimeException bei Netzfehler
     */
    public function __construct(
        private readonly Database $db,
        private readonly Settings $settings,
        private readonly Notifications $notifications,
        private readonly string $url,
        private readonly string $actionUrl = '',
        ?\Closure $fetcher = null,
        private readonly ?string $installed = null,
    ) {
        $this->fetcher = $fetcher;
    }

    public static function fromContext(Context $ctx): self
    {
        return new self(
            $ctx->db,
            $ctx->settings,
            new Notifications($ctx->db),
            (string) ($ctx->config['updates']['url'] ?? ''),
            $ctx->url('/global-admin') . '#version',
        );
    }

    public function installedVersion(): string
    {
        return $this->installed ?? Changelog::currentVersion();
    }

    /** Ist eine Quelle eingerichtet? UPDATE_CHECK_URL=off schaltet die Prüfung ab. */
    public function configured(): bool
    {
        return $this->url !== '' && strtolower($this->url) !== 'off';
    }

    /** Steht eine automatische Prüfung an? Nach der Anmeldung schon nach LOGIN_GAP. */
    public function isDue(bool $afterLogin, ?int $now = null): bool
    {
        if (!$this->configured()) {
            return false;
        }
        $checked = $this->settings->getInt(self::S_CHECKED);
        $gap = $afterLogin ? self::LOGIN_GAP : self::DAILY;

        return $checked === 0 || ($now ?? time()) - $checked >= $gap;
    }

    /** Wurde gerade erst geprüft? Schützt die Quelle vor schnell wiederholtem Klicken. */
    public function checkedRecently(?int $now = null): bool
    {
        $checked = $this->settings->getInt(self::S_CHECKED);

        return $checked > 0 && ($now ?? time()) - $checked < self::MANUAL_GAP;
    }

    /**
     * Fragt die Quelle ab und speichert das Ergebnis. Wirft nicht; Fehler stehen
     * in state()['error'].
     *
     * @return bool ob tatsächlich bei der Quelle angefragt wurde
     */
    public function run(?int $now = null): bool
    {
        if (!$this->configured()) {
            return false;
        }
        $now ??= time();

        $blockedUntil = $this->settings->getInt(self::S_BLOCKED_UNTIL);
        if ($blockedUntil > $now) {
            $this->settings->set(self::S_ERROR, sprintf(
                'GitHub hat die Abfragen vorübergehend begrenzt. Nächster Versuch ab %s Uhr.',
                date('H:i', $blockedUntil),
            ));

            return false;
        }
        if (!$this->takeSlot($now)) {
            $this->settings->set(self::S_ERROR, sprintf(
                'Höchstens %d Prüfungen pro Stunde. Bitte später erneut versuchen.',
                self::HOURLY_CAP,
            ));

            return false;
        }

        $this->settings->set(self::S_CHECKED, (string) $now);
        // ETag nur mitschicken, wenn der zugehörige Stand auch gespeichert ist.
        $etag = $this->settings->get(self::S_LATEST) !== null ? $this->settings->get(self::S_ETAG) : null;
        try {
            $response = $this->fetcher !== null
                ? ($this->fetcher)($this->url, $etag)
                : $this->download($this->url, $etag);

            if ($response['status'] === 429 || $response['status'] === 403) {
                $wait = min(self::MAX_BACKOFF, max(60, $response['retryAfter'] ?? self::DEFAULT_BACKOFF));
                $this->settings->set(self::S_BLOCKED_UNTIL, (string) ($now + $wait));
                throw new \RuntimeException(sprintf(
                    'GitHub hat die Abfragen begrenzt (HTTP %d). Nächster Versuch ab %s Uhr.',
                    $response['status'],
                    date('H:i', $now + $wait),
                ));
            }
            if ($response['status'] === 304) {
                // Unverändert: gespeicherter Stand gilt weiter.
                $this->settings->set(self::S_ERROR, null);
                $this->notifyIfNewer();

                return true;
            }
            if ($response['status'] !== 200) {
                throw new \RuntimeException(sprintf(
                    '%s antwortete mit HTTP %d.',
                    (string) parse_url($this->url, PHP_URL_HOST),
                    $response['status'],
                ));
            }

            $entries = Changelog::parse($response['body']);
            if ($entries === []) {
                throw new \RuntimeException('Die Antwort enthielt keine Versionsnummer.');
            }
            $this->settings->set(self::S_LATEST, $entries[0]['version']);
            $this->settings->set(self::S_ENTRIES, json_encode(array_slice($entries, 0, self::MAX_STORED), JSON_UNESCAPED_UNICODE));
            $this->settings->set(self::S_ETAG, $response['etag'] !== null ? mb_substr($response['etag'], 0, 200) : null);
            $this->settings->set(self::S_ERROR, null);
            $this->notifyIfNewer();
        } catch (\RuntimeException $e) {
            $this->settings->set(self::S_ERROR, mb_substr($e->getMessage(), 0, 300));
        }

        return true;
    }

    /**
     * Stand für Anzeige und API.
     *
     * @return array{installed: string, latest: ?string, available: bool, checkedAt: ?int, error: ?string,
     *               configured: bool, news: list<array{version: string, date: ?string, items: list<string>}>}
     */
    public function state(): array
    {
        $installed = $this->installedVersion();
        $latest = $this->settings->get(self::S_LATEST);
        if ($latest !== null && !preg_match('/^\d+\.\d+\.\d+$/', $latest)) {
            $latest = null;
        }
        $available = $this->configured() && $latest !== null && version_compare($latest, $installed, '>');
        $checked = $this->settings->getInt(self::S_CHECKED);

        return [
            'installed' => $installed,
            'latest' => $latest,
            'available' => $available,
            'checkedAt' => $checked > 0 ? $checked : null,
            'error' => $this->settings->get(self::S_ERROR),
            'configured' => $this->configured(),
            'news' => $available ? Changelog::newerThan($this->storedEntries(), $installed) : [],
        ];
    }

    /** @return list<array{version: string, date: ?string, items: list<string>}> */
    private function storedEntries(): array
    {
        $decoded = json_decode((string) $this->settings->get(self::S_ENTRIES), true);
        $entries = [];
        foreach (is_array($decoded) ? $decoded : [] as $entry) {
            if (is_array($entry) && is_string($entry['version'] ?? null) && is_array($entry['items'] ?? null)) {
                $entries[] = [
                    'version' => $entry['version'],
                    'date' => is_string($entry['date'] ?? null) ? $entry['date'] : null,
                    'items' => array_values(array_filter($entry['items'], 'is_string')),
                ];
            }
        }

        return $entries;
    }

    /** Zählt die Abfrage gegen die Stundengrenze; false, wenn sie erreicht ist. */
    private function takeSlot(int $now): bool
    {
        $window = json_decode((string) $this->settings->get(self::S_WINDOW), true);
        $start = is_array($window) ? (int) ($window['start'] ?? 0) : 0;
        $count = is_array($window) ? (int) ($window['count'] ?? 0) : 0;
        if ($now - $start >= 3600 || $now < $start) {
            $start = $now;
            $count = 0;
        }
        if ($count >= self::HOURLY_CAP) {
            return false;
        }
        $this->settings->set(self::S_WINDOW, json_encode(['start' => $start, 'count' => $count + 1]));

        return true;
    }

    /** Meldet eine neue Version genau einmal an alle Global-Admins. */
    private function notifyIfNewer(): void
    {
        $latest = $this->state()['available'] ? (string) $this->settings->get(self::S_LATEST) : '';
        if ($latest === '') {
            return;
        }
        $notified = (string) $this->settings->get(self::S_NOTIFIED);
        if ($notified !== '' && version_compare($latest, $notified, '<=')) {
            return;
        }
        $admins = array_map('intval', array_column(
            $this->db->fetchAll("SELECT id FROM users WHERE role = 'admin'"),
            'id',
        ));
        $this->notifications->sendMany(
            $admins,
            null,
            sprintf(
                'Neue Version %s der Berufsmesse verfügbar (installiert: %s). '
                . 'Was sich ändert, steht im Versionsdialog unten in der Seitenleiste.',
                $latest,
                $this->installedVersion(),
            ),
            'info',
            null,
            $this->actionUrl !== '' ? $this->actionUrl : null,
        );
        $this->settings->set(self::S_NOTIFIED, $latest);
    }

    /**
     * HTTP-Abruf mit kurzem Timeout und Größenlimit.
     *
     * @return array{status: int, body: string, etag: ?string, retryAfter: ?int}
     */
    private function download(string $url, ?string $etag): array
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (!in_array($scheme, ['https', 'http'], true) || $host === '') {
            throw new \RuntimeException('Die Adresse für die Update-Prüfung (UPDATE_CHECK_URL) ist ungültig.');
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP-Erweiterung curl fehlt.');
        }

        $body = '';
        $tooLarge = false;
        $headers = [];
        $requestHeaders = ['Accept: text/plain, text/markdown, */*'];
        if ($etag !== null && $etag !== '') {
            $requestHeaders[] = 'If-None-Match: ' . $etag;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_USERAGENT => 'Berufsmesse/' . $this->installedVersion() . ' (Update-Pruefung)',
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                    $tooLarge = true;

                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($tooLarge) {
            throw new \RuntimeException('Die Antwort von ' . $host . ' war zu groß.');
        }
        if ($ok === false) {
            throw new \RuntimeException('Keine Verbindung zu ' . $host . ($error !== '' ? ' (' . $error . ')' : '') . '.');
        }
        $retryAfter = $headers['retry-after'] ?? null;

        return [
            'status' => $status,
            'body' => $body,
            'etag' => $headers['etag'] ?? null,
            'retryAfter' => $retryAfter !== null && ctype_digit($retryAfter) ? (int) $retryAfter : null,
        ];
    }
}
