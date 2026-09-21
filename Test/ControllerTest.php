<?php

require_once 'tests/units/Base.php';

use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\Kensho\Controller\KenshoController;
use KanboardTests\units\Base;

require_once __DIR__ . '/KenshoSchemaHelper.php';

/**
 * Unit tests for KenshoController (Task 06).
 *
 * Covers:
 *  - AI-gated actions (propose/clarify/reflect) throw AccessForbiddenException
 *    when the gate is closed (isAiEnabled() overridden to false).
 *  - Every mutation rejects a bad CSRF token with AccessForbiddenException.
 *  - A valid-CSRF areas() call persists the posted area for user 1.
 *
 * No network calls: the gate-closed tests never reach a provider, and the
 * CSRF-rejection tests never get past checkCSRFForm().
 */
class ControllerTest extends Base
{
    use KenshoSchemaHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createKenshoSchema();
    }

    /** Controller with a forced AI-gate result and a logged-in user id. */
    private function controller(bool $aiEnabled): KenshoController
    {
        $c = new class($this->container, $aiEnabled) extends KenshoController {
            private $ai;
            public function __construct($container, $ai) { parent::__construct($container); $this->ai = $ai; }
            protected function isAiEnabled(): bool { return $this->ai; }
        };

        // Stub userSession → logged-in as user 1.
        $this->container['userSession'] = $this->createUserSessionStub(1);
        return $c;
    }

    private function createUserSessionStub(int $id)
    {
        return new class($id) {
            private $id;
            public function __construct($id) { $this->id = $id; }
            public function getId() { return $this->id; }
            public function isLogged() { return true; }
            public function isAdmin() { return true; }
        };
    }

    /**
     * Build a Request with the given POST body plus a csrf_token field.
     *
     * NOTE: Request's constructor signature (verified against
     * testing/kanboard-src/app/Core/Http/Request.php and
     * tests/units/Core/Http/RequestTest.php) is:
     *   __construct($container, $server = [], $get = [], $post = [], $files = [], $cookies = [])
     * so the POST body is the 4th positional argument, not the 5th.
     *
     * checkCSRFForm() (app/Controller/BaseController.php) validates via
     * $this->token->validateCSRFToken($this->request->getRawValue('csrf_token')),
     * i.e. the ONE-TIME csrf token pair (getCSRFToken() / validateCSRFToken()),
     * not the reusable pcsrf pair (getReusableCSRFToken() / validateReusableCSRFToken()).
     */
    private function withRequest(array $post = [], string $csrf = null)
    {
        $token = $this->container['token']->getCSRFToken();
        $post['csrf_token'] = $csrf ?? $token;
        $this->container['request'] = new \Kanboard\Core\Http\Request($this->container, [], [], $post, [], []);
    }

    public function testProposeForbiddenWhenGateClosed()
    {
        $this->expectException(AccessForbiddenException::class);
        $this->withRequest();
        $this->controller(false)->propose();
    }

    public function testClarifyForbiddenWhenGateClosed()
    {
        $this->expectException(AccessForbiddenException::class);
        $this->withRequest();
        $this->controller(false)->clarify();
    }

    public function testReflectForbiddenWhenGateClosed()
    {
        $this->expectException(AccessForbiddenException::class);
        $this->withRequest();
        $this->controller(false)->reflect();
    }

    public function testAreasRejectsBadCsrf()
    {
        $this->expectException(AccessForbiddenException::class);
        $this->withRequest(['areas' => '[]'], 'wrong-token');
        $this->controller(true)->areas();
    }

    public function testAnalyzeRejectsBadCsrf()
    {
        $this->expectException(AccessForbiddenException::class);
        $this->withRequest(['window_days' => '30'], 'wrong-token');
        $this->controller(true)->analyze();
    }

    public function testGoalsRejectsBadCsrf()
    {
        $this->expectException(AccessForbiddenException::class);
        $this->withRequest(['goals' => '[]'], 'wrong-token');
        $this->controller(true)->goals();
    }

    public function testReflectionRejectsBadCsrf()
    {
        $this->expectException(AccessForbiddenException::class);
        $this->withRequest(['narrative' => 'x'], 'wrong-token');
        $this->controller(true)->reflection();
    }

    public function testProposeRejectsBadCsrfWhenGateOpen()
    {
        $this->expectException(AccessForbiddenException::class);
        $this->withRequest([], 'wrong-token');
        $this->controller(true)->propose();
    }

    public function testClarifyRejectsBadCsrfWhenGateOpen()
    {
        $this->expectException(AccessForbiddenException::class);
        $this->withRequest([], 'wrong-token');
        $this->controller(true)->clarify();
    }

    public function testReflectRejectsBadCsrfWhenGateOpen()
    {
        $this->expectException(AccessForbiddenException::class);
        $this->withRequest([], 'wrong-token');
        $this->controller(true)->reflect();
    }

    public function testAreasSavesWithValidCsrf()
    {
        $this->withRequest([
            'areas' => json_encode([
                ['label' => 'Health', 'importance' => 9, 'sources' => ['project_ids' => [1], 'category_ids' => [], 'tag_ids' => []]],
            ]),
        ]);

        // areas() ends with flash->success() + response->redirect(), both of which
        // touch PHP session / header() machinery that is not valid in CLI test
        // context. Mirror SubtaskGenerator's CreateSubtaskTest pattern: replace
        // response/flash with mocks so those tail calls are no-ops, and assert the
        // real side effect (persistence) via the model instead of the response.
        $this->container['response'] = $this
            ->getMockBuilder(\Kanboard\Core\Http\Response::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['redirect', 'json', 'html'])
            ->getMock();
        $this->container['flash'] = $this
            ->getMockBuilder(\Kanboard\Core\Session\FlashMessage::class)
            ->setConstructorArgs([$this->container])
            ->onlyMethods(['success', 'failure'])
            ->getMock();

        // Should not throw; area persists for user 1.
        try {
            $this->controller(true)->areas();
        } catch (\Kanboard\Core\Controller\AccessForbiddenException $e) {
            $this->fail('Valid CSRF should not be rejected');
        }
        $profile = (new \Kanboard\Plugin\Kensho\Model\KenshoProfileModel($this->container))->getProfile(1);
        $this->assertCount(1, $profile['areas']);
        $this->assertEquals('Health', $profile['areas'][0]['label']);
    }
}
