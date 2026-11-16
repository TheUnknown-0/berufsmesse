<?php
/**
 * Hilfe-Ruf auf jeder Seite: offene Rufe als Banner (für Empfänger und den
 * eigenen Raum) und am Messetag der Knopf „Hilfe rufen“. Den Inhalt zeichnet
 * assets/js/help-calls.js nach der Abfrage von /{school}/api/hilfe.
 * In der App (App-Modus) entfällt beides, die App hat eigene Bildschirme.
 */
use App\Core\Permissions as P;

if ($ctx->school === null || !$auth->check()
    || $ctx->session->get(\App\Services\ApiTokens::SESSION_APP_MODE) === true) {
    return;
}
$helpSchoolId = (int) $ctx->school['id'];
if (!$auth->can(P::HILFE_RUFEN, $helpSchoolId)
    && !$auth->can(P::HILFE_RUFE_EMPFANGEN, $helpSchoolId)
    && !$auth->can(P::HILFE_RUFE_VERWALTEN, $helpSchoolId)) {
    return;
}
$baseUrl = $ctx->config['app']['base_url'];
?>
<link rel="stylesheet" href="<?= e($baseUrl) ?>/assets/css/help-calls.css">
<div id="help-call-bar" class="help-bar" aria-live="polite"
     data-status-url="<?= e($ctx->schoolUrl('/api/hilfe')) ?>"
     data-page-url="<?= e($ctx->schoolUrl('/hilfe')) ?>"></div>
<script src="<?= e($baseUrl) ?>/assets/js/help-calls.js" defer></script>
