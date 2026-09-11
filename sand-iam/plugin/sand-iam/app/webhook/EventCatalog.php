<?php

declare(strict_types=1);

namespace plugin\SandIam\app\webhook;

use plugin\sandadmin\exception\ApiException;

final class EventCatalog
{
    /** @return array<string,array{version:int,name:string,purpose:string,data_fields:list<string>}> */
    public static function all(): array
    {
        return [
            'acceptance.fixture.event' => self::event('验收专用事件', '仅用于受控验收投递与清理；不代表业务身份或业务对象变化', ['endpoint_id']),
            'identity.created' => self::event('应用用户已创建', '同步账号创建结果', ['identity_id', 'identity_code', 'display_name', 'lifecycle_state', 'status', 'changed_fields']),
            'identity.updated' => self::event('应用用户资料已修改', '同步账号资料变化', ['identity_id', 'identity_code', 'display_name', 'lifecycle_state', 'status', 'changed_fields']),
            'identity.enabled' => self::event('应用用户已启用', '同步账号启用状态', ['identity_id', 'identity_code', 'display_name', 'lifecycle_state', 'status', 'changed_fields']),
            'identity.disabled' => self::event('应用用户已停用', '同步账号停用状态', ['identity_id', 'identity_code', 'display_name', 'lifecycle_state', 'status', 'changed_fields']),
            'identity.deleted' => self::event('应用用户已删除', '同步账号删除状态', ['identity_id', 'identity_code', 'display_name', 'lifecycle_state', 'status', 'changed_fields']),
            'identity.restored' => self::event('应用用户已恢复', '同步账号恢复状态', ['identity_id', 'identity_code', 'display_name', 'lifecycle_state', 'status', 'changed_fields']),
            'identity.login.succeeded' => self::event('应用用户登录成功', '安全运营与登录通知', self::auditFields()),
            'identity.login.failed' => self::event('应用用户登录失败', '安全运营与异常登录告警', self::auditFields()),
            'identity.logout.succeeded' => self::event('应用用户已退出', '会话撤销联动', self::auditFields()),
            'identity.profile.updated' => self::event('应用用户资料已更新', '业务产品刷新用户展示资料', self::auditFields()),
            'directory.changed' => self::event('身份目录配置已变化', '目录与账号供应链路追踪', self::auditFields()),
            'authorization.policy.changed' => self::event('授权策略已变化', '权限缓存失效与变更审查', self::auditFields()),
            'credential.changed' => self::event('调用凭证已变化', '调用方密钥轮换与撤销联动', self::auditFields()),
            'oidc.signingkey.changed' => self::event('OIDC 签名密钥已变化', 'OIDC 验签方刷新 JWKS 与安全运营联动', self::auditFields()),
            'security.operation.denied' => self::event('安全操作被拒绝', '异常访问告警与排障', self::auditFields()),
            'security.alert.raised' => self::event('安全告警已产生', '告警出口与处置系统接入', ['alert_id', 'rule_code', 'severity', 'request_id']),
        ];
    }

    /** @return array{version:int,name:string,purpose:string,data_fields:list<string>} */
    public static function get(string $type): array
    {
        $event = self::all()[$type] ?? null;
        if ($event === null) throw new ApiException('SAND_IAM_WEBHOOK_EVENT_TYPE_INVALID', 400);
        return $event;
    }

    public static function fromAudit(string $action, string $outcome): ?string
    {
        if ($action === 'identity.login' || preg_match('/^identity\.(?:federation|mfa|passkey|cas|kerberos|radius)_login$/', $action)) {
            return $outcome === 'succeeded' ? 'identity.login.succeeded' : 'identity.login.failed';
        }
        if ($action === 'identity.logout' && $outcome === 'succeeded') return 'identity.logout.succeeded';
        if ($action === 'identity.profile_update' && $outcome === 'succeeded') return 'identity.profile.updated';
        if ((str_starts_with($action, 'identity_provider.') || str_starts_with($action, 'scim.') || str_starts_with($action, 'sync_connector.')) && $outcome === 'succeeded') return 'directory.changed';
        if (str_starts_with($action, 'policy.') && $outcome === 'succeeded') return 'authorization.policy.changed';
        if (str_starts_with($action, 'credential.') && $outcome === 'succeeded') return 'credential.changed';
        if (in_array($action, ['oidc.signing_key.rotate', 'oidc.signing_key.retire'], true) && $outcome === 'succeeded') return 'oidc.signingkey.changed';
        if (in_array($outcome, ['denied', 'failed'], true) && self::isSecurityAction($action)) return 'security.operation.denied';
        return null;
    }

    /** @param list<string> $fields @return array{version:int,name:string,purpose:string,data_fields:list<string>} */
    private static function event(string $name, string $purpose, array $fields): array
    {
        return ['version' => 1, 'name' => $name, 'purpose' => $purpose, 'data_fields' => $fields];
    }

    /** @return list<string> */
    private static function auditFields(): array
    {
        return ['action', 'outcome', 'resource_type', 'resource_id', 'request_id'];
    }

    private static function isSecurityAction(string $action): bool
    {
        foreach (['identity.', 'oauth.', 'authorize.', 'scope.', 'credential.', 'identity_provider.', 'scim.', 'cas.', 'radius.', 'kerberos.'] as $prefix) {
            if (str_starts_with($action, $prefix)) return true;
        }
        return false;
    }
}
