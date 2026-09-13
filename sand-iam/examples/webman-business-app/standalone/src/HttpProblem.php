<?php

declare(strict_types=1);

namespace Example\Standalone;

use RuntimeException;

final class HttpProblem extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $problemCode)
    {
        parent::__construct($problemCode);
    }
}
