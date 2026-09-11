<?php

declare(strict_types=1);

namespace plugin\SandIam\app\sync;

interface SyncDriverInterface
{
    /** @return array{inbound:bool,outbound:bool} */
    public static function capabilities(): array;

    /** @param array<string,mixed> $config @return array{records:list<array<string,mixed>>,next_cursor:?string,has_more:bool,full_snapshot?:bool} */
    public static function pullPage(array $config, ?string $cursor, int $limit): array;

    /** @param array<string,mixed> $config @param list<array<string,mixed>> $events @return list<string> accepted event IDs */
    public static function pushBatch(array $config, array $events): array;

    /** @param array<string,mixed> $config */
    public static function test(array $config): void;
}
