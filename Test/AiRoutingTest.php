<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\Kensho\Model\KenshoAiModel;
use Kanboard\Plugin\Kensho\Model\KenshoAiUnavailable;
use KanboardTests\units\Base;

require_once __DIR__ . '/AiQuotaFixture.php';

/** Every Kensho AI call picks its model through ProviderRegistry::resolve(), never from the raw post. */
class AiRoutingTest extends Base
{
    use AiQuotaFixture;

    private $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInForAi();
        $this->registry = new class {
            public array $resolved = [];
            public array $used = [];
            public string $answer = 'routed';
            public function resolve(string $feature, ?int $projectId = null, ?string $pick = null): string
            {
                $this->resolved[] = [$feature, $projectId, $pick];
                return $this->answer;
            }
            public function structured(array $m, string $s, ?string $p = null): array { $this->used[] = $p; return []; }
        };
    }

    private function model(): KenshoAiModel
    {
        return new class($this->container, $this->registry) extends KenshoAiModel {
            public function __construct($c, private $stub) { parent::__construct($c); }
            protected function getRegistry() { return $this->stub; }
        };
    }

    public function testClarifyResolvesThePostedPickAndUsesTheResolvedId(): void
    {
        $this->model()->clarifyGoals('Health', 'move more', 'posted-raw');
        $this->assertSame([['kensho.clarify', null, 'posted-raw']], $this->registry->resolved);
        $this->assertSame(['routed'], $this->registry->used);
    }

    public function testProposeAndReflectResolveTheirOwnFeature(): void
    {
        $model = $this->model();
        $model->proposeAreas(['dimensions' => []], 'a');
        $model->reflect([], [], 'b');
        $this->assertSame([['kensho.propose', null, 'a'], ['kensho.reflect', null, 'b']], $this->registry->resolved);
    }

    public function testNothingReadyMeansNoCallAndNoQuotaSpend(): void
    {
        $this->registry->answer = '';
        try {
            $this->model()->clarifyGoals('Health', 'x', 'p');
            $this->fail('expected KenshoAiUnavailable');
        } catch (KenshoAiUnavailable $e) {
            $this->assertSame([], $this->registry->used);
            $this->assertSame(0, $this->container['db']->table(\Kanboard\Plugin\AiConnector\Model\AiQuota::TABLE)->count());
        }
    }

    public function testTheControllerPassesThePostedAiProfileAsThePickNotAsTheModel(): void
    {
        $model = $this->model();
        $controller = new class($this->container, $model) extends \Kanboard\Plugin\Kensho\Controller\KenshoController {
            public function __construct($c, private $m) { parent::__construct($c); }
            protected function isAiEnabled(): bool { return true; }
            protected function aiModel(): KenshoAiModel { return $this->m; }
        };
        $post = ['csrf_token' => $this->container['token']->getCSRFToken(), 'area_label' => 'Health', 'note' => 'x', 'ai_profile' => 'posted-raw'];
        $this->container['request'] = new \Kanboard\Core\Http\Request($this->container, [], [], $post, [], []);
        $response = $this->getMockBuilder(\Kanboard\Core\Http\Response::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['json'])->getMock();
        $this->container['response'] = $response;

        $controller->clarify();

        $this->assertSame([['kensho.clarify', null, 'posted-raw']], $this->registry->resolved);
        $this->assertSame(['routed'], $this->registry->used);
    }
}
