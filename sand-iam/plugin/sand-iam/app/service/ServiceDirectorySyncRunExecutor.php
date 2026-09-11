<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

final class ServiceDirectorySyncRunExecutor implements DirectorySyncRunExecutor
{
    /** @param null|\Closure(int,int,string,string):array{id:int,state:string,pulled:int,pushed:int,created:int,updated:int,missing:int,disabled:int,conflict:int} $runner */
    public function __construct(private readonly ?\Closure $runner = null) {}

    /** @return array{id:int,state:string,pulled:int,pushed:int,created:int,updated:int,missing:int,disabled:int,conflict:int} */
    public function run(int $connectorId, int $applicationId, string $actor, string $requestId): array
    {
        if ($this->runner !== null) {
            return ($this->runner)($connectorId, $applicationId, $actor, $requestId);
        }
        return (new SyncConnectorService())->run($connectorId, $applicationId, $actor, $requestId);
    }
}
