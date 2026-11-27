<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\Kensho\Model\KenshoAiGate;
use KanboardTests\units\Base;

class AiGateTest extends Base
{
    public function testGateClosedWhenConnectorAbsent()
    {
        // connectorPresent=false forces the class_exists-false (manual) path.
        $this->assertFalse(KenshoAiGate::isReady($this->container, 80400, false));
    }

    public function testGateClosedOnOldPhp()
    {
        $this->assertFalse(KenshoAiGate::isReady($this->container, 80300, true));
    }

    public function testGateClosedWhenTheRegistryCannotResolveAProfile()
    {
        // An older AiConnector: a registry class without resolve() keeps AI off.
        $old = new class {
            public function isReady(): bool { return true; }
        };
        $this->assertFalse(KenshoAiGate::isReady($this->container, 80400, true, get_class($old)));
    }

    public function testGateOpenOnlyWhenTheRegistryCanResolveAndIsReady()
    {
        $new = new class {
            public function __construct($c = null) {}
            public function isReady(): bool { return true; }
            public function resolve(string $f, ?int $p = null, ?string $pick = null): string { return 'x'; }
        };
        $this->assertTrue(KenshoAiGate::isReady($this->container, 80400, true, get_class($new)));
    }
}
