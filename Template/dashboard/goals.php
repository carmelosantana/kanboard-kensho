<p class="kensho-lead"><?= t('For each area, say how important it is to you and who you want to be there. You can type this yourself; AI can help sharpen a rough note, but only you decide what to keep.') ?></p>

<?php foreach ($profile['areas'] as $a): ?>
    <fieldset class="kensho-area" data-area-id="<?= $this->text->e($a['id']) ?>">
        <legend><?= $this->text->e($a['label']) ?></legend>
        <label><?= t('Importance (1–10)') ?>
            <input type="range" min="1" max="10" value="<?= (int) $a['importance'] ?>" data-area-importance>
            <output><?= (int) $a['importance'] ?></output>
        </label>
        <label><?= t('Who you want to be here') ?>
            <textarea data-area-identity rows="2"><?= $this->text->e($a['identity']) ?></textarea>
        </label>
        <label><?= t('Goals (one per line)') ?>
            <textarea data-area-goals rows="3"><?= $this->text->e(implode("\n", $a['goals'])) ?></textarea>
        </label>
        <?php if ($ai_ready): ?>
            <button type="button" class="kbx-btn" data-kensho-action="clarify" data-area-label="<?= $this->text->e($a['label']) ?>"><?= t('Clarify with AI') ?></button>
            <p class="kensho-hint" data-area-question></p>
        <?php endif ?>
    </fieldset>
<?php endforeach ?>

<?php if (empty($profile['areas'])): ?>
    <p><?= t('Create some areas first on the "Areas & themes" tab.') ?></p>
<?php endif ?>

<button type="button" class="kbx-btn kbx-btn--primary" data-kensho-action="save-goals" <?= empty($profile['areas']) ? 'disabled' : '' ?>><?= t('Save goals') ?></button>
