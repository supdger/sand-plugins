<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\CasLoginRequest;
use plugin\SandIam\app\model\CasService;
use plugin\SandIam\app\model\CasTicket;
use plugin\SandIam\app\model\Identity;
use plugin\SandIam\app\model\IdentityAuth;
use plugin\SandIam\app\model\Organization;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

final class CasProtocolService
{
    public function __construct(
        private readonly HumanAuthService $auth = new HumanAuthService(),
        private readonly AuditWriter $audit = new AuditWriter(),
    ) {}

    /** @return array{request:string,interaction_uri:string} */
    public function beginLogin(string $serviceUrl, bool $renew, bool $gateway, string $requestId): array
    {
        $this->enabled();
        if ($renew || $gateway) throw new ApiException('SAND_IAM_CAS_OPTION_UNSUPPORTED', 400);
        $service = $this->registeredService($serviceUrl);
        $token = 'CRT-' . $this->randomToken(36);
        CasLoginRequest::create([
            'application_id' => (int) $service->application_id,
            'cas_service_id' => (int) $service->id,
            'request_hash' => $this->hash('cas-request:' . $token),
            'expire_time' => date('Y-m-d H:i:s', time() + 300),
            'status' => 1,
        ]);
        $this->writeAudit($service, 'cas.login.begin', 'cas_login_request', 0, 'anonymous', $requestId, ['service_id' => (int) $service->id]);
        return ['request' => $token, 'interaction_uri' => $this->issuer() . '/cas/interaction?request=' . rawurlencode($token)];
    }

    /** @return array{application_id:int,organization_code:string,application_code:string,application_name:string,service_name:string,service_url:string,expires_in:int} */
    public function interaction(string $requestToken): array
    {
        $this->enabled();
        $request = $this->loginRequest($requestToken);
        $service = CasService::where('id', (int) $request->cas_service_id)->where('application_id', (int) $request->application_id)->where('status', 1)->find();
        $application = Application::where('id', (int) $request->application_id)->where('status', 1)->find();
        $organization = $application === null ? null : Organization::where('id', (int) $application->organization_id)->where('status', 1)->find();
        if ($service === null || $application === null || $organization === null) throw new ApiException('SAND_IAM_CAS_REQUEST_INVALID', 400);
        return [
            'application_id' => (int) $application->id,
            'organization_code' => (string) $organization->code,
            'application_code' => (string) $application->code,
            'application_name' => (string) $application->name,
            'service_name' => (string) $service->name,
            'service_url' => (string) $service->service_url,
            'expires_in' => max(0, strtotime((string) $request->expire_time) - time()),
        ];
    }

    /** @return array{redirect_uri:string,expires_in:int} */
    public function confirm(string $requestToken, string $accessToken, string $requestId): array
    {
        $this->enabled();
        [$application, $identity] = $this->auth->authenticatedPrincipal($accessToken);
        Db::startTrans();
        try {
            $request = $this->loginRequest($requestToken, true);
            $service = CasService::where('id', (int) $request->cas_service_id)->where('application_id', (int) $request->application_id)->where('status', 1)->lock(true)->find();
            if ($service === null || (int) $application->id !== (int) $request->application_id || (int) $identity->application_id !== (int) $request->application_id) {
                throw new ApiException('SAND_IAM_CAS_APPLICATION_MISMATCH', 403);
            }
            $ticket = 'ST-' . $this->randomToken(40);
            $record = CasTicket::create([
                'application_id' => (int) $request->application_id,
                'cas_service_id' => (int) $service->id,
                'identity_id' => (int) $identity->id,
                'ticket_hash' => $this->hash('cas-ticket:' . $ticket),
                'expire_time' => date('Y-m-d H:i:s', time() + 300),
                'status' => 1,
            ]);
            $request->save(['consumed_time' => date('Y-m-d H:i:s'), 'status' => 2]);
            $this->writeAudit($service, 'cas.ticket.issue', 'cas_ticket', (int) $record->id, (string) $identity->id, $requestId);
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
        return ['redirect_uri' => $this->appendTicket((string) $service->service_url, $ticket), 'expires_in' => 300];
    }

    /**
     * Declines a CAS sign-in once. The request is consumed under the same lock
     * as confirmation so it cannot later issue a ticket; the registered service
     * receives its exact URL without a ticket and the event is auditable.
     *
     * @return array{redirect_uri:string,expires_in:int}
     */
    public function reject(string $requestToken, string $accessToken, string $requestId): array
    {
        $this->enabled();
        [$application, $identity] = $this->auth->authenticatedPrincipal($accessToken);
        Db::startTrans();
        try {
            $request = $this->loginRequest($requestToken, true);
            $service = CasService::where('id', (int) $request->cas_service_id)->where('application_id', (int) $request->application_id)->where('status', 1)->lock(true)->find();
            if ($service === null || (int) $application->id !== (int) $request->application_id || (int) $identity->application_id !== (int) $request->application_id) {
                throw new ApiException('SAND_IAM_CAS_APPLICATION_MISMATCH', 403);
            }
            $request->save(['consumed_time' => date('Y-m-d H:i:s'), 'status' => 2]);
            $this->writeAudit($service, 'cas.login.reject', 'cas_login_request', (int) $request->id, (string) $identity->id, $requestId, ['reason' => 'user_denied']);
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
        return ['redirect_uri' => (string) $service->service_url, 'expires_in' => 0];
    }

    /**
     * Consumes a service ticket exactly once.
     *
     * @return array{username:string,attributes:array<string,string>}|null
     */
    public function validate(string $serviceUrl, string $ticket, string $requestId): ?array
    {
        $this->enabled();
        if (!$this->validServiceUrl($serviceUrl) || !preg_match('/^ST-[A-Za-z0-9_-]{54}$/', $ticket)) return null;
        Db::startTrans();
        try {
            $record = CasTicket::where('ticket_hash', $this->hash('cas-ticket:' . $ticket))->lock(true)->find();
            if ($record === null || (int) $record->status !== 1 || $record->consumed_time !== null || strtotime((string) $record->expire_time) <= time()) {
                Db::commit();
                return null;
            }
            $service = CasService::where('id', (int) $record->cas_service_id)->where('application_id', (int) $record->application_id)->where('status', 1)->find();
            $identity = Identity::where('id', (int) $record->identity_id)->where('application_id', (int) $record->application_id)->where('status', 1)->find();
            $identityAuth = IdentityAuth::where('identity_id', (int) $record->identity_id)->where('application_id', (int) $record->application_id)->where('status', 1)->find();
            $application = Application::where('id', (int) $record->application_id)->where('status', 1)->find();
            $organization = $application === null ? null : Organization::where('id', (int) $application->organization_id)->where('status', 1)->find();
            $record->save(['consumed_time' => date('Y-m-d H:i:s'), 'status' => 2]);
            if ($service === null || $identity === null || $application === null || $organization === null || !hash_equals((string) $service->service_url, $serviceUrl)) {
                Db::commit();
                return null;
            }
            $username = trim((string) ($identity->code ?? ''));
            if ($username === '') {
                Db::commit();
                return null;
            }
            $attributes = [];
            $released = $service->released_attributes;
            if (is_string($released)) $released = json_decode($released, true);
            foreach (is_array($released) ? $released : [] as $attribute) {
                if ($attribute === 'display_name' && trim((string) ($identity->display_name ?? '')) !== '') $attributes['displayName'] = (string) $identity->display_name;
                if ($attribute === 'email' && $identityAuth !== null && trim((string) ($identityAuth->email ?? '')) !== '') $attributes['email'] = (string) $identityAuth->email;
            }
            $this->writeAudit($service, 'cas.ticket.validate', 'cas_ticket', (int) $record->id, 'cas_client', $requestId, ['identity_id' => (int) $identity->id]);
            Db::commit();
            return ['username' => $username, 'attributes' => $attributes];
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
    }

    public function cas1Response(?array $principal): string
    {
        return $principal === null ? "no\n\n" : "yes\n" . str_replace(["\r", "\n"], '', (string) $principal['username']) . "\n";
    }

    /** @param array{username:string,attributes:array<string,string>}|null $principal */
    public function xmlResponse(?array $principal, bool $includeAttributes): string
    {
        $prefix = '<?xml version="1.0" encoding="UTF-8"?>' . '<cas:serviceResponse xmlns:cas="http://www.yale.edu/tp/cas">';
        if ($principal === null) return $prefix . '<cas:authenticationFailure code="INVALID_TICKET">票据无效、已过期或已使用</cas:authenticationFailure></cas:serviceResponse>';
        $body = '<cas:authenticationSuccess><cas:user>' . $this->xml((string) $principal['username']) . '</cas:user>';
        if ($includeAttributes && $principal['attributes'] !== []) {
            $body .= '<cas:attributes>';
            foreach ($principal['attributes'] as $name => $value) $body .= '<cas:' . $name . '>' . $this->xml($value) . '</cas:' . $name . '>';
            $body .= '</cas:attributes>';
        }
        return $prefix . $body . '</cas:authenticationSuccess></cas:serviceResponse>';
    }

    private function registeredService(string $url): CasService
    {
        if (!$this->validServiceUrl($url)) throw new ApiException('SAND_IAM_CAS_SERVICE_INVALID', 400);
        $service = CasService::where('service_url', $url)->where('status', 1)->find();
        if ($service === null || Application::where('id', (int) $service->application_id)->where('status', 1)->find() === null) throw new ApiException('SAND_IAM_CAS_SERVICE_NOT_REGISTERED', 403);
        return $service;
    }

    private function loginRequest(string $token, bool $lock = false): CasLoginRequest
    {
        if (!preg_match('/^CRT-[A-Za-z0-9_-]{48}$/', $token)) throw new ApiException('SAND_IAM_CAS_REQUEST_INVALID', 400);
        $query = CasLoginRequest::where('request_hash', $this->hash('cas-request:' . $token))->where('status', 1);
        if ($lock) $query->lock(true);
        $request = $query->find();
        if ($request === null || $request->consumed_time !== null || strtotime((string) $request->expire_time) <= time()) throw new ApiException('SAND_IAM_CAS_REQUEST_INVALID', 400);
        return $request;
    }

    private function validServiceUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 512 || !filter_var($url, FILTER_VALIDATE_URL) || str_contains($url, '*')) return false;
        $parts = parse_url($url);
        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) return false;
        parse_str((string) ($parts['query'] ?? ''), $query);
        return !array_key_exists('ticket', $query);
    }

    private function appendTicket(string $serviceUrl, string $ticket): string
    {
        if (!$this->validServiceUrl($serviceUrl)) throw new ApiException('SAND_IAM_CAS_SERVICE_INVALID', 400);
        return $serviceUrl . (str_contains($serviceUrl, '?') ? '&' : '?') . 'ticket=' . rawurlencode($ticket);
    }

    private function hash(string $value): string { return hash_hmac('sha256', $value, $this->pepper()); }
    private function pepper(): string { $value = (string) config('plugin.sand-iam.app.auth_pepper', ''); if (strlen($value) < 32) throw new ApiException('SAND_IAM_CAS_CONFIGURATION_UNAVAILABLE', 503); return $value; }
    private function randomToken(int $bytes): string { return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '='); }
    private function xml(string $value): string { return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function issuer(): string { $value = rtrim((string) config('plugin.sand-iam.app.oidc_issuer', ''), '/'); if (!preg_match('#^https://[A-Za-z0-9.-]+(?::[0-9]{1,5})?/api/sand-iam/v1$#', $value)) throw new ApiException('SAND_IAM_CAS_CONFIGURATION_UNAVAILABLE', 503); return $value; }
    private function enabled(): void { if ((int) config('plugin.sand-iam.app.cas_enabled', 0) !== 1) throw new ApiException('SAND_IAM_CAS_DISABLED', 403); $this->pepper(); }
    private function requestId(string $value): string { return preg_match('/^[A-Za-z0-9_.:-]{8,96}$/', $value) ? $value : 'req_' . bin2hex(random_bytes(16)); }
    /** @param array<string,mixed> $context */
    private function writeAudit(CasService $service, string $action, string $resourceType, int $resourceId, string $actor, string $requestId, array $context = []): void
    {
        $application = Application::find((int) $service->application_id);
        $this->audit->write('protocol', $actor, $application ? (int) $application->organization_id : null, (int) $service->application_id, $action, $resourceType, $resourceId, 'succeeded', $this->requestId($requestId), $context);
    }
}
