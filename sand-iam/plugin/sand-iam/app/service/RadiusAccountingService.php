<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\model\RadiusAccountingEvent;
use plugin\SandIam\app\model\RadiusAccountingSession;
use plugin\SandIam\app\model\RadiusNas;
use plugin\SandIam\app\radius\RadiusNetwork;
use plugin\SandIam\app\radius\RadiusPacketCodec;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

final class RadiusAccountingService
{
    private const ACCT_STATUS_TYPE = 40;
    private const ACCT_INPUT_OCTETS = 42;
    private const ACCT_OUTPUT_OCTETS = 43;
    private const ACCT_SESSION_ID = 44;
    private const ACCT_SESSION_TIME = 46;
    private const ACCT_INPUT_GIGAWORDS = 52;
    private const ACCT_OUTPUT_GIGAWORDS = 53;
    private const EVENT_TIMESTAMP = 55;

    public function __construct(
        private readonly RadiusPacketCodec $codec = new RadiusPacketCodec(),
        private readonly RadiusSecretCipher $cipher = new RadiusSecretCipher(),
    ) {}

    public function handle(string $sourceIp, string $rawPacket): ?string
    {
        if ((int) config('plugin.sand-iam.app.radius_server_enabled', 0) !== 1 || filter_var($sourceIp, FILTER_VALIDATE_IP) === false) return null;
        $matches = [];
        foreach (RadiusNas::where('status', 1)->where('accounting_enabled', true)->select()->all() as $nas) if (RadiusNetwork::contains((string) $nas->source_cidr, $sourceIp)) $matches[] = $nas;
        if (count($matches) !== 1) return null;
        $nas = $matches[0];
        try {
            $secret = $this->cipher->decrypt((string) $nas->encrypted_shared_secret);
            $packet = $this->codec->decode($rawPacket);
            $this->codec->verifyAccountingAuthenticator($packet, $secret);
            $fingerprint = $this->fingerprint($nas, $rawPacket);
            $application = Application::where('id', (int) $nas->application_id)->where('status', 1)->find();
            $organization = $application === null ? null : Organization::where('id', (int) $application->organization_id)->where('status', 1)->find();
            if ($application === null || $organization === null) return null;
            $event = $this->event($packet, $nas, $fingerprint);
            $this->apply($application, $nas, $event);
            return $this->codec->response(RadiusPacketCodec::ACCOUNTING_RESPONSE, $packet['identifier'], $packet['authenticator'], [], $secret, false);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array{attributes:list<array{type:int,value:string,offset:int,length:int}>} $packet @return array{type:string,session_reference:string,user_reference:?string,event_time:string,session_seconds:int,input_octets:int,output_octets:int,request_fingerprint:string} */
    private function event(array $packet, RadiusNas $nas, string $fingerprint): array
    {
        $status = $this->uint32($packet, self::ACCT_STATUS_TYPE, true);
        $type = match ($status) { 1 => 'start', 2 => 'stop', 3 => 'interim', default => throw new ApiException('SAND_IAM_RADIUS_ACCOUNTING_STATUS_UNSUPPORTED', 400) };
        $sessionIds = $this->codec->values($packet, self::ACCT_SESSION_ID);
        if (count($sessionIds) !== 1 || $sessionIds[0] === '' || strlen($sessionIds[0]) > 253 || preg_match('/[\x00-\x1f\x7f]/', $sessionIds[0])) throw new ApiException('SAND_IAM_RADIUS_ACCOUNTING_SESSION_INVALID', 400);
        $usernames = $this->codec->values($packet, RadiusPacketCodec::USER_NAME);
        if (count($usernames) > 1 || (isset($usernames[0]) && ($usernames[0] === '' || strlen($usernames[0]) > 253 || preg_match('/[\x00-\x1f\x7f]/', $usernames[0])))) throw new ApiException('SAND_IAM_RADIUS_ACCOUNTING_USER_INVALID', 400);
        $timestamp = $this->uint32($packet, self::EVENT_TIMESTAMP, false);
        if ($timestamp === null) $timestamp = time();
        if ($timestamp < 946684800 || $timestamp > time() + 300) throw new ApiException('SAND_IAM_RADIUS_ACCOUNTING_TIME_INVALID', 400);
        $input = $this->uint32($packet, self::ACCT_INPUT_OCTETS, false) ?? 0;
        $output = $this->uint32($packet, self::ACCT_OUTPUT_OCTETS, false) ?? 0;
        $input += ($this->uint32($packet, self::ACCT_INPUT_GIGAWORDS, false) ?? 0) * 4294967296;
        $output += ($this->uint32($packet, self::ACCT_OUTPUT_GIGAWORDS, false) ?? 0) * 4294967296;
        $sessionSeconds = $this->uint32($packet, self::ACCT_SESSION_TIME, false) ?? 0;
        $key = $this->replayKey();
        return [
            'type' => $type,
            'session_reference' => hash_hmac('sha256', 'radius-session:' . (int) $nas->id . "\0" . $sessionIds[0], $key),
            'user_reference' => isset($usernames[0]) ? hash_hmac('sha256', 'radius-user:' . (int) $nas->application_id . "\0" . $usernames[0], $key) : null,
            'event_time' => date('Y-m-d H:i:s', $timestamp),
            'session_seconds' => $sessionSeconds,
            'input_octets' => $input,
            'output_octets' => $output,
            'request_fingerprint' => $fingerprint,
        ];
    }

    /** @param array{type:string,session_reference:string,user_reference:?string,event_time:string,session_seconds:int,input_octets:int,output_octets:int,request_fingerprint:string} $event */
    private function apply(Application $application, RadiusNas $nas, array $event): void
    {
        Db::startTrans();
        try {
            $existingEvent = RadiusAccountingEvent::where('radius_nas_id', (int) $nas->id)->where('request_fingerprint', $event['request_fingerprint'])->lock(true)->find();
            if ($existingEvent !== null) { Db::commit(); return; }
            $session = RadiusAccountingSession::where('radius_nas_id', (int) $nas->id)->where('session_reference', $event['session_reference'])->where('state', 'active')->lock(true)->find();
            $outcome = 'applied';
            if ($event['type'] === 'start') {
                if ($session !== null) {
                    $outcome = 'conflict';
                } else {
                    $session = RadiusAccountingSession::create(['application_id' => (int) $application->id, 'radius_nas_id' => (int) $nas->id, 'session_reference' => $event['session_reference'], 'user_reference' => $event['user_reference'], 'state' => 'active', 'start_time' => $event['event_time'], 'last_event_time' => $event['event_time'], 'session_seconds' => $event['session_seconds'], 'input_octets' => $event['input_octets'], 'output_octets' => $event['output_octets'], 'status' => 1]);
                }
            } elseif ($session === null) {
                $outcome = 'orphaned';
            } elseif ($event['session_seconds'] < (int) $session->session_seconds || $event['input_octets'] < (int) $session->input_octets || $event['output_octets'] < (int) $session->output_octets || strtotime($event['event_time']) < strtotime((string) $session->last_event_time)) {
                $outcome = 'conflict';
            } else {
                $changes = ['last_event_time' => $event['event_time'], 'session_seconds' => $event['session_seconds'], 'input_octets' => $event['input_octets'], 'output_octets' => $event['output_octets']];
                if ($event['type'] === 'stop') $changes += ['state' => 'stopped', 'stop_time' => $event['event_time'], 'status' => 2];
                $session->save($changes);
            }
            RadiusAccountingEvent::create(['application_id' => (int) $application->id, 'radius_nas_id' => (int) $nas->id, 'accounting_session_id' => $session ? (int) $session->id : null, 'session_reference' => $event['session_reference'], 'user_reference' => $event['user_reference'], 'request_fingerprint' => $event['request_fingerprint'], 'event_type' => $event['type'], 'outcome' => $outcome, 'event_time' => $event['event_time'], 'session_seconds' => $event['session_seconds'], 'input_octets' => $event['input_octets'], 'output_octets' => $event['output_octets']]);
            Db::commit();
        } catch (\Throwable $exception) { Db::rollback(); throw $exception; }
    }

    /** @param array{attributes:list<array{type:int,value:string,offset:int,length:int}>} $packet */
    private function uint32(array $packet, int $type, bool $required): ?int
    {
        $values = $this->codec->values($packet, $type);
        if (count($values) > 1 || ($required && count($values) !== 1) || (isset($values[0]) && strlen($values[0]) !== 4)) throw new ApiException('SAND_IAM_RADIUS_ACCOUNTING_ATTRIBUTE_INVALID', 400);
        if (!isset($values[0])) return null;
        $value = unpack('Nvalue', $values[0]);
        return is_array($value) ? (int) $value['value'] : null;
    }

    private function fingerprint(RadiusNas $nas, string $rawPacket): string { return hash_hmac('sha256', 'radius-accounting:' . (int) $nas->id . "\0" . $rawPacket, $this->replayKey()); }

    private function replayKey(): string { $key = (string) config('plugin.sand-iam.app.radius_replay_key', ''); if (strlen($key) < 32) throw new ApiException('SAND_IAM_RADIUS_REPLAY_CACHE_UNAVAILABLE', 503); return $key; }
}
