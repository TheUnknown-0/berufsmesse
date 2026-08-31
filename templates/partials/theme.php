<?php
/**
 * Schulspezifische Design-Overrides (Farben) als <style>-Block.
 * Kommt direkt nach dem App-Stylesheet in den <head> beider Layouts.
 */

use App\Services\Customization;

if ($ctx->school !== null) {
    $css = (new Customization($ctx->settings, $ctx->schoolId()))->themeCss();
    if ($css !== '') {
        echo '<style>' . $css . '</style>';
    }
}
