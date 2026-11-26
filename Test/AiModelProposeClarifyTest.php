<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\Kensho\Model\KenshoAiModel;
use KanboardTests\units\Base;

require_once __DIR__ . '/AiQuotaFixture.php';

class AiModelProposeClarifyTest extends Base
{
    use AiQuotaFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInForAi();
    }

    private function modelReturning(array $canned): KenshoAiModel
    {
        $registry = new class($canned) {
            private $canned; public $lastMessages;
            public function __construct($c) { $this->canned = $c; }
            public function structured(array $m, string $s, ?string $p = null): array { $this->lastMessages = $m; return $this->canned; }
        };
        return new class($this->container, $registry) extends KenshoAiModel {
            private $stub;
            public function __construct($c, $stub) { parent::__construct($c); $this->stub = $stub; }
            protected function getRegistry() { return $this->stub; }
        };
    }

    public function testProposeAreasNormalizes()
    {
        $model = $this->modelReturning(['areas' => [
            ['label' => 'Health', 'rationale' => 'Lots of gym tasks', 'dimension_refs' => [['type' => 'project', 'id' => 1]], 'importance_hint' => 8],
            ['label' => '', 'rationale' => 'dropped: no label'],
        ]]);
        $out = $model->proposeAreas(['dimensions' => [['type' => 'project', 'id' => 1, 'label' => 'Gym']]]);
        $this->assertCount(1, $out['areas']); // blank-label entry dropped
        $this->assertEquals('Health', $out['areas'][0]['label']);
        $this->assertEquals(1, $out['areas'][0]['dimension_refs'][0]['id']);
        $this->assertEquals(8, $out['areas'][0]['importance_hint']);
    }

    public function testClarifyGoalsNormalizes()
    {
        $model = $this->modelReturning([
            'identity' => 'Someone who moves daily.',
            'goals' => ['Run 3x/week', '', 'Sleep by 11pm'],
            'reflection_question' => 'What makes rest feel earned?',
        ]);
        $out = $model->clarifyGoals('Health', 'want to be fit and rested');
        $this->assertEquals('Someone who moves daily.', $out['identity']);
        $this->assertSame(['Run 3x/week', 'Sleep by 11pm'], $out['goals']); // blank dropped
        $this->assertStringContainsString('rest', $out['reflection_question']);
    }

    public function testProposeAreasSystemPromptChallengesAndDefersMeaning()
    {
        $model = $this->modelReturning(['areas' => []]);
        $model->proposeAreas(['dimensions' => []]);
        $ref = new ReflectionMethod($model, 'getRegistry');
        $ref->setAccessible(true);
        $system = $ref->invoke($model)->lastMessages[0]['content'];
        $this->assertStringContainsStringIgnoringCase('suggestion', $system);
        $this->assertStringContainsStringIgnoringCase('purpose', $system); // as prohibition
        $this->assertStringContainsStringIgnoringCase('challenge', $system); // active challenge-not-flatter half
        $this->assertStringContainsStringIgnoringCase('lapse', $system); // low/absent activity is not a failure
    }

    public function testClarifyGoalsSystemPromptChallengesAndDefersMeaning()
    {
        $model = $this->modelReturning([
            'identity' => '',
            'goals' => [],
            'reflection_question' => '',
        ]);
        $model->clarifyGoals('Health', 'a rough note');
        $ref = new ReflectionMethod($model, 'getRegistry');
        $ref->setAccessible(true);
        $system = $ref->invoke($model)->lastMessages[0]['content'];
        $this->assertStringContainsStringIgnoringCase('challenge', $system);
        $this->assertStringContainsStringIgnoringCase('purpose', $system); // no-purpose/worth guardrail
        $this->assertStringContainsStringIgnoringCase('worth', $system);
        $this->assertStringContainsStringIgnoringCase('lapse', $system); // low/absent activity is not a failure
    }

    public function testProposeAreasGuardsAgainstNonArrayAreas()
    {
        // Malformed model response: "areas" present but not an array — must not crash.
        $model = $this->modelReturning(['areas' => 'garbage-non-array-string']);
        $out = $model->proposeAreas(['dimensions' => []]);
        $this->assertSame(['areas' => []], $out);
    }

    public function testClarifyGoalsGuardsAgainstNonArrayGoals()
    {
        // Malformed model response: "goals" present but not an array — must not crash.
        $model = $this->modelReturning([
            'identity' => 'Someone who rests well.',
            'goals' => 'garbage-non-array-string',
            'reflection_question' => 'What does rest mean to you?',
        ]);
        $out = $model->clarifyGoals('Health', 'want to be fit and rested');
        $this->assertSame([], $out['goals']);
    }

    public function testProposeAreasGuardsAgainstNonArrayDimensionRefs()
    {
        // Malformed model response: "dimension_refs" present but not an array — must not crash.
        $model = $this->modelReturning(['areas' => [
            ['label' => 'Health', 'rationale' => 'x', 'dimension_refs' => 'garbage-non-array-string'],
        ]]);
        $out = $model->proposeAreas(['dimensions' => []]);
        $this->assertCount(1, $out['areas']);
        $this->assertEquals('Health', $out['areas'][0]['label']);
        $this->assertSame([], $out['areas'][0]['dimension_refs']);
    }
}
