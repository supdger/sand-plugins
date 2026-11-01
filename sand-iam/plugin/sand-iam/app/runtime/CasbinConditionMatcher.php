<?php

declare(strict_types=1);

namespace plugin\SandIam\app\runtime;

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/** JSONB/presence bridge only. Comparisons run in Casbin's official expression library. */
final class CasbinConditionMatcher
{
    public function __construct(private readonly ExpressionLanguage $expressions = new ExpressionLanguage()) {}

    public function matches(array $attributes, string $json): bool
    {
        $condition = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($condition)) return false;
        (new ScopeMatcher())->assertValid($condition, 'policy condition');
        foreach ($condition as $operator => $constraints) {
            foreach ($constraints as $key => $expected) {
                if (!array_key_exists($key, $attributes)) return false;
                // Fixed expressions only; administrators never provide code.
                $expression = $operator === 'equals' ? 'value === expected' : 'value in expected';
                if ($this->expressions->evaluate($expression, ['value' => $attributes[$key], 'expected' => $expected]) !== true) return false;
            }
        }
        return true;
    }
}
