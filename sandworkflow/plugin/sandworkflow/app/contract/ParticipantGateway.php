<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\contract;

interface ParticipantGateway
{
    /** @return list<ActorRef> */
    public function resolve(array $rules, ActorRef $initiator): array;

    /** @return list<array{account_space:string,account_id:string,name:string}> */
    public function search(string $accountSpace, string $keyword, int $limit = 20): array;
}
