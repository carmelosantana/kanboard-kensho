<?php
/**
 * Head-assets partial, attached to hook `template:layout:head`.
 *
 * Emits a <link> for the CSS and a <script src> for the JS — no inline JS,
 * so this plugin stays inside a strict `default-src 'self'` CSP.
 */
?>
<link rel="stylesheet" href="<?= $this->url->href('KenshoController', 'css', ['plugin' => 'Kensho']) ?>">
<script defer src="<?= $this->url->href('KenshoController', 'js', ['plugin' => 'Kensho']) ?>"></script>
