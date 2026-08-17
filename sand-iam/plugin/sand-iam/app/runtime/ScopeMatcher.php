<?php

declare(strict_types=1);

namespace plugin\SandIam\app\runtime;

use plugin\sandadmin\exception\ApiException;

final class ScopeMatcher
{
    /** @param array<string, mixed> $clause */
    public function assertValid(array $clause, string $field): void
    {
        foreach ($clause as $operator => $constraints) {
            if (!in_array($operator, ['equals', 'in'], true) || !is_array($constraints)) {
                throw new ApiException('SAND_IAM_POLICY_DENIED: invalid ' . $field, 403);
            }
            foreach ($constraints as $attribute => $expected) {
                if (!is_string($attribute) || $attribute === '' || str_contains($attribute, '.')) {
                    throw new ApiException('SAND_IAM_POLICY_DENIED: invalid attribute constraint', 403);
                }
                if ($operator === 'equals' && !$this->scalarOrNull($expected)) {
                    throw new ApiException('SAND_IAM_POLICY_DENIED: invalid equals value', 403);
                }
                if ($operator === 'in' && (!is_array($expected) || $expected === [] || array_filter($expected, fn (mixed $value): bool => !$this->scalarOrNull($value)) !== [])) {
                    throw new ApiException('SAND_IAM_POLICY_DENIED: invalid in value', 403);
                }
            }
        }
    }

    /** @param array<string, mixed> $clause @param array<string, mixed> $attributes */
    public function matches(array $clause, array $attributes): bool
    {
        try {
            $this->assertValid($clause, 'policy clause');
        } catch (ApiException) {
            return false;
        }
        foreach (($clause['equals'] ?? []) as $attribute => $expected) {
            if (!array_key_exists($attribute, $attributes) || $attributes[$attribute] !== $expected) {
                return false;
            }
        }
        foreach (($clause['in'] ?? []) as $attribute => $expected) {
            if (!array_key_exists($attribute, $attributes) || !in_array($attributes[$attribute], $expected, true)) {
                return false;
            }
        }
        return true;
    }

    private function scalarOrNull(mixed $value): bool
    {
        return is_string($value) || is_int($value) || is_bool($value) || $value === null;
    }
}
