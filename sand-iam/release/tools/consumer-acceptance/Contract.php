<?php

declare(strict_types=1);

/** Transport-free contract gate used by --mode=validate. */
final class ConsumerAcceptanceContract
{
    public const PLAN_SCHEMA = 'sand-iam.consumer-acceptance-plan/v1';
    public const REPORT_SCHEMA = 'sand-iam.consumer-acceptance-report/v1';
    public const PROFILE = 'consumer_l04';
    /** @var list<string> */
    public const STEPS = ['preflight', 'allow', 'deny', 'revoke', 'audit', 'cleanup'];
    /** @var list<string> */
    private const GATES = ['authorization_gate', 'fixture_ownership_gate', 'cleanup_gate'];
    /** @var list<string> */
    private const FIXTURE_KEYS = ['organization_id', 'application_id', 'identity_id', 'business_object_id'];

    /** @param array<mixed> $plan */
    public static function plan(array $plan): array
    {
        self::keys($plan, ['schema', 'profile', 'run_id', 'candidate', 'origins', 'gate_receipts', 'fixtures', 'fixture_scope_sha256', 'cleanup_manifest_sha256', 'live_credentials', 'steps'], 'plan');
        self::same($plan['schema'], self::PLAN_SCHEMA, 'plan.schema'); self::same($plan['profile'], self::PROFILE, 'plan.profile'); self::id($plan['run_id'], 'plan.run_id');
        self::candidate($plan['candidate'], 'plan.candidate');
        $origins = self::origins($plan['origins'], 'plan.origins');
        $fixtures = self::fixtures($plan['fixtures'], 'plan.fixtures');
        $scopeHash = self::scopeHash($plan['run_id'], $fixtures);
        $cleanupHash = self::cleanupHash($plan['run_id'], $scopeHash, $fixtures);
        if (!is_string($plan['fixture_scope_sha256']) || !hash_equals($scopeHash, $plan['fixture_scope_sha256'])) self::fail('plan.fixture_scope_sha256 is not bound to named fixtures');
        if (!is_string($plan['cleanup_manifest_sha256']) || !hash_equals($cleanupHash, $plan['cleanup_manifest_sha256'])) self::fail('plan.cleanup_manifest_sha256 is not bound to cleanup payload');
        self::receipts($plan['gate_receipts'], $plan['run_id'], $plan['candidate'], $scopeHash, $cleanupHash, 'plan.gate_receipts');
        $credentials = self::object($plan['live_credentials'], 'plan.live_credentials'); self::keys($credentials, ['sandiam', 'consumer'], 'plan.live_credentials');
        foreach (['sandiam', 'consumer'] as $name) {
            $binding = self::object($credentials[$name], 'plan.live_credentials.' . $name); self::keys($binding, ['secret_id', 'origin'], 'plan.live_credentials.' . $name);
            self::secretId($binding['secret_id'], 'plan.live_credentials.' . $name . '.secret_id'); self::origin($binding['origin'], 'plan.live_credentials.' . $name . '.origin');
            if (!hash_equals($origins[$name], $binding['origin'])) self::fail('credential cross-origin binding is forbidden');
        }
        if ($credentials['sandiam']['secret_id'] === $credentials['consumer']['secret_id']) self::fail('live credential IDs must be distinct');
        self::steps($plan['steps'], 'plan.steps', ['id']); return $plan;
    }

    /** @param array<mixed> $report */
    public static function report(array $report): array
    {
        self::keys($report, ['schema', 'mode', 'status', 'real_l04', 'generated_at', 'run_id', 'plan_sha256', 'candidate', 'gate_receipts', 'fixtures', 'fixture_scope_sha256', 'cleanup_manifest_sha256', 'steps', 'business_attempted', 'failure_stops_business', 'cleanup_boundary'], 'report');
        self::same($report['schema'], self::REPORT_SCHEMA, 'report.schema');
        if (!in_array($report['mode'], ['validate', 'live'], true) || !in_array($report['status'], ['validated', 'unsupported', 'failed'], true)) self::fail('report mode or status is invalid');
        if ($report['real_l04'] !== false) self::fail('report.real_l04 must be false until complete live execution computes it');
        self::utc($report['generated_at'], 'report.generated_at'); self::id($report['run_id'], 'report.run_id'); self::sha($report['plan_sha256'], 'report.plan_sha256');
        self::candidate($report['candidate'], 'report.candidate');
        $fixtures = self::fixtures($report['fixtures'], 'report.fixtures');
        $scopeHash = self::scopeHash($report['run_id'], $fixtures);
        $cleanupHash = self::cleanupHash($report['run_id'], $scopeHash, $fixtures);
        if (!is_string($report['fixture_scope_sha256']) || !hash_equals($scopeHash, $report['fixture_scope_sha256'])) self::fail('report.fixture_scope_sha256 is not bound to named fixtures');
        if (!is_string($report['cleanup_manifest_sha256']) || !hash_equals($cleanupHash, $report['cleanup_manifest_sha256'])) self::fail('report.cleanup_manifest_sha256 is not bound to cleanup payload');
        self::receipts($report['gate_receipts'], $report['run_id'], $report['candidate'], $scopeHash, $cleanupHash, 'report.gate_receipts');
        self::steps($report['steps'], 'report.steps', ['id', 'status']); foreach ($report['steps'] as $step) if (!in_array($step['status'], ['not_run', 'blocked'], true)) self::fail('report step status is invalid');
        if ($report['business_attempted'] !== false || $report['failure_stops_business'] !== true) self::fail('runner must stop before business operations');
        $cleanup = self::object($report['cleanup_boundary'], 'report.cleanup_boundary'); self::keys($cleanup, ['state', 'fixture_ids'], 'report.cleanup_boundary');
        if (!in_array($cleanup['state'], ['not_started', 'not_attempted'], true) || $cleanup['fixture_ids'] !== self::fixtureIds($fixtures)) self::fail('cleanup boundary is not bound to this-run fixtures');
        return $report;
    }

    /** @param array<string,string> $fixtures */
    public static function scopeHash(string $runId, array $fixtures): string
    {
        $fixtures = self::fixtures($fixtures, 'scope.fixtures');
        return hash('sha256', json_encode(['schema' => 'sand-iam.consumer-acceptance-scope/v1', 'run_id' => $runId, 'fixtures' => self::namedFixtures($fixtures)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @param array<string,string> $fixtures */
    public static function cleanupHash(string $runId, string $scopeHash, array $fixtures): string
    {
        self::sha($scopeHash, 'cleanup.fixture_scope_sha256');
        return hash('sha256', json_encode(['schema' => 'sand-iam.consumer-acceptance-cleanup/v1', 'run_id' => $runId, 'fixture_scope_sha256' => $scopeHash, 'step_id' => 'cleanup', 'fixture_ids' => self::fixtureIds(self::fixtures($fixtures, 'cleanup.fixtures'))], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private static function candidate(mixed $value, string $label): void
    {
        $candidate = self::object($value, $label); self::keys($candidate, ['candidate_sha256', 'host_sha256', 'consumer_tree_sha256'], $label);
        foreach ($candidate as $key => $hash) self::sha($hash, $label . '.' . $key);
    }

    private static function origins(mixed $value, string $label): array
    {
        $origins = self::object($value, $label); self::keys($origins, ['sandiam', 'consumer'], $label);
        foreach ($origins as $key => $origin) self::origin($origin, $label . '.' . $key);
        return $origins;
    }

    private static function fixtures(mixed $value, string $label): array
    {
        $fixtures = self::object($value, $label); self::keys($fixtures, self::FIXTURE_KEYS, $label);
        foreach ($fixtures as $key => $fixture) self::fixture($fixture, $label . '.' . $key);
        if (count(array_unique($fixtures, SORT_STRING)) !== 4) self::fail('fixtures must be distinct'); return $fixtures;
    }

    /** @param array<string,string> $fixtures @return array<string,string> */
    private static function namedFixtures(array $fixtures): array
    {
        $named = [];
        foreach (self::FIXTURE_KEYS as $key) $named[$key] = $fixtures[$key];
        return $named;
    }

    /** @param array<string,string> $fixtures @return list<string> */
    private static function fixtureIds(array $fixtures): array
    {
        return array_values(self::namedFixtures($fixtures));
    }

    private static function receipts(mixed $value, string $runId, array $candidate, string $scopeHash, string $cleanupHash, string $label): void
    {
        $receipts = self::object($value, $label); self::keys($receipts, self::GATES, $label);
        foreach (self::GATES as $gate) {
            $receipt = self::object($receipts[$gate], $label . '.' . $gate);
            self::keys($receipt, ['id', 'run_id', 'candidate_sha256', 'host_sha256', 'consumer_tree_sha256', 'fixture_scope_sha256', 'cleanup_manifest_sha256'], $label . '.' . $gate);
            self::id($receipt['id'], $label . '.' . $gate . '.id'); self::same($receipt['run_id'], $runId, $label . '.' . $gate . '.run_id');
            foreach (['candidate_sha256', 'host_sha256', 'consumer_tree_sha256'] as $field) if (!is_string($receipt[$field]) || !hash_equals($candidate[$field], $receipt[$field])) self::fail($label . '.' . $gate . ' has stale candidate binding');
            if (!is_string($receipt['fixture_scope_sha256']) || !hash_equals($scopeHash, $receipt['fixture_scope_sha256']) || !is_string($receipt['cleanup_manifest_sha256']) || !hash_equals($cleanupHash, $receipt['cleanup_manifest_sha256'])) self::fail($label . '.' . $gate . ' expands fixture or cleanup scope');
        }
    }

    private static function steps(mixed $steps, string $label, array $fields): void
    {
        if (!is_array($steps) || !array_is_list($steps) || count($steps) !== count(self::STEPS)) self::fail($label . ' must have fixed steps');
        foreach ($steps as $index => $step) { $step = self::object($step, $label . '[' . $index . ']'); self::keys($step, $fields, $label . '[' . $index . ']'); self::same($step['id'], self::STEPS[$index], $label . '[' . $index . '].id'); }
    }

    private static function origin(mixed $value, string $label): void
    {
        if (!is_string($value) || preg_match('/[\\\\%@?#\s]/', $value) === 1) self::fail($label . ' must be strict lowercase origin');
        $port = '(?::(?:[1-9][0-9]{0,3}|[1-5][0-9]{4}|6[0-4][0-9]{3}|65[0-4][0-9]{2}|655[0-2][0-9]|6553[0-5]))?';
        $pattern = '#^(?:https://[a-z0-9]+(?:[a-z0-9.-]*[a-z0-9])?' . $port . '|http://127\.0\.0\.1' . $port . ')$#D';
        if (preg_match($pattern, $value) !== 1) self::fail($label . ' must be HTTPS or exact 127.0.0.1 HTTP origin');
    }

    private static function object(mixed $value, string $label): array { if (!is_array($value) || array_is_list($value)) self::fail($label . ' must be object'); return $value; }
    private static function keys(array $value, array $expected, string $label): void { if (array_diff($expected, array_keys($value)) !== [] || array_diff(array_keys($value), $expected) !== []) self::fail($label . ' additional or missing property'); }
    private static function same(mixed $value, string $expected, string $label): void { if (!is_string($value) || $value !== $expected) self::fail($label . ' is invalid'); }
    private static function sha(mixed $value, string $label): void { if (!is_string($value) || preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) self::fail($label . ' must be lowercase SHA-256'); }
    private static function id(mixed $value, string $label): void { if (!is_string($value) || preg_match('/^[a-z][a-z0-9_-]{2,95}$/D', $value) !== 1) self::fail($label . ' is invalid'); }
    private static function fixture(mixed $value, string $label): void { if (!is_string($value) || preg_match('/^consumer_l04_[a-z0-9_]{3,96}$/D', $value) !== 1) self::fail($label . ' needs consumer_l04_ prefix'); }
    private static function secretId(mixed $value, string $label): void { if (!is_string($value) || preg_match('/^[A-Z][A-Z0-9_]{2,95}$/D', $value) !== 1) self::fail($label . ' is invalid'); }
    private static function utc(mixed $value, string $label): void { if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) !== 1) self::fail($label . ' is invalid'); $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC')); $errors = DateTimeImmutable::getLastErrors(); if (!$date instanceof DateTimeImmutable || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0)) || $date->format('Y-m-d\TH:i:s\Z') !== $value) self::fail($label . ' is invalid'); }
    private static function fail(string $message): never { throw new InvalidArgumentException($message); }
}
