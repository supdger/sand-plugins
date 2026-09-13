<?php

declare(strict_types=1);

require_once __DIR__ . '/ActionRegistry.php';
require_once __DIR__ . '/ReceiptVerifier.php';

/** Offline authorization and preflight contract for the future v2 live runner. */
final class ConsumerAcceptanceV2LiveContract
{
    private const REPORT_STEPS = ['preflight', 'allow', 'deny', 'revoke', 'audit', 'cleanup'];

    /** @param array<string,mixed> $plan @param list<array<string,mixed>> $receipts */
    public static function authorize(array $plan, array $receipts, string $trustedKeyFile, DateTimeImmutable $now, ?array $observedCapability = null, ?string $capabilityKeyFile = null): array
    {
        self::plan($plan);
        $base = self::receiptBase($plan);
        if ($observedCapability === null || $capabilityKeyFile === null || !self::observedCapability($observedCapability, $plan, $capabilityKeyFile, $now)) return self::report($plan, 'preflight_blocked');
        if (count($receipts) !== 3) throw new InvalidArgumentException('exactly three independent receipts are required');
        $seen = [];
        foreach ($receipts as $receipt) {
            self::receipt($receipt, $base);
            ConsumerAcceptanceV2ReceiptVerifier::verify($receipt, $trustedKeyFile, $now);
            $seen[$receipt['type']] = true;
        }
        ksort($seen, SORT_STRING);
        if (array_keys($seen) !== ['authorization_gate', 'cleanup_gate', 'fixture_ownership_gate']) throw new InvalidArgumentException('receipt types are incomplete or duplicated');
        return self::report($plan, 'authorized_offline');
    }

    /** @param array<string,mixed> $plan */
    private static function plan(array $plan): void
    {
        self::keys($plan, ['schema', 'run_id', 'candidate', 'origins', 'database', 'scope_cleanup', 'fixture_manifest_sha256', 'actions', 'secret_slots'], 'plan');
        self::same($plan['schema'], 'sand-iam.consumer-acceptance-v2-plan/v1', 'plan.schema'); self::id($plan['run_id'], 'plan.run_id');
        self::candidate($plan['candidate'], 'plan.candidate'); self::origins($plan['origins'], 'plan.origins');
        $database = self::obj($plan['database'], 'plan.database'); self::keys($database, ['iam', 'a', 'b'], 'plan.database'); foreach ($database as $name => $binding) { $binding=self::obj($binding,'plan.database.'.$name); self::keys($binding,['role','schema_fingerprint_sha256'],'plan.database.'.$name); self::id($binding['role'],'plan.database.'.$name.'.role'); self::sha($binding['schema_fingerprint_sha256'],'plan.database.'.$name.'.schema_fingerprint_sha256'); }
        $scope = self::obj($plan['scope_cleanup'], 'plan.scope_cleanup'); self::keys($scope, ['scope_sha256', 'cleanup_sha256'], 'plan.scope_cleanup'); self::sha($scope['scope_sha256'], 'plan.scope_cleanup.scope_sha256'); self::sha($scope['cleanup_sha256'], 'plan.scope_cleanup.cleanup_sha256');
        self::sha($plan['fixture_manifest_sha256'], 'plan.fixture_manifest_sha256');
        if ($plan['actions'] !== ConsumerAcceptanceV2ActionRegistry::steps()) throw new InvalidArgumentException('plan actions must equal fixed registry IDs');
        $slots = self::obj($plan['secret_slots'], 'plan.secret_slots'); self::keys($slots, ['iam_admin', 'a_user', 'b_workload'], 'plan.secret_slots');
        $expectedSlots = ['iam_admin' => 'SAND_IAM_ADMIN_TOKEN', 'a_user' => 'CONSUMER_A_USER_TOKEN', 'b_workload' => 'PROVIDER_B_WORKLOAD_CREDENTIAL'];
        if (!hash_equals(ConsumerAcceptanceV2ReceiptVerifier::canonical($expectedSlots), ConsumerAcceptanceV2ReceiptVerifier::canonical($slots))) throw new InvalidArgumentException('secret slots must use fixed names and purposes');
    }

    /** @param array<string,mixed> $receipt @param array<string,mixed> $base */
    private static function receipt(array $receipt, array $base): void
    {
        self::keys($receipt, ['schema', 'type', 'algorithm', 'key_id', 'public_key_sha256', 'issued_at', 'expires_at', 'run_id', 'plan_sha256', 'candidate', 'origins', 'database', 'scope_cleanup', 'fixture_manifest_sha256', 'action_registry_sha256', 'authorizer', 'signature'], 'receipt');
        self::same($receipt['schema'], 'sand-iam.consumer-acceptance-v2-receipt/v1', 'receipt.schema');
        if (!in_array($receipt['type'], ['authorization_gate', 'fixture_ownership_gate', 'cleanup_gate'], true)) throw new InvalidArgumentException('receipt type is invalid');
        foreach (['run_id', 'plan_sha256', 'fixture_manifest_sha256', 'action_registry_sha256'] as $field) if (!is_string($receipt[$field]) || !hash_equals((string) $base[$field], $receipt[$field])) throw new InvalidArgumentException('receipt subject binding is inconsistent');
        foreach (['candidate', 'origins', 'database', 'scope_cleanup'] as $field) {
            if (!is_array($receipt[$field]) || !is_array($base[$field]) || !hash_equals(ConsumerAcceptanceV2ReceiptVerifier::canonical($base[$field]), ConsumerAcceptanceV2ReceiptVerifier::canonical($receipt[$field]))) throw new InvalidArgumentException('receipt range binding is inconsistent');
        }
        self::id($receipt['authorizer'], 'receipt.authorizer');
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    private static function receiptBase(array $plan): array
    {
        return ['run_id' => $plan['run_id'], 'plan_sha256' => hash('sha256', ConsumerAcceptanceV2ReceiptVerifier::canonical($plan)), 'candidate' => $plan['candidate'], 'origins' => $plan['origins'], 'database' => $plan['database'], 'scope_cleanup' => $plan['scope_cleanup'], 'fixture_manifest_sha256' => $plan['fixture_manifest_sha256'], 'action_registry_sha256' => ConsumerAcceptanceV2ActionRegistry::manifestHash()];
    }

    /** @param array<string,mixed> $observed @param array<string,mixed> $plan */
    private static function observedCapability(array $observed, array $plan, string $trustedKeyFile, DateTimeImmutable $now): bool
    {
        self::keys($observed, ['schema', 'algorithm', 'key_id', 'public_key_sha256', 'issued_at', 'expires_at', 'host_revision_sha256', 'plan_sha256', 'capability', 'version', 'enabled', 'scope_sha256', 'signature'], 'observed capability');
        if (($observed['schema'] ?? null) !== 'sand-iam.consumer-acceptance-v2-capability/v1' || ($observed['capability'] ?? null) !== 'consumer_l04_cleanup_v1' || ($observed['version'] ?? null) !== 'v1' || ($observed['enabled'] ?? null) !== true || !is_string($observed['host_revision_sha256'] ?? null) || !is_string($observed['plan_sha256'] ?? null) || !is_string($observed['scope_sha256'] ?? null)) return false;
        try { ConsumerAcceptanceV2ReceiptVerifier::verify($observed, $trustedKeyFile, $now); } catch (Throwable) { return false; }
        return hash_equals(hash('sha256', ConsumerAcceptanceV2ReceiptVerifier::canonical($plan)), $observed['plan_sha256'])
            && hash_equals($plan['candidate']['host_sha256'], $observed['host_revision_sha256'])
            && hash_equals($plan['scope_cleanup']['scope_sha256'], $observed['scope_sha256']);
    }

    /** @return array{status:string,before_first_write:bool,database_factory_initialized:bool} */
    public static function validateObservedCapabilityFile(string $file, string $trustedKeyFile, string $hostRevisionSha256, string $scopeSha256, DateTimeImmutable $now): array
    {
        try {
            ConsumerAcceptanceV2ReceiptVerifier::readLocalJson($file);
            $ok = false; // The CLI provides only host/scope, never the complete signed plan binding.
        } catch (Throwable) { $ok = false; }
        return ['status' => $ok ? 'observed_validated_offline' : 'preflight_blocked', 'before_first_write' => true, 'database_factory_initialized' => false];
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    private static function report(array $plan, string $status): array
    {
        return ['schema' => 'sand-iam.consumer-acceptance-v2-report/v1', 'run_id' => $plan['run_id'], 'status' => $status, 'real_l04' => false, 'before_first_write' => true, 'transport_initialized' => false, 'database_factory_initialized' => false, 'action_registry_sha256' => ConsumerAcceptanceV2ActionRegistry::manifestHash(), 'steps' => self::REPORT_STEPS];
    }

    private static function candidate(mixed $value, string $label): void { $v=self::obj($value,$label); self::keys($v,['candidate_sha256','host_sha256','clean_host_revision_sha256','tree_a_sha256','tree_b_sha256'],$label); foreach($v as $k=>$x) self::sha($x,$label.'.'.$k); }
    private static function origins(mixed $value, string $label): void { $v=self::obj($value,$label); self::keys($v,['iam','a','b'],$label); foreach($v as $x) if(!is_string($x)||preg_match('#^https://[a-z0-9.-]+$#D',$x)!==1) throw new InvalidArgumentException($label.' must use frozen HTTPS origins'); }
    private static function obj(mixed $v,string $l):array { if(!is_array($v)||array_is_list($v)) throw new InvalidArgumentException($l.' must be object'); return $v; }
    private static function keys(array $v,array $e,string $l):void { if(array_diff($e,array_keys($v))!==[]||array_diff(array_keys($v),$e)!==[]) throw new InvalidArgumentException($l.' has invalid key set'); }
    private static function same(mixed $v,string $e,string $l):void { if(!is_string($v)||$v!==$e) throw new InvalidArgumentException($l.' is invalid'); }
    private static function sha(mixed $v,string $l):void { if(!is_string($v)||preg_match('/^[0-9a-f]{64}$/D',$v)!==1) throw new InvalidArgumentException($l.' must be SHA-256'); }
    private static function id(mixed $v,string $l):void { if(!is_string($v)||preg_match('/^[a-z][a-z0-9_-]{2,95}$/D',$v)!==1) throw new InvalidArgumentException($l.' is invalid'); }
    private static function slot(mixed $v):void { if(!is_string($v)||preg_match('/^[A-Z][A-Z0-9_]{2,95}$/D',$v)!==1) throw new InvalidArgumentException('secret slots must be names only'); }
}
