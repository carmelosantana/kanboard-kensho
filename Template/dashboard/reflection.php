<p class="kensho-lead"><?= t('Do the things you do line up with who you want to be? Here is the gap, plainly. This is a mirror, not a verdict — and it says nothing about your worth.') ?></p>

<table class="table-striped kensho-align">
    <thead>
        <tr>
            <th><?= t('Area') ?></th>
            <th><?= t('You rate it') ?></th>
            <th><?= t('Stated importance share') ?></th>
            <th><?= t('Actual effort share') ?></th>
            <th><?= t('Gap') ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($alignment['rows'] as $row): ?>
            <tr>
                <td><?= $this->text->e($row['label']) ?></td>
                <td><?= $row['area_id'] === \Kanboard\Plugin\Kensho\Model\KenshoScoreModel::UNASSIGNED_ID ? '—' : (int) $row['importance'] . '/10' ?></td>
                <td><?= $this->text->e((string) $row['importance_share']) ?>%</td>
                <td><?= $this->text->e((string) $row['actual_share']) ?>%</td>
                <td class="<?= $row['discrepancy'] > 0 ? 'kensho-under' : 'kensho-over' ?>">
                    <?= $this->text->e(($row['discrepancy'] > 0 ? '+' : '') . $row['discrepancy']) ?>
                </td>
            </tr>
        <?php endforeach ?>
        <?php if (empty($alignment['rows'])): ?>
            <tr><td colspan="5"><?= t('Create some areas first on the "Areas & themes" tab to see a reflection here.') ?></td></tr>
        <?php endif ?>
    </tbody>
</table>

<?php if ($alignment['uses_task_count_fallback']): ?>
    <p class="kensho-hint"><?= t('You have not logged time, so effort here is counted by number of tasks instead.') ?></p>
<?php endif ?>

<?php if ($profile['settings']['show_score'] && $alignment['score'] !== null): ?>
    <div class="kensho-score kbx-badge">
        <?= t('Alignment') ?>: <strong><?= (int) $alignment['score'] ?></strong>/100
    </div>
    <p class="kensho-caveat"><?= t('This number is a chosen proxy, not a measurement of you or your worth. It compares two distributions you defined; the formula is: 100 − ½ × Σ|stated share − actual share|. Change your areas or their importance and it changes. You can turn it off on the "Areas & themes" tab.') ?></p>
<?php endif ?>

<?php if ($ai_ready): ?>
    <button type="button" class="kbx-btn kbx-btn--primary" data-kensho-action="reflect"><?= t('Reflect with AI') ?></button>
<?php endif ?>

<form method="post" action="<?= $this->url->href('KenshoController', 'reflection', ['plugin' => 'Kensho']) ?>" data-kensho-reflection-form>
    <?= $this->form->csrf() ?>
    <input type="hidden" name="window_days" value="<?= (int) $profile['window_days'] ?>">
    <input type="hidden" name="score" value="<?= $alignment['score'] === null ? '' : (int) $alignment['score'] ?>">
    <input type="hidden" name="discrepancies" data-kensho-discrepancies value="<?= $this->text->e(json_encode($alignment['rows'])) ?>">
    <input type="hidden" name="provider_profile" value="" data-kensho-provider-profile>
    <label><?= t('Your reflection (edit freely before saving)') ?>
        <textarea name="narrative" rows="6" data-kensho-narrative></textarea>
    </label>
    <button type="submit" class="kbx-btn"><?= t('Save reflection') ?></button>
</form>

<?php if (! empty($profile['reflections'])): ?>
    <h3><?= t('Past reflections') ?></h3>
    <ul class="kensho-history">
        <?php foreach (array_reverse($profile['reflections']) as $r): ?>
            <li>
                <time><?= $this->text->e(date('Y-m-d', (int) $r['date'])) ?></time>
                <?php if ($r['score'] !== null): ?><span class="kbx-badge"><?= (int) $r['score'] ?>/100</span><?php endif ?>
                <p><?= nl2br($this->text->e($r['narrative'])) ?></p>
            </li>
        <?php endforeach ?>
    </ul>
<?php endif ?>
