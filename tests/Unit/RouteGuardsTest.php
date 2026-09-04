<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Router;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionMethod;

/**
 * Wacht darüber, dass jede registrierte Route geschützt ist.
 *
 * Geprüft wird der Quelltext des Handlers samt der privaten Helfer, die er
 * aufruft (requireSchool(), beginWrite(), requirePortal() …) — die Guards
 * stehen in dieser Anwendung häufig in solchen Helfern.
 *
 * Zwei Stufen:
 *  1. Anmeldung  — requireLogin/requireSchool/requireAdmin.
 *  2. Berechtigung — requirePermission/requireAdmin oder eine ausdrückliche
 *     Rollen- bzw. can()-Prüfung, die mit HTTP 403 abbricht.
 *
 * Der Test prüft das VORHANDENSEIN eines Guards, nicht dessen fachliche
 * Richtigkeit: ob eine Seite die passende Berechtigung verlangt, bleibt eine
 * inhaltliche Entscheidung. Er verhindert aber, dass eine neue Route ohne
 * jede Prüfung in die Anwendung gelangt.
 *
 * Bewusst offene Routen stehen unten in den beiden Ausnahmelisten — jeweils
 * mit Begründung. Wer eine Route dort einträgt, trifft diese Entscheidung
 * sichtbar.
 */
final class RouteGuardsTest extends TestCase
{
    /**
     * Ohne Anmeldung erreichbar.
     *
     * @var array<string, string> "METHODE Pfadmuster" => Begründung
     */
    private const PUBLIC_ROUTES = [
        'GET /' => 'Landing/Schulauswahl vor der Anmeldung.',
        'GET /setup' => 'Ersteinrichtung; sperrt sich selbst, sobald ein Admin existiert.',
        'POST /setup' => 'Ersteinrichtung; sperrt sich selbst, sobald ein Admin existiert.',
        'GET /login' => 'Anmeldeformular.',
        'POST /login' => 'Anmeldung.',
        'POST /logout' => 'Abmeldung.',
        'GET /{school}/login' => 'Anmeldeformular der Schule.',
        'POST /{school}/login' => 'Anmeldung an der Schule.',
        'GET /{school}/registrieren' => 'Selbstregistrierung, sofern die Schule sie freigeschaltet hat.',
        'POST /{school}/registrieren' => 'Selbstregistrierung, sofern die Schule sie freigeschaltet hat.',
        'GET /zugang' => 'Globales Seitenpasswort.',
        'POST /zugang' => 'Globales Seitenpasswort.',
        'GET /passwort-aendern' => 'Erzwungener Passwortwechsel; die Session besteht hier bereits.',
        'POST /passwort-aendern' => 'Erzwungener Passwortwechsel; die Session besteht hier bereits.',
        'GET /medien/logos/{file}' => 'Logos erscheinen schon auf der Anmeldeseite; Dateinamen sind zufällig.',
        'GET /medien/branding/{file}' => 'Hintergrundbilder der Anmeldeseite; Dateinamen sind zufällig.',
        'GET /healthz' => 'Container-Healthcheck.',
        'GET /readyz' => 'Container-Healthcheck.',
        'GET /aussteller-einladung' => 'Einladungslink; der Token im Aufruf ist der Nachweis.',
        'POST /aussteller-einladung' => 'Einladungslink; der Token im Aufruf ist der Nachweis.',
    ];

    /**
     * Angemeldet erreichbar, bewusst ohne Berechtigungsprüfung.
     *
     * @var array<string, string> "METHODE Pfadmuster" => Begründung
     */
    private const AUTHENTICATED_ONLY = [
        'GET /{school}/' => 'Verteilerseite: leitet nur an den Bereich der eigenen Rolle weiter.',
        'GET /{school}/aussteller' => 'Ausstellerverzeichnis für alle Rollen; nur aktive, freigegebene Felder.',
        'GET /{school}/aussteller/{id}' => 'Ausstellerverzeichnis für alle Rollen; nur aktive, freigegebene Felder.',
        'GET /api/benachrichtigungen' => 'Liefert ausschließlich die eigenen Benachrichtigungen.',
        'POST /api/benachrichtigungen/gelesen' => 'Setzt ausschließlich die eigenen Benachrichtigungen auf gelesen.',
        'GET /{school}/feedback' => 'Freischaltung und Zielgruppe prüft FeedbackService::isOpen()/isInAudience().',
        'GET /{school}/feedback/{id}' => 'Freischaltung und Zielgruppe prüft FeedbackService::isOpen()/isInAudience().',
        'POST /{school}/feedback/{id}' => 'Freischaltung und Zielgruppe prüft FeedbackService::isOpen()/isInAudience().',
    ];

    /** @return list<array{method: string, pattern: string, handler: array{class-string, string}}> */
    private function routes(): array
    {
        $router = new Router();
        foreach (glob(dirname(__DIR__, 2) . '/src/routes/*.php') ?: [] as $file) {
            $register = require $file;
            $register($router);
        }

        $routes = $router->all();
        self::assertNotEmpty($routes, 'Es wurden keine Routen geladen.');

        return $routes;
    }

    public function testJedeRouteVerlangtEineAnmeldung(): void
    {
        $ungeschuetzt = [];

        foreach ($this->routes() as $route) {
            $key = $route['method'] . ' ' . $route['pattern'];
            if (isset(self::PUBLIC_ROUTES[$key])) {
                continue;
            }

            $source = $this->handlerSource($route['handler']);
            if (!$this->hasLoginGuard($source)) {
                $ungeschuetzt[] = $key . ' → ' . $route['handler'][0] . '::' . $route['handler'][1];
            }
        }

        self::assertSame([], $ungeschuetzt, sprintf(
            "Diese Routen prüfen die Anmeldung nicht:\n%s\n\n"
            . 'Entweder fehlt requireLogin()/requireSchool(), oder die Route gehört '
            . 'mit Begründung in RouteGuardsTest::PUBLIC_ROUTES.',
            implode("\n", $ungeschuetzt),
        ));
    }

    public function testJedeRoutePruefteEineBerechtigung(): void
    {
        $ungeschuetzt = [];

        foreach ($this->routes() as $route) {
            $key = $route['method'] . ' ' . $route['pattern'];
            if (isset(self::PUBLIC_ROUTES[$key]) || isset(self::AUTHENTICATED_ONLY[$key])) {
                continue;
            }

            $source = $this->handlerSource($route['handler']);
            if (!$this->hasPermissionGuard($source)) {
                $ungeschuetzt[] = $key . ' → ' . $route['handler'][0] . '::' . $route['handler'][1];
            }
        }

        self::assertSame([], $ungeschuetzt, sprintf(
            "Diese Routen sind nur anmeldungs-, aber nicht berechtigungsgeschützt:\n%s\n\n"
            . 'Erwartet wird requirePermission()/requireAdmin() oder eine ausdrückliche '
            . 'Rollenprüfung mit HTTP 403 — sonst gehört die Route mit Begründung in '
            . 'RouteGuardsTest::AUTHENTICATED_ONLY.',
            implode("\n", $ungeschuetzt),
        ));
    }

    /**
     * Verwaiste Einträge in den Ausnahmelisten würden künftige Routen
     * desselben Pfads stillschweigend freistellen.
     */
    public function testAusnahmelistenEnthaltenNurBestehendeRouten(): void
    {
        $vorhanden = [];
        foreach ($this->routes() as $route) {
            $vorhanden[$route['method'] . ' ' . $route['pattern']] = true;
        }

        $verwaist = array_values(array_diff(
            [...array_keys(self::PUBLIC_ROUTES), ...array_keys(self::AUTHENTICATED_ONLY)],
            array_keys($vorhanden),
        ));

        self::assertSame([], $verwaist, sprintf(
            "Diese Ausnahmen zeigen auf Routen, die es nicht (mehr) gibt:\n%s",
            implode("\n", $verwaist),
        ));
    }

    // ---------- Quelltext-Analyse ----------

    /** @param array{class-string, string} $handler */
    private function handlerSource(array $handler): string
    {
        $seen = [];

        return $this->collectSource($handler[0], $handler[1], $seen);
    }

    /**
     * Quelltext einer Methode samt aller Methoden, die sie auf `$this`
     * aufruft — geerbte Guards der Basisklasse eingeschlossen.
     *
     * @param array<string, true> $seen
     */
    private function collectSource(string $class, string $method, array &$seen): string
    {
        $key = $class . '::' . $method;
        if (isset($seen[$key])) {
            return '';
        }
        $seen[$key] = true;

        try {
            $reflection = new ReflectionMethod($class, $method);
        } catch (ReflectionException) {
            return '';
        }

        $file = $reflection->getFileName();
        if ($file === false) {
            return '';
        }

        $lines = file($file);
        if ($lines === false) {
            return '';
        }

        $source = implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));

        $result = $source;
        if (preg_match_all('/\$this->([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $source, $matches) > 0) {
            foreach (array_unique($matches[1]) as $called) {
                $result .= $this->collectSource($class, $called, $seen);
            }
        }

        return $result;
    }

    private function hasLoginGuard(string $source): bool
    {
        foreach (['requireLogin(', 'requireSchool(', 'requireAdmin('] as $guard) {
            if (str_contains($source, $guard)) {
                return true;
            }
        }

        return false;
    }

    private function hasPermissionGuard(string $source): bool
    {
        if (str_contains($source, 'requirePermission(') || str_contains($source, 'requireAdmin(')) {
            return true;
        }

        // Ausdrückliche Prüfung einer Rolle oder eines Rechts, die mit 403
        // abbricht — so schützen sich die rollengebundenen Bereiche
        // (Schüler-, Lehrer- und Portalseiten).
        $checksRoleOrRight = str_contains($source, '->can(')
            || str_contains($source, '->role()')
            || preg_match('/\[\s*.role.\s*\]/', $source) === 1;

        return $checksRoleOrRight && preg_match('/HttpException\(\s*403/', $source) === 1;
    }
}
