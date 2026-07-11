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
}
