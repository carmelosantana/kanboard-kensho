<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\Kensho\Model\KenshoAiModel;
use KanboardTests\units\Base;

require_once __DIR__ . '/AiQuotaFixture.php';

class AiModelReflectTest extends Base
{
    use AiQuotaFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInForAi();
    }

    /** Build a KenshoAiModel whose getRegistry() returns a canned structured() result. */
    private function modelReturning(array $canned): KenshoAiModel
    {
        $registry = new class($canned) {
            private $canned;
            public $lastMessages;
            public $lastSchema;
            public $lastProfile;
            public function __construct($canned) { $this->canned = $canned; }
            public function resolve(string $f, ?int $p = null, ?string $pick = null): string { return $pick ?? 'default'; }
            public function structured(array $messages, string $schema, ?string $profileId = null): array
            {
                $this->lastMessages = $messages;
                $this->lastSchema = $schema;
                $this->lastProfile = $profileId;
                return $this->canned;
            }
        };

        return new class($this->container, $registry) extends KenshoAiModel {
            private $stub;
            public function __construct($c, $stub) { parent::__construct($c); $this->stub = $stub; }
            protected function getRegistry() { return $this->stub; }
        };
    }

    public function testReflectReturnsSchemaShapedArray()
    {
        $model = $this->modelReturning([
            'observations' => ['You logged most time on Health this window.'],
            'questions'    => ['What would "enough" rest look like this week?'],
            'gentle_note'  => 'A quiet stretch is just a lapse, not a verdict.',
        ]);

        $out = $model->reflect(
            [['area_id' => 'area_health', 'importance_share' => 18.0, 'actual_share' => 4.2, 'discrepancy' => 13.8]],
            ['area_health' => 'Someone who moves daily and rests well.']
        );

        $this->assertIsArray($out['observations']);
        $this->assertIsArray($out['questions']);
        $this->assertIsString($out['gentle_note']);
        $this->assertArrayNotHasKey('verdict', $out);
        $this->assertArrayNotHasKey('score', $out);

        // Canned values must actually flow through (a regression returning empty
        // arrays/strings would still pass the shape assertions above).
        $this->assertSame('You logged most time on Health this window.', $out['observations'][0]);
        $this->assertSame('A quiet stretch is just a lapse, not a verdict.', $out['gentle_note']);
    }

    public function testReflectNormalizesGarbageToSafeDefaults()
    {
        $model = $this->modelReturning(['unexpected' => true]);
        $out = $model->reflect([], []);
        $this->assertSame([], $out['observations']);
        $this->assertSame([], $out['questions']);
        $this->assertSame('', $out['gentle_note']);
    }

    public function testReflectSystemPromptEncodesGuardrails()
    {
        $model = $this->modelReturning(['observations' => [], 'questions' => [], 'gentle_note' => '']);
        $model->reflect([], []);
        // Reach the stub to inspect the system message actually sent.
        $ref = new ReflectionMethod($model, 'getRegistry');
        $ref->setAccessible(true);
        $stub = $ref->invoke($model);
        $system = $stub->lastMessages[0]['content'];
        $this->assertEquals('system', $stub->lastMessages[0]['role']);
        // Guardrails present, verbatim intent (challenge-not-flatter, no purpose claim, lapses normal).
        $this->assertStringContainsStringIgnoringCase('challenge', $system);
        $this->assertStringContainsStringIgnoringCase('purpose', $system); // as a prohibition
        $this->assertStringContainsStringIgnoringCase('lapse', $system);
    }
}
