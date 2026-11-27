<div class="kbx-modal kensho-privacy" data-kensho-privacy hidden role="dialog" aria-modal="true" aria-labelledby="kensho-privacy-title">
    <div class="kbx-modal__content">
        <h3 id="kensho-privacy-title"><?= t('Before sending to AI') ?></h3>
        <p><?= t('This runs only when you confirm below. It sends the following to the provider you pick — and nothing else. No task descriptions or comments are ever sent.') ?></p>
        <ul>
            <li><?= t('Your life-area labels and importance ratings') ?></li>
            <li><?= t('Aggregated numbers per project/category/tag (task counts, time)') ?></li>
            <li><?= t('Up to 5 sample task titles per dimension') ?></li>
            <li><?= t('Your own desired-self statements') ?></li>
        </ul>
        <div data-kensho-privacy-detail class="kensho-privacy-detail"></div>

        <?php /* One picker per feature: each feature routes (and so offers) its own models. JS shows the one for the action. */ ?>
        <?php foreach ($ai_options as $feature => $options): ?>
            <div data-kensho-picker="<?= $this->text->e($feature) ?>" hidden>
                <?= $this->render('AiConnector:picker/select', ['options' => $options, 'name' => 'ai_profile_' . str_replace('.', '_', $feature)]) ?>
            </div>
        <?php endforeach ?>

        <div class="kbx-toolbar">
            <button type="button" class="kbx-btn kbx-btn--primary" data-kensho-privacy-confirm><?= t('Send') ?></button>
            <button type="button" class="kbx-btn" data-kensho-privacy-cancel><?= t('Cancel') ?></button>
        </div>
    </div>
</div>
