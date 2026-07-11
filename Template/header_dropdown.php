<?php
/**
 * Entry-point link in the header user dropdown, attached to hook
 * `template:header:dropdown`.
 */
?>
<li>
    <?= $this->url->icon('compass', t('Kensho'), 'KenshoController', 'show', ['plugin' => 'Kensho']) ?>
</li>
