<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\Kensho\Plugin;
use KanboardTests\units\Base;

class PluginTest extends Base
{
    public function testPluginMetadata()
    {
        $plugin = new Plugin($this->container);
        $this->assertEquals('Kensho', $plugin->getPluginName());
        $this->assertEquals('1.0.4', $plugin->getPluginVersion());
        $this->assertEquals('Carmelo Santana', $plugin->getPluginAuthor());
        // Core's PluginLoader reads getCompatibleVersion(); any other name is never called.
        $this->assertSame('>=1.2.47', $plugin->getCompatibleVersion());
        $this->assertFalse(method_exists($plugin, 'getCompatibleKanboardVersion'));
    }

    public function testInitializeRegistersRoutes()
    {
        // Route::addRoute() is a no-op unless the routing table is activated
        // (normally done by RouteProvider when ENABLE_URL_REWRITE is true,
        // which the sqlite test config leaves off). Enable it explicitly so
        // this test actually exercises route registration.
        $this->container['route']->enable();

        $plugin = new Plugin($this->container);
        $plugin->initialize();

        // findRoute lives on the Route service (container['route']), not the
        // Router dispatcher (container['router'], which has no findRoute()).
        // It returns controller/action/plugin for a registered path.
        $route = $this->container['route']->findRoute('kensho');
        $this->assertEquals('KenshoController', $route['controller']);
        $this->assertEquals('show', $route['action']);
        $this->assertEquals('kensho', strtolower($route['plugin']));
    }

    public function testPluginJsonIsValidAndDeclaresRecommends()
    {
        $json = json_decode(file_get_contents(__DIR__ . '/../plugin.json'), true);
        $this->assertIsArray($json);
        $this->assertEquals('Kensho', $json['name']);
        $this->assertEquals('1.0.4', $json['version']);
        $this->assertArrayNotHasKey('requires', $json);
        // recommends is an array of {plugin, min_version, reason} dep objects —
        // the shape ModMenu's DependencyResolver/PluginManager::normalizeDeps expects.
        $this->assertIsArray($json['recommends']);
        $plugins = array_column($json['recommends'], 'plugin');
        $this->assertContains('AiConnector', $plugins);
        $this->assertContains('ShadcnTheme', $plugins);
        $floors = array_column($json['recommends'], 'min_version', 'plugin');
        $this->assertSame('1.3.0', $floors['AiConnector']); // AiQuota, the shared AI limits
    }

    /**
     * Regression: logging in landed the user on /kensho/assets/js.
     *
     * Kensho used to serve its CSS/JS through routes (kensho/assets/css|js ->
     * KenshoController::css|js) emitted into `template:layout:head`. That head
     * hook renders on EVERY page, including /login, so an anonymous browser
     * fetched those two URLs while logged out. They are ordinary authenticated
     * routes, so AuthenticationMiddleware::handleAuthentication() treated each
     * one as a page request and did
     * `session_set('redirectAfterLogin', $this->request->getUri())`, clobbering
     * the user's real destination with "/kensho/assets/js". On a successful
     * login BaseController::redirectAfterLogin() replayed it and dumped the
     * user on the raw JS file instead of the dashboard.
     *
     * The fix is the suite convention every sibling plugin already follows:
     * register the assets as STATIC files under plugins/Kensho/Assets/, which
     * the web server serves directly and the PHP router never sees.
     */
    public function testAssetsAreStaticFilesAndNeverAuthenticatedRoutes()
    {
        $this->container['route']->enable();

        $plugin = new Plugin($this->container);
        $plugin->initialize();

        $hook = $this->container['hook'];

        // 1. Nothing from Kensho may be injected into template:layout:head —
        //    that is what put fetchable URLs on the login page.
        foreach ($hook->getListeners('template:layout:head') as $listener) {
            $this->assertStringNotContainsString(
                'Kensho',
                (string) ($listener['template'] ?? ''),
                'Kensho must not attach to template:layout:head (renders on /login)'
            );
        }

        // 2. Assets are registered as static paths on the standard asset hooks.
        $css = array_column($hook->getListeners('template:layout:css'), 'template');
        $js  = array_column($hook->getListeners('template:layout:js'), 'template');
        $this->assertContains('plugins/Kensho/Assets/css/kensho.css', $css);
        $this->assertContains('plugins/Kensho/Assets/js/kensho.js', $js);

        // 3. Those paths are real files on disk (that is what makes them static
        //    — AssetHelper::css()/js() filemtime()s them and the web server,
        //    not the router, serves them).
        foreach (array_merge($css, $js) as $path) {
            if (str_starts_with($path, 'plugins/Kensho/')) {
                // Resolve against this plugin's own directory so the assertion
                // holds wherever the checkout lives (worktree, container mount).
                $this->assertFileExists(
                    __DIR__ . '/../' . substr($path, strlen('plugins/Kensho/')),
                    $path . ' must exist on disk to be served statically'
                );
            }
        }

        // 4. The routed asset endpoints must be gone. findRoute() falls back to
        //    DashboardController/show with an empty plugin when a path is not
        //    registered, so an empty plugin means "not routed".
        foreach (['kensho/assets/css', 'kensho/assets/js'] as $path) {
            $route = $this->container['route']->findRoute($path);
            $this->assertSame(
                '',
                $route['plugin'],
                $path . ' must not be a route: an anonymous hit on it is captured into redirectAfterLogin'
            );
        }
    }
}
