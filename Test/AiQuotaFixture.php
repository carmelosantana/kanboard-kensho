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
        require_once dirname(__DIR__, 2) . "/AiConnector/Schema/$driver.php";
        \Kanboard\Plugin\AiConnector\Schema\version_1($this->container['db']->getConnection());

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
