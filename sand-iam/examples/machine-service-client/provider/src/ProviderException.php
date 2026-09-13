<?php
declare(strict_types=1);
namespace Sand\Iam\Example\ProviderB;
final class ProviderException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $httpStatus)
    {
        parent::__construct($errorCode);
    }
}
