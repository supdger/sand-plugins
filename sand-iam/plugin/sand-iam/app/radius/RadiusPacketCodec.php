<?php

declare(strict_types=1);

namespace plugin\SandIam\app\radius;

use plugin\sandadmin\exception\ApiException;

/** RFC 2865/2869 packet codec. Callers must never log packet bodies. */
final class RadiusPacketCodec
{
    public const ACCESS_REQUEST = 1;
    public const ACCESS_ACCEPT = 2;
    public const ACCESS_REJECT = 3;
    public const ACCESS_CHALLENGE = 11;
    public const ACCOUNTING_REQUEST = 4;
    public const ACCOUNTING_RESPONSE = 5;
    public const USER_NAME = 1;
    public const USER_PASSWORD = 2;
    public const REPLY_MESSAGE = 18;
    public const STATE = 24;
    public const MESSAGE_AUTHENTICATOR = 80;

    /** @return array{code:int,identifier:int,length:int,authenticator:string,attributes:list<array{type:int,value:string,offset:int,length:int}>,raw:string} */
    public function decode(string $packet): array
    {
        $size = strlen($packet);
        if ($size < 20 || $size > 4096) throw new ApiException('SAND_IAM_RADIUS_PACKET_INVALID', 400);
        $header = unpack('Ccode/Cidentifier/nlength', substr($packet, 0, 4));
        if (!is_array($header) || (int) $header['length'] !== $size) throw new ApiException('SAND_IAM_RADIUS_PACKET_INVALID', 400);
        $attributes = [];
        $offset = 20;
        while ($offset < $size) {
            if ($offset + 2 > $size) throw new ApiException('SAND_IAM_RADIUS_ATTRIBUTE_INVALID', 400);
            $type = ord($packet[$offset]);
            $length = ord($packet[$offset + 1]);
            if ($type < 1 || $length < 2 || $offset + $length > $size) throw new ApiException('SAND_IAM_RADIUS_ATTRIBUTE_INVALID', 400);
            $attributes[] = ['type' => $type, 'value' => substr($packet, $offset + 2, $length - 2), 'offset' => $offset, 'length' => $length];
            $offset += $length;
        }
        if ($offset !== $size) throw new ApiException('SAND_IAM_RADIUS_PACKET_INVALID', 400);
        return ['code' => (int) $header['code'], 'identifier' => (int) $header['identifier'], 'length' => $size, 'authenticator' => substr($packet, 4, 16), 'attributes' => $attributes, 'raw' => $packet];
    }

    /** @param array{attributes:list<array{type:int,value:string,offset:int,length:int}>,raw:string} $packet */
    public function verifyMessageAuthenticator(array $packet, string $secret): void
    {
        $this->secret($secret);
        $matches = array_values(array_filter($packet['attributes'], static fn (array $attribute): bool => $attribute['type'] === self::MESSAGE_AUTHENTICATOR));
        if (count($matches) !== 1 || $matches[0]['length'] !== 18) throw new ApiException('SAND_IAM_RADIUS_MESSAGE_AUTHENTICATOR_REQUIRED', 401);
        $attribute = $matches[0];
        $raw = substr_replace($packet['raw'], str_repeat("\0", 16), $attribute['offset'] + 2, 16);
        $expected = hash_hmac('md5', $raw, $secret, true);
        if (!hash_equals($expected, $attribute['value'])) throw new ApiException('SAND_IAM_RADIUS_MESSAGE_AUTHENTICATOR_INVALID', 401);
    }

    /** @param array{code:int,authenticator:string,raw:string} $packet */
    public function verifyAccountingAuthenticator(array $packet, string $secret): void
    {
        $this->secret($secret);
        if ($packet['code'] !== self::ACCOUNTING_REQUEST || strlen($packet['authenticator']) !== 16) throw new ApiException('SAND_IAM_RADIUS_ACCOUNTING_PACKET_INVALID', 401);
        $wire = substr_replace($packet['raw'], str_repeat("\0", 16), 4, 16);
        if (!hash_equals(hash('md5', $wire . $secret, true), $packet['authenticator'])) throw new ApiException('SAND_IAM_RADIUS_ACCOUNTING_AUTHENTICATOR_INVALID', 401);
    }

    /** @param array{attributes:list<array{type:int,value:string,offset:int,length:int}>} $packet @return list<string> */
    public function values(array $packet, int $type): array
    {
        return array_values(array_map(static fn (array $attribute): string => $attribute['value'], array_filter($packet['attributes'], static fn (array $attribute): bool => $attribute['type'] === $type)));
    }

    public function decryptUserPassword(string $ciphertext, string $secret, string $requestAuthenticator): string
    {
        $this->secret($secret);
        $length = strlen($ciphertext);
        if (strlen($requestAuthenticator) !== 16 || $length < 16 || $length > 128 || $length % 16 !== 0) throw new ApiException('SAND_IAM_RADIUS_PASSWORD_ATTRIBUTE_INVALID', 401);
        $plaintext = '';
        $previous = $requestAuthenticator;
        for ($offset = 0; $offset < $length; $offset += 16) {
            $block = substr($ciphertext, $offset, 16);
            $digest = hash('md5', $secret . $previous, true);
            $plaintext .= $block ^ $digest;
            $previous = $block;
        }
        $plaintext = rtrim($plaintext, "\0");
        if ($plaintext === '' || strlen($plaintext) > 128 || preg_match('/[\x00-\x1f\x7f]/', $plaintext)) throw new ApiException('SAND_IAM_RADIUS_PASSWORD_ATTRIBUTE_INVALID', 401);
        return $plaintext;
    }

    /**
     * @param list<array{type:int,value:string}> $attributes
     */
    public function response(int $code, int $identifier, string $requestAuthenticator, array $attributes, string $secret, bool $messageAuthenticator): string
    {
        $this->secret($secret);
        if (!in_array($code, [self::ACCESS_ACCEPT, self::ACCESS_REJECT, self::ACCESS_CHALLENGE, self::ACCOUNTING_RESPONSE], true) || $identifier < 0 || $identifier > 255 || strlen($requestAuthenticator) !== 16) throw new ApiException('SAND_IAM_RADIUS_RESPONSE_INVALID', 500);
        $wire = '';
        foreach ($attributes as $attribute) {
            $type = (int) ($attribute['type'] ?? 0);
            $value = (string) ($attribute['value'] ?? '');
            if ($type < 1 || $type > 255 || $type === self::MESSAGE_AUTHENTICATOR || strlen($value) > 253) throw new ApiException('SAND_IAM_RADIUS_RESPONSE_INVALID', 500);
            $wire .= chr($type) . chr(strlen($value) + 2) . $value;
        }
        $messageOffset = null;
        if ($messageAuthenticator) {
            $messageOffset = strlen($wire) + 2;
            $wire .= chr(self::MESSAGE_AUTHENTICATOR) . chr(18) . str_repeat("\0", 16);
        }
        $length = 20 + strlen($wire);
        if ($length > 4096) throw new ApiException('SAND_IAM_RADIUS_RESPONSE_INVALID', 500);
        $prefix = pack('CCn', $code, $identifier, $length);
        if ($messageOffset !== null) {
            $signature = hash_hmac('md5', $prefix . $requestAuthenticator . $wire, $secret, true);
            $wire = substr_replace($wire, $signature, $messageOffset, 16);
        }
        $authenticator = hash('md5', $prefix . $requestAuthenticator . $wire . $secret, true);
        return $prefix . $authenticator . $wire;
    }

    private function secret(string $secret): void
    {
        if (strlen($secret) < 16 || strlen($secret) > 128 || preg_match('/[\x00-\x1f\x7f]/', $secret)) throw new ApiException('SAND_IAM_RADIUS_SHARED_SECRET_INVALID', 503);
    }
}
