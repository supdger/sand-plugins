<?php

declare(strict_types=1);

/**
 * SandIAM seven-chain acceptance inventory.
 *
 * This is deliberately declarative: it does not connect to a host or database.
 * The runner consumes it for dry-run, source preflight, in-memory simulation and
 * an explicitly supplied live driver.
 *
 * @return array<string, mixed>
 */
return [
    'schema_version' => 'sand-iam.terminal-acceptance/v1',
    'fixture_prefix' => 'sand_iam_acceptance_0000000000000000_',
    'chains' => [
        'organization-application-environment' => [
            'title' => '客户主体、接入应用与应用环境',
            'purpose' => '确认一个受控对象可以创建、读取、修改、停用或恢复，并在结束时清理。',
            'contracts' => ['environment_lifecycle_non_pg_contract_test.php', 'environment_lifecycle_behavior_non_pg_test.php'],
            'live_credential_slots' => ['platform_admin', 'scoped_admin', 'out_of_scope_admin'],
            'live_prerequisites' => ['受控 SandAdmin 宿主', '平台管理员登录态', '可清理的测试组织权限'],
            'cleanup' => '按环境、应用、客户主体的反向顺序停用或删除本轮固定前缀夹具，并核对零残留。',
        ],
        'identity-group-role-policy' => [
            'title' => '用户、用户组、角色、资源与策略',
            'purpose' => '确认允许与拒绝均由后端执行并留下审计，而不是由页面推断。',
            'contracts' => ['identity_group_role_authorization_non_pg_contract_test.php', 'policy_versioning_non_pg_test.php'],
            'live_credential_slots' => ['platform_admin', 'application_user', 'out_of_scope_admin'],
            'live_prerequisites' => ['应用管理员和受限用户账号', '受控业务资源', '允许与拒绝各一条策略'],
            'cleanup' => '撤销策略、角色和用户组关系，禁用测试身份，并核对本轮审计与配置可按前缀定位。',
        ],
        'human-auth-session-mfa' => [
            'title' => '登录、会话与多因素验证',
            'purpose' => '以本轮唯一前缀注册专用身份，确认普通登录与启用 MFA 后的 challenge 验证分别签发会话，三条会话撤销后均被拒绝。',
            'contracts' => ['human_auth_core_contract_test.php', 'mfa_passkey_contract_test.php', 'acceptance_fixture_service_non_pg_test.php'],
            'live_credential_slots' => ['platform_admin', 'application_user'],
            'live_prerequisites' => ['应用允许本轮专用身份注册', '已配置认证策略', '可用的 TOTP 验证器'],
            'cleanup' => '撤销本轮注册、普通登录和 MFA challenge 验证会话及 MFA 因子，按恢复码、因子、刷新令牌、会话、认证凭据、身份顺序删除，并核对零残留。',
        ],
        'workload-credential-invocation' => [
            'title' => '调用身份、服务授权与凭证',
            'purpose' => '确认真实提供方收到一次授权调用；未授权与已撤销凭证均被拒绝。',
            'contracts' => ['service_grant_invocation_control_non_pg_test.php', 'service_grant_model_non_pg_test.php'],
            'live_credential_slots' => ['platform_admin', 'service_client'],
            'live_prerequisites' => ['受控业务提供方或 SandAI 测试服务', '仅接受本轮固定前缀请求的调用身份', '可查询的提供方调用记录'],
            'cleanup' => '撤销凭证与服务授权，清除提供方测试记录，并核对调用和审计残留。',
        ],
        'oauth-cas-api-governance' => [
            'title' => 'OAuth/OIDC、CAS 与接口治理',
            'purpose' => '确认 PKCE、CAS 客户端和语义动作治理在允许、拒绝和审计上保持一致。',
            'contracts' => ['oauth_oidc_contract_test.php', 'cas_protocol_non_pg_contract_test.php', 'api_governance_contract_test.php'],
            'live_credential_slots' => ['platform_admin', 'application_user', 'service_client'],
            'live_prerequisites' => ['标准 OAuth/OIDC 测试客户端', '受控 CAS 服务地址', '绑定了语义动作的受控业务路由'],
            'cleanup' => '撤销授权码、令牌、CAS 票据和路由绑定测试记录，并核对客户端与审计残留。',
        ],
        'delegation-scope' => [
            'title' => '三角色管理委派与越权拒绝',
            'purpose' => '确认平台管理员、被委派应用管理员和范围外管理员的操作边界。',
            'contracts' => ['environment_lifecycle_non_pg_contract_test.php', 'delegation_webhook_audit_non_pg_contract_test.php'],
            'live_credential_slots' => ['platform_admin', 'scoped_admin', 'out_of_scope_admin'],
            'live_prerequisites' => ['平台管理员、被委派管理员和范围外管理员三种账号', '两个隔离的受控应用'],
            'cleanup' => '撤销管理委派与临时角色，退出三种账号并删除本轮范围测试对象。',
        ],
        'event-webhook-delivery' => [
            'title' => '事件通知与投递',
            'purpose' => '确认签名、500 后重试、2xx 成功、投递记录和审计能够串成一条证据链。',
            'contracts' => ['delegation_webhook_audit_non_pg_contract_test.php', 'acceptance_fixture_webhook_event_service_non_pg_test.php', 'acceptance_fixture_service_non_pg_test.php'],
            'live_credential_slots' => ['platform_admin', 'service_client'],
            'live_prerequisites' => ['受控 HTTPS 接收器', '能先返回 500 再返回 2xx 的测试脚本', '接收器签名校验记录'],
            'cleanup' => '删除 Webhook、撤销签名密钥、清空接收器内存记录，并核对投递与审计残留。',
        ],
    ],
];
