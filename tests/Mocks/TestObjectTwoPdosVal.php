<?php

namespace Kaly\Tests\Mocks;

use PDO;

class TestObjectTwoPdosVal implements TestObjectTwoPdosInterface
{
    public PDO $db;
    public PDO $backupDb;

    public function __construct(
        PDO $db,
        PDO $backupDb,
    ) {
        $this->db = $db;
        $this->backupDb = $backupDb;
    }
}
