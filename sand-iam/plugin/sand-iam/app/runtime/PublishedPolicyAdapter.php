<?php

declare(strict_types=1);

namespace plugin\SandIam\app\runtime;

use Casbin\Model\Model;
use Casbin\Persist\Adapter;

/** Immutable request-local facts translated into Casbin p/g, never matched here. */
final class PublishedPolicyAdapter implements Adapter
{
    /** @var list<list<string>> */
    private array $policies = [];
    /** @var list<list<string>> */
    private array $links = [];
    /** @var array<int,array<string,mixed>> */
    private array $snapshots = [];
    /** @var array<int,list<string>> */
    private array $sources = [];

    /** @param array<string,mixed> $facts */
    public function __construct(private readonly array $facts)
    {
        $app = $facts['application_id']; $identity = $facts['identity_id'];
        $rows = [];
        foreach ($facts['policies'] as $policy) {
            $id = (int) $policy['id']; $vid = (int) $policy['published_version_id'];
            if ($id <= 0 || $vid <= 0 || (int) ($policy['version_id'] ?? 0) !== $vid || (int) ($policy['version_policy_id'] ?? 0) !== $id || (int) ($policy['version_application_id'] ?? 0) !== $app || isset($this->snapshots[$id])) {
                throw new \RuntimeException('SAND_IAM_POLICY_MAPPING_INVALID: policy=' . $id . ' version=' . $vid);
            }
            $snapshot = $policy['snapshot'];
            try { self::assertSnapshot($snapshot, $app); }
            catch (\Throwable $exception) { throw new \RuntimeException('SAND_IAM_POLICY_MAPPING_INVALID: policy=' . $id . ' version=' . $vid, 0, $exception); }
            $type = $snapshot['identity_id'] > 0 ? 'identity' : 'role';
            $subjectId = $snapshot[$type . '_id'];
            if (!in_array($subjectId, $facts['references'][$type], true) || !in_array($snapshot['resource_id'], $facts['references']['resource'], true)) {
                throw new \RuntimeException('SAND_IAM_POLICY_MAPPING_INVALID: cross-application reference policy=' . $id . ' version=' . $vid);
            }
            $rows[] = ['id' => $id, 'vid' => $vid, 'snapshot' => $snapshot, 'subject' => $type . ':' . $subjectId];
        }
        usort($rows, static fn (array $a, array $b): int => [$a['snapshot']['priority'], $a['snapshot']['effect'] === 'deny' ? 0 : 1, $a['id']] <=> [$b['snapshot']['priority'], $b['snapshot']['effect'] === 'deny' ? 0 : 1, $b['id']]);
        foreach ($rows as $rank => $row) {
            $s = $row['snapshot'];
            $this->policies[] = [(string) ($rank + 1), $row['subject'], 'app:' . $app, 'resource:' . $s['resource_id'], $s['action'], json_encode($s['condition'], JSON_THROW_ON_ERROR), $s['effect'], (string) $row['id'], (string) $row['vid']];
            $this->snapshots[$row['id']] = array_merge($s, ['id' => $row['id'], 'published_version_id' => $row['vid']]);
        }
        foreach ($facts['direct_roles'] as $binding) {
            $role = (int) $binding['role_id'];
            $this->links[] = ['identity:' . $identity, 'role:' . $role, 'app:' . $app];
            $this->source($role, 'direct_identity_role');
        }
        foreach ($facts['group_roles'] as $binding) {
            $role = (int) $binding['role_id']; $group = (int) $binding['group_id'];
            $this->links[] = ['identity:' . $identity, 'group:' . $group, 'app:' . $app];
            $this->links[] = ['group:' . $group, 'role:' . $role, 'app:' . $app];
            $this->source($role, 'identity_group:' . $group);
        }
        $this->links = array_values(array_unique($this->links, SORT_REGULAR));
        sort($this->links); ksort($this->sources);
    }

    /** Shared publish/runtime compiler: no request attributes or decision. */
    public static function assertSnapshot(mixed $snapshot, int $applicationId): void
    {
        if (!is_array($snapshot) || ($snapshot['application_id'] ?? null) !== $applicationId || !is_int($snapshot['resource_id'] ?? null) || $snapshot['resource_id'] <= 0 || !is_int($snapshot['priority'] ?? null) || !is_string($snapshot['action'] ?? null) || $snapshot['action'] === '' || !in_array($snapshot['effect'] ?? null, ['allow', 'deny'], true) || !is_array($snapshot['condition'] ?? null) || !is_array($snapshot['scope'] ?? null)) {
            throw new \RuntimeException('SAND_IAM_POLICY_MAPPING_INVALID: snapshot shape');
        }
        if (array_intersect(array_keys($snapshot), ['id', 'published_version_id', 'policy_id', 'version_id', 'version_policy_id', 'version_application_id']) !== []) {
            throw new \RuntimeException('SAND_IAM_POLICY_MAPPING_INVALID: reserved version metadata');
        }
        foreach (['identity_id', 'role_id'] as $key) {
            if (!array_key_exists($key, $snapshot) || (!is_int($snapshot[$key]) && $snapshot[$key] !== null) || ($snapshot[$key] ?? 0) < 0) throw new \RuntimeException('SAND_IAM_POLICY_MAPPING_INVALID: subject shape');
        }
        if ((($snapshot['identity_id'] ?? 0) > 0) === (($snapshot['role_id'] ?? 0) > 0)) throw new \RuntimeException('SAND_IAM_POLICY_MAPPING_INVALID: exactly one subject required');
        (new ScopeMatcher())->assertValid($snapshot['condition'], 'policy condition');
    }

    public function loadPolicy(Model $model): void
    {
        foreach ($this->policies as $rule) $model->addPolicy('p', 'p', $rule);
        foreach ($this->links as $link) $model->addPolicy('g', 'g', $link);
    }

    /** Create an independent same-model single-p diagnostic, retaining original effect. */
    public function loadSingle(Model $model, array $rule): void
    {
        $model->addPolicy('p', 'p', $rule);
        foreach ($this->links as $link) $model->addPolicy('g', 'g', $link);
    }
    public function policies(): array { return $this->policies; }
    public function snapshot(int $id): ?array { return $this->snapshots[$id] ?? null; }
    public function sources(): array { return $this->sources; }
    public function subjectSource(array $snapshot): array { return ($snapshot['identity_id'] ?? 0) === $this->facts['identity_id'] ? ['direct_identity'] : ($this->sources[$snapshot['role_id'] ?? 0] ?? []); }
    public function hash(): string { return hash('sha256', json_encode([$this->policies, $this->links, $this->snapshots], JSON_THROW_ON_ERROR)); }
    private function source(int $role, string $source): void { $this->sources[$role] ??= []; if (!in_array($source, $this->sources[$role], true)) $this->sources[$role][] = $source; }
    public function savePolicy(Model $model): void { $this->rejectWrite(); }
    public function addPolicy(string $sec, string $ptype, array $rule): void { $this->rejectWrite(); }
    public function removePolicy(string $sec, string $ptype, array $rule): void { $this->rejectWrite(); }
    public function removeFilteredPolicy(string $sec, string $ptype, int $fieldIndex, string ...$fieldValues): void { $this->rejectWrite(); }
    private function rejectWrite(): never { throw new \LogicException('SAND_IAM_POLICY_ADAPTER_READ_ONLY'); }
}
