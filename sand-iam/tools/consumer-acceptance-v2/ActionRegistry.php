<?php

declare(strict_types=1);

/** Immutable action and state-machine registry; plans carry IDs, never paths. */
final class ConsumerAcceptanceV2ActionRegistry
{
    /** @return array<string,array{effect:string,authorization_order:string,origin:string,method:string,path:string}> */
    public static function actions(): array
    {
        return [
            'iam_login' => ['effect' => 'business_write', 'authorization_order' => 'after_authorization', 'origin' => 'iam', 'method' => 'POST', 'path' => '/api/sand-iam/v1/auth/login'],
            'a_decide' => ['effect' => 'audit_side_effect', 'authorization_order' => 'after_authorization', 'origin' => 'iam', 'method' => 'POST', 'path' => '/api/sand-iam/v1/authorization/decide'],
            'a_session_revoke' => ['effect' => 'business_write', 'authorization_order' => 'after_authorization', 'origin' => 'iam', 'method' => 'POST', 'path' => '/api/sand-iam/v1/auth/sessions/revoke'],
            'a_consumer_read' => ['effect' => 'audit_side_effect', 'authorization_order' => 'after_authorization', 'origin' => 'a', 'method' => 'GET', 'path' => '/items/{business_object_id}'],
            'a_consumer_close' => ['effect' => 'business_write', 'authorization_order' => 'after_authorization', 'origin' => 'a', 'method' => 'POST', 'path' => '/items/{business_object_id}/close'],
            'b_issue' => ['effect' => 'audit_side_effect', 'authorization_order' => 'after_authorization', 'origin' => 'iam', 'method' => 'POST', 'path' => '/app/sand-iam/runtime/context/issue'],
            'b_verify' => ['effect' => 'audit_side_effect', 'authorization_order' => 'after_authorization', 'origin' => 'iam', 'method' => 'POST', 'path' => '/app/sand-iam/runtime/context/verify'],
            'b_provider_process' => ['effect' => 'business_write', 'authorization_order' => 'after_authorization', 'origin' => 'b', 'method' => 'POST', 'path' => '/provider/v1/documents/{business_object_id}/process'],
            'admin_credential_revoke' => ['effect' => 'business_write', 'authorization_order' => 'after_authorization', 'origin' => 'iam', 'method' => 'POST', 'path' => '/app/sand-iam/admin/credential/revoke'],
            'admin_grant_revoke' => ['effect' => 'business_write', 'authorization_order' => 'after_authorization', 'origin' => 'iam', 'method' => 'POST', 'path' => '/app/sand-iam/admin/grant/revoke'],
            'admin_audit' => ['effect' => 'strict_read_only', 'authorization_order' => 'after_authorization', 'origin' => 'iam', 'method' => 'GET', 'path' => '/app/sand-iam/admin/audit/index'],
        ];
    }

    /** @return list<string> */
    public static function steps(): array
    {
        return array_keys(self::actions());
    }

    public static function manifestHash(): string
    {
        return hash('sha256', json_encode(self::actions(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
