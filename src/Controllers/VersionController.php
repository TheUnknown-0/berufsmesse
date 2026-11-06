<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\UpdateCheck;

/**
 * Update-Prüfung für Global-Admins (Versionsdialog in der Seitenleiste).
 *
 * Die Seite ruft die Prüfung nach der Anmeldung und einmal täglich selbst auf
 * (public/assets/js/version.js). Der Knopf „Nach neuen Versionen suchen“ im
 * Dialog erzwingt sie.
 */
final class VersionController extends Controller
{
    /** POST /api/version/pruefen  {force?: bool} */
    public function check(array $params): array
    {
        $this->requireAdmin();
        $this->requireCsrf();
        $force = !empty($this->jsonInput()['force']);

        $session = $this->ctx->session;
        $afterLogin = (bool) $session->get(UpdateCheck::SESSION_DUE, false);
        $session->remove(UpdateCheck::SESSION_DUE);
        // Die Abfrage bei GitHub darf andere Anfragen derselben Sitzung nicht aufhalten.
        $session->release();

        $updates = UpdateCheck::fromContext($this->ctx);
        $ran = false;
        $notice = null;
        if ($force) {
            if ($updates->checkedRecently()) {
                $notice = 'Vor weniger als einer Minute geprüft, das Ergebnis unten ist aktuell.';
            } else {
                $ran = $updates->run();
            }
        } elseif ($updates->isDue($afterLogin)) {
            $ran = $updates->run();
        }
        $state = $updates->state();

        return [
            'success' => true,
            'checked' => $ran,
            'installed' => $state['installed'],
            'latest' => $state['latest'],
            'available' => $state['available'],
            'html' => $this->ctx->view->renderPartial('partials/version-status', ['update' => $state, 'notice' => $notice]),
        ];
    }
}
