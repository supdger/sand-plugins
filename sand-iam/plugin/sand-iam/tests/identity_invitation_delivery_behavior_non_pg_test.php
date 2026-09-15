<?php
declare(strict_types=1);

namespace plugin\sandadmin\exception { class ApiException extends \RuntimeException {} }
namespace plugin\SandIam\app\model {
    #[\AllowDynamicProperties]
    class MemoryRecord {
        public function __construct(array $values) { foreach ($values as $key => $value) $this->$key = $value; }
        public function save(array $values): void { foreach ($values as $key => $value) $this->$key = $value; }
    }
    class Application extends MemoryRecord {
        public static function where(string $key, mixed $value): Query { return (new Query(self::class))->where($key, $value); }
    }
    class Identity extends MemoryRecord {}
    class IdentityInvitation extends MemoryRecord {
        public static array $rows = [];
        public static function where(string $key, mixed $value): Query { return (new Query(self::class))->where($key, $value); }
        public static function create(array $values): self { return self::$rows[1] = new self(['id' => 1] + $values); }
    }
    class IdentityGroup extends MemoryRecord {
        public static function where(string $key, mixed $value): Query { return (new Query(self::class))->where($key, $value); }
    }
    class IdentityGroupMember extends MemoryRecord {
        public static array $rows = [];
        public static function where(string $key, mixed $value): Query { return (new Query(self::class))->where($key, $value); }
        public static function create(array $values): self { $id = self::$rows === [] ? 1 : max(array_keys(self::$rows)) + 1; return self::$rows[$id] = new self(['id' => $id] + $values); }
    }
    class Query {
        private array $conditions = [];
        private array $excluded = [];
        public function __construct(private string $model) {}
        public function where(string $key, mixed $value, mixed $third = null): self { if ($value === '<>') $this->excluded[$key] = $third; else $this->conditions[$key] = [$value]; return $this; }
        public function whereIn(string $key, array $values): self { $this->conditions[$key] = $values; return $this; }
        public function lock(bool $value): self { return $this; }
        private function matches(): array {
            $rows = match ($this->model) {
                Application::class => [new Application(['id' => 10, 'organization_id' => 5, 'status' => 1])],
                IdentityGroup::class => [new IdentityGroup(['id' => 1, 'application_id' => 10, 'status' => 1]), new IdentityGroup(['id' => 2, 'application_id' => 11, 'status' => 1]), new IdentityGroup(['id' => 3, 'application_id' => 10, 'status' => 2])],
                IdentityGroupMember::class => IdentityGroupMember::$rows,
                default => IdentityInvitation::$rows,
            };
            $matched = [];
            foreach ($rows as $row) {
                foreach ($this->excluded as $key => $value) if ($row->$key === $value) continue 2;
                foreach ($this->conditions as $key => $values) if (!in_array($row->$key, $values, true)) continue 2;
                $matched[] = $row;
            }
            return $matched;
        }
        public function find(): ?MemoryRecord { return $this->matches()[0] ?? null; }
        public function update(array $values): int { $rows = $this->matches(); foreach ($rows as $row) $row->save($values); return count($rows); }
    }
}
namespace plugin\SandIam\app\service {
    class HumanAuthService {
        public static array $created = [];
        public function activateInvitation(mixed $app, string $type, string $target, array $payload, string $requestId, bool $login): \plugin\SandIam\app\model\Identity {
            $identity = new \plugin\SandIam\app\model\Identity(['id' => 50, 'application_id' => $app->id, 'display_name' => 'Invited']);
            self::$created[] = $identity;
            return $identity;
        }
    }
    class InvitationSecretCipher {
        public function encrypt(string $value): string { return $value; }
        public function decrypt(string $value): string { return $value; }
    }
    class AuditWriter {
        public static array $events = [];
        public static bool $fail = false;
        public function write(mixed ...$args): void { self::$events[] = $args; if (self::$fail) throw new \RuntimeException('audit unavailable'); }
    }
    class MessageProviderService {
        public static mixed $duringSend = null;
        public static bool $sent = true;
        public static int $calls = 0;
        public function sendMessage(mixed ...$args): bool { self::$calls++; if (self::$duringSend !== null) (self::$duringSend)(); return self::$sent; }
    }
}
namespace think\facade {
    class Db {
        public static bool $active = false;
        private static string $snapshot;
        public static function startTrans(): void { self::$snapshot = serialize([\plugin\SandIam\app\model\IdentityInvitation::$rows, \plugin\SandIam\app\service\AuditWriter::$events, \plugin\SandIam\app\service\HumanAuthService::$created, \plugin\SandIam\app\model\IdentityGroupMember::$rows]); self::$active = true; }
        public static function commit(): void { self::$active = false; }
        public static function rollback(): void { [\plugin\SandIam\app\model\IdentityInvitation::$rows, \plugin\SandIam\app\service\AuditWriter::$events, \plugin\SandIam\app\service\HumanAuthService::$created, \plugin\SandIam\app\model\IdentityGroupMember::$rows] = unserialize(self::$snapshot); self::$active = false; }
    }
}
namespace {
    use plugin\SandIam\app\model\IdentityInvitation;
    use plugin\SandIam\app\service\AuditWriter;
    use plugin\SandIam\app\service\IdentityInvitationService;
    use plugin\SandIam\app\service\MessageProviderService;
    use plugin\sandadmin\exception\ApiException;
    function config(string $key, mixed $default = null): mixed {
        return match ($key) {
            'plugin.sand-iam.app.identity_lifecycle_enabled' => 1,
            'plugin.sand-iam.app.invitation_token_pepper' => str_repeat('offline-only', 4),
            'plugin.sand-iam.app.invitation_accept_url' => 'https://example.invalid/accept',
            default => $default,
        };
    }
    require dirname(__DIR__) . '/app/service/IdentityInvitationService.php';
    $service = new IdentityInvitationService();
    foreach ([true, 1.9, '1bad', '1.0', '1e0', null, [], 0, -1, 2, 3, (string) PHP_INT_MAX . '0'] as $groupId) {
        $before = serialize([IdentityInvitation::$rows, AuditWriter::$events, MessageProviderService::$calls]);
        try { $service->create(10, 'email', 'person@example.invalid', [$groupId], 72, 'admin', 'invalid-group'); throw new \RuntimeException('invalid group accepted'); }
        catch (ApiException $error) { if ($error->getCode() !== 400 || $error->getMessage() !== 'SAND_IAM_INVITATION_GROUPS_INVALID') throw $error; }
        if (serialize([IdentityInvitation::$rows, AuditWriter::$events, MessageProviderService::$calls]) !== $before) throw new \RuntimeException('invalid group caused side effects');
    }
    $service->create(10, 'email', 'person@example.invalid', [1, '1'], 72, 'admin', 'valid-group');
    if (IdentityInvitation::$rows[1]->initial_group_ids !== [1]) throw new \RuntimeException('valid group normalization failed');
    foreach ([true, false] as $sent) {
        MessageProviderService::$sent = $sent;
        try { $service->create(10, 'email', 'person@example.invalid', [], 72, 'admin', 'normal'); }
        catch (ApiException $error) { if ($sent || $error->getCode() !== 503) throw $error; }
        $row = IdentityInvitation::$rows[1];
        if ($row->state !== ($sent ? 'pending' : 'delivery_failed') || $row->status !== 1) throw new \RuntimeException('normal delivery state incorrect');
        if ($sent && $row->encrypted_delivery_token !== null) throw new \RuntimeException('delivered token retained');
    }
    foreach ([true, false] as $sent) {
        IdentityInvitation::$rows = []; AuditWriter::$events = [];
        MessageProviderService::$sent = $sent;
        MessageProviderService::$duringSend = static fn () => $service->revoke(1, 10, 'admin', 'revoke-during-send');
        try { $service->create(10, 'email', 'person@example.invalid', [], 72, 'admin', 'send'); }
        catch (ApiException $error) { if ($sent || $error->getCode() !== 503) throw $error; }
        $row = IdentityInvitation::$rows[1];
        if ($row->state !== 'revoked' || $row->status !== 2 || $row->encrypted_delivery_token !== null) throw new \RuntimeException('delivery completion overwrote revocation');
        try { $service->resend(1, 10, 'admin', 'resend'); throw new \RuntimeException('revoked invitation resent'); }
        catch (ApiException $error) { if ($error->getCode() !== 409) throw $error; }
    }
    foreach ([true, false] as $sent) {
        MessageProviderService::$sent = $sent;
        MessageProviderService::$duringSend = static function (): void {
            IdentityInvitation::$rows[1]->save(['token_hash' => 'new-generation', 'encrypted_delivery_token' => 'new-delivery-token']);
        };
        try { $service->create(10, 'email', 'person@example.invalid', [], 72, 'admin', 'new-generation'); }
        catch (ApiException $error) { if ($sent || $error->getCode() !== 503) throw $error; }
        $row = IdentityInvitation::$rows[1];
        if ($row->state !== 'sending' || $row->encrypted_delivery_token !== 'new-delivery-token') throw new \RuntimeException('old completion changed new delivery');
    }
    MessageProviderService::$duringSend = null;
    MessageProviderService::$sent = true;
    foreach (['pending', 'delivery_failed'] as $state) {
        IdentityInvitation::$rows[1]->save(['state' => $state, 'status' => 2]);
        $calls = MessageProviderService::$calls;
        try { $service->resend(1, 10, 'admin', 'disabled'); throw new \RuntimeException('disabled invitation resent'); }
        catch (ApiException $error) { if ($error->getCode() !== 409) throw $error; }
        if (MessageProviderService::$calls !== $calls) throw new \RuntimeException('disabled invitation sent message');
    }
    IdentityInvitation::$rows[1]->save(['state' => 'pending', 'status' => 1]);
    $before = serialize([IdentityInvitation::$rows, AuditWriter::$events]);
    AuditWriter::$fail = true;
    try { $service->revoke(1, 10, 'admin', 'audit-failure'); throw new \RuntimeException('audit failure hidden'); }
    catch (\RuntimeException $error) { if ($error->getMessage() !== 'audit unavailable') throw $error; }
    finally { AuditWriter::$fail = false; }
    if (serialize([IdentityInvitation::$rows, AuditWriter::$events]) !== $before || \think\facade\Db::$active) throw new \RuntimeException('revoke audit failure left mutation');
    $service->revoke(1, 10, 'admin', 'retry');
    if (IdentityInvitation::$rows[1]->state !== 'revoked') throw new \RuntimeException('revoke retry failed');
    foreach ([['pending', 11], ['accepted', 10]] as [$state, $applicationId]) {
        IdentityInvitation::$rows[1]->save(['state' => $state, 'status' => 1, 'application_id' => $applicationId]);
        $before = serialize([IdentityInvitation::$rows, AuditWriter::$events]);
        try { $service->revoke(1, 10, 'admin', 'invalid'); throw new \RuntimeException('invalid revoke accepted'); }
        catch (ApiException $error) { if ($error->getCode() !== 409) throw $error; }
        if (serialize([IdentityInvitation::$rows, AuditWriter::$events]) !== $before) throw new \RuntimeException('invalid revoke changed record');
    }
    echo "Invitation delivery/revocation behavior PASS (offline)\n";
    $token = 'siam_inv_' . str_repeat('a', 43);
    IdentityInvitation::$rows = [1 => new IdentityInvitation([
        'id' => 1, 'application_id' => 10, 'state' => 'pending', 'status' => 1,
        'token_hash' => hash_hmac('sha256', 'token:' . $token, config('plugin.sand-iam.app.invitation_token_pepper')),
        'expire_time' => date('Y-m-d H:i:s', time() + 3600), 'guest_identity_id' => null,
        'encrypted_target' => 'person@example.invalid', 'target_type' => 'email', 'target_hash' => 'target-fixture',
        'initial_group_ids' => [1], 'encrypted_delivery_token' => null,
    ])];
    foreach ([2 => 'pending', 3 => 'delivery_failed', 4 => 'sending', 5 => 'accepted'] as $id => $state) {
        IdentityInvitation::$rows[$id] = new IdentityInvitation(['id' => $id, 'application_id' => 10, 'target_hash' => 'target-fixture', 'state' => $state, 'status' => 1, 'encrypted_delivery_token' => 'fixture']);
    }
    IdentityInvitation::$rows[6] = new IdentityInvitation(['id' => 6, 'application_id' => 11, 'target_hash' => 'target-fixture', 'state' => 'pending', 'status' => 1, 'encrypted_delivery_token' => 'fixture']);
    IdentityInvitation::$rows[7] = new IdentityInvitation(['id' => 7, 'application_id' => 10, 'target_hash' => 'another-target', 'state' => 'pending', 'status' => 1, 'encrypted_delivery_token' => 'fixture']);
    foreach ([2, 3, 4, 5, 6, 7] as $id) IdentityInvitation::$rows[$id]->token_hash = 'other-token-' . $id;
    $snapshot = static fn (): string => serialize([IdentityInvitation::$rows, AuditWriter::$events, \plugin\SandIam\app\service\HumanAuthService::$created, \plugin\SandIam\app\model\IdentityGroupMember::$rows]);
    $acceptanceBaseline = $snapshot();
    $before = $snapshot();
    AuditWriter::$fail = true;
    try { $service->accept($token, [], 'accept'); throw new \RuntimeException('Acceptance audit failure hidden'); }
    catch (\RuntimeException $error) { if ($error->getMessage() !== 'audit unavailable') throw $error; }
    finally { AuditWriter::$fail = false; }
    if ($snapshot() !== $before || \think\facade\Db::$active) throw new \RuntimeException('Failed acceptance consumed invitation or retained identity/audit');
    $result = $service->accept($token, [], 'accept');
    if ($result !== ['id' => 50, 'display_name' => 'Invited'] || IdentityInvitation::$rows[1]->state !== 'accepted' || count(\plugin\SandIam\app\service\HumanAuthService::$created) !== 1) throw new \RuntimeException('Acceptance recovery failed');
    $members = \plugin\SandIam\app\model\IdentityGroupMember::$rows;
    if (count($members) !== 1 || $members[1]->identity_group_id !== 1 || $members[1]->application_id !== 10 || $members[1]->identity_id !== 50 || $members[1]->status !== 1) throw new \RuntimeException('Initial group membership missing or incorrectly scoped');
    foreach ([2, 3, 4] as $id) if (IdentityInvitation::$rows[$id]->state !== 'revoked' || IdentityInvitation::$rows[$id]->status !== 2 || IdentityInvitation::$rows[$id]->encrypted_delivery_token !== null) throw new \RuntimeException('Other active invitation not revoked');
    foreach ([5 => 'accepted', 6 => 'pending', 7 => 'pending'] as $id => $state) if (IdentityInvitation::$rows[$id]->state !== $state || IdentityInvitation::$rows[$id]->encrypted_delivery_token !== 'fixture') throw new \RuntimeException('Unrelated invitation changed');
    $before = $snapshot();
    try { $service->accept($token, [], 'replay'); throw new \RuntimeException('Consumed invitation reused'); }
    catch (ApiException $error) { if ($error->getCode() !== 400) throw $error; }
    if ($snapshot() !== $before || \think\facade\Db::$active) throw new \RuntimeException('Replay changed acceptance');
    echo "Invitation acceptance audit rollback/retry behavior PASS (offline)\n";
    echo "Invitation initial group and other invitation isolation behavior PASS (offline)\n";
    foreach ([1, 2] as $memberStatus) {
        [IdentityInvitation::$rows, AuditWriter::$events, \plugin\SandIam\app\service\HumanAuthService::$created, \plugin\SandIam\app\model\IdentityGroupMember::$rows] = unserialize($acceptanceBaseline);
        \plugin\SandIam\app\model\IdentityGroupMember::$rows[20] = new \plugin\SandIam\app\model\IdentityGroupMember([
            'id' => 20, 'identity_group_id' => 1, 'application_id' => 10, 'identity_id' => 50, 'status' => $memberStatus,
        ]);
        $before = $snapshot();
        AuditWriter::$fail = true;
        try { $service->accept($token, [], 'member-recovery'); throw new \RuntimeException('Member audit failure hidden'); }
        catch (\RuntimeException $error) { if ($error->getMessage() !== 'audit unavailable') throw $error; }
        finally { AuditWriter::$fail = false; }
        if ($snapshot() !== $before || \think\facade\Db::$active) throw new \RuntimeException('Existing member changed despite rollback');
        $service->accept($token, [], 'member-recovery');
        $members = \plugin\SandIam\app\model\IdentityGroupMember::$rows;
        if (array_keys($members) !== [20] || $members[20]->status !== 1 || $members[20]->identity_id !== 50 || $members[20]->application_id !== 10) throw new \RuntimeException('Existing membership not reused or restored');
    }
    echo "Invitation existing member reuse/restoration behavior PASS (offline)\n";
}
