<?php

namespace Kanboard\Plugin\Kensho;

use Kanboard\Core\Plugin\Base;
use Kanboard\Core\Translator;

/**
 * Kensho — a private, per-user reflection companion.
 *
 * Registers the dashboard route + mutation/AI routes, an entry-point link in
 * the header user dropdown, and the plugin's static CSS/JS assets. All actions operate
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

        // ── Entry-point link in the header user dropdown ──
        $this->template->hook->attach('template:header:dropdown', 'Kensho:header_dropdown');

        // ── Assets: static files, served by the web server, never by the router ──
        //
        // These must NOT be routed through a controller. `template:layout:css`
        // and `template:layout:js` render on every page, including /login, so an
        // anonymous browser fetches them while logged out. A routed asset is an
        // ordinary authenticated request, and AuthenticationMiddleware stores the
        // URI of any such request in session `redirectAfterLogin` — which the
        // login then replays, landing the user on the raw asset file instead of
        // the dashboard. Static paths bypass the PHP router entirely.
        $this->hook->on('template:layout:css', ['template' => 'plugins/Kensho/Assets/css/kensho.css']);
        $this->hook->on('template:layout:js', ['template' => 'plugins/Kensho/Assets/js/kensho.js']);
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
        return '1.0.1';
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
