<?php

declare(strict_types=1);

namespace plugin\SandIam\app\federation;

interface LdapDirectoryAdapter
{
    /**
     * @param array<string,mixed> $config
     * @return array{entries:list<array<string,mixed>>,cursor:?string,complete:bool}
     */
    public function page(array $config, ?string $cursor): array;
}
