<?php

declare(strict_types=1);

use plugin\SandIam\app\radius\RadiusPacketCodec;
use plugin\sandadmin\exception\ApiException;

function t11RadiusFail(string $message): never { fwrite(STDERR, "IAM-T11 RADIUS codec test failed: {$message}\n"); exit(1); }
function t11RadiusExpect(callable $callback, string $code): void { try { $callback(); } catch (ApiException $exception) { if (str_contains($exception->getMessage(), $code)) return; } t11RadiusFail("expected {$code}"); }

// This codec has no host/runtime dependencies beyond its exception type.
class RadiusCodecTestException extends RuntimeException {}
class_alias(RadiusCodecTestException::class, ApiException::class);
require dirname(__DIR__) . '/app/radius/RadiusPacketCodec.php';

$codec = new RadiusPacketCodec();
$secret = 'radius-shared-secret-v1';
$requestAuthenticator = hex2bin('00112233445566778899aabbccddeeff');
$password = str_pad('correct-horse', 16, "\0");
$passwordCipher = $password ^ hash('md5', $secret . $requestAuthenticator, true);
$attributes = chr(1) . chr(8) . 'lawyer' . chr(2) . chr(18) . $passwordCipher . chr(80) . chr(18) . str_repeat("\0", 16);
$header = pack('CCn', 1, 7, 20 + strlen($attributes)) . $requestAuthenticator;
$signature = hash_hmac('md5', $header . $attributes, $secret, true);
$attributes = substr_replace($attributes, $signature, strlen($attributes) - 16, 16);
$raw = $header . $attributes;

$packet = $codec->decode($raw);
$codec->verifyMessageAuthenticator($packet, $secret);
if ($packet['code'] !== 1 || $packet['identifier'] !== 7 || $codec->values($packet, 1) !== ['lawyer']) t11RadiusFail('packet decode failed');
if ($codec->decryptUserPassword($codec->values($packet, 2)[0] ?? '', $secret, $packet['authenticator']) !== 'correct-horse') t11RadiusFail('User-Password decrypt failed');

$tampered = $raw; $tampered[24] = 'X';
t11RadiusExpect(static fn () => $codec->verifyMessageAuthenticator($codec->decode($tampered), $secret), 'SAND_IAM_RADIUS_MESSAGE_AUTHENTICATOR_INVALID');
t11RadiusExpect(static fn () => $codec->decode(substr($raw, 0, -1)), 'SAND_IAM_RADIUS_PACKET_INVALID');

$response = $codec->response(2, 7, $requestAuthenticator, [['type' => 18, 'value' => 'Access granted']], $secret, true);
$decodedResponse = $codec->decode($response);
$attributesWire = substr($response, 20);
$expectedResponseAuthenticator = hash('md5', substr($response, 0, 4) . $requestAuthenticator . $attributesWire . $secret, true);
if (!hash_equals($expectedResponseAuthenticator, $decodedResponse['authenticator'])) t11RadiusFail('Response Authenticator invalid');
$messageAttributes = $codec->values($decodedResponse, 80);
if (count($messageAttributes) !== 1 || strlen($messageAttributes[0]) !== 16) t11RadiusFail('response Message-Authenticator missing');
$messageRecord = array_values(array_filter($decodedResponse['attributes'], static fn (array $attribute): bool => $attribute['type'] === 80))[0] ?? null;
if (!is_array($messageRecord)) t11RadiusFail('response Message-Authenticator record missing');
$responseForHmac = substr_replace($response, $requestAuthenticator, 4, 16);
$responseForHmac = substr_replace($responseForHmac, str_repeat("\0", 16), $messageRecord['offset'] + 2, 16);
if (!hash_equals(hash_hmac('md5', $responseForHmac, $secret, true), $messageAttributes[0])) t11RadiusFail('response Message-Authenticator invalid');

echo "RADIUS packet codec non-PG tests passed\n";
