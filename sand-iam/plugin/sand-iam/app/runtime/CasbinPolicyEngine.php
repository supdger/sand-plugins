<?php

declare(strict_types=1);

namespace plugin\SandIam\app\runtime;

use Casbin\Enforcer;
use Casbin\Model\Model;
use Composer\InstalledVersions;

/** Casbin is the sole subject/role/action/condition/priority/effect authority. */
final class CasbinPolicyEngine
{
    public const VERSION = '4.5.0';
    private readonly string $model;

    public function __construct(
        private readonly ScopeMatcher $conditions = new ScopeMatcher(),
        private readonly CasbinConditionMatcher $conditionMatcher = new CasbinConditionMatcher(),
    )
    {
        if (!class_exists(Enforcer::class) || !InstalledVersions::isInstalled('casbin/casbin') || InstalledVersions::getVersion('casbin/casbin') !== self::VERSION . '.0') {
            throw new \RuntimeException('SAND_IAM_CASBIN_UNAVAILABLE');
        }
        $this->model = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/casbin/application-policy.conf');
        if ($this->model === '') throw new \RuntimeException('SAND_IAM_CASBIN_MODEL_UNAVAILABLE');
    }

    /** @return array<string,mixed> */
    public function decide(PublishedPolicyAdapter $adapter, int $applicationId, int $identityId, int $resourceId, string $action, array $attributes, bool $diagnose = false): array
    {
        $request = ['identity:' . $identityId, 'app:' . $applicationId, 'resource:' . $resourceId, $action, $attributes];
        $enforcer = $this->enforcer($adapter);
        [$allowed, $explanation] = $enforcer->enforceEx(...$request);
        $snapshot = $this->explainedSnapshot($adapter, $explanation);
        if ($allowed && $snapshot === null) throw new \RuntimeException('SAND_IAM_CASBIN_EXPLANATION_INVALID');
        $scopeValid = true;
        if ($allowed) {
            try { $this->conditions->assertValid($snapshot['scope'], 'policy scope'); }
            catch (\Throwable) { $allowed = false; $scopeValid = false; }
        }
        $rules = [];
        if ($diagnose) {
            foreach ($adapter->policies() as $rule) {
                // Target filtering controls which rows to display, not matching.
                if ($rule[2] !== $request[1] || $rule[3] !== $request[2] || $rule[4] !== $action) continue;
                $single = new Model(); $single->loadModelFromText($this->model);
                $adapter->loadSingle($single, $rule);
                $diagnostic = $this->configure(new Enforcer($single));
                [, $matched] = $diagnostic->enforceEx(...$request);
                if ($matched !== [] && $matched !== $rule) throw new \RuntimeException('SAND_IAM_CASBIN_EXPLANATION_INVALID');
                $s = $adapter->snapshot((int) $rule[7]);
                $keys = array_merge(array_keys($s['condition']['equals'] ?? []), array_keys($s['condition']['in'] ?? []));
                $rules[] = ['policy_id' => $s['id'], 'published_version_id' => $s['published_version_id'], 'effect' => $s['effect'], 'priority' => $s['priority'], 'condition' => $this->redact($s['condition']), 'condition_matched' => $matched !== [], 'subject_source' => $adapter->subjectSource($s), 'scope_source' => 'published_version_snapshot', 'data_scope' => $this->redact($s['scope']), 'missing_context' => array_values(array_filter($keys, static fn (string $key): bool => !array_key_exists($key, $attributes)))];
            }
            usort($rules, static fn (array $a, array $b): int => [$a['priority'], $a['policy_id']] <=> [$b['priority'], $b['policy_id']]);
        }
        return ['allowed' => $allowed, 'code' => $allowed ? 'allowed' : 'SAND_IAM_POLICY_DENIED', 'policy_ids' => $snapshot === null ? [] : [$snapshot['id']], 'scope' => $allowed ? $snapshot['scope'] : [], 'published_version_ids' => $snapshot === null ? [] : [$snapshot['published_version_id']], 'subject_source' => $snapshot === null ? [] : $adapter->subjectSource($snapshot), 'effective_role_sources' => $adapter->sources(), 'matched_rules' => $rules, 'missing_context' => array_values(array_unique(array_merge([], ...array_column($rules, 'missing_context')))), 'final_reason' => $allowed ? '命中已发布允许规则' : (!$scopeValid ? '决定性规则的数据范围无效' : ($snapshot !== null && $snapshot['effect'] === 'deny' ? '同优先级拒绝规则优先' : '未命中有效允许规则')), 'engine' => 'casbin', 'engine_version' => self::VERSION, 'model_hash' => hash('sha256', $this->model), 'snapshot_set_hash' => $adapter->hash()];
    }

    private function enforcer(PublishedPolicyAdapter $adapter): Enforcer
    {
        $model = new Model(); $model->loadModelFromText($this->model);
        return $this->configure(new Enforcer($model, $adapter));
    }
    private function configure(Enforcer $enforcer): Enforcer
    {
        $enforcer->enableAutoSave(false);
        $enforcer->buildRoleLinks();
        // This bounded pure function knows only the existing condition DSL.
        // It cannot query identity/roles, select priority or return a decision.
        $enforcer->addFunction('iamCondition', function (mixed $attributes, mixed $condition): bool {
            if (!is_array($attributes) || !is_string($condition)) return false;
            return $this->conditionMatcher->matches($attributes, $condition);
        });
        return $enforcer;
    }
    private function explainedSnapshot(PublishedPolicyAdapter $adapter, array $explanation): ?array
    {
        if ($explanation === []) return null;
        if (!in_array($explanation, $adapter->policies(), true)) throw new \RuntimeException('SAND_IAM_CASBIN_EXPLANATION_INVALID');
        $snapshot = $adapter->snapshot((int) ($explanation[7] ?? 0));
        if ($snapshot === null || (string) $snapshot['published_version_id'] !== ($explanation[8] ?? null)) throw new \RuntimeException('SAND_IAM_CASBIN_EXPLANATION_INVALID');
        return $snapshot;
    }
    private function redact(array $value): array
    {
        foreach ($value as $key => $item) {
            if (preg_match('/(?:secret|token|password|credential|key)/i', (string) $key) === 1) $value[$key] = '[已脱敏]';
            elseif (is_array($item)) $value[$key] = $this->redact($item);
        }
        return $value;
    }
}
