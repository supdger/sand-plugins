<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Identity;
use plugin\SandIam\app\model\IdentityBinding;
use plugin\SandIam\app\model\IdentityProvider;
use plugin\SandIam\app\model\IdentityProviderApplication;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\model\ScimGroup;
use plugin\SandIam\app\model\ScimGroupMember;
use plugin\SandIam\app\model\ScimResource;
use plugin\SandIam\app\model\ScimToken;
use plugin\SandIam\app\model\AuthSession;
use plugin\SandIam\app\model\AuthRefreshToken;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

/** SCIM 2.0 directory endpoint; it never grants SandAdmin roles. */
final class ScimService
{
    private const SOURCE_EXTENSION = 'urn:sand:params:scim:schemas:extension:source:1.0';

    public function __construct(private readonly AuditWriter $audit = new AuditWriter()) {}

    /** @return array{token:string,id:int} */
    public function issueToken(int $providerId, int $applicationId, string $name, string $requestId, ?string $expireTime = null, bool $manageTransaction = true): array
    {
        $provider = $this->provider($providerId, $applicationId);
        if (!preg_match('/^.{1,128}$/u', $name)) throw new ApiException('SAND_IAM_SCIM_TOKEN_NAME_INVALID', 400);
        $plain = 'sisc_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        try {
            if ($manageTransaction) Db::startTrans();
            $provider = $this->lockedProvider($provider, $applicationId);
            $record = ScimToken::create(['application_id' => $applicationId, 'identity_provider_id' => (int) $provider->id, 'name' => $name, 'token_hash' => $this->tokenHash($plain), 'expire_time' => $this->tokenExpireTime($expireTime), 'status' => 1]);
            $this->audit($provider, $applicationId, 'scim.token_issue', 'succeeded', $requestId, ['token_id' => (int) $record->id]);
            if ($manageTransaction) Db::commit();
        } catch (\Throwable $exception) {
            if ($manageTransaction) Db::rollback();
            if (str_contains(strtolower($exception->getMessage()), 'unique')) throw new ApiException('SAND_IAM_SCIM_TOKEN_CONFLICT', 409);
            throw $exception;
        }
        return ['token' => $plain, 'id' => (int) $record->id];
    }

    /** @return list<array<string,mixed>> */
    public function listTokens(int $providerId, int $applicationId): array
    {
        $provider = $this->provider($providerId, $applicationId);
        return array_map(static fn (ScimToken $token): array => [
            'id' => (int) $token->id,
            'identity_provider_id' => (int) $provider->id,
            'application_id' => (int) $token->application_id,
            'name' => (string) $token->name,
            'status' => (int) $token->status,
            'expire_time' => $token->expire_time,
            'last_used_time' => $token->last_used_time,
            'revoked_time' => $token->revoked_time,
            'create_time' => $token->create_time,
        ], ScimToken::where('identity_provider_id', (int) $provider->id)->where('application_id', $applicationId)->order('id', 'desc')->select()->all());
    }

    public function revokeToken(int $providerId, int $applicationId, int $tokenId, string $requestId, bool $manageTransaction = true): void
    {
        $provider = $this->provider($providerId, $applicationId);
        if ($manageTransaction) Db::startTrans();
        try {
            $provider = $this->lockedProvider($provider, $applicationId);
            $token = ScimToken::where('id',$tokenId)->where('identity_provider_id',(int)$provider->id)->where('application_id',$applicationId)->where('status',1)->lock(true)->find();
            if ($token === null) throw new ApiException('SAND_IAM_SCIM_TOKEN_NOT_FOUND',404);
            $token->save(['status'=>2,'revoked_time'=>date('Y-m-d H:i:s')]);
            $this->audit($provider, $applicationId, 'scim.token_revoke','succeeded',$requestId,['token_id'=>$tokenId]);
            if ($manageTransaction) Db::commit();
        } catch (\Throwable $exception) { if ($manageTransaction) Db::rollback(); throw $exception; }
    }

    /** @return array{0:IdentityProvider,1:int} */
    public function authenticate(string $providerPublicCode, string $token): array
    {
        $provider = IdentityProvider::where('public_code', $providerPublicCode)->where('provider_type', 'scim')->where('status', 1)->find();
        if ($provider === null) throw new ApiException('SAND_IAM_SCIM_UNAUTHORIZED', 401);
        $record = ScimToken::where('identity_provider_id', (int) $provider->id)->where('token_hash', $this->tokenHash($token))->where('status', 1)->find();
        if ($record === null || $record->expire_time === null || strtotime((string) $record->expire_time) <= time()) throw new ApiException('SAND_IAM_SCIM_UNAUTHORIZED', 401);
        $mount = IdentityProviderApplication::where('identity_provider_id', (int) $provider->id)->where('application_id', (int) $record->application_id)->where('organization_id', (int) $provider->organization_id)->where('status', 1)->find();
        $application = $mount === null ? null : Application::where('id', (int) $record->application_id)->where('status', 1)->find();
        $organization = $application === null ? null : Organization::where('id', (int) $application->organization_id)->where('status', 1)->find();
        if ($application === null || $organization === null || $mount === null || ((string) $provider->scope_type === 'application' && (int) $provider->application_id !== (int) $application->id)) throw new ApiException('SAND_IAM_SCIM_UNAUTHORIZED', 401);
        $record->save(['last_used_time' => date('Y-m-d H:i:s')]);
        return [$provider, (int) $record->application_id];
    }

    /** @param array<string,mixed> $resource @return array<string,mixed> */
    public function createUser(IdentityProvider $provider, int $applicationId, array $resource, string $requestId): array
    {
        $sourceKey = $this->sourceKey($resource);
        $externalId = $this->externalId($resource);
        $subject = 'scim:' . $sourceKey;
        if (IdentityBinding::where('identity_provider_id', (int) $provider->id)->where('application_id', $applicationId)->where('subject', $subject)->find()) throw new ApiException('SAND_IAM_SCIM_CONFLICT', 409);
        $username = $this->username($resource['userName'] ?? '');
        $displayName = $this->displayName($resource, $username);
        $sourceState = $this->active($resource) ? 'active' : 'disabled';
        Db::startTrans();
        try {
            $provider = $this->lockedProvider($provider, $applicationId);
            $code = $this->identityCode($provider, $subject);
            $identity = Identity::where('application_id', $applicationId)->where('code', $code)->find();
            if ($identity !== null) throw new ApiException('SAND_IAM_SCIM_CONFLICT', 409);
            $identity = Identity::create(['application_id' => $applicationId, 'code' => $code, 'display_name' => $displayName, 'status' => 1]);
            IdentityBinding::create(['application_id' => $applicationId, 'identity_id' => (int) $identity->id, 'identity_provider_id' => (int) $provider->id, 'subject' => $subject, 'source_state' => $sourceState, 'source_updated_time' => date('Y-m-d H:i:s'), 'status' => 1]);
            $attributes = ['userName' => $username, 'displayName' => $displayName];
            if ($externalId !== null) $attributes['externalId'] = $externalId;
            $scim = ScimResource::create(['application_id' => $applicationId, 'identity_provider_id' => (int) $provider->id, 'identity_id' => (int) $identity->id, 'external_id' => $sourceKey, 'scim_id' => 'su_' . bin2hex(random_bytes(16)), 'version' => 1, 'source_state' => $sourceState, 'source_attributes' => $attributes]);
            $this->audit($provider, $applicationId, 'scim.user_create', 'succeeded', $requestId, ['identity_id' => (int) $identity->id, 'scim_id' => (string) $scim->scim_id]);
            Db::commit();
        } catch (\Throwable $exception) { Db::rollback(); if (str_contains(strtolower($exception->getMessage()), 'unique')) throw new ApiException('SAND_IAM_SCIM_CONFLICT', 409); throw $exception; }
        return $this->resourceUser($scim, $identity);
    }

    /** @return array<string,mixed> */
    public function getUser(IdentityProvider $provider, int $applicationId, string $id): array
    {
        $scim = $this->scimResource($provider, $applicationId, $id);
        $identity = Identity::where('id', (int) $scim->identity_id)->where('application_id', $applicationId)->find();
        if ($identity === null) throw new ApiException('SAND_IAM_SCIM_NOT_FOUND', 404);
        return $this->resourceUser($scim, $identity);
    }

    /** @param array<string,mixed> $patch @return array<string,mixed> */
    public function patchUser(IdentityProvider $provider, int $applicationId, string $id, array $patch, ?string $ifMatch, string $requestId): array
    {
        $operations = $patch['Operations'] ?? null;
        if (!is_array($operations) || $operations === [] || count($operations) > 16) throw new ApiException('SAND_IAM_SCIM_INVALID_PATCH', 400);
        Db::startTrans();
        try {
            $provider = $this->lockedProvider($provider, $applicationId);
            $scim = ScimResource::where('identity_provider_id', (int) $provider->id)->where('application_id', $applicationId)->where('scim_id', $id)->lock(true)->find();
            if ($scim === null || $scim->source_state === 'deleted') throw new ApiException('SAND_IAM_SCIM_NOT_FOUND', 404);
            $this->assertResourceVersion($scim, $ifMatch);
            $attributes = $scim->source_attributes; if (is_string($attributes)) $attributes = json_decode($attributes, true); if (!is_array($attributes)) $attributes = [];
            $changes = []; $paths = [];
            foreach ($operations as $operation) {
                if (!is_array($operation) || !in_array(strtolower((string) ($operation['op'] ?? '')), ['add', 'remove', 'replace'], true)) throw new ApiException('SAND_IAM_SCIM_INVALID_PATCH', 400);
                $path = (string) ($operation['path'] ?? ''); $value = $operation['value'] ?? null;
                $op = strtolower((string) $operation['op']);
                if ($path === '' && is_array($value) && in_array($op, ['add', 'replace'], true)) {
                    if (array_key_exists(self::SOURCE_EXTENSION, $value)) throw new ApiException('SAND_IAM_SCIM_IMMUTABLE_SOURCE_KEY', 400);
                    if (array_key_exists('externalId', $value)) { $externalId = $this->externalId($value); if ($externalId === null) unset($attributes['externalId']); else $attributes['externalId'] = $externalId; $paths[] = 'externalId'; }
                    foreach (['active', 'displayName', 'userName'] as $attribute) {
                        if (!array_key_exists($attribute, $value)) continue;
                        $attributeValue = $value[$attribute];
                        if ($attribute === 'active') { if (!is_bool($attributeValue)) throw new ApiException('SAND_IAM_SCIM_INVALID_PATCH', 400); $changes['source_state'] = $attributeValue ? 'active' : 'disabled'; $paths[] = 'active'; }
                        elseif ($attribute === 'displayName') { if (!is_string($attributeValue)) throw new ApiException('SAND_IAM_SCIM_INVALID_PATCH', 400); $attributes['displayName'] = $this->cleanDisplayName($attributeValue, (string) ($attributes['userName'] ?? '')); $paths[] = 'displayName'; }
                        else { if (!is_string($attributeValue)) throw new ApiException('SAND_IAM_SCIM_INVALID_PATCH', 400); $attributes['userName'] = $this->username($attributeValue); $paths[] = 'userName'; }
                    }
                }
                elseif ($path === 'active' && ($op === 'remove' || is_bool($value))) { $changes['source_state'] = $op === 'remove' ? 'active' : ($value ? 'active' : 'disabled'); $paths[] = 'active'; }
                elseif ($path === 'displayName' && is_string($value)) { $attributes['displayName'] = $this->cleanDisplayName($value, (string) ($attributes['userName'] ?? '')); $paths[] = 'displayName'; }
                elseif ($path === 'userName' && is_string($value)) { $attributes['userName'] = $this->username($value); $paths[] = 'userName'; }
                elseif ($path === 'externalId') { if ($op === 'remove') unset($attributes['externalId']); else { $externalId = $this->externalId(['externalId' => $value]); if ($externalId === null) unset($attributes['externalId']); else $attributes['externalId'] = $externalId; } $paths[] = 'externalId'; }
                elseif ($path === self::SOURCE_EXTENSION || str_starts_with($path, self::SOURCE_EXTENSION . ':')) throw new ApiException('SAND_IAM_SCIM_IMMUTABLE_SOURCE_KEY', 400);
                else throw new ApiException('SAND_IAM_SCIM_INVALID_PATCH', 400);
            }
            $scim->save($changes + ['source_attributes' => $attributes, 'version' => (int) $scim->version + 1]);
            if (array_key_exists('displayName', $attributes)) {
                $identity = Identity::where('id', (int) $scim->identity_id)->where('application_id', $applicationId)->lock(true)->find();
                if ($identity === null) throw new ApiException('SAND_IAM_SCIM_NOT_FOUND', 404);
                $identity->save(['display_name' => (string) $attributes['displayName']]);
            }
            if (isset($changes['source_state'])) $this->setBindingSourceState($provider, $applicationId, (string) $scim->external_id, (string) $changes['source_state']);
            $this->audit($provider, $applicationId, 'scim.user_patch', 'succeeded', $requestId, ['identity_id' => (int) $scim->identity_id, 'paths' => array_values(array_unique($paths))]);
            Db::commit();
        } catch (\Throwable $exception) { Db::rollback(); throw $exception; }
        return $this->resourceUser($scim->refresh(), Identity::find((int) $scim->identity_id));
    }

    /** @param array<string,mixed> $resource @return array<string,mixed> */
    public function replaceUser(IdentityProvider $provider, int $applicationId, string $id, array $resource, ?string $ifMatch, string $requestId): array
    {
        $current = $this->scimResource($provider, $applicationId, $id);
        $this->assertSourceKey((string) $current->external_id, $resource);
        $userName = $this->username($resource['userName'] ?? '');
        $resource['Operations'] = [['op' => 'replace', 'path' => 'active', 'value' => $this->active($resource)], ['op' => 'replace', 'path' => 'displayName', 'value' => $this->displayName($resource, $userName)], ['op' => 'replace', 'path' => 'userName', 'value' => $userName]];
        if (array_key_exists('externalId', $resource)) $resource['Operations'][] = ['op' => 'replace', 'path' => 'externalId', 'value' => $this->externalId($resource)];
        return $this->patchUser($provider, $applicationId, $id, $resource, $ifMatch, $requestId);
    }

    public function deleteUser(IdentityProvider $provider, int $applicationId, string $id, ?string $ifMatch, string $requestId): void
    {
        Db::startTrans();
        try {
            $provider = $this->lockedProvider($provider, $applicationId);
            $scim = ScimResource::where('identity_provider_id', (int) $provider->id)->where('application_id', $applicationId)->where('scim_id', $id)->lock(true)->find();
            if ($scim === null || $scim->source_state === 'deleted') throw new ApiException('SAND_IAM_SCIM_NOT_FOUND', 404);
            $this->assertResourceVersion($scim, $ifMatch);
            $scim->save(['source_state' => 'deleted', 'version' => (int) $scim->version + 1]);
            $this->setBindingSourceState($provider, $applicationId, (string) $scim->external_id, 'deleted');
            $this->audit($provider, $applicationId, 'scim.user_delete', 'succeeded', $requestId, ['scim_id' => $id]);
            Db::commit();
        } catch (\Throwable $exception) { Db::rollback(); throw $exception; }
    }

    /** @return array<string,mixed> */
    public function listUsers(IdentityProvider $provider, int $applicationId, ?string $filter, int $startIndex, int $count): array
    {
        $query = ScimResource::where('identity_provider_id', (int) $provider->id)->where('application_id', $applicationId)->where('source_state', '<>', 'deleted');
        if ($filter !== null && $filter !== '') {
            if (!preg_match('~^(userName|externalId) eq "([^\"]{1,191})"$~', $filter, $match)) throw new ApiException('SAND_IAM_SCIM_INVALID_FILTER', 400);
            if ($match[1] === 'userName') $query->where('source_attributes->userName', $match[2]);
            else $query->where('source_attributes->externalId', $match[2]);
        }
        $count = min(max($count, 1), 100); $startIndex = max($startIndex, 1); $total = (clone $query)->count();
        $items = $query->order('id')->limit($startIndex - 1, $count)->select()->all();
        return ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'], 'totalResults' => $total, 'startIndex' => $startIndex, 'itemsPerPage' => count($items), 'Resources' => array_map(fn (ScimResource $item) => $this->resourceUser($item, Identity::find((int) $item->identity_id)), $items)];
    }

    /** @param array<string,mixed> $resource @return array<string,mixed> */
    public function createGroup(IdentityProvider $provider, int $applicationId, array $resource, string $requestId): array
    {
        $sourceKey = $this->sourceKey($resource); $externalId = $this->externalId($resource); $displayName = $this->groupDisplayName($resource['displayName'] ?? null);
        try { Db::startTrans(); $provider = $this->lockedProvider($provider, $applicationId); $attributes = ['displayName' => $displayName]; if ($externalId !== null) $attributes['externalId'] = $externalId; $group = ScimGroup::create(['application_id' => $applicationId, 'identity_provider_id' => (int) $provider->id, 'external_id' => $sourceKey, 'display_name' => $displayName, 'scim_id' => 'sg_' . bin2hex(random_bytes(16)), 'version' => 1, 'source_state' => 'active', 'source_attributes' => $attributes, 'status' => 1]); $this->replaceMembers($group, $provider, $applicationId, $resource['members'] ?? []); $this->audit($provider, $applicationId, 'scim.group_create', 'succeeded', $requestId, ['group_id' => (int) $group->id]); Db::commit(); } catch (\Throwable $exception) { Db::rollback(); if (str_contains(strtolower($exception->getMessage()), 'unique')) throw new ApiException('SAND_IAM_SCIM_CONFLICT', 409); throw $exception; }
        return $this->group($group);
    }

    /** @return array<string,mixed> */
    public function getGroup(IdentityProvider $provider, int $applicationId, string $id): array { return $this->group($this->scimGroup($provider, $applicationId, $id)); }
    /** @return array<string,mixed> */
    public function listGroups(IdentityProvider $provider, int $applicationId, ?string $filter, int $startIndex, int $count): array
    {
        $query = ScimGroup::where('identity_provider_id', (int) $provider->id)->where('application_id', $applicationId)->where('source_state', '<>', 'deleted');
        if ($filter !== null && $filter !== '') { if (!preg_match('/^(displayName|externalId) eq "([^"\\\\]{1,191})"$/', $filter, $m)) throw new ApiException('SAND_IAM_SCIM_INVALID_FILTER', 400); $query->where($m[1] === 'displayName' ? 'display_name' : 'source_attributes->externalId', $m[2]); }
        $count = min(max($count, 1), 100); $startIndex = max($startIndex, 1); $total = (clone $query)->count(); $items = $query->order('id')->limit($startIndex - 1, $count)->select()->all();
        return ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'], 'totalResults' => $total, 'startIndex' => $startIndex, 'itemsPerPage' => count($items), 'Resources' => array_map(fn (ScimGroup $group) => $this->group($group), $items)];
    }
    /** @param array<string,mixed> $resource @return array<string,mixed> */
    public function replaceGroup(IdentityProvider $provider, int $applicationId, string $id, array $resource, ?string $ifMatch, string $requestId): array
    {
        $current = $this->scimGroup($provider, $applicationId, $id);
        $this->assertSourceKey((string) $current->external_id, $resource);
        Db::startTrans(); try { $provider = $this->lockedProvider($provider, $applicationId); $group = $this->lockedGroup($provider, $applicationId, $id); $this->assertGroupVersion($group, $ifMatch); $name = $this->groupDisplayName($resource['displayName'] ?? null); $attributes = $group->source_attributes; if (is_string($attributes)) $attributes = json_decode($attributes, true); if (!is_array($attributes)) $attributes = []; $attributes['displayName'] = $name; if (array_key_exists('externalId', $resource)) { $externalId = $this->externalId($resource); if ($externalId === null) unset($attributes['externalId']); else $attributes['externalId'] = $externalId; } $group->save(['display_name' => $name, 'source_attributes' => $attributes, 'version' => (int) $group->version + 1]); $this->replaceMembers($group, $provider, $applicationId, $resource['members'] ?? []); $this->audit($provider, $applicationId, 'scim.group_replace', 'succeeded', $requestId, ['scim_id' => $id]); Db::commit(); } catch (\Throwable $e) { Db::rollback(); throw $e; }
        return $this->group($group->refresh());
    }
    /** @param array<string,mixed> $patch @return array<string,mixed> */
    public function patchGroup(IdentityProvider $provider, int $applicationId, string $id, array $patch, ?string $ifMatch, string $requestId): array
    {
        $ops = $patch['Operations'] ?? null; if (!is_array($ops) || $ops === [] || count($ops) > 16) throw new ApiException('SAND_IAM_SCIM_INVALID_PATCH', 400);
        $current = $this->getGroup($provider, $applicationId, $id);
        foreach ($ops as $op) {
            if (!is_array($op) || !in_array(strtolower((string) ($op['op'] ?? '')), ['add','remove','replace'], true)) throw new ApiException('SAND_IAM_SCIM_INVALID_PATCH', 400);
            $kind = strtolower((string) $op['op']); $path = (string) ($op['path'] ?? ''); $value = $op['value'] ?? null;
            if ($path === '' && is_array($value) && in_array($kind, ['add', 'replace'], true)) {
                if (array_key_exists(self::SOURCE_EXTENSION, $value)) throw new ApiException('SAND_IAM_SCIM_IMMUTABLE_SOURCE_KEY', 400);
                if (array_key_exists('externalId', $value)) $current['externalId'] = $this->externalId($value);
                if (array_key_exists('displayName', $value)) { if (!is_string($value['displayName'])) throw new ApiException('SAND_IAM_SCIM_INVALID_PATCH', 400); $current['displayName'] = $this->groupDisplayName($value['displayName']); }
                if (array_key_exists('members', $value)) {
                    $members = $value['members'];
                    if (!is_array($members)) throw new ApiException('SAND_IAM_SCIM_INVALID_PATCH', 400);
                    $current['members'] = $kind === 'add' ? array_merge(is_array($current['members'] ?? null) ? $current['members'] : [], array_is_list($members) ? $members : [$members]) : (array_is_list($members) ? $members : [$members]);
                }
                continue;
            }
            if ($path === 'displayName' && is_string($value) && $kind !== 'remove') { $current['displayName'] = $this->cleanDisplayName($value, ''); continue; }
            if ($path === 'externalId') { $current['externalId'] = $kind === 'remove' ? null : $this->externalId(['externalId' => $value]); continue; }
            if ($path === self::SOURCE_EXTENSION || str_starts_with($path, self::SOURCE_EXTENSION . ':')) throw new ApiException('SAND_IAM_SCIM_IMMUTABLE_SOURCE_KEY', 400);
            if ($path === 'members') {
                if ($kind === 'remove') $current['members'] = [];
                elseif ($kind === 'replace') $current['members'] = is_array($value) && array_is_list($value) ? $value : [$value];
                elseif ($kind === 'add') $current['members'] = array_merge(is_array($current['members'] ?? null) ? $current['members'] : [], is_array($value) && array_is_list($value) ? $value : [$value]);
                else throw new ApiException('SAND_IAM_SCIM_INVALID_PATCH', 400);
                continue;
            }
            if ($kind === 'remove' && preg_match('/^members\\[value eq "([^"\\\\]{1,191})"\\]$/', $path, $match)) {
                $current['members'] = array_values(array_filter(is_array($current['members'] ?? null) ? $current['members'] : [], static fn (mixed $member): bool => !is_array($member) || (string) ($member['value'] ?? '') !== $match[1]));
                continue;
            }
            throw new ApiException('SAND_IAM_SCIM_INVALID_PATCH',400);
        }
        return $this->replaceGroup($provider,$applicationId,$id,$current,$ifMatch,$requestId);
    }
    public function deleteGroup(IdentityProvider $provider,int $applicationId,string $id,?string $ifMatch,string $requestId): void { Db::startTrans(); try{$provider=$this->lockedProvider($provider,$applicationId);$group=$this->lockedGroup($provider,$applicationId,$id);$this->assertGroupVersion($group,$ifMatch);$group->save(['source_state'=>'deleted','status'=>2,'version'=>(int)$group->version+1]);foreach(ScimGroupMember::withTrashed()->where('group_id',(int)$group->id)->where('application_id',$applicationId)->lock(true)->select()->all() as $member){if((int)$member->status===1){$member->save(['status'=>2]);$member->delete();}}$this->audit($provider,$applicationId,'scim.group_delete','succeeded',$requestId,['scim_id'=>$id]);Db::commit();}catch(\Throwable $e){Db::rollback();throw $e;} }

    /** @return array<string,mixed> */
    public function serviceProviderConfig(): array
    {
        return ['schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig'], 'patch' => ['supported' => true], 'bulk' => ['supported' => false], 'filter' => ['supported' => true, 'maxResults' => 100], 'changePassword' => ['supported' => false], 'sort' => ['supported' => false], 'etag' => ['supported' => true], 'authenticationSchemes' => [['type' => 'oauthbearertoken', 'name' => 'Bearer Token', 'primary' => true]]];
    }

    /** @return array<string,mixed> */
    public function schemas(): array { return ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'], 'totalResults' => 3, 'Resources' => [['id' => 'urn:ietf:params:scim:schemas:core:2.0:User', 'name' => 'User', 'attributes' => [['name' => 'userName', 'required' => true, 'mutability' => 'readWrite'], ['name' => 'externalId', 'mutability' => 'readWrite'], ['name' => 'displayName', 'mutability' => 'readWrite'], ['name' => 'active', 'mutability' => 'readWrite']]], ['id' => 'urn:ietf:params:scim:schemas:core:2.0:Group', 'name' => 'Group', 'attributes' => [['name' => 'externalId', 'mutability' => 'readWrite'], ['name' => 'displayName', 'required' => true, 'mutability' => 'readWrite'], ['name' => 'members', 'multiValued' => true, 'mutability' => 'readWrite']]], ['id' => self::SOURCE_EXTENSION, 'name' => 'SandIAM source', 'attributes' => [['name' => 'sourceKey', 'required' => true, 'mutability' => 'immutable']]]]]; }
    /** @return array<string,mixed> */
    public function resourceTypes(): array { return ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'], 'totalResults' => 2, 'Resources' => [['id' => 'User', 'name' => 'User', 'endpoint' => '/Users', 'schema' => 'urn:ietf:params:scim:schemas:core:2.0:User'], ['id' => 'Group', 'name' => 'Group', 'endpoint' => '/Groups', 'schema' => 'urn:ietf:params:scim:schemas:core:2.0:Group']]]; }

    private function provider(int $providerId, int $applicationId): IdentityProvider { $provider = IdentityProvider::where('id', $providerId)->where('provider_type', 'scim')->where('status', 1)->find(); return $this->assertProviderAvailable($provider, $applicationId, false); }
    private function lockedProvider(IdentityProvider $provider, int $applicationId): IdentityProvider { $live = IdentityProvider::where('id', (int) $provider->id)->where('provider_type', 'scim')->where('status', 1)->lock(true)->find(); return $this->assertProviderAvailable($live, $applicationId, true); }
    private function assertProviderAvailable(?IdentityProvider $provider, int $applicationId, bool $lock): IdentityProvider { $applicationQuery = $provider === null ? null : Application::where('id', $applicationId)->where('organization_id', (int) $provider->organization_id)->where('status', 1); if ($applicationQuery !== null && $lock) $applicationQuery->lock(true); $application = $applicationQuery?->find(); $organizationQuery = $application === null ? null : Organization::where('id', (int) $application->organization_id)->where('status', 1); if ($organizationQuery !== null && $lock) $organizationQuery->lock(true); $organization = $organizationQuery?->find(); $mountQuery = $application === null || $provider === null ? null : IdentityProviderApplication::where('identity_provider_id', (int) $provider->id)->where('application_id', $applicationId)->where('organization_id', (int) $application->organization_id)->where('status', 1); if ($mountQuery !== null && $lock) $mountQuery->lock(true); $mount = $mountQuery?->find(); if ($provider === null || $application === null || $organization === null || $mount === null || ((string) $provider->scope_type === 'application' && (int) $provider->application_id !== $applicationId)) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_UNAVAILABLE', 404); return $provider; }
    private function tokenHash(string $value): string { $pepper = (string) config('plugin.sand-iam.app.auth_pepper', ''); if ($pepper === '') throw new ApiException('SAND_IAM_FEDERATION_CONFIGURATION_UNAVAILABLE', 503); return hash_hmac('sha256', 'scim:' . $value, $pepper); }
    private function externalId(array $resource): ?string { if (!array_key_exists('externalId', $resource)) return null; $value = $resource['externalId']; if ($value === null) return null; if (!is_string($value) || strlen($value) > 186 || trim($value) !== $value || !preg_match('//u', $value) || preg_match('/[\p{Cc}]/u', $value)) throw new ApiException('SAND_IAM_SCIM_INVALID_RESOURCE', 400); return $value; }
    private function sourceKey(array $resource): string { $extension = $resource[self::SOURCE_EXTENSION] ?? null; $value = is_array($extension) ? ($extension['sourceKey'] ?? null) : null; if (!is_string($value) || $value === '' || strlen($value) > 186 || trim($value) !== $value || !preg_match('//u', $value) || preg_match('/[\p{Cc}]/u', $value)) throw new ApiException('SAND_IAM_SCIM_SOURCE_KEY_INVALID', 400); return $value; }
    private function assertSourceKey(string $actual, array $resource): void { if (!array_key_exists(self::SOURCE_EXTENSION, $resource)) return; if (!hash_equals($actual, $this->sourceKey($resource))) throw new ApiException('SAND_IAM_SCIM_IMMUTABLE_SOURCE_KEY', 400); }
    private function username(mixed $value): string { if (!is_string($value) || trim($value) !== $value || !preg_match('//u', $value) || preg_match('/[\p{Cc}]/u', $value) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.@-]{1,127}$/', $value)) throw new ApiException('SAND_IAM_SCIM_INVALID_RESOURCE', 400); return $value; }
    private function displayName(array $resource, string $fallback): string { $value = $resource['displayName'] ?? ($resource['name']['formatted'] ?? $fallback); if (!is_string($value)) throw new ApiException('SAND_IAM_SCIM_INVALID_RESOURCE', 400); return $this->cleanDisplayName($value, $fallback); }
    private function cleanDisplayName(string $value, string $fallback): string { if (!preg_match('//u', $value)) throw new ApiException('SAND_IAM_SCIM_INVALID_RESOURCE', 400); $value = preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $value) ?? ''; $value = preg_replace('/\s+/u', ' ', trim($value)) ?? ''; $value = mb_substr($value, 0, 128); return $value === '' ? $fallback : $value; }
    private function groupDisplayName(mixed $value): string { if (!is_string($value)) throw new ApiException('SAND_IAM_SCIM_INVALID_RESOURCE', 400); $value = $this->cleanDisplayName($value, ''); if ($value === '') throw new ApiException('SAND_IAM_SCIM_INVALID_RESOURCE', 400); return $value; }
    private function identityCode(IdentityProvider $provider, string $subject): string { return 'scim-p' . (int) $provider->id . '-' . substr(hash('sha256', 'scim-subject:v1\0' . $subject), 0, 32); }
    private function active(array $resource): bool { if (!array_key_exists('active', $resource)) return true; if (!is_bool($resource['active'])) throw new ApiException('SAND_IAM_SCIM_INVALID_RESOURCE', 400); return $resource['active']; }
    private function tokenExpireTime(?string $expireTime): string { if ($expireTime === null || trim($expireTime) === '') return date('Y-m-d H:i:s', time() + 90 * 86400); $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $expireTime); $errors = \DateTimeImmutable::getLastErrors(); if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d H:i:s') !== $expireTime || $date->getTimestamp() <= time() || $date->getTimestamp() > time() + 366 * 86400) throw new ApiException('SAND_IAM_SCIM_TOKEN_EXPIRE_INVALID', 400); return $expireTime; }
    private function scimResource(IdentityProvider $provider, int $applicationId, string $id): ScimResource { $resource = ScimResource::where('identity_provider_id', (int) $provider->id)->where('application_id', $applicationId)->where('scim_id', $id)->find(); if ($resource === null || $resource->source_state === 'deleted') throw new ApiException('SAND_IAM_SCIM_NOT_FOUND', 404); return $resource; }
    private function scimGroup(IdentityProvider $provider, int $applicationId, string $id): ScimGroup { $group = ScimGroup::where('identity_provider_id', (int) $provider->id)->where('application_id', $applicationId)->where('scim_id', $id)->find(); if ($group === null || $group->source_state === 'deleted') throw new ApiException('SAND_IAM_SCIM_NOT_FOUND', 404); return $group; }
    private function lockedGroup(IdentityProvider $provider, int $applicationId, string $id): ScimGroup { $group = ScimGroup::where('identity_provider_id', (int) $provider->id)->where('application_id', $applicationId)->where('scim_id', $id)->lock(true)->find(); if ($group === null || $group->source_state === 'deleted') throw new ApiException('SAND_IAM_SCIM_NOT_FOUND', 404); return $group; }
    /** @param mixed $members */
    private function replaceMembers(ScimGroup $group, IdentityProvider $provider, int $applicationId, mixed $members): void
    {
        if (!is_array($members) || count($members) > 10_000) throw new ApiException('SAND_IAM_SCIM_INVALID_RESOURCE', 400);
        $ids = [];
        foreach ($members as $member) {
            if (!is_array($member) || !is_string($member['value'] ?? null)) throw new ApiException('SAND_IAM_SCIM_INVALID_RESOURCE', 400);
            $resource = $this->scimResource($provider, $applicationId, (string) $member['value']);
            $ids[] = (int) $resource->identity_id;
        }
        $ids = array_values(array_unique($ids));
        $members = [];
        foreach (ScimGroupMember::withTrashed()->where('group_id', (int) $group->id)->lock(true)->select()->all() as $member) {
            if ((int) $member->application_id !== $applicationId) throw new ApiException('SAND_IAM_SCIM_NOT_FOUND', 404);
            $members[(int) $member->identity_id] = $member;
        }
        foreach ($members as $identityId => $member) {
            if (!in_array($identityId, $ids, true) && (int) $member->status === 1) {
                $member->save(['status' => 2]);
                $member->delete();
            }
        }
        foreach ($ids as $identityId) {
            $member = $members[$identityId] ?? null;
            if ($member === null) ScimGroupMember::create(['group_id' => (int) $group->id, 'application_id' => $applicationId, 'identity_id' => $identityId, 'status' => 1]);
            elseif ((int) $member->status !== 1 || $member->delete_time !== null) {
                ScimGroupMember::withTrashed()->where('id', (int) $member->id)->update([
                    'status' => 1,
                    'delete_time' => null,
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }
    private function resourceVersion(ScimResource $resource): string { return 'W/"' . (int) $resource->version . '"'; }
    private function assertResourceVersion(ScimResource $resource, ?string $ifMatch): void { if ($ifMatch === null || $ifMatch === '') throw new ApiException('SAND_IAM_SCIM_PRECONDITION_REQUIRED', 428); if (!hash_equals($this->resourceVersion($resource), trim($ifMatch))) throw new ApiException('SAND_IAM_SCIM_PRECONDITION_FAILED', 412); }
    private function setBindingSourceState(IdentityProvider $provider, int $applicationId, string $externalId, string $state): void
    {
        $binding = IdentityBinding::where('identity_provider_id',(int)$provider->id)->where('application_id',$applicationId)->where('subject','scim:'.$externalId)->lock(true)->find();
        if ($binding === null) throw new ApiException('SAND_IAM_SCIM_NOT_FOUND', 404);
        $binding->save(['source_state'=>$state,'source_updated_time'=>date('Y-m-d H:i:s')]);
        if ($state === 'active') return;
        $sessions = AuthSession::where('identity_binding_id',(int)$binding->id)->where('status',1)->column('id');
        if ($sessions !== []) { AuthSession::whereIn('id',$sessions)->update(['status'=>2,'revoked_time'=>date('Y-m-d H:i:s')]); AuthRefreshToken::whereIn('session_id',$sessions)->where('status',1)->update(['status'=>2,'revoked_time'=>date('Y-m-d H:i:s')]); }
    }
    private function assertGroupVersion(ScimGroup $group, ?string $ifMatch): void { if ($ifMatch === null || $ifMatch === '') throw new ApiException('SAND_IAM_SCIM_PRECONDITION_REQUIRED', 428); if (!hash_equals('W/"' . (int) $group->version . '"', trim($ifMatch))) throw new ApiException('SAND_IAM_SCIM_PRECONDITION_FAILED', 412); }
    /** @return array<string,mixed> */
    private function resourceUser(ScimResource $resource, ?Identity $identity): array { if ($identity === null) throw new ApiException('SAND_IAM_SCIM_NOT_FOUND', 404); $attributes = $resource->source_attributes; if (is_string($attributes)) $attributes = json_decode($attributes, true); if (!is_array($attributes)) $attributes = []; $response = ['schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User', self::SOURCE_EXTENSION], 'id' => (string) $resource->scim_id, 'userName' => (string) ($attributes['userName'] ?? $identity->code), 'displayName' => (string) ($attributes['displayName'] ?? $identity->display_name), 'active' => $resource->source_state === 'active', self::SOURCE_EXTENSION => ['sourceKey' => (string) $resource->external_id], 'meta' => ['resourceType' => 'User', 'version' => $this->resourceVersion($resource), 'lastModified' => (string) $resource->update_time]]; if (array_key_exists('externalId', $attributes)) $response['externalId'] = $attributes['externalId']; return $response; }
    /** @return array<string,mixed> */
    private function group(ScimGroup $group): array { $members=[]; foreach (ScimGroupMember::where('group_id',(int)$group->id)->where('application_id',(int)$group->application_id)->where('status',1)->select()->all() as $member) { $resource=ScimResource::where('identity_provider_id',(int)$group->identity_provider_id)->where('application_id',(int)$group->application_id)->where('identity_id',(int)$member->identity_id)->where('source_state','<>','deleted')->find(); if($resource!==null)$members[]=['value'=>(string)$resource->scim_id]; } $attributes = $group->source_attributes; if (is_string($attributes)) $attributes = json_decode($attributes, true); if (!is_array($attributes)) $attributes = []; $response = ['schemas' => ['urn:ietf:params:scim:schemas:core:2.0:Group', self::SOURCE_EXTENSION], 'id' => (string) $group->scim_id, 'displayName' => (string) $group->display_name, 'members'=>$members, self::SOURCE_EXTENSION => ['sourceKey' => (string) $group->external_id], 'meta' => ['resourceType' => 'Group', 'version' => 'W/"' . (int) $group->version . '"', 'lastModified' => (string) $group->update_time]]; if (array_key_exists('externalId', $attributes)) $response['externalId'] = $attributes['externalId']; return $response; }
    private function audit(IdentityProvider $provider, int $applicationId, string $action, string $outcome, string $requestId, array $context): void { $application = Application::where('id', $applicationId)->where('organization_id', (int) $provider->organization_id)->find(); $this->audit->write('scim', (string) $provider->id, $application ? (int) $application->organization_id : null, $application ? (int) $application->id : null, $action, 'identity_provider', (int) $provider->id, $outcome, $requestId !== '' ? substr($requestId, 0, 96) : bin2hex(random_bytes(16)), $context); }
}
