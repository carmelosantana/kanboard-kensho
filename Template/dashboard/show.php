<div class="page-header">
    <h2><?= t('Kensho') ?> <small>— <?= t('seeing your own patterns') ?></small></h2>
</div>

<div class="kensho" data-kensho-root
     data-csrf="<?= $this->text->e($this->app->getToken()->getCSRFToken()) ?>"
     data-url-propose="<?= $this->url->href('KenshoController', 'propose', ['plugin' => 'Kensho']) ?>"
     data-url-clarify="<?= $this->url->href('KenshoController', 'clarify', ['plugin' => 'Kensho']) ?>"
     data-url-reflect="<?= $this->url->href('KenshoController', 'reflect', ['plugin' => 'Kensho']) ?>"
     data-url-areas="<?= $this->url->href('KenshoController', 'areas', ['plugin' => 'Kensho']) ?>"
     data-url-goals="<?= $this->url->href('KenshoController', 'goals', ['plugin' => 'Kensho']) ?>"
     data-ai-ready="<?= $ai_ready ? '1' : '0' ?>">

    <p class="kensho-lead"><?= t('This page is private to you. Lapses and quiet weeks are normal, not failure — this is a mirror, not a verdict, and not a measurement of your worth.') ?></p>

    <div class="kbx-toolbar kensho-tabs" role="tablist">
        <button type="button" class="kbx-btn" data-kensho-tab="areas" role="tab" aria-selected="true"><?= t('Areas & themes') ?></button>
        <button type="button" class="kbx-btn" data-kensho-tab="goals" role="tab" aria-selected="false"><?= t('Goals & desired identity') ?></button>
        <button type="button" class="kbx-btn" data-kensho-tab="reflection" role="tab" aria-selected="false"><?= t('Alignment reflection') ?></button>
    </div>

    <section data-kensho-panel="areas">
        <?= $this->render('Kensho:dashboard/areas', ['aggregate' => $aggregate, 'profile' => $profile, 'ai_ready' => $ai_ready]) ?>
    </section>
    <section data-kensho-panel="goals" hidden>
        <?= $this->render('Kensho:dashboard/goals', ['profile' => $profile, 'ai_ready' => $ai_ready]) ?>
    </section>
    <section data-kensho-panel="reflection" hidden>
        <?= $this->render('Kensho:dashboard/reflection', ['profile' => $profile, 'alignment' => $alignment, 'ai_ready' => $ai_ready]) ?>
    </section>

    <?= $this->render('Kensho:dashboard/privacy_summary', ['ai_options' => $ai_options]) ?>
</div>
