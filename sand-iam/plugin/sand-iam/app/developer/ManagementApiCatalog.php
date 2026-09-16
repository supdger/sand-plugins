<?php

declare(strict_types=1);

namespace plugin\SandIam\app\developer;

use plugin\sandadmin\exception\ApiException;

/**
 * Complete SandIAM management-route catalog used to generate OpenAPI.
 * A contract test reconciles this list with config/route.php.
 */
final class ManagementApiCatalog
{
    private const CRUD = [
        'organization' => '客户主体',
        'application' => '接入应用',
        'environment' => '应用环境',
        'client' => '服务调用身份',
        'service' => '服务',
        'action' => '服务动作',
        'grant' => '服务授权',
        'identity' => '应用用户',
        'auth-policy' => '登录安全策略',
        'application-experience' => '应用登录体验',
        'oauth-client' => 'OAuth 客户端',
        'identity-provider' => '身份源',
        'identity-binding' => '外部身份绑定',
        'role' => '应用角色',
        'user-type' => '用户类型',
        'resource' => '业务资源',
        'policy' => '授权策略',
        'admin-organization-grant' => '客户主体管理员',
        'admin-application-grant' => '应用管理员',
        'api-resource' => '接口目录',
        'application-business-action' => '应用业务动作',
        'api-route-binding' => '路由绑定',
        'cas-service' => 'CAS 接入服务',
        'radius-nas' => 'RADIUS 网络设备',
        'application-network-policy' => '应用网络规则',
        'audit-retention-policy' => '审计保留策略',
    ];

    /**
     * Route names are optimized for human-facing URLs, while permission resources
     * follow the stable domain dictionary. Only the exceptions belong here; the
     * contract test reconciles every value with the controller attribute.
     */
    private const PERMISSION_OVERRIDES = [
        'POST /developer/onboarding/preview' => 'sand_iam:onboarding:preview',
        'POST /developer/onboarding/apply' => 'sand_iam:onboarding:apply',
        'POST /developer/route-manifest/preview' => 'sand_iam:onboarding:preview',
        'POST /developer/route-manifest/apply' => 'sand_iam:onboarding:apply',
        'POST /acceptance-fixture/cleanup' => 'sand_iam:acceptance_fixture:cleanup',
        'POST /acceptance-fixture/webhook-event' => 'sand_iam:acceptance_fixture:cleanup',
        'GET /acceptance-fixture/status' => 'sand_iam:acceptance_fixture:read',
        'GET /application-business-action/index' => 'sand_iam:api_resource:index',
        'GET /application-business-action/read' => 'sand_iam:api_resource:read',
        'POST /application-business-action/save' => 'sand_iam:api_resource:save',
        'POST /application-business-action/update' => 'sand_iam:api_resource:update',
        'POST /application-business-action/disable' => 'sand_iam:api_resource:disable',
        'GET /application-business-action/pending-claims' => 'sand_iam:api_resource:read',
        'POST /application-business-action/publish' => 'sand_iam:api_resource:update',
        'GET /action/index' => 'sand_iam:service_action:index',
        'GET /action/read' => 'sand_iam:service_action:read',
        'POST /action/save' => 'sand_iam:service_action:save',
        'POST /action/update' => 'sand_iam:service_action:update',
        'POST /action/disable' => 'sand_iam:service_action:disable',
        'POST /grant/disable' => 'sand_iam:grant:revoke',
        'POST /policy/simulate' => 'sand_iam:policy:read',
        'POST /policy/rollback' => 'sand_iam:policy:publish',
        'GET /policy/versions' => 'sand_iam:policy:read',
        'GET /grant/actions' => 'sand_iam:grant:index',
        'GET /grant/services' => 'sand_iam:grant:index',
        'GET /webhook/delivery/index' => 'sand_iam:webhook_delivery:index',
        'GET /webhook/delivery/read' => 'sand_iam:webhook_delivery:read',
        'POST /webhook/delivery/retry' => 'sand_iam:webhook_delivery:retry',
        'GET /admin-application-grant/admin-options' => 'sand_iam:admin_application_grant:save',
        'GET /admin-organization-grant/admin-options' => 'sand_iam:admin_organization_grant:save',
        'GET /message-provider/options' => 'sand_iam:message_provider:index',
        'GET /message-provider/mounts' => 'sand_iam:message_provider_mount:index',
        'POST /message-provider/mount' => 'sand_iam:message_provider_mount:save',
        'POST /message-provider/unmount' => 'sand_iam:message_provider_mount:disable',
        'GET /identity-group/members' => 'sand_iam:identity_group_member:index',
        'POST /identity-group/member/add' => 'sand_iam:identity_group_member:add',
        'POST /identity-group/member/remove' => 'sand_iam:identity_group_member:remove',
        'GET /identity-group-role/role-index' => 'sand_iam:identity_group_role:index',
        'GET /identity-import/rows' => 'sand_iam:identity_import:read',
        'POST /oauth-client/secret/rotate' => 'sand_iam:oauth_client:rotate',
        'GET /oauth-client/logout-delivery/index' => 'sand_iam:oauth_client:read',
        'POST /oauth-client/logout-delivery/reissue' => 'sand_iam:oauth_client:update',
        'GET /oidc-signing-key/index' => 'sand_iam:oauth_client:index',
        'GET /oidc-signing-key/status' => 'sand_iam:oauth_client:read',
        'POST /oidc-signing-key/rotate' => 'sand_iam:oauth_client:rotate',
        'POST /oidc-signing-key/retire' => 'sand_iam:oauth_client:rotate',
        'POST /federation/provider/create' => 'sand_iam:federation:create',
        'GET /identity-provider-preset/index' => 'sand_iam:identity_provider:read',
        'GET /identity-provider-preset/read' => 'sand_iam:identity_provider:read',
        'POST /identity-provider-preset/draft' => 'sand_iam:identity_provider:read',
        'GET /sync-connector/runs' => 'sand_iam:sync_run:index',
        'POST /sync-connector/run' => 'sand_iam:sync_run:run',
        'GET /sync-connector/outbox' => 'sand_iam:sync_run:index',
        'POST /sync-connector/outbox-retry' => 'sand_iam:sync_run:run',
        'GET /initialization/draft-index' => 'sand_iam:initialization:index',
        'GET /initialization/draft-read' => 'sand_iam:initialization:read',
    ];

    /** @return list<array{method:string,path:string,operation_id:string,summary:string,permission:string,sensitive:bool}> */
    public static function routes(): array
    {
        $routes = [];
        foreach (self::CRUD as $segment => $name) {
            foreach ([
                ['GET', 'index', '查看列表'],
                ['GET', 'read', '查看详情'],
                ['POST', 'save', '新增'],
                ['POST', 'update', '修改'],
                ['POST', 'disable', '停用'],
            ] as [$method, $action, $verb]) {
                $routes[] = self::route($method, "/{$segment}/{$action}", "{$verb}{$name}");
            }
        }
        foreach (self::specialRoutes() as [$method, $path, $summary, $sensitive]) {
            $routes[] = self::route($method, $path, $summary, $sensitive);
        }
        usort($routes, static fn (array $left, array $right): int => [$left['path'], $left['method']] <=> [$right['path'], $right['method']]);
        return $routes;
    }

    /** @return array<string,mixed> */
    public static function openApi(string $serverUrl = '/app/sand-iam/admin'): array
    {
        if ($serverUrl === '' || str_contains($serverUrl, '#')) throw new ApiException('SAND_IAM_OPENAPI_SERVER_INVALID', 400);
        $paths = [];
        foreach (self::routes() as $route) {
            $operation = [
                'operationId' => $route['operation_id'],
                'summary' => $route['summary'],
                'tags' => [self::tag($route['path'])],
                'security' => [['SandAdminSession' => []]],
                'parameters' => [[
                    'name' => 'X-Request-Id',
                    'in' => 'header',
                    'required' => false,
                    'schema' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 96],
                    'description' => '业务请求链路标识；调用方应保存，用于查询 SandIAM 审计。',
                ]],
                'x-sand-iam-permission' => $route['permission'],
                'x-sand-iam-sensitive-input' => $route['sensitive'],
                'responses' => [
                    '200' => ['$ref' => '#/components/responses/Success'],
                    '400' => ['$ref' => '#/components/responses/BadRequest'],
                    '401' => ['$ref' => '#/components/responses/Unauthenticated'],
                    '403' => ['$ref' => '#/components/responses/Forbidden'],
                    '409' => ['$ref' => '#/components/responses/Conflict'],
                ],
            ];
            if ($route['method'] !== 'GET') {
                $operation['requestBody'] = [
                    'required' => true,
                    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ManagementInput']]],
                ];
            }
            if ($route['path'] === '/policy/versions') {
                $operation['parameters'] = array_merge($operation['parameters'], [
                    self::queryParameter('id', true, ['type' => 'integer', 'minimum' => 1], '已获应用管理授权的策略主键；不支持全局版本查询。'),
                    self::queryParameter('page', false, ['type' => 'integer', 'minimum' => 1, 'default' => 1], '页码。'),
                    self::queryParameter('limit', false, ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20], '每页记录数。'),
                ]);
                $operation['responses']['200'] = self::jsonResponse('策略历史版本，按版本号倒序', '#/components/schemas/PolicyVersionListEnvelope');
            }
            if ($route['path'] === '/oauth-client/logout-delivery/index') {
                $operation['parameters'] = array_merge($operation['parameters'], [
                    self::queryParameter('id', true, ['type' => 'integer', 'minimum' => 1], 'OAuth 客户端主键。'),
                    self::queryParameter('state', false, ['type' => 'string', 'enum' => ['pending', 'sending', 'delivered', 'dead']], '可选投递状态；恢复页面使用 dead。'),
                    self::queryParameter('page', false, ['type' => 'integer', 'minimum' => 1, 'default' => 1], '页码。'),
                    self::queryParameter('limit', false, ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20], '每页记录数。'),
                ]);
                $operation['responses']['200'] = self::jsonResponse('OIDC 后通道登出投递分页结果', '#/components/schemas/OidcLogoutDeliveryListEnvelope');
                $operation['responses']['404'] = ['$ref' => '#/components/responses/NotFound'];
            }
            if ($route['path'] === '/oauth-client/logout-delivery/reissue') {
                $operation['parameters'][0]['required'] = true;
                $operation['requestBody'] = [
                    'required' => true,
                    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/OidcLogoutDeliveryReissueInput']]],
                ];
                $operation['responses']['200'] = self::jsonResponse('OIDC 后通道登出恢复任务', '#/components/schemas/OidcLogoutDeliveryReissueEnvelope');
                $operation['responses']['404'] = ['$ref' => '#/components/responses/NotFound'];
                $operation['responses']['503'] = ['$ref' => '#/components/responses/Unavailable'];
                $operation['x-sand-iam-error-codes'] = [
                    'SAND_IAM_OIDC_BACKCHANNEL_LOGOUT_DISABLED',
                    'SAND_IAM_OIDC_BACKCHANNEL_CLIENT_UNAVAILABLE',
                    'SAND_IAM_OIDC_BACKCHANNEL_URI_UNAVAILABLE',
                    'SAND_IAM_OIDC_LOGOUT_DELIVERY_NOT_FOUND',
                    'SAND_IAM_OIDC_LOGOUT_DELIVERY_NOT_RECOVERABLE',
                    'SAND_IAM_OIDC_LOGOUT_SESSION_NOT_REVOKED',
                    'SAND_IAM_OIDC_LOGOUT_RECOVERY_CONFLICT',
                    'SAND_IAM_IDEMPOTENCY_CONFLICT',
                ];
            }
            $paths[$route['path']][strtolower($route['method'])] = $operation;
        }
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'SandIAM 管理 API',
                'version' => '0.13.0-candidate',
                'description' => 'SandAdmin 管理平面的完整路由目录。稳定权限码、敏感输入标记和错误响应可用于生成客户端与权限审查。',
            ],
            'servers' => [['url' => $serverUrl]],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'SandAdminSession' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'Authorization', 'description' => '由 SandAdmin 登录态提供，不得写入代码或文档。'],
                ],
                'schemas' => [
                    'PolicyVersionListEnvelope' => [
                        'type' => 'object',
                        'required' => ['code', 'msg', 'data'],
                        'properties' => [
                            'code' => ['type' => 'integer'], 'msg' => ['type' => 'string'],
                            'data' => [
                                'type' => 'object',
                                'required' => ['data', 'total', 'current_page', 'per_page', 'published_version_id'],
                                'properties' => [
                                    'total' => ['type' => 'integer', 'minimum' => 0],
                                    'current_page' => ['type' => 'integer', 'minimum' => 1],
                                    'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                                    'published_version_id' => ['type' => ['integer', 'null'], 'description' => '策略主记录指向的已发布快照；不表示策略当前启用。草稿和撤销状态不会改写此标识。'],
                                    'data' => ['type' => 'array', 'items' => [
                                        'type' => 'object', 'additionalProperties' => false,
                                        'required' => ['id', 'version_no', 'operation', 'rollback_of_version_id', 'create_time'],
                                        'properties' => [
                                            'id' => ['type' => 'integer', 'minimum' => 1],
                                            'version_no' => ['type' => 'integer', 'minimum' => 1],
                                            'operation' => ['type' => 'string', 'enum' => ['publish', 'rollback']],
                                            'rollback_of_version_id' => ['type' => ['integer', 'null']],
                                            'create_time' => ['type' => 'string'],
                                        ],
                                    ]],
                                ],
                            ],
                        ],
                    ],
                    'ManagementInput' => ['type' => 'object', 'additionalProperties' => true, 'description' => '控制器按白名单字段校验；密钥只允许发送到敏感输入接口。'],
                    'OidcLogoutDeliveryListItem' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['id', 'application_id', 'oauth_client_id', 'auth_session_id', 'event_id', 'state', 'attempt_count', 'status'],
                        'properties' => [
                            'id' => ['type' => 'integer', 'minimum' => 1],
                            'application_id' => ['type' => 'integer', 'minimum' => 1],
                            'oauth_client_id' => ['type' => 'integer', 'minimum' => 1],
                            'auth_session_id' => ['type' => 'integer', 'minimum' => 1, 'description' => '内部关联字段；管理页面不得展示。'],
                            'event_id' => ['type' => 'string'],
                            'state' => ['type' => 'string', 'enum' => ['pending', 'sending', 'delivered', 'dead']],
                            'attempt_count' => ['type' => 'integer', 'minimum' => 0],
                            'next_attempt_time' => ['type' => ['string', 'null']],
                            'delivered_time' => ['type' => ['string', 'null']],
                            'response_status' => ['type' => ['integer', 'null']],
                            'response_digest' => ['type' => ['string', 'null'], 'description' => '内部响应摘要；管理页面不得展示。'],
                            'last_error_code' => ['type' => ['string', 'null']],
                            'status' => ['type' => 'integer', 'enum' => [1, 2]],
                            'create_time' => ['type' => ['string', 'null']],
                            'update_time' => ['type' => ['string', 'null']],
                        ],
                    ],
                    'OidcLogoutDeliveryListEnvelope' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['code', 'msg', 'data'],
                        'properties' => [
                            'code' => ['type' => 'integer'],
                            'msg' => ['type' => 'string'],
                            'data' => [
                                'type' => 'object',
                                'required' => ['data'],
                                'additionalProperties' => true,
                                'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/OidcLogoutDeliveryListItem']]],
                            ],
                        ],
                    ],
                    'OidcLogoutDeliveryReissueInput' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['id', 'delivery_id'],
                        'properties' => [
                            'id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'OAuth 客户端主键。'],
                            'delivery_id' => ['type' => 'integer', 'minimum' => 1, 'description' => '原 dead 投递主键。'],
                        ],
                    ],
                    'OidcLogoutDeliveryReissueResult' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['source_delivery_id', 'delivery_id', 'event_id', 'state', 'already_reissued'],
                        'properties' => [
                            'source_delivery_id' => ['type' => 'integer', 'minimum' => 1],
                            'delivery_id' => ['type' => 'integer', 'minimum' => 1],
                            'event_id' => ['type' => 'string'],
                            'state' => ['type' => 'string', 'enum' => ['pending', 'sending', 'delivered', 'dead']],
                            'already_reissued' => ['type' => 'boolean'],
                        ],
                    ],
                    'OidcLogoutDeliveryReissueEnvelope' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['code', 'msg', 'data'],
                        'properties' => [
                            'code' => ['type' => 'integer'],
                            'msg' => ['type' => 'string'],
                            'data' => ['$ref' => '#/components/schemas/OidcLogoutDeliveryReissueResult'],
                        ],
                    ],
                    'Envelope' => [
                        'type' => 'object',
                        'required' => ['code', 'msg', 'data'],
                        'properties' => ['code' => ['type' => 'integer'], 'msg' => ['type' => 'string'], 'data' => []],
                    ],
                ],
                'responses' => [
                    'Success' => self::response('操作成功'),
                    'BadRequest' => self::response('输入不合法或资源不存在'),
                    'Unauthenticated' => self::response('SandAdmin 登录态无效'),
                    'Forbidden' => self::response('当前管理员没有所需权限或管理范围'),
                    'Conflict' => self::response('资源版本、唯一键或当前状态冲突'),
                    'NotFound' => self::response('指定资源不存在或不在当前管理范围'),
                    'Unavailable' => self::response('所需安全配置或协议能力未启用'),
                ],
            ],
        ];
    }

    /** @return list<array{string,string,string,bool}> */
    private static function specialRoutes(): array
    {
        $get = [
            '/application-business-action/pending-claims' => '查看待认领历史业务动作',
            '/identity-provider-preset/index' => '查看外部身份源预设', '/identity-provider-preset/read' => '查看外部身份源预设详情',
            '/identity-role/index' => '查看应用用户角色', '/identity-user-type/index' => '查看用户类型分配',
            '/audit/index' => '查询审计记录', '/audit/read' => '查看审计详情', '/audit/export' => '导出审计记录',
            '/audit/archive/index' => '查看审计归档', '/audit/archive/read' => '查看审计归档详情',
            '/credential/index' => '查看调用凭证', '/credential/read' => '查看调用凭证详情',
            '/scim/token/index' => '查看 SCIM 令牌', '/webhook/index' => '查看事件回调', '/webhook/read' => '查看事件回调详情',
            '/webhook/delivery/index' => '查看事件投递', '/webhook/delivery/read' => '查看事件投递详情',
            '/admin-application-grant/admin-options' => '选择可委派管理员',
            '/admin-organization-grant/admin-options' => '选择客户主体可委派管理员',
            '/message-provider/index' => '查看消息服务', '/message-provider/read' => '查看消息服务详情',
            '/message-provider/options' => '选择消息服务', '/message-provider/mounts' => '查看应用消息服务',
            '/identity-group/index' => '查看用户组', '/identity-group/read' => '查看用户组详情', '/identity-group/members' => '查看用户组成员',
            '/identity-group-role/index' => '查看用户组已授予的角色', '/identity-group-role/role-index' => '查看角色已授予的用户组',
            '/identity-invitation/index' => '查看用户邀请', '/identity-invitation/read' => '查看邀请详情',
            '/identity-import/index' => '查看用户导入任务', '/identity-import/rows' => '查看导入明细',
            '/policy/versions' => '查看策略历史版本',
            '/grant/actions' => '查看服务授权动作候选', '/grant/services' => '查看服务授权服务候选',
            '/identity-export/masked' => '导出脱敏用户', '/identity-export/sensitive' => '导出敏感用户信息',
            '/oauth-registration-token/index' => '查看动态注册令牌',
            '/oauth-client/logout-delivery/index' => '查看 OIDC 后通道登出投递',
            '/oidc-signing-key/index' => '查看 OIDC 签名密钥状态', '/oidc-signing-key/status' => '查看 OIDC 当前签名密钥状态',
            '/sync-connector/index' => '查看目录连接', '/sync-connector/read' => '查看目录连接详情', '/sync-connector/runs' => '查看目录同步记录', '/sync-connector/outbox' => '查看目录同步出站事件',
            '/developer/openapi' => '获取管理 API OpenAPI', '/developer/events' => '获取事件目录',
            '/security-alert/index' => '查看安全告警', '/security-alert/read' => '查看安全告警详情',
            '/initialization/index' => '查看初始化记录', '/initialization/read' => '查看初始化记录详情', '/initialization/draft-index' => '查看初始化草稿列表', '/initialization/draft-read' => '查看初始化草稿详情', '/initialization/export' => '导出初始化包',
            '/acceptance-fixture/status' => '检查本轮验收数据是否仍有残留',
        ];
        $post = [
            '/application-business-action/publish' => '发布应用业务动作',
            '/grant/revoke' => '撤销服务授权', '/policy/publish' => '发布授权策略', '/policy/rollback' => '回滚并发布授权策略版本', '/policy/revoke' => '撤销授权策略', '/policy/simulate' => '模拟并解释授权策略（只读）',
            '/identity-role/grant' => '授予应用用户角色', '/identity-role/revoke' => '撤销应用用户角色',
            '/identity-user-type/grant' => '分配用户类型', '/identity-user-type/revoke' => '撤销用户类型',
            '/credential/issue' => '签发调用凭证', '/credential/rotate' => '轮换调用凭证', '/credential/revoke' => '撤销调用凭证',
            '/oauth-client/secret/rotate' => '轮换 OAuth 客户端密钥',
            '/oauth-client/logout-delivery/reissue' => '为失败的 OIDC 后通道登出重新签发令牌',
            '/oidc-signing-key/rotate' => '轮换 OIDC issuer 签名密钥', '/oidc-signing-key/retire' => '退役已过验证宽限期的 OIDC 签名密钥',
            '/federation/provider/create' => '新增联合身份源', '/federation/mount' => '挂载联合身份源', '/federation/sync' => '同步联合身份目录',
            '/scim/token/issue' => '签发 SCIM 令牌',
            '/webhook/save' => '新增事件回调', '/webhook/update' => '修改事件回调', '/webhook/disable' => '停用事件回调',
            '/webhook/secret/rotate' => '轮换事件回调密钥', '/webhook/delivery/retry' => '重试事件投递',
            '/message-provider/save' => '新增消息服务', '/message-provider/update' => '修改消息服务', '/message-provider/disable' => '停用消息服务',
            '/message-provider/mount' => '为应用启用消息服务', '/message-provider/unmount' => '取消应用消息服务',
            '/identity/delete' => '删除应用用户', '/identity/enable' => '启用应用用户', '/identity/restore' => '恢复应用用户',
            '/identity-group/save' => '新增用户组', '/identity-group/update' => '修改用户组', '/identity-group/disable' => '停用用户组',
            '/identity-group/member/add' => '添加用户组成员', '/identity-group/member/remove' => '移除用户组成员',
            '/identity-group-role/grant' => '为用户组授予角色', '/identity-group-role/revoke' => '撤销用户组角色',
            '/identity-invitation/resend' => '重新发送邀请', '/identity-invitation/revoke' => '撤销邀请',
            '/identity-import/confirm' => '确认用户导入', '/oauth-registration-token/revoke' => '撤销动态注册令牌',
            '/sync-connector/save' => '新增目录连接', '/sync-connector/update' => '修改目录连接', '/sync-connector/disable' => '停用目录连接', '/sync-connector/run' => '运行目录同步', '/sync-connector/outbox-retry' => '重新排队失败的同步出站事件',
            '/security-alert/resolve' => '处理安全告警',
            '/acceptance-fixture/cleanup' => '清理本轮受控验收数据',
            '/acceptance-fixture/webhook-event' => '仅向本轮受控 Webhook 端点生成验收事件',
        ];
        $sensitive = [
            '/developer/onboarding/preview' => '预检开发者一份清单接入', '/developer/onboarding/apply' => '应用开发者一份清单接入',
            '/developer/route-manifest/preview' => '预检应用路由清单', '/developer/route-manifest/apply' => '确认并应用路由清单',
            '/federation/configure' => '配置联合身份源密钥', '/scim/token/revoke' => '撤销 SCIM 令牌',
            '/identity-provider-preset/draft' => '生成外部身份源配置草稿（不保存）',
            '/message-provider/configure' => '配置消息服务密钥', '/message-provider/test' => '测试消息服务',
            '/identity-invitation/send' => '发送用户邀请', '/identity-import/preview' => '预检用户导入文件',
            '/sync-connector/configure' => '配置目录连接密钥', '/sync-connector/test' => '测试目录连接',
            '/oauth-registration-token/issue' => '签发动态注册令牌', '/radius-nas/configure' => '配置 RADIUS 共享密钥',
            '/initialization/preview' => '预检初始化包', '/initialization/apply' => '应用初始化包', '/initialization/rollback' => '回滚初始化包',
            '/initialization/save' => '保存初始化草稿', '/initialization/update' => '更新初始化草稿', '/initialization/disable' => '停用初始化草稿',
        ];
        $routes = [];
        foreach ($get as $path => $summary) $routes[] = ['GET', $path, $summary, false];
        foreach ($post as $path => $summary) $routes[] = ['POST', $path, $summary, false];
        foreach ($sensitive as $path => $summary) $routes[] = ['POST', $path, $summary, true];
        return $routes;
    }

    /** @return array{method:string,path:string,operation_id:string,summary:string,permission:string,sensitive:bool} */
    private static function route(string $method, string $path, string $summary, bool $sensitive = false): array
    {
        $operation = str_replace(['/', '-'], ['.', '_'], trim($path, '/'));
        $parts = explode('/', trim($path, '/'));
        $action = str_replace('-', '_', implode('_', array_slice($parts, 1)) ?: 'index');
        $resource = str_replace('-', '_', $parts[0]);
        $permission = self::PERMISSION_OVERRIDES["{$method} {$path}"] ?? "sand_iam:{$resource}:{$action}";
        return ['method' => $method, 'path' => $path, 'operation_id' => str_replace('.', '_', $operation), 'summary' => $summary, 'permission' => $permission, 'sensitive' => $sensitive];
    }

    private static function tag(string $path): string
    {
        $segment = explode('/', trim($path, '/'))[0];
        return $segment === 'developer' ? '开发者工具' : (self::CRUD[$segment] ?? match ($segment) {
            'identity-group-role' => '用户组角色',
            default => $segment,
        });
    }

    /** @param array<string,mixed> $schema @return array<string,mixed> */
    private static function queryParameter(string $name, bool $required, array $schema, string $description): array
    {
        return ['name' => $name, 'in' => 'query', 'required' => $required, 'schema' => $schema, 'description' => $description];
    }

    /** @return array<string,mixed> */
    private static function jsonResponse(string $description, string $schemaRef): array
    {
        return [
            'description' => $description,
            'content' => ['application/json' => ['schema' => ['$ref' => $schemaRef]]],
        ];
    }

    /** @return array<string,mixed> */
    private static function response(string $description): array
    {
        return ['description' => $description, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Envelope']]]];
    }
}
