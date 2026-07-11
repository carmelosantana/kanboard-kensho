<p class="kensho-lead"><?= t('Here is where your effort actually went. Group these into life areas that mean something to you — rename, merge, or ignore any of them. They are yours; nothing here judges how you spend your time.') ?></p>

<form method="post" action="<?= $this->url->href('KenshoController', 'analyze', ['plugin' => 'Kensho']) ?>" class="kbx-toolbar">
    <?= $this->form->csrf() ?>
    <label><?= t('Window') ?>
        <?= $this->form->select('window_days', [30 => t('30 days'), 90 => t('90 days'), 180 => t('180 days'), 365 => t('365 days')], $profile) ?>
    </label>
    <label>
        <input type="hidden" name="show_score" value="0">
        <input type="checkbox" name="show_score" value="1" <?= $profile['settings']['show_score'] ? 'checked' : '' ?>>
        <?= t('Show the optional alignment score') ?>
    </label>
    <button type="submit" class="kbx-btn"><?= t('Recompute') ?></button>
</form>

<?php if ($ai_ready): ?>
    <button type="button" class="kbx-btn kbx-btn--primary" data-kensho-action="propose"><?= t('Propose areas with AI') ?></button>
    <span class="kensho-hint"><?= t('AI suggests groupings from your aggregated numbers only; you edit and confirm before anything is saved.') ?></span>
<?php endif ?>

<table class="table-striped kensho-dims">
    <thead>
        <tr>
            <th><?= t('Dimension') ?></th>
            <th><?= t('Type') ?></th>
            <th><?= t('Tasks') ?></th>
            <th><?= t('Time spent') ?></th>
            <th><?= t('Sample titles') ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($aggregate['dimensions'] as $d): ?>
            <tr>
                <td><?= $this->text->e($d['label']) ?></td>
                <td><span class="kbx-badge"><?= $this->text->e($d['type']) ?></span></td>
                <td><?= (int) $d['task_count'] ?></td>
                <td><?= $this->text->e((string) $d['time_spent']) ?></td>
                <td><?= $this->text->e(implode(' · ', $d['sample_titles'])) ?></td>
            </tr>
        <?php endforeach ?>
        <?php if (empty($aggregate['dimensions'])): ?>
            <tr><td colspan="5"><?= t('No activity in this window yet — that is perfectly fine.') ?></td></tr>
        <?php endif ?>
    </tbody>
</table>

<h3><?= t('Your life areas') ?></h3>
<p class="kensho-lead"><?= t('Give each area a name, pick which of the dimensions above belong to it, and say how important it is to you. You can do all of this by hand — AI is optional.') ?></p>

<div data-kensho-areas-editor>
    <template data-kensho-area-card-template>
        <fieldset class="kensho-area-card" data-kensho-area-card>
            <label><?= t('Label') ?>
                <input type="text" data-area-card-label value="" placeholder="<?= $this->text->e(t('e.g. Health, Family, Craft')) ?>">
            </label>
            <label><?= t('Importance (1–10)') ?>
                <input type="number" min="1" max="10" value="5" data-area-card-importance>
            </label>
            <fieldset class="kensho-area-card-dims">
                <legend><?= t('Includes') ?></legend>
                <?php foreach ($aggregate['dimensions'] as $d): ?>
                    <label class="kensho-dim-check">
                        <input type="checkbox" data-area-card-dim data-dim-type="<?= $this->text->e($d['type']) ?>" data-dim-id="<?= (int) $d['id'] ?>">
                        <?= $this->text->e($d['type']) ?>: <?= $this->text->e($d['label']) ?>
                    </label>
                <?php endforeach ?>
            </fieldset>
            <p class="kensho-hint" data-area-card-rationale></p>
            <button type="button" class="kbx-btn" data-kensho-action="remove-area-card"><?= t('Remove this area') ?></button>
        </fieldset>
    </template>

    <div data-kensho-area-cards>
        <?php foreach ($profile['areas'] as $a): ?>
            <fieldset class="kensho-area-card" data-kensho-area-card>
                <input type="hidden" data-area-card-id value="<?= $this->text->e($a['id']) ?>">
                <input type="hidden" data-area-card-identity value="<?= $this->text->e($a['identity']) ?>">
                <input type="hidden" data-area-card-goals value="<?= $this->text->e(json_encode($a['goals'])) ?>">
                <label><?= t('Label') ?>
                    <input type="text" data-area-card-label value="<?= $this->text->e($a['label']) ?>">
                </label>
                <label><?= t('Importance (1–10)') ?>
                    <input type="number" min="1" max="10" value="<?= (int) $a['importance'] ?>" data-area-card-importance>
                </label>
                <fieldset class="kensho-area-card-dims">
                    <legend><?= t('Includes') ?></legend>
                    <?php foreach ($aggregate['dimensions'] as $d):
                        $srcKey = $d['type'] === 'project' ? 'project_ids' : ($d['type'] === 'category' ? 'category_ids' : 'tag_ids');
                        $checked = in_array((int) $d['id'], $a['sources'][$srcKey] ?? [], true);
                    ?>
                        <label class="kensho-dim-check">
                            <input type="checkbox" data-area-card-dim data-dim-type="<?= $this->text->e($d['type']) ?>" data-dim-id="<?= (int) $d['id'] ?>" <?= $checked ? 'checked' : '' ?>>
                            <?= $this->text->e($d['type']) ?>: <?= $this->text->e($d['label']) ?>
                        </label>
                    <?php endforeach ?>
                </fieldset>
                <button type="button" class="kbx-btn" data-kensho-action="remove-area-card"><?= t('Remove this area') ?></button>
            </fieldset>
        <?php endforeach ?>
    </div>

    <button type="button" class="kbx-btn" data-kensho-action="add-area-card"><?= t('Add area') ?></button>
</div>

<button type="button" class="kbx-btn kbx-btn--primary" data-kensho-action="save-areas"><?= t('Save areas') ?></button>
