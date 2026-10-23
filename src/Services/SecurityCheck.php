<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Prüft die laufende Server-Umgebung auf bekannte Sicherheitslücken und meldet
 * neue hohe oder kritische Funde den Global-Admins (In-App-Benachrichtigung).
 *
 * Quellen:
 *  - PHP: Security Advisories von php-src auf GitHub (Schweregrad und
 *    gepatchte Version je Branch), unterstützte Branches von php.net
 *  - Systempakete des Containers (Apache, OpenSSL, libxml2, Bildbibliotheken ...):
 *    OSV.dev mit den Versionen aus der dpkg-Datenbank
 *  - MariaDB: Release-Liste von mariadb.org, nur als Hinweis auf eine neuere
 *    Patch-Version (CVE-Daten gibt es dort nicht maschinenlesbar)
 *
 * Läuft über bin/security-check.php im Hintergrund (docker-entrypoint.sh).
 * Ohne Internetzugang scheitert die Abfrage. Das Ergebnis hält das fest, damit
 * der Global-Admin nicht „alles in Ordnung“ anzeigt, obwohl nichts geprüft wurde.
 */
final class SecurityCheck
{
    /** settings-Schlüssel (global) für das letzte Prüfergebnis. */
    public const STATE_KEY = 'security_check_state';

    /** settings-Schlüssel (global) für bereits gemeldete Kennungen. */
    private const NOTIFIED_KEY = 'security_check_notified';

    /** Ab diesem CVSS-Basiswert gilt ein Fund als „hoch“. */
    private const HIGH_SCORE = 7.0;

    /** Mehr Funde passen nicht sinnvoll in settings.setting_value (TEXT). */
    private const MAX_FINDINGS = 40;

    /** Gemeldete Kennungen, die wir uns höchstens merken. */
    private const MAX_NOTIFIED = 300;

    /** OSV-Detailabrufe pro Lauf; der Rest folgt im nächsten Lauf. */
    private const MAX_OSV_FETCHES = 2000;

    /** Gleichzeitige Verbindungen beim parallelen Abruf. */
    private const PARALLEL = 16;

    private const GITHUB_ADVISORIES = 'https://api.github.com/repos/php/php-src/security-advisories?per_page=50&sort=published&direction=desc';
    private const PHP_RELEASES = 'https://www.php.net/releases/index.php?json&version=';
    private const OSV_BATCH = 'https://api.osv.dev/v1/querybatch';
    private const OSV_VULN = 'https://api.osv.dev/v1/vulns/';
    private const MARIADB_RELEASES = 'https://downloads.mariadb.org/rest-api/mariadb/';

    /** @var \Closure(string, ?string): string */
    private readonly \Closure $http;

    private readonly bool $customHttp;

    /**
     * @param (callable(string, ?string): string)|null $http Abruf (URL, POST-Body oder null); für Tests austauschbar
     */
    public function __construct(
        private readonly Database $db,
        private readonly Settings $settings,
        private readonly Notifications $notifications,
        private readonly string $actionUrl = '/global-admin',
        ?callable $http = null,
        private readonly string $dpkgStatusFile = '/var/lib/dpkg/status',
        private readonly string $osReleaseFile = '/etc/os-release',
        private readonly ?string $cacheFile = null,
    ) {
        $this->http = $http !== null ? \Closure::fromCallable($http) : self::fetch(...);
        $this->customHttp = $http !== null;
    }

    /**
     * Führt alle Prüfungen aus, speichert das Ergebnis und benachrichtigt bei
     * neuen Funden.
     *
     * @return array{checked_at: string, php_version: string, findings: list<array<string, mixed>>, notes: list<string>, errors: list<string>, notified: int}
     */
    public function run(): array
    {
        $state = [
            'checked_at' => date('c'),
            'php_version' => PHP_VERSION,
            'findings' => [],
            'notes' => [],
            'errors' => [],
            'notified' => 0,
        ];

        $checks = [
            'PHP' => fn (): array => $this->checkPhp(),
            'Systempakete' => fn (): array => $this->checkSystemPackages(),
            'MariaDB' => fn (): array => $this->checkMariaDb(),
        ];
        foreach ($checks as $source => $check) {
            try {
                [$findings, $notes] = $check();
                array_push($state['findings'], ...$findings);
                array_push($state['notes'], ...$notes);
            } catch (\Throwable $e) {
                $state['errors'][] = $source . ': ' . mb_substr($e->getMessage(), 0, 200);
            }
        }

        usort($state['findings'], static fn (array $a, array $b): int => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        if (count($state['findings']) > self::MAX_FINDINGS) {
            $state['notes'][] = sprintf('%d weitere Funde nicht einzeln aufgeführt.', count($state['findings']) - self::MAX_FINDINGS);
            $state['findings'] = array_slice($state['findings'], 0, self::MAX_FINDINGS);
        }

        $state['notified'] = $this->notifyNew($state['findings']);
        $this->settings->set(self::STATE_KEY, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $state;
    }

    /** Letztes gespeichertes Ergebnis oder null, wenn noch nie geprüft wurde. */
    public static function lastState(Settings $settings): ?array
    {
        $raw = $settings->get(self::STATE_KEY);
        $state = $raw !== null ? json_decode($raw, true) : null;

        return is_array($state) ? $state : null;
    }

    // ---------------------------------------------------------------- PHP

    /** @return array{0: list<array<string, mixed>>, 1: list<string>} */
    private function checkPhp(): array
    {
        $branch = self::branch(PHP_VERSION);
        $findings = self::phpFindings(PHP_VERSION, $this->getJson(self::GITHUB_ADVISORIES));

        $releases = $this->getJson(self::PHP_RELEASES . $branch);
        $supported = $releases['supported_versions'] ?? null;
        if (is_array($supported) && !in_array($branch, $supported, true)) {
            $findings[] = [
                'component' => 'PHP ' . PHP_VERSION,
                'id' => 'PHP-EOL-' . $branch,
                'severity' => 'critical',
                'score' => null,
                'summary' => 'PHP ' . $branch . ' erhält keine Sicherheitsupdates mehr. Im Dockerfile auf einen unterstützten Branch wechseln ('
                    . implode(', ', $supported) . ').',
                'fixed' => null,
                'url' => 'https://www.php.net/supported-versions.php',
            ];
        }

        return [$findings, []];
    }

    /**
     * Hohe und kritische php-src-Advisories, die die gegebene Version betreffen.
     *
     * @param array<mixed> $advisories Antwort der GitHub-API
     * @return list<array<string, mixed>>
     */
    public static function phpFindings(string $version, array $advisories): array
    {
        $branch = self::branch($version);
        $findings = [];

        foreach ($advisories as $advisory) {
            if (!is_array($advisory) || !in_array($advisory['severity'] ?? '', ['high', 'critical'], true)) {
                continue;
            }
            foreach ($advisory['vulnerabilities'] ?? [] as $vuln) {
                $patched = trim((string) ($vuln['patched_versions'] ?? ''));
                if ($patched === '' || self::branch($patched) !== $branch || version_compare($version, $patched, '>=')) {
                    continue;
                }
                $findings[] = [
                    'component' => 'PHP ' . $version,
                    'id' => (string) ($advisory['cve_id'] ?? $advisory['ghsa_id'] ?? '?'),
                    'severity' => $advisory['severity'],
                    'score' => $advisory['cvss_severities']['cvss_v4']['score']
                        ?? $advisory['cvss_severities']['cvss_v3']['score']
                        ?? $advisory['cvss']['score']
                        ?? null,
                    'summary' => mb_substr((string) ($advisory['summary'] ?? ''), 0, 200),
                    'fixed' => $patched,
                    'url' => (string) ($advisory['html_url'] ?? ''),
                ];
                break;
            }
        }

        return $findings;
    }

    // ------------------------------------------------------- Systempakete

    /** @return array{0: list<array<string, mixed>>, 1: list<string>} */
    private function checkSystemPackages(): array
    {
        if (!is_readable($this->dpkgStatusFile) || !is_readable($this->osReleaseFile)) {
            return [[], ['Systempakete: keine dpkg-Datenbank gefunden, Prüfung übersprungen.']];
        }
        $os = self::parseOsRelease((string) file_get_contents($this->osReleaseFile));
        if (($os['ID'] ?? '') !== 'debian' || ($os['VERSION_ID'] ?? '') === '') {
            return [[], ['Systempakete: kein Debian-System, Prüfung übersprungen.']];
        }
        $ecosystem = 'Debian:' . $os['VERSION_ID'];
        $packages = self::parseDpkgStatus((string) file_get_contents($this->dpkgStatusFile));

        // Alle Pakete in einer Abfrage: OSV liefert je Paket die Kennungen der
        // Lücken, die genau diese Version betreffen.
        $queries = [];
        foreach ($packages as $name => $version) {
            $queries[] = ['package' => ['ecosystem' => $ecosystem, 'name' => $name], 'version' => $version];
        }
        $batch = $this->getJson(self::OSV_BATCH, json_encode(['queries' => $queries]));

        // Details nur für neue oder geänderte Einträge abrufen, der Rest kommt
        // aus dem Cache. Beim ersten Lauf sind das rund tausend Einträge, die
        // meisten ohne Debian-Fix und als unbedeutend eingestuft.
        $cache = $this->loadCache();
        $names = array_keys($packages);
        $refs = [];
        $toFetch = [];
        foreach ($batch['results'] ?? [] as $i => $result) {
            foreach ($result['vulns'] ?? [] as $ref) {
                $id = (string) ($ref['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $package = $names[$i] ?? '?';
                $refs[] = [$id, $package];
                $modified = (string) ($ref['modified'] ?? '');
                if (($cache[$id]['modified'] ?? null) !== $modified) {
                    $toFetch[$id] = [$package, $modified];
                }
            }
        }
        $pending = max(0, count($toFetch) - self::MAX_OSV_FETCHES);
        $toFetch = array_slice($toFetch, 0, self::MAX_OSV_FETCHES, true);
        $urls = [];
        foreach (array_keys($toFetch) as $id) {
            $urls[$id] = self::OSV_VULN . rawurlencode((string) $id);
        }
        foreach ($this->getJsonMany($urls) as $id => $vuln) {
            if ($vuln === null) {
                $pending++;
                continue;
            }
            [$package, $modified] = $toFetch[$id];
            $cache[$id] = self::summarizeOsv($vuln, $ecosystem, $package) + ['modified' => $modified];
        }

        $findings = [];
        $minorFixes = 0;
        foreach ($refs as [$id, $package]) {
            $entry = $cache[$id] ?? null;
            // Ohne Fix in dieser Debian-Version hilft auch ein Neubau nicht.
            if ($entry === null || $entry['fixed'] === null) {
                continue;
            }
            $high = ($entry['score'] ?? 0) >= self::HIGH_SCORE || in_array($entry['urgency'], ['high', 'critical'], true);
            if (!$high) {
                $minorFixes++;
                continue;
            }
            $findings[] = [
                'component' => $package . ' ' . $packages[$package],
                'id' => $entry['cve'],
                'severity' => ($entry['score'] ?? 0) >= 9.0 ? 'critical' : 'high',
                'score' => $entry['score'],
                'summary' => $entry['summary'],
                'fixed' => $entry['fixed'],
                'url' => 'https://security-tracker.debian.org/tracker/' . rawurlencode($entry['cve']),
            ];
        }
        $this->saveCache($cache);

        $notes = [sprintf('Systempakete: %d Quellpakete (%s) geprüft.', count($packages), $ecosystem)];
        if ($minorFixes > 0) {
            $notes[] = sprintf('Systempakete: %d weitere behobene Lücken mit niedriger oder fehlender Bewertung. Ein Neubau des Containers holt die Fixes.', $minorFixes);
        }
        if ($pending > 0) {
            $notes[] = sprintf('Systempakete: %d Einträge noch nicht ausgewertet, folgen beim nächsten Lauf.', $pending);
        }

        return [$findings, $notes];
    }

    /**
     * Quellpakete mit Version aus /var/lib/dpkg/status. OSV führt Debian unter
     * den Quellpaketen (apache2, openssl), nicht unter den Binärpaketen.
     *
     * @return array<string, string> Quellpaket => Version
     */
    public static function parseDpkgStatus(string $content): array
    {
        $packages = [];
        foreach (preg_split('/\R\R+/', $content) ?: [] as $block) {
            $fields = [];
            foreach (preg_split('/\R/', $block) ?: [] as $line) {
                if (preg_match('/^([A-Za-z-]+):\s*(.*)$/', $line, $m)) {
                    $fields[$m[1]] = trim($m[2]);
                }
            }
            if (!isset($fields['Package'], $fields['Version']) || !str_ends_with($fields['Status'] ?? '', ' installed')) {
                continue;
            }
            $name = $fields['Package'];
            $version = $fields['Version'];
            // "Source: openssl" oder "Source: libpng1.6 (1.6.48-1)", wenn die
            // Quellversion von der des Binärpakets abweicht.
            if (isset($fields['Source']) && preg_match('/^(\S+)(?:\s+\(([^)]+)\))?/', $fields['Source'], $m)) {
                $name = $m[1];
                $version = $m[2] ?? $version;
            }
            $packages[$name] = $version;
        }
        ksort($packages);

        return $packages;
    }

    /** @return array<string, string> */
    public static function parseOsRelease(string $content): array
    {
        $values = [];
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            if (preg_match('/^([A-Z_]+)=(.*)$/', $line, $m)) {
                $values[$m[1]] = trim($m[2], "\"'");
            }
        }

        return $values;
    }

    /**
     * Verdichtet einen OSV-Eintrag auf das, was die Auswertung braucht.
     *
     * @param array<mixed> $vuln
     * @return array{cve: string, fixed: ?string, score: ?float, urgency: string, summary: string}
     */
    public static function summarizeOsv(array $vuln, string $ecosystem, string $package): array
    {
        $fixed = null;
        $urgency = '';
        foreach ($vuln['affected'] ?? [] as $affected) {
            if (($affected['package']['ecosystem'] ?? '') !== $ecosystem || ($affected['package']['name'] ?? '') !== $package) {
                continue;
            }
            $urgency = (string) ($affected['ecosystem_specific']['urgency'] ?? '');
            foreach ($affected['ranges'] ?? [] as $range) {
                foreach ($range['events'] ?? [] as $event) {
                    if (isset($event['fixed'])) {
                        $fixed = (string) $event['fixed'];
                    }
                }
            }
        }

        $score = null;
        foreach ($vuln['severity'] ?? [] as $severity) {
            if (($severity['type'] ?? '') === 'CVSS_V3') {
                $score = self::cvss3BaseScore((string) ($severity['score'] ?? ''));
            }
        }

        return [
            'cve' => (string) ($vuln['upstream'][0] ?? $vuln['aliases'][0] ?? $vuln['id'] ?? '?'),
            'fixed' => $fixed,
            'score' => $score,
            'urgency' => $urgency,
            'summary' => mb_substr(trim((string) ($vuln['summary'] ?? $vuln['details'] ?? '')), 0, 200),
        ];
    }

    /**
     * CVSS-3.x-Basiswert aus einem Vektor nach der FIRST-Spezifikation 3.1.
     * Null bei unvollständigem oder fremdem Vektor.
     */
    public static function cvss3BaseScore(string $vector): ?float
    {
        if (!preg_match('#^CVSS:3\.[01]/#', $vector)) {
            return null;
        }
        $m = [];
        foreach (explode('/', substr($vector, 9)) as $part) {
            [$key, $value] = array_pad(explode(':', $part, 2), 2, '');
            $m[$key] = $value;
        }

        $changed = ($m['S'] ?? '') === 'C';
        $weights = [
            'AV' => ['N' => 0.85, 'A' => 0.62, 'L' => 0.55, 'P' => 0.2],
            'AC' => ['L' => 0.77, 'H' => 0.44],
            'PR' => ['N' => 0.85, 'L' => $changed ? 0.68 : 0.62, 'H' => $changed ? 0.5 : 0.27],
            'UI' => ['N' => 0.85, 'R' => 0.62],
            'C' => ['H' => 0.56, 'L' => 0.22, 'N' => 0.0],
            'I' => ['H' => 0.56, 'L' => 0.22, 'N' => 0.0],
            'A' => ['H' => 0.56, 'L' => 0.22, 'N' => 0.0],
        ];
        $w = [];
        foreach ($weights as $metric => $options) {
            if (!isset($options[$m[$metric] ?? ''])) {
                return null;
            }
            $w[$metric] = $options[$m[$metric]];
        }
        if (!in_array($m['S'] ?? '', ['U', 'C'], true)) {
            return null;
        }

        $iss = 1 - (1 - $w['C']) * (1 - $w['I']) * (1 - $w['A']);
        $impact = $changed ? 7.52 * ($iss - 0.029) - 3.25 * (($iss - 0.02) ** 15) : 6.42 * $iss;
        if ($impact <= 0) {
            return 0.0;
        }
        $exploitability = 8.22 * $w['AV'] * $w['AC'] * $w['PR'] * $w['UI'];
        $base = $changed ? min(1.08 * ($impact + $exploitability), 10) : min($impact + $exploitability, 10);

        // „Roundup“ aus Anhang A der Spezifikation: vermeidet Fließkomma-Fehler
        // wie 4.000000001 → 4.1.
        $int = (int) round($base * 100000);

        return $int % 10000 === 0 ? $int / 100000 : (floor($int / 10000) + 1) / 10;
    }

    // ------------------------------------------------------------ MariaDB

    /** @return array{0: list<array<string, mixed>>, 1: list<string>} */
    private function checkMariaDb(): array
    {
        $running = (string) $this->db->fetchValue('SELECT VERSION()');
        if (!preg_match('/^(\d+\.\d+)\.(\d+)/', $running, $m) || stripos($running, 'mariadb') === false) {
            return [[], []];
        }
        $version = $m[1] . '.' . $m[2];
        $releases = array_keys($this->getJson(self::MARIADB_RELEASES . $m[1] . '/')['releases'] ?? []);
        $latest = $version;
        foreach ($releases as $release) {
            if (preg_match('/^\d+\.\d+\.\d+$/', (string) $release) && version_compare((string) $release, $latest, '>')) {
                $latest = (string) $release;
            }
        }

        return [[], $latest !== $version
            ? ['MariaDB: ' . $latest . ' ist verfügbar (läuft: ' . $version . '). Patch-Releases enthalten oft Sicherheitsfixes: Image neu ziehen.']
            : []];
    }

    // ------------------------------------------------------ Benachrichtigung

    /**
     * Meldet Funde, die noch nie gemeldet wurden, allen Global-Admins.
     *
     * @param list<array<string, mixed>> $findings
     * @return int Anzahl neu gemeldeter Funde
     */
    private function notifyNew(array $findings): int
    {
        $notified = json_decode($this->settings->get(self::NOTIFIED_KEY) ?? '[]', true);
        $notified = is_array($notified) ? $notified : [];

        $new = [];
        foreach ($findings as $finding) {
            $key = $finding['id'] . '@' . $finding['component'];
            if (!in_array($key, $notified, true)) {
                $new[$key] = $finding;
            }
        }
        if ($new === []) {
            return 0;
        }

        $admins = array_map('intval', array_column($this->db->fetchAll("SELECT id FROM users WHERE role = 'admin'"), 'id'));
        if ($admins !== []) {
            $ids = array_unique(array_map(static fn (array $f): string => (string) $f['id'], $new));
            $critical = array_filter($new, static fn (array $f): bool => $f['severity'] === 'critical') !== [];
            $this->notifications->sendMany(
                $admins,
                null,
                sprintf(
                    'Sicherheitswarnung: %d neue %s Lücke(n) in der Server-Umgebung (%s%s). Details und Abhilfe im Global-Admin.',
                    count($new),
                    $critical ? 'kritische/hohe' : 'hohe',
                    implode(', ', array_slice($ids, 0, 3)),
                    count($ids) > 3 ? ', …' : '',
                ),
                'info',
                null,
                $this->actionUrl,
            );
        }

        $notified = array_slice(array_values(array_unique([...$notified, ...array_keys($new)])), -self::MAX_NOTIFIED);
        $this->settings->set(self::NOTIFIED_KEY, json_encode($notified, JSON_UNESCAPED_UNICODE));

        return count($new);
    }

    // ------------------------------------------------------------- Helfer

    /** "8.3.36" → "8.3" */
    public static function branch(string $version): string
    {
        return implode('.', array_slice(explode('.', $version), 0, 2));
    }

    /** @return array<mixed> */
    private function getJson(string $url, ?string $body = null): array
    {
        $data = json_decode(($this->http)($url, $body), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Ungültige Antwort von ' . parse_url($url, PHP_URL_HOST));
        }

        return $data;
    }

    /**
     * Mehrere GET-Abrufe; ein fehlgeschlagener liefert null statt abzubrechen.
     * Mit curl parallel, mit ausgetauschtem Abruf (Tests) der Reihe nach.
     *
     * @param array<string, string> $urls Schlüssel => URL
     * @return array<string, array<mixed>|null>
     */
    private function getJsonMany(array $urls): array
    {
        $results = [];
        if ($this->customHttp || !function_exists('curl_multi_init')) {
            foreach ($urls as $key => $url) {
                try {
                    $results[$key] = $this->getJson($url);
                } catch (\RuntimeException) {
                    $results[$key] = null;
                }
            }

            return $results;
        }

        $multi = curl_multi_init();
        $queue = $urls;
        $active = [];
        $start = function () use (&$queue, &$active, $multi): void {
            while (count($active) < self::PARALLEL && $queue !== []) {
                $key = array_key_first($queue);
                $handle = curl_init($queue[$key]);
                curl_setopt_array($handle, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 20,
                    CURLOPT_USERAGENT => 'Berufsmesse-Sicherheitscheck',
                    CURLOPT_HTTPHEADER => ['Accept: application/json'],
                ]);
                curl_multi_add_handle($multi, $handle);
                $active[spl_object_id($handle)] = [$key, $handle];
                unset($queue[$key]);
            }
        };

        $start();
        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi, 1.0);
            while (($info = curl_multi_info_read($multi)) !== false) {
                [$key, $handle] = $active[spl_object_id($info['handle'])];
                $body = curl_multi_getcontent($handle);
                $ok = $info['result'] === CURLE_OK && curl_getinfo($handle, CURLINFO_RESPONSE_CODE) === 200;
                $data = $ok ? json_decode((string) $body, true) : null;
                $results[$key] = is_array($data) ? $data : null;
                curl_multi_remove_handle($multi, $handle);
                unset($active[spl_object_id($info['handle'])]);
                $start();
            }
        } while ($active !== []);
        curl_multi_close($multi);

        return $results;
    }

    /** HTTP-Abruf mit kurzem Timeout; wirft bei Netz- oder HTTP-Fehler. */
    private static function fetch(string $url, ?string $body): string
    {
        $context = stream_context_create(['http' => [
            'method' => $body === null ? 'GET' : 'POST',
            'header' => "User-Agent: Berufsmesse-Sicherheitscheck\r\nAccept: application/json\r\n"
                . ($body === null ? '' : "Content-Type: application/json\r\n"),
            'content' => $body ?? '',
            'timeout' => 20,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents($url, false, $context);
        $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0] . ' ', $m) ? (int) $m[1] : 0;
        if ($response === false || $status !== 200) {
            throw new \RuntimeException(sprintf('%s nicht erreichbar (HTTP %s)', parse_url($url, PHP_URL_HOST), $status ?: 'keine Antwort'));
        }

        return $response;
    }

    /** @return array<string, array<string, mixed>> */
    private function loadCache(): array
    {
        $file = $this->cacheFile ?? sys_get_temp_dir() . '/security-check-osv.json';
        $data = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($data) ? $data : [];
    }

    /** @param array<string, array<string, mixed>> $cache */
    private function saveCache(array $cache): void
    {
        $file = $this->cacheFile ?? sys_get_temp_dir() . '/security-check-osv.json';
        @file_put_contents($file, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}
