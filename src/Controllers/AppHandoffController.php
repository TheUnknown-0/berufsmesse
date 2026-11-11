<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Services\ApiTokens;

/**
 * Übergabe aus der App an eine angemeldete Web-Sitzung.
 *
 * Die App holt sich über POST /api/v1/web-sitzung einen Einmal-Code und
 * öffnet GET /app/uebergabe/{code} in ihrer WebView. Hier wird der Code
 * eingelöst, die Sitzung für das Konto gestartet und zum Ziel weitergeleitet.
 */
final class AppHandoffController extends Controller
{
    /** GET /app/uebergabe/{code} */
    public function enter(array $params): string
    {
        $handoff = (new ApiTokens($this->ctx->db))->consumeHandoff((string) $params['code']);
        if ($handoff === null) {
            throw new HttpException(403, 'Der Link aus der App ist abgelaufen oder wurde schon benutzt. Bitte in der App erneut öffnen.');
        }

        $user = $this->ctx->db->fetchOne('SELECT * FROM users WHERE id = ?', [$handoff['user_id']]);
        if ($user === null) {
            throw new HttpException(403, 'Dieses Konto gibt es nicht mehr.');
        }

        // Sitzung eines anderen Kontos in der WebView sauber ersetzen.
        if ($this->ctx->auth->id() !== (int) $user['id']) {
            $this->ctx->auth->loginAs($user);
        }
        $this->ctx->session->set('site_authenticated', true);
        // Seiten in der App: ohne eigene Kopfzeile und Seitenleiste (siehe layouts/app.php).
        $this->ctx->session->set(ApiTokens::SESSION_APP_MODE, true);
        $this->ctx->session->set(ApiTokens::SESSION_APP_THEME, $handoff['theme']);
        if ($handoff['token_id'] !== null) {
            $this->ctx->session->set(ApiTokens::SESSION_TOKEN_KEY, $handoff['token_id']);
        }

        $target = ApiTokens::safePath($handoff['target_path']) ?? '/';
        $this->redirect($this->ctx->url($target));
    }
}
