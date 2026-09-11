<?php

declare(strict_types=1);

namespace plugin\SandIam\app\security;

use plugin\sandadmin\exception\ApiException;

/** Resolves and pins every public A/AAAA address for an outbound HTTPS host. */
final class PublicDnsResolver
{
    /** @var (\Closure(string):array<int, array<string, mixed>>)|null */
    private readonly ?\Closure $lookup;

    /** @param (callable(string):array<int, array<string, mixed>>)|null $lookup */
    public function __construct(?callable $lookup = null)
    {
        $this->lookup = $lookup === null ? null : \Closure::fromCallable($lookup);
    }

    /** @return list<string> */
    public function resolve(string $host, string $unavailableCode, string $rejectedCode): array
    {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $addresses = [$host];
        } else {
            if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                throw new ApiException($rejectedCode, 400);
            }
            $records = $this->lookup === null ? $this->lookup($host) : ($this->lookup)($host);
            $addresses = [];
            foreach ($records as $record) {
                if (!is_array($record)) continue;
                foreach (['ip', 'ipv6'] as $field) {
                    $address = $record[$field] ?? null;
                    if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false) {
                        $addresses[] = $address;
                    }
                }
            }
        }
        $addresses = array_values(array_unique($addresses));
        if ($addresses === []) throw new ApiException($unavailableCode, 503);
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new ApiException($rejectedCode, 400);
            }
        }
        return $addresses;
    }

    public static function curlResolveEntry(string $host, int $port, string $address): string
    {
        $pinnedAddress = str_contains($address, ':') ? '[' . $address . ']' : $address;
        return $host . ':' . $port . ':' . $pinnedAddress;
    }

    /** @return array<int, array<string, mixed>> */
    private function lookup(string $host): array
    {
        $records = [];
        foreach ([DNS_A, DNS_AAAA] as $type) {
            $resolved = dns_get_record($host, $type);
            if (is_array($resolved)) $records = [...$records, ...$resolved];
        }
        return $records;
    }
}
