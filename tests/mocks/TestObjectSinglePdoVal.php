<?php

namespace Kaly\Tests\Mocks;

use PDO;

class TestObjectSinglePdoVal
{
    public PDO $backupDb;

    public function __construct(
        PDO $backupDb,
    ) {
        $this->backupDb = $backupDb;
    }
}
