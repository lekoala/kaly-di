<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

use PDO;

class TestObjectSinglePdoVal
{
    public PDO $backupDb;

    public function __construct(PDO $backupDb)
    {
        $this->backupDb = $backupDb;
    }
}
