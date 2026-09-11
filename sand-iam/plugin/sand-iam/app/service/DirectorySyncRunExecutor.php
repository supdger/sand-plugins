<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

interface DirectorySyncRunExecutor
{
    /** @return array{id:int,state:string,pulled:int,pushed:int,created:int,updated:int,missing:int,disabled:int,conflict:int} */
    public function run(int $connectorId, int $applicationId, string $actor, string $requestId): array;
}
