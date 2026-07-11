<?php

namespace Kanboard\Plugin\Kensho;

use Kanboard\Core\Plugin\Base;
use Kanboard\Core\Translator;

/**
 * Kensho — a private, per-user reflection companion.
 *
 * Registers the dashboard route + mutation/AI routes, an entry-point link in
 * the header user dropdown, and the plugin's CSS/JS assets. All actions operate
 * on the current user's own data (personal scope; login required).
 */
class Plugin extends Base
{
    public function initialize()
    {
        // ── Routes (query-string form works over curl; clean routes for UI) ──
        $this->route->addRoute('kensho', 'KenshoController', 'show', 'Kensho');
        $this->route->addRoute('kensho/analyze', 'KenshoController', 'analyze', 'Kensho');
        $this->route->addRoute('kensho/areas', 'KenshoController', 'areas', 'Kensho');
        $this->route->addRoute('kensho/goals', 'KenshoController', 'goals', 'Kensho');
        $this->route->addRoute('kensho/reflection', 'KenshoController', 'reflection', 'Kensho');
        $this->route->addRoute('kensho/propose', 'KenshoController', 'propose', 'Kensho');
        $this->route->addRoute('kensho/clarify', 'KenshoController', 'clarify', 'Kensho');
        $this->route->addRoute('kensho/reflect', 'KenshoController', 'reflect', 'Kensho');
        $this->route->addRoute('kensho/assets/css', 'KenshoController', 'css', 'Kensho');
        $this->route->addRoute('kensho/assets/js', 'KenshoController', 'js', 'Kensho');

        // ── Entry-point link in the header user dropdown ──
        $this->template->hook->attach('template:header:dropdown', 'Kensho:header_dropdown');

        // ── Assets: a head partial emits <link> + <script src> (no inline JS) ──
        $this->template->hook->attach('template:layout:head', 'Kensho:layout_assets');
    }

    public function onStartup()
    {
        Translator::load($this->languageModel->getCurrentLanguage(), __DIR__ . '/Locale');
    }

    public function getPluginName()
    {
        return 'Kensho';
    }

    public function getPluginDescription()
    {
        return t('A private, per-user reflection companion: mine your own activity, record who you want to be, and reflect on whether the two align.');
    }

    public function getPluginAuthor()
    {
        return 'Carmelo Santana';
    }

    public function getPluginVersion()
    {
        return '1.0.0';
    }

    public function getPluginHomepage()
    {
        return 'https://github.com/carmelosantana/kanboard-kensho';
    }

    public function getCompatibleKanboardVersion()
    {
        return '>=1.2.47';
    }
}
