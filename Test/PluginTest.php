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
        $this->assertEquals('1.0.0', $plugin->getPluginVersion());
        $this->assertEquals('Carmelo Santana', $plugin->getPluginAuthor());
        $this->assertStringContainsString('>=1.2.47', $plugin->getCompatibleKanboardVersion());
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
        $this->assertEquals('1.0.0', $json['version']);
        $this->assertArrayNotHasKey('requires', $json);
        // recommends is an array of {plugin, min_version, reason} dep objects —
        // the shape ModMenu's DependencyResolver/PluginManager::normalizeDeps expects.
        $this->assertIsArray($json['recommends']);
        $plugins = array_column($json['recommends'], 'plugin');
        $this->assertContains('AiConnector', $plugins);
        $this->assertContains('ShadcnTheme', $plugins);
    }
}
