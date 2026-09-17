<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service {
    final class AuditWriter {}
}

namespace {
    use plugin\SandIam\app\service\AuditWriter;
    use plugin\SandIam\app\service\ScimService;

    require dirname(__DIR__) . '/app/service/ScimService.php';

    $service = new ScimService(new AuditWriter());
    $schemas = $service->schemas();
    $resources = [];
    foreach ($schemas['Resources'] ?? [] as $schema) {
        $resources[$schema['id'] ?? ''] = $schema;
        foreach ($schema['attributes'] ?? [] as $attribute) {
            foreach (['name', 'type', 'multiValued', 'required', 'mutability'] as $field) {
                if (!array_key_exists($field, $attribute)) {
                    throw new RuntimeException("SCIM attribute discovery omits {$field}");
                }
            }
        }
    }

    $userUrn = 'urn:ietf:params:scim:schemas:core:2.0:User';
    $groupUrn = 'urn:ietf:params:scim:schemas:core:2.0:Group';
    $extensionUrn = 'urn:sand:params:scim:schemas:extension:source:1.0';
    if (count($resources) !== 3) throw new RuntimeException('SCIM schema discovery must return exactly three schemas');
    foreach ([$userUrn, $groupUrn, $extensionUrn] as $urn) {
        if (!isset($resources[$urn])) throw new RuntimeException("SCIM schema discovery omits {$urn}");
        if (($resources[$urn]['schemas'][0] ?? null) !== 'urn:ietf:params:scim:schemas:core:2.0:Schema') {
            throw new RuntimeException("SCIM schema resource {$urn} omits its own schema");
        }
    }

    $userAttributes = array_column($resources[$userUrn]['attributes'], null, 'name');
    foreach (['userName' => 'string', 'externalId' => 'string', 'displayName' => 'string', 'active' => 'boolean'] as $name => $type) {
        if (($userAttributes[$name]['type'] ?? null) !== $type) {
            throw new RuntimeException("SCIM User attribute {$name} has no usable type");
        }
    }
    if (($userAttributes['userName']['required'] ?? null) !== true
        || ($userAttributes['externalId']['caseExact'] ?? null) !== true) {
        throw new RuntimeException('SCIM User attribute metadata does not match the service contract');
    }
    $groupAttributes = array_column($resources[$groupUrn]['attributes'], null, 'name');
    if (($groupAttributes['members']['type'] ?? null) !== 'complex'
        || ($groupAttributes['members']['multiValued'] ?? null) !== true
        || ($groupAttributes['members']['subAttributes'][0]['name'] ?? null) !== 'value'
        || ($groupAttributes['members']['subAttributes'][0]['type'] ?? null) !== 'string'
        || ($groupAttributes['members']['subAttributes'][0]['mutability'] ?? null) !== 'immutable'
        || ($groupAttributes['members']['subAttributes'][0]['caseExact'] ?? null) !== true
        || ($groupAttributes['displayName']['required'] ?? null) !== true
        || ($groupAttributes['externalId']['caseExact'] ?? null) !== true) {
        throw new RuntimeException('SCIM Group members discovery is incomplete');
    }

    $resourceTypes = array_column($service->resourceTypes()['Resources'] ?? [], null, 'id');
    $expectedResourceTypes = [
        'User' => ['/Users', $userUrn],
        'Group' => ['/Groups', $groupUrn],
    ];
    if (array_keys($resourceTypes) !== array_keys($expectedResourceTypes)) {
        throw new RuntimeException('SCIM discovery must return exactly the User and Group resource types');
    }
    foreach ($expectedResourceTypes as $id => [$endpoint, $schema]) {
        $resourceType = $resourceTypes[$id];
        if (($resourceType['schemas'][0] ?? null) !== 'urn:ietf:params:scim:schemas:core:2.0:ResourceType') {
            throw new RuntimeException('SCIM resource type omits its own schema');
        }
        if (($resourceType['name'] ?? null) !== $id
            || ($resourceType['endpoint'] ?? null) !== $endpoint
            || ($resourceType['schema'] ?? null) !== $schema) {
            throw new RuntimeException("SCIM resource type {$id} has an invalid endpoint or base schema");
        }
        $extensions = $resourceType['schemaExtensions'] ?? [];
        if (($extensions[0]['schema'] ?? null) !== $extensionUrn || ($extensions[0]['required'] ?? null) !== true) {
            throw new RuntimeException('SCIM resource type omits the required SandIAM source extension');
        }
    }
    $sourceAttributes = array_column($resources[$extensionUrn]['attributes'], null, 'name');
    if (array_keys($sourceAttributes) !== ['sourceKey']
        || ($sourceAttributes['sourceKey']['type'] ?? null) !== 'string'
        || ($sourceAttributes['sourceKey']['required'] ?? null) !== true
        || ($sourceAttributes['sourceKey']['mutability'] ?? null) !== 'immutable'
        || ($sourceAttributes['sourceKey']['caseExact'] ?? null) !== true) {
        throw new RuntimeException('SCIM sourceKey discovery must match case-sensitive immutable comparison');
    }

    echo "SCIM schema discovery checks passed\n";
}
