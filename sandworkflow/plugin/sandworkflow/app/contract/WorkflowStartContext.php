<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\contract;

use InvalidArgumentException;

final class WorkflowStartContext
{
    /**
     * @param array<string, array{account_space:string,account_id:string,snapshot?:array}> $participants
     * @param array<string, list<array{account_space:string,account_id:string,snapshot?:array}>> $participantChoices
     * @param array<string, list<array{account_space:string,account_id:string,snapshot?:array}>> $participantGroups
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $ownerScope,
        public readonly string $businessType,
        public readonly string $businessId,
        public readonly ActorRef $initiator,
        public readonly array $participants,
        public readonly string $requestId,
        public readonly array $participantChoices = [],
        public readonly array $participantGroups = [],
    ) {
        foreach ([$tenantId, $ownerScope, $businessType, $businessId] as $value) {
            if ($value === '' || strlen($value) > 64) {
                throw new InvalidArgumentException('流程作用域或业务标识无效');
            }
        }
        foreach ($participants as $key => $participant) {
            $this->assertParticipantKey((string) $key);
            new ActorRef((string) ($participant['account_space'] ?? ''), (string) ($participant['account_id'] ?? ''), (array) ($participant['snapshot'] ?? []));
        }
        foreach ($participantChoices as $key => $choices) {
            $this->assertParticipantKey((string) $key);
            if (array_key_exists($key, $participants)) {
                throw new InvalidArgumentException('参与人键不能同时用于单人绑定和发起人自选');
            }
            if (!is_array($choices) || $choices === [] || count($choices) > 25) {
                throw new InvalidArgumentException('发起人自选处理人数量必须为1至25人');
            }
            $seen = [];
            foreach ($choices as $choice) {
                if (!is_array($choice)) {
                    throw new InvalidArgumentException('发起人自选处理人格式无效');
                }
                $actor = new ActorRef(
                    (string) ($choice['account_space'] ?? ''),
                    (string) ($choice['account_id'] ?? ''),
                    (array) ($choice['snapshot'] ?? [])
                );
                $actorKey = $actor->accountSpace . ':' . $actor->accountId;
                if (isset($seen[$actorKey])) {
                    throw new InvalidArgumentException('发起人自选处理人不能重复');
                }
                $seen[$actorKey] = true;
            }
        }
        foreach ($participantGroups as $key => $actors) {
            $this->assertParticipantKey((string) $key);
            if (array_key_exists($key, $participants)
                || array_key_exists($key, $participantChoices)) {
                throw new InvalidArgumentException('参与人键不能同时用于多种绑定');
            }
            $this->assertActorGroup($actors);
        }
    }

    public function participant(string $key): ?ActorRef
    {
        $value = $this->participants[$key] ?? null;
        if (!is_array($value)) {
            return null;
        }
        return new ActorRef((string) $value['account_space'], (string) $value['account_id'], (array) ($value['snapshot'] ?? []));
    }

    /** @return list<ActorRef> */
    public function choice(string $key): array
    {
        $values = $this->participantChoices[$key] ?? null;
        if (!is_array($values)) {
            return [];
        }
        return array_map(
            static fn (array $value): ActorRef => new ActorRef(
                (string) $value['account_space'],
                (string) $value['account_id'],
                (array) ($value['snapshot'] ?? [])
            ),
            $values
        );
    }

    /** @return list<ActorRef> */
    public function group(string $key): array
    {
        $values = $this->participantGroups[$key] ?? null;
        if (!is_array($values)) {
            return [];
        }
        return array_map(
            static fn (array $value): ActorRef => new ActorRef(
                (string) $value['account_space'],
                (string) $value['account_id'],
                (array) ($value['snapshot'] ?? [])
            ),
            $values
        );
    }

    public function runtimeParticipants(): array
    {
        return array_merge(
            $this->participants,
            $this->participantChoices,
            $this->participantGroups
        );
    }

    private function assertParticipantKey(string $key): void
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $key)) {
            throw new InvalidArgumentException('参与人键格式无效');
        }
    }

    private function assertActorGroup(mixed $actors): void
    {
        if (!is_array($actors) || $actors === [] || count($actors) > 25) {
            throw new InvalidArgumentException('参与人组数量必须为1至25人');
        }
        $seen = [];
        foreach ($actors as $actorData) {
            if (!is_array($actorData)) {
                throw new InvalidArgumentException('参与人组格式无效');
            }
            $actor = new ActorRef(
                (string) ($actorData['account_space'] ?? ''),
                (string) ($actorData['account_id'] ?? ''),
                (array) ($actorData['snapshot'] ?? [])
            );
            $actorKey = $actor->accountSpace . ':' . $actor->accountId;
            if (isset($seen[$actorKey])) {
                throw new InvalidArgumentException('参与人组不能包含重复账户');
            }
            $seen[$actorKey] = true;
        }
    }
}
