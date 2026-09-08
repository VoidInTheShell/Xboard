<?php

namespace App\Exceptions;

use RuntimeException;

class ChangeVersionConflictException extends RuntimeException
{
    public function __construct(public readonly int $expectedVersion, public readonly int $currentVersion)
    {
        parent::__construct("管理数据版本已从 {$expectedVersion} 变为 {$currentVersion}，请重新读取后再提交。");
    }
}
