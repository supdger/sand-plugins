<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\OAuthClient;
use plugin\SandIam\app\model\OidcLogoutDelivery;
use plugin\SandIam\app\oidc\NativeOidcBackchannelHttpAdapter;
use plugin\SandIam\app\oidc\OidcBackchannelHttpAdapter;
use think\facade\Db;

final class OidcBackchannelLogoutService
{
    /** The four scheduled retry delays before the fifth attempt becomes dead. */
    public const RETRY_DELAYS = [60, 300, 1800, 7200, 43200];
    public const MAX_DELIVERY_WINDOW = 9360;
    public function __construct(
        private readonly OidcLogoutTokenCipher $cipher = new OidcLogoutTokenCipher(),
        private readonly OidcBackchannelHttpAdapter $http = new NativeOidcBackchannelHttpAdapter(),
        private readonly AuditWriter $audit = new AuditWriter(),
    ) {}

    /** @return array{claimed:int,delivered:int,retried:int,dead:int} */
    public function deliverBatch(int $limit = 20): array
    {
        $limit = min(max($limit, 1), 100);
        OidcLogoutDelivery::where('state', 'sending')->where('locked_until', '<', date('Y-m-d H:i:s'))->update(['state' => 'pending', 'locked_until' => null]);
        Db::startTrans();
        try {
            $rows = OidcLogoutDelivery::where('state', 'pending')->where('next_attempt_time', '<=', date('Y-m-d H:i:s'))->order('id')->limit($limit)->lock('FOR UPDATE SKIP LOCKED')->select()->all();
            foreach ($rows as $row) $row->save(['state' => 'sending', 'locked_until' => date('Y-m-d H:i:s', time() + 120)]);
            Db::commit();
        } catch (\Throwable $exception) { Db::rollback(); throw $exception; }

        $result = ['claimed' => count($rows), 'delivered' => 0, 'retried' => 0, 'dead' => 0];
        foreach ($rows as $row) $result[$this->deliver($row)]++;
        return $result;
    }

    /** @return 'delivered'|'retried'|'dead' */
    private function deliver(OidcLogoutDelivery $claimed): string
    {
        $row = OidcLogoutDelivery::where('id', (int) $claimed->id)->where('state', 'sending')->find();
        if ($row === null) return 'dead';
        $client = OAuthClient::where('id', (int) $row->oauth_client_id)->where('application_id', (int) $row->application_id)->where('status', 1)->find();
        $attempt = (int) $row->attempt_count + 1;
        if ($client === null) return $this->fail($row, $attempt, 'SAND_IAM_OIDC_BACKCHANNEL_CLIENT_UNAVAILABLE');
        try {
            $payload = json_decode($this->cipher->decrypt((string) $row->encrypted_logout_token), true, 8, JSON_THROW_ON_ERROR);
            $targetUri = is_array($payload) ? trim((string) ($payload['target_uri'] ?? '')) : '';
            $token = is_array($payload) ? (string) ($payload['logout_token'] ?? '') : '';
            if ($targetUri === '' || $token === '') throw new \RuntimeException('Invalid encrypted logout envelope');
            $body = http_build_query(['logout_token' => $token], '', '&', PHP_QUERY_RFC3986);
            $response = $this->http->postLogoutToken($targetUri, $body, 10);
            if ($response['status'] < 200 || $response['status'] >= 300) return $this->fail($row, $attempt, 'SAND_IAM_OIDC_BACKCHANNEL_HTTP_' . $response['status'], $response['status'], $response['body']);
            $row->save(['state' => 'delivered', 'attempt_count' => $attempt, 'delivered_time' => date('Y-m-d H:i:s'), 'locked_until' => null, 'response_status' => $response['status'], 'response_digest' => hash('sha256', $response['body']), 'last_error_code' => null, 'status' => 2]);
            $this->writeAudit($row, 'delivered', ['attempt' => $attempt, 'response_status' => $response['status']]);
            return 'delivered';
        } catch (\Throwable) {
            return $this->fail($row, $attempt, 'SAND_IAM_OIDC_BACKCHANNEL_DELIVERY_FAILED');
        }
    }

    /** @return 'retried'|'dead' */
    private function fail(OidcLogoutDelivery $row, int $attempt, string $code, ?int $status = null, string $body = ''): string
    {
        $dead = $attempt >= 5;
        $delay = self::RETRY_DELAYS[min($attempt - 1, 4)];
        $row->save(['state' => $dead ? 'dead' : 'pending', 'attempt_count' => $attempt, 'next_attempt_time' => $dead ? null : date('Y-m-d H:i:s', time() + $delay), 'locked_until' => null, 'response_status' => $status, 'response_digest' => $body === '' ? null : hash('sha256', $body), 'last_error_code' => $code, 'status' => $dead ? 2 : 1]);
        $this->writeAudit($row, $dead ? 'dead' : 'retry_scheduled', ['attempt' => $attempt, 'error_code' => $code]);
        return $dead ? 'dead' : 'retried';
    }

    /** @param array<string,mixed> $context */
    private function writeAudit(OidcLogoutDelivery $row, string $deliveryState, array $context): void
    {
        $application = Application::find((int) $row->application_id);
        try {
            $attempt = (int) ($context['attempt'] ?? $row->attempt_count);
            $requestId = hash('sha256', "oidc.backchannel_logout_delivery\0" . (string) $row->event_id . "\0" . $attempt);
            $this->audit->write(
                'system',
                'oidc_logout_worker',
                $application ? (int) $application->organization_id : null,
                (int) $row->application_id,
                'oidc.backchannel_logout_delivery',
                'oidc_logout_delivery',
                (int) $row->id,
                $deliveryState === 'delivered' ? 'succeeded' : 'failed',
                $requestId,
                $context + [
                    'oauth_client_id' => (int) $row->oauth_client_id,
                    'event_id' => (string) $row->event_id,
                    'delivery_state' => $deliveryState,
                ],
            );
        } catch (\Throwable) { /* delivery state remains authoritative */ }
    }
}
