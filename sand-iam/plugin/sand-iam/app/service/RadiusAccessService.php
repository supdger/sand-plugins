<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\RadiusNas;
use plugin\SandIam\app\model\RadiusReplay;
use plugin\SandIam\app\radius\RadiusNetwork;
use plugin\SandIam\app\radius\RadiusPacketCodec;
use plugin\sandadmin\exception\ApiException;

final class RadiusAccessService
{
    public function __construct(
        private readonly RadiusPacketCodec $codec = new RadiusPacketCodec(),
        private readonly RadiusSecretCipher $cipher = new RadiusSecretCipher(),
    ) {}

    /** Return null when the RFC requires a silent discard. */
    public function handle(string $sourceIp, string $rawPacket): ?string
    {
        if ((int) config('plugin.sand-iam.app.radius_server_enabled', 0) !== 1 || filter_var($sourceIp, FILTER_VALIDATE_IP) === false) return null;
        $matches = [];
        foreach (RadiusNas::where('status', 1)->select()->all() as $nas) if (RadiusNetwork::contains((string) $nas->source_cidr, $sourceIp)) $matches[] = $nas;
        if (count($matches) !== 1) return null;
        $nas = $matches[0];
        try {
            $secret = $this->cipher->decrypt((string) $nas->encrypted_shared_secret);
            $packet = $this->codec->decode($rawPacket);
            if ($packet['code'] !== RadiusPacketCodec::ACCESS_REQUEST) return null;
            $this->codec->verifyMessageAuthenticator($packet, $secret);
            if (!$this->claimReplay($nas, $rawPacket)) return null;
            $usernames = $this->codec->values($packet, RadiusPacketCodec::USER_NAME);
            $passwords = $this->codec->values($packet, RadiusPacketCodec::USER_PASSWORD);
            if (count($usernames) !== 1 || count($passwords) !== 1) return $this->reject($packet, $secret);
            $username = $usernames[0];
            if ($username === '' || strlen($username) > 253 || !preg_match('//u', $username) || preg_match('/[\x00-\x1f\x7f]/', $username)) return $this->reject($packet, $secret);
            $password = $this->codec->decryptUserPassword($passwords[0], $secret, $packet['authenticator']);
            $application = Application::where('id', (int) $nas->application_id)->where('status', 1)->find();
            if ($application === null) return $this->reject($packet, $secret);
            (new HumanAuthService())->verifyPasswordForProtocol($application, $username, $password, $sourceIp, 'radius_' . bin2hex(random_bytes(16)), 'radius');
            return $this->codec->response(RadiusPacketCodec::ACCESS_ACCEPT, $packet['identifier'], $packet['authenticator'], [['type' => RadiusPacketCodec::REPLY_MESSAGE, 'value' => 'Access granted']], $secret, true);
        } catch (ApiException $exception) {
            if (!isset($packet, $secret) || !is_array($packet) || !is_string($secret) || ($packet['code'] ?? null) !== RadiusPacketCodec::ACCESS_REQUEST) return null;
            if (str_contains($exception->getMessage(), 'MESSAGE_AUTHENTICATOR') || str_contains($exception->getMessage(), 'PACKET_INVALID') || str_contains($exception->getMessage(), 'ATTRIBUTE_INVALID')) return null;
            return $this->reject($packet, $secret);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array{identifier:int,authenticator:string} $packet */
    private function reject(array $packet, string $secret): string
    {
        return $this->codec->response(RadiusPacketCodec::ACCESS_REJECT, $packet['identifier'], $packet['authenticator'], [['type' => RadiusPacketCodec::REPLY_MESSAGE, 'value' => 'Access denied']], $secret, true);
    }

    private function claimReplay(RadiusNas $nas, string $rawPacket): bool
    {
        $key = (string) config('plugin.sand-iam.app.radius_replay_key', '');
        if (strlen($key) < 32) throw new ApiException('SAND_IAM_RADIUS_REPLAY_CACHE_UNAVAILABLE', 503);
        $fingerprint = hash_hmac('sha256', $rawPacket, $key);
        try {
            RadiusReplay::where('expire_time', '<=', date('Y-m-d H:i:s'))->delete();
            RadiusReplay::create(['radius_nas_id' => (int) $nas->id, 'request_fingerprint' => $fingerprint, 'expire_time' => date('Y-m-d H:i:s', time() + 300), 'status' => 1]);
            return true;
        } catch (\Throwable $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'unique') || str_contains($exception->getMessage(), '23505')) return false;
            throw $exception;
        }
    }
}
