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

        <?php if (! empty($profiles)): ?>
            <label><?= t('Provider') ?>
                <select data-kensho-provider>
                    <?php foreach ($profiles as $p): ?>
                        <option value="<?= $this->text->e($p['id']) ?>" <?= $p['id'] === $default_profile_id ? 'selected' : '' ?>>
                            <?= $this->text->e($p['label'] ?? $p['id']) ?>
                        </option>
                    <?php endforeach ?>
                </select>
            </label>
        <?php else: ?>
            <p class="kensho-hint"><?= t('No AI provider is configured.') ?></p>
        <?php endif ?>

        <div class="kbx-toolbar">
            <button type="button" class="kbx-btn kbx-btn--primary" data-kensho-privacy-confirm <?= empty($profiles) ? 'disabled' : '' ?>><?= t('Send') ?></button>
            <button type="button" class="kbx-btn" data-kensho-privacy-cancel><?= t('Cancel') ?></button>
        </div>
    </div>
</div>
