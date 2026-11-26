<?php

require_once 'tests/units/Base.php';
require_once __DIR__ . '/KenshoSchemaHelper.php';
require_once __DIR__ . '/AiQuotaFixture.php';

use Kanboard\Plugin\AiConnector\Model\AiQuota;
use Kanboard\Plugin\Kensho\Controller\KenshoController;
use Kanboard\Plugin\Kensho\Model\KenshoAiModel;
use Kanboard\Plugin\Kensho\Model\KenshoAiRefused;
use KanboardTests\units\Base;

/** Every Kensho AI call goes through AiConnector's shared quota and input cap first. */
class AiQuotaTest extends Base
{
    use KenshoSchemaHelper;
    use AiQuotaFixture;

    private int $userId;
    private $registry;
    private array $json = [];
    private int $status = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createKenshoSchema();
        $this->userId = $this->signInForAi();
        $this->registry = new class {
            public int $calls = 0;
            public function structured(array $m, string $s, ?string $p = null): array { $this->calls++; return ['identity' => 'x', 'goals' => [], 'reflection_question' => '']; }
        };
    }

    private function model(): KenshoAiModel
    {
        return new class($this->container, $this->registry) extends KenshoAiModel {
            public function __construct($c, private $stub) { parent::__construct($c); }
            protected function getRegistry() { return $this->stub; }
        };
    }

    private function limit(string $key, string $value): void
    {
        $this->container['configModel']->save([$key => $value]);
        $this->container['memoryCache']->flush();
    }

    public function testTheGateNeedsAiConnectorsQuotaService(): void
    {
        $this->assertStringContainsString('AiQuota::class', file_get_contents(dirname(__DIR__) . '/Model/KenshoAiGate.php'));
    }

    public function testAnAllowedCallIsCountedUnderItsFeature(): void
    {
        $this->model()->clarifyGoals('Health', 'move more');
        $this->assertSame(1, $this->registry->calls);
        $row = $this->container['db']->table(AiQuota::TABLE)->findOne();
        $this->assertSame($this->userId, (int) $row['user_id']);
        $this->assertSame('kensho.clarify', $row['feature']);
    }

    public function testPastTheHourlyQuotaTheProviderIsNotCalled(): void
    {
        $this->limit(AiQuota::REQUESTS_PER_HOUR, '1');
        $model = $this->model();
        $model->proposeAreas(['dimensions' => []]);
        try {
            $model->reflect([], []);
            $this->fail('expected a refusal');
        } catch (KenshoAiRefused $e) {
            $this->assertStringContainsString('1', $e->getMessage());
        }
        $this->assertSame(1, $this->registry->calls);
    }

    public function testAnOverlongNoteIsRefusedBeforeTheProvider(): void
    {
        $this->limit(AiQuota::MAX_INPUT_CHARS, '2000');
        $this->expectException(KenshoAiRefused::class);
        try {
            $this->model()->clarifyGoals('Health', str_repeat('a', 5000));
        } finally {
            $this->assertSame(0, $this->registry->calls);
        }
    }

    public function testTheControllerAnswers429WithTheRefusal(): void
    {
        $model = new class($this->container) extends KenshoAiModel {
            public function clarifyGoals(string $label, string $note, ?string $profileId = null): array
            {
                throw new KenshoAiRefused('You have reached the AI limit.');
            }
        };
        $controller = new class($this->container, $model) extends KenshoController {
            public function __construct($c, private $m) { parent::__construct($c); }
            protected function isAiEnabled(): bool { return true; }
            protected function aiModel(): KenshoAiModel { return $this->m; }
        };
        $post = ['csrf_token' => $this->container['token']->getCSRFToken(), 'area_label' => 'Health', 'note' => 'x'];
        $this->container['request'] = new \Kanboard\Core\Http\Request($this->container, [], [], $post, [], []);
        $response = $this->getMockBuilder(\Kanboard\Core\Http\Response::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['json'])->getMock();
        $response->method('json')->willReturnCallback(function ($data, $status = 200) { $this->json = $data; $this->status = $status; });
        $this->container['response'] = $response;

        $controller->clarify();

        $this->assertSame(429, $this->status);
        $this->assertSame(['error' => 'You have reached the AI limit.'], $this->json);
    }

    public function testTheSchemaCountsTowardTheInputCap(): void
    {
        $spy = new class {
            public array $messages = []; public string $schema = '';
            public function structured(array $m, string $s, ?string $p = null): array { $this->messages = $m; $this->schema = $s; return []; }
        };
        $this->registry = $spy;
        $this->model()->clarifyGoals('Health', 'move more');
        $messagesOnly = array_sum(array_map(fn ($c) => mb_strlen($c), array_column($spy->messages, 'content')));
        $this->assertNotSame('', $spy->schema);

        $this->limit(AiQuota::MAX_INPUT_CHARS, (string) $messagesOnly);
        $this->expectException(KenshoAiRefused::class);
        $this->model()->clarifyGoals('Health', 'move more');
    }

}
