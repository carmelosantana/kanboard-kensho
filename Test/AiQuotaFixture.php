<?php

/**
 * AiConnector's request table plus a signed-in user, so tests that reach an AI
 * call pass through the real AiQuota check. Not named *Test.php on purpose.
 */
trait AiQuotaFixture
{
    private function signInForAi(): int
    {
        $driver = ['sqlite' => 'Sqlite', 'mysql' => 'Mysql', 'postgres' => 'Postgres'][DB_DRIVER];
        // Beside the loaded AiConnector, so a worktree checkout of Kensho still finds it.
        $root = class_exists(\Kanboard\Plugin\AiConnector\Model\AiQuota::class)
            ? dirname((new \ReflectionClass(\Kanboard\Plugin\AiConnector\Model\AiQuota::class))->getFileName(), 2)
            : dirname(__DIR__, 2) . '/AiConnector';
        $schema = "$root/Schema/$driver.php";
        if (! is_file($schema)) {
            // The real-DB runner mounts only the plugin under test; AiQuota itself is covered by AiConnector's own runs.
            $this->markTestSkipped('AiConnector is not mounted next to Kensho.');
        }
        require_once $schema;
        \Kanboard\Plugin\AiConnector\Schema\version_1($this->container['db']->getConnection());
        \Kanboard\Plugin\AiConnector\Schema\version_2($this->container['db']->getConnection());

        $id = (int) $this->container['userModel']->create(['username' => 'ai-user-' . bin2hex(random_bytes(3))]);
        $this->container['userSession'] = new class($id) {
            public function __construct(private int $id) {}
            public function getId() { return $this->id; }
            public function isLogged() { return true; }
            public function isAdmin() { return false; }
        };
        return $id;
    }
}
