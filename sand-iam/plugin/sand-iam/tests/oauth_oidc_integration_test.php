<?php

declare(strict_types=1);

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\AuditLog;
use plugin\SandIam\app\model\AuthPolicy;
use plugin\SandIam\app\model\AuthSession;
use plugin\SandIam\app\model\OAuthClient;
use plugin\SandIam\app\model\OAuthGrant;
use plugin\SandIam\app\model\OAuthToken;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\api\controller\OAuthOidcController;
use plugin\SandIam\app\service\HumanAuthService;
use plugin\SandIam\app\service\OAuthOidcService;
use plugin\sandadmin\exception\ApiException;
use Webman\Config;
use Webman\ThinkOrm\ThinkOrm;
use think\facade\Db;

function t03Fail(string $message): never
{
    fwrite(STDERR, "IAM-T03 OAuth/OIDC integration failed: {$message}\n");
    exit(1);
}

function t03Assert(bool $condition, string $message): void
{
    if (!$condition) t03Fail($message);
}

function t03Expect(callable $callback, string $expected): void
{
    try {
        $callback();
    } catch (ApiException $exception) {
        if (str_contains($exception->getMessage(), $expected)) return;
        t03Fail("expected {$expected}, received {$exception->getMessage()}");
    }
    t03Fail("expected {$expected}, but no exception was thrown");
}

/** @param list<string> $sensitive */
function t03AuthorizeEndpointErrorResponse(mixed $response, int $status, string $error, array $sensitive): void
{
    t03Assert($response instanceof \support\Response, 'authorize failure did not return an HTTP response');
    t03Assert($response->getStatusCode() === $status, 'authorize failure status is not stable');
    t03Assert($response->getHeader('Location') === null, 'authorize failure redirected without a validated redirect URI');
    t03Assert($response->getHeader('Cache-Control') === 'no-store' && $response->getHeader('Pragma') === 'no-cache' && $response->getHeader('Referrer-Policy') === 'no-referrer', 'authorize failure response is cacheable or permits referrer leakage');
    $body = json_decode($response->rawBody(), true);
    t03Assert(is_array($body) && ($body['error'] ?? null) === $error && ($body['error_description'] ?? null) === '授权请求未被接受', 'authorize failure body does not use the stable OAuth error contract');
    t03Assert(!array_key_exists('state', $body), 'authorize failure reflected state without a validated redirect URI');
    foreach ($sensitive as $value) {
        t03Assert(!str_contains($response->rawBody(), $value), 'authorize failure leaked request material');
    }
}

/** @return array<string,mixed> */
function t03Jwt(string $token): array
{
    [$header, $claims] = array_pad(explode('.', $token, 3), 3, '');
    $decode = static function (string $value): array {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if (!is_string($decoded)) t03Fail('JWT was not base64url');
        $data = json_decode($decoded, true);
        if (!is_array($data)) t03Fail('JWT was not JSON');
        return $data;
    };
    return ['header' => $decode($header), 'claims' => $decode($claims)];
}

/** @return array<string,string> */
function t03Query(string $uri): array
{
    parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
    return array_map('strval', $query);
}

function t03Challenge(string $verifier): string
{
    return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
}

/** @param array<string,mixed> $payload @return array{request:string,csrf:string} */
function t03Bind(OAuthOidcService $oauth, array $payload, string $accessToken, string $requestId): array
{
    $begin = $oauth->beginAuthorization($payload, $requestId . '-begin');
    t03Assert(isset($begin['interaction_uri']), 'authorize did not create interaction');
    $request = t03Query((string) $begin['interaction_uri'])['request'] ?? '';
    t03Assert($request !== '', 'interaction request missing');
    $interaction = $oauth->interaction($request);
    t03Assert(($interaction['client_id'] ?? '') === (string) $payload['client_id'], 'interaction exposed an internal client id');
    $bound = $oauth->bindInteraction($request, $accessToken, $requestId . '-bind');
    t03Assert(isset($bound['csrf_token']) && ($bound['csrf_token'] ?? '') !== '', 'bind did not return a CSRF token');
    return ['request' => $request, 'csrf' => (string) $bound['csrf_token']];
}

/** @param array<string,mixed> $payload @return array<string,mixed> */
function t03AuthorizeAndExchange(OAuthOidcService $oauth, array $payload, string $accessToken, string $verifier, string $requestId): array
{
    $bound = t03Bind($oauth, $payload, $accessToken, $requestId);
    $approved = $oauth->approveAuthorization(['authorization_request' => $bound['request'], 'csrf_token' => $bound['csrf'], 'decision' => 'approve'], $accessToken, $requestId . '-approve');
    $code = t03Query((string) $approved['redirect_uri'])['code'] ?? '';
    t03Assert($code !== '', 'approve did not issue authorization code');
    return $oauth->token(['grant_type' => 'authorization_code', 'client_id' => $payload['client_id'], 'code' => $code, 'redirect_uri' => $payload['redirect_uri'], 'code_verifier' => $verifier], $requestId . '-exchange');
}

$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($key === false || !openssl_pkey_export($key, $privatePem)) t03Fail('could not generate temporary RSA key');
putenv('SAND_IAM_OIDC_ISSUER=https://iam.example.test/api/sand-iam/v1');
putenv('SAND_IAM_OIDC_PRIVATE_KEY_BASE64=' . base64_encode($privatePem));
putenv('SAND_IAM_OIDC_KID=t03-ephemeral-rsa');
putenv('SAND_IAM_OIDC_SUBJECT_KEY=t03-subject-key-that-is-longer-than-32-bytes');

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$sandIamRoot = dirname(__DIR__, 3);
if (!is_file($hostRoot . '/vendor/autoload.php')) t03Fail("SandAdmin host dependencies are unavailable at {$hostRoot}");
chdir($hostRoot);
require $hostRoot . '/vendor/autoload.php';
require $sandIamRoot . '/plugin/sand-iam/app/functions.php';

Config::clear();
support\App::loadAllConfig(['route']);
Config::load($sandIamRoot . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam');
ThinkOrm::start(null);

$human = new HumanAuthService();
$oauth = new OAuthOidcService();
$organization = Organization::create(['code' => 't03-runtime-org', 'name' => 'T03 OAuth organization', 'status' => 1]);
$applicationA = Application::create(['organization_id' => (int) $organization->id, 'code' => 't03-app-a', 'name' => 'T03 OAuth app A', 'status' => 1]);
$applicationB = Application::create(['organization_id' => (int) $organization->id, 'code' => 't03-app-b', 'name' => 'T03 OAuth app B', 'status' => 1]);
AuthPolicy::create(['application_id' => (int) $applicationA->id, 'registration_enabled' => 1, 'status' => 1]);
AuthPolicy::create(['application_id' => (int) $applicationB->id, 'registration_enabled' => 1, 'status' => 1]);

$password = 'T03!IntegrationPassword';
$user = $human->register(['organization_code' => 't03-runtime-org', 'application_code' => 't03-app-a', 'username' => 't03-user-a', 'display_name' => 'T03 User A', 'email' => 't03-a@example.test', 'password' => $password], '127.0.0.31', 't03-register-a');
$otherUser = $human->register(['organization_code' => 't03-runtime-org', 'application_code' => 't03-app-b', 'username' => 't03-user-b', 'display_name' => 'T03 User B', 'email' => 't03-b@example.test', 'password' => $password], '127.0.0.32', 't03-register-b');
t03Assert(isset($user['access_token'], $otherUser['access_token']), 'human login fixture did not yield application sessions');

$clientId = 't03-public-rp';
$redirect = 'https://rp.example.test/callback';
$logoutRedirect = 'https://rp.example.test/logout';
$public = OAuthClient::create(['application_id' => (int) $applicationA->id, 'code' => $clientId, 'name' => 'T03 public RP', 'client_type' => 'public', 'redirect_uris' => [$redirect], 'post_logout_redirect_uris' => [$logoutRedirect], 'allowed_scopes' => ['openid', 'profile', 'email', 'offline_access'], 'allowed_audiences' => [], 'status' => 1]);
$secondPublic = OAuthClient::create(['application_id' => (int) $applicationA->id, 'code' => 't03-public-rp-2', 'name' => 'T03 second RP', 'client_type' => 'public', 'redirect_uris' => ['https://rp2.example.test/callback'], 'post_logout_redirect_uris' => [], 'allowed_scopes' => ['openid', 'profile'], 'allowed_audiences' => [], 'status' => 1]);
$consentClient = OAuthClient::create(['application_id' => (int) $applicationA->id, 'code' => 't03-consent-rp', 'name' => 'T03 consent RP', 'client_type' => 'public', 'redirect_uris' => ['https://consent.example.test/callback'], 'post_logout_redirect_uris' => [], 'allowed_scopes' => ['openid', 'profile', 'email'], 'allowed_audiences' => [], 'status' => 1]);
$machineSecret = 't03-machine-secret';
$machine = OAuthClient::create(['application_id' => (int) $applicationA->id, 'code' => 't03-machine', 'name' => 'T03 machine client', 'client_type' => 'confidential', 'secret_hash' => password_hash(hash_hmac('sha256', 'client-secret:' . $machineSecret, (string) getenv('SAND_IAM_AUTH_PEPPER')), PASSWORD_ARGON2ID), 'secret_version' => 'v1', 'redirect_uris' => [$redirect], 'post_logout_redirect_uris' => [], 'allowed_scopes' => ['inventory.read'], 'allowed_audiences' => ['https://api.example.test/inventory'], 'default_audience' => 'https://api.example.test/inventory', 'status' => 1]);

$discovery = $oauth->discovery();
t03Assert(($discovery['issuer'] ?? '') === 'https://iam.example.test/api/sand-iam/v1' && ($discovery['authorization_endpoint'] ?? '') === 'https://iam.example.test/api/sand-iam/v1/oauth/authorize' && ($discovery['subject_types_supported'] ?? []) === ['pairwise'], 'discovery issuer endpoints or subject type are inconsistent');
$jwks = $oauth->jwks();
t03Assert(count($jwks['keys'] ?? []) === 1 && !isset($jwks['keys'][0]['d'], $jwks['keys'][0]['p'], $jwks['keys'][0]['q']), 'JWKS did not expose exactly the public temporary key');

$verifier = str_repeat('a', 43);
$payload = ['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $redirect, 'scope' => 'openid profile email offline_access', 'state' => 't03-state', 'nonce' => 't03-nonce', 'code_challenge_method' => 'S256', 'code_challenge' => t03Challenge($verifier)];
$formBody = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
$queryRequest = new \support\Request("GET /api/sand-iam/v1/oauth/authorize?" . $formBody . " HTTP/1.1\r\nHost: iam.example.test\r\n\r\n");
$queryAuthorizeResponse = (new OAuthOidcController())->authorize($queryRequest);
t03Assert($queryAuthorizeResponse->getStatusCode() === 302, 'GET authorize did not read query parameters into the authorization request');
$formRequest = new \support\Request("POST /api/sand-iam/v1/oauth/authorize HTTP/1.1\r\nHost: iam.example.test\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($formBody) . "\r\n\r\n" . $formBody);
$authorizeResponse = (new OAuthOidcController())->authorize($formRequest);
t03Assert($authorizeResponse->getStatusCode() === 302, 'POST authorize did not read form parameters into the authorization request');
$duplicateRequest = new \support\Request("POST /api/sand-iam/v1/oauth/authorize?client_id=t03-public-rp HTTP/1.1\r\nHost: iam.example.test\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($formBody) . "\r\n\r\n" . $formBody);
$duplicateResponse = (new OAuthOidcController())->authorize($duplicateRequest);
t03AuthorizeEndpointErrorResponse($duplicateResponse, 400, 'invalid_request', [$formBody, $payload['state'], $payload['nonce'], $payload['code_challenge']]);
t03Expect(static fn () => $oauth->beginAuthorization(array_replace($payload, ['redirect_uri' => 'https://rp.example.test/other']), 't03-redirect'), 'SAND_IAM_OAUTH_REDIRECT_URI_INVALID');
$bound = t03Bind($oauth, $payload, (string) $user['access_token'], 't03-main');
t03Expect(static fn () => $oauth->bindInteraction($bound['request'], (string) $otherUser['access_token'], 't03-cross-app'), 'SAND_IAM_OAUTH_APPLICATION_MISMATCH');
$secondSession = $human->login(['organization_code' => 't03-runtime-org', 'application_code' => 't03-app-a', 'identifier' => 't03-a@example.test', 'password' => $password], '127.0.0.33', 't03-second-session');
t03Expect(static fn () => $oauth->bindInteraction($bound['request'], (string) $secondSession['access_token'], 't03-rebind'), 'SAND_IAM_OAUTH_AUTHORIZATION_REQUEST_ALREADY_BOUND');
t03Expect(static fn () => $oauth->approveAuthorization(['authorization_request' => $bound['request'], 'csrf_token' => 'wrong', 'decision' => 'approve'], (string) $user['access_token'], 't03-csrf'), 'SAND_IAM_OAUTH_AUTHORIZATION_REQUEST_INVALID');
$approved = $oauth->approveAuthorization(['authorization_request' => $bound['request'], 'csrf_token' => $bound['csrf'], 'decision' => 'approve'], (string) $user['access_token'], 't03-approve');
$codeQuery = t03Query((string) $approved['redirect_uri']);
$code = $codeQuery['code'] ?? '';
t03Assert($code !== '', 'code was not returned to exact redirect');
t03Assert(($codeQuery['state'] ?? '') === 't03-state', 'state was not returned unchanged with the code');
t03Expect(static fn () => $oauth->token(['grant_type' => 'authorization_code', 'client_id' => 't03-public-rp-2', 'code' => $code, 'redirect_uri' => 'https://rp2.example.test/callback', 'code_verifier' => $verifier], 't03-wrong-client-code'), 'SAND_IAM_OAUTH_INVALID_GRANT');
t03Expect(static fn () => $oauth->token(['grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $code, 'redirect_uri' => $redirect, 'code_verifier' => str_repeat('b', 43)], 't03-bad-pkce'), 'SAND_IAM_OAUTH_INVALID_GRANT');
$tokens = $oauth->token(['grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $code, 'redirect_uri' => $redirect, 'code_verifier' => $verifier], 't03-exchange');
t03Expect(static fn () => $oauth->token(['grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $code, 'redirect_uri' => $redirect, 'code_verifier' => $verifier], 't03-code-replay'), 'SAND_IAM_OAUTH_INVALID_GRANT');
t03Assert(isset($tokens['access_token'], $tokens['id_token'], $tokens['refresh_token']), 'offline access consent did not issue token set');
$access = t03Jwt((string) $tokens['access_token']);
$idToken = t03Jwt((string) $tokens['id_token']);
t03Assert(($access['header']['alg'] ?? '') === 'RS256' && ($access['header']['typ'] ?? '') === 'at+jwt' && ($access['claims']['iss'] ?? '') === $discovery['issuer'] && ($access['claims']['aud'] ?? '') === $discovery['userinfo_endpoint'] && ($access['claims']['token_use'] ?? '') === 'access_token', 'access JWT claims are invalid');
t03Assert(($idToken['header']['typ'] ?? '') === 'JWT' && ($idToken['claims']['aud'] ?? '') === $clientId && ($idToken['claims']['token_use'] ?? '') === 'id_token' && ($idToken['claims']['nonce'] ?? '') === 't03-nonce' && isset($idToken['claims']['auth_time'], $idToken['claims']['sid']) && !isset($idToken['claims']['application_id'], $idToken['claims']['grant_id'], $idToken['claims']['identity_id'], $idToken['claims']['scope']), 'ID token was not minimal or correctly bound');
$verified = $oauth->verifyAccessTokenForAudience((string) $tokens['access_token'], (string) $discovery['userinfo_endpoint'], ['openid', 'profile']);
t03Assert(($verified['client_id'] ?? '') === $clientId, 'valid access token did not verify');
t03Expect(static fn () => $oauth->verifyAccessTokenForAudience((string) $tokens['access_token'], 'https://api.example.test/other'), 'SAND_IAM_OAUTH_TOKEN_INVALID');
t03Expect(static fn () => $oauth->verifyAccessTokenForAudience((string) $tokens['access_token'], (string) $discovery['userinfo_endpoint'], ['inventory.read']), 'SAND_IAM_OAUTH_INSUFFICIENT_SCOPE');
$profile = $oauth->userinfo((string) $tokens['access_token'], 't03-userinfo');
t03Assert(isset($profile['sub'], $profile['name'], $profile['email']) && ($profile['email'] ?? '') === 't03-a@example.test', 'userinfo ignored granted profile/email scopes');
t03Assert(($profile['sub'] ?? '') === ($idToken['claims']['sub'] ?? ''), 'userinfo subject differs from ID token subject');

$consentVerifier = str_repeat('d', 43);
$consentPayload = ['response_type' => 'code', 'client_id' => 't03-consent-rp', 'redirect_uri' => 'https://consent.example.test/callback', 'scope' => 'openid profile', 'nonce' => 't03-consent-nonce', 'code_challenge_method' => 'S256', 'code_challenge' => t03Challenge($consentVerifier)];
$consentLow = t03Bind($oauth, $consentPayload, (string) $user['access_token'], 't03-consent-low');
$oauth->approveAuthorization(['authorization_request' => $consentLow['request'], 'csrf_token' => $consentLow['csrf'], 'decision' => 'approve'], (string) $user['access_token'], 't03-consent-low-approve');
$consentHighBegin = $oauth->beginAuthorization(array_replace($consentPayload, ['scope' => 'openid profile email']), 't03-consent-high');
$consentHighRequest = t03Query((string) $consentHighBegin['interaction_uri'])['request'] ?? '';
$consentHigh = $oauth->bindInteraction($consentHighRequest, (string) $user['access_token'], 't03-consent-high-bind');
t03Assert(($consentHigh['consent_required'] ?? false) === true, 'increased scope bypassed explicit consent');

$oldSession = AuthSession::find((int) $secondSession['session_id']);
t03Assert($oldSession !== null, 'second human session fixture is missing');
Db::execute('UPDATE sand_iam_auth_session SET create_time = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 600), (int) $oldSession->id]);
$oldSession = AuthSession::find((int) $oldSession->id);
t03Assert($oldSession !== null && strtotime((string) $oldSession->create_time) < time() - 300, 'could not age the session fixture for reauthentication checks');
$reauthPayload = array_replace($payload, ['prompt' => 'login', 'code_challenge' => t03Challenge(str_repeat('e', 43))]);
$reauthBegin = $oauth->beginAuthorization($reauthPayload, 't03-prompt-login');
$reauthRequest = t03Query((string) $reauthBegin['interaction_uri'])['request'] ?? '';
t03Expect(static fn () => $oauth->bindInteraction($reauthRequest, (string) $secondSession['access_token'], 't03-prompt-login-bind'), 'SAND_IAM_OAUTH_REAUTH_REQUIRED');
$maxAgePayload = array_replace($payload, ['max_age' => 60, 'code_challenge' => t03Challenge(str_repeat('f', 43))]);
$maxAgeBegin = $oauth->beginAuthorization($maxAgePayload, 't03-max-age');
$maxAgeRequest = t03Query((string) $maxAgeBegin['interaction_uri'])['request'] ?? '';
t03Expect(static fn () => $oauth->bindInteraction($maxAgeRequest, (string) $secondSession['access_token'], 't03-max-age-bind'), 'SAND_IAM_OAUTH_REAUTH_REQUIRED');

$malformedScope = $oauth->beginAuthorization(array_replace($payload, ['scope' => 'openid bad?']), 't03-malformed-scope');
t03Assert((t03Query((string) $malformedScope['redirect_uri'])['error'] ?? '') === 'invalid_scope', 'malformed scope was silently accepted');
$promptNone = $oauth->beginAuthorization(array_replace($payload, ['prompt' => 'none']), 't03-prompt-none');
t03Assert((t03Query((string) $promptNone['redirect_uri'])['error'] ?? '') === 'login_required', 'prompt=none did not fail closed without a browser session');
$promptInvalid = $oauth->beginAuthorization(array_replace($payload, ['prompt' => 'none login']), 't03-prompt-invalid');
t03Assert((t03Query((string) $promptInvalid['redirect_uri'])['error'] ?? '') === 'invalid_request', 'prompt=none combination was accepted');

$rotated = $oauth->token(['grant_type' => 'refresh_token', 'client_id' => $clientId, 'refresh_token' => $tokens['refresh_token']], 't03-refresh');
t03Assert(isset($rotated['access_token'], $rotated['refresh_token']), 'refresh did not rotate token');
t03Expect(static fn () => $oauth->token(['grant_type' => 'refresh_token', 'client_id' => $clientId, 'refresh_token' => $tokens['refresh_token']], 't03-refresh-replay'), 'SAND_IAM_OAUTH_REFRESH_REPLAY_DETECTED');
t03Expect(static fn () => $oauth->userinfo((string) $rotated['access_token'], 't03-replay-userinfo'), 'SAND_IAM_OAUTH_TOKEN_INVALID');

$machineTokens = $oauth->token(['grant_type' => 'client_credentials', 'client_id' => 't03-machine', 'client_secret' => $machineSecret, 'scope' => 'inventory.read', 'audience' => 'https://api.example.test/inventory'], 't03-machine');
t03Assert(isset($machineTokens['access_token']) && !isset($machineTokens['refresh_token'], $machineTokens['id_token']), 'client credentials returned user token types');
$machineClaims = $oauth->verifyAccessTokenForAudience((string) $machineTokens['access_token'], 'https://api.example.test/inventory', ['inventory.read']);
t03Assert(($machineClaims['sub'] ?? '') === 'client:t03-machine', 'client credentials did not use machine subject');
t03Expect(static fn () => $oauth->token(['grant_type' => 'client_credentials', 'client_id' => 't03-machine', 'client_secret' => 'wrong-secret', 'scope' => 'inventory.read'], 't03-machine-secret'), 'SAND_IAM_OAUTH_INVALID_CLIENT');
t03Expect(static fn () => $oauth->token(['grant_type' => 'client_credentials', 'client_id' => $clientId, 'scope' => 'profile'], 't03-public-machine'), 'SAND_IAM_OAUTH_UNAUTHORIZED_CLIENT');
$oauth->revoke(['client_id' => 't03-machine', 'client_secret' => $machineSecret, 'token' => 'unknown-token'], 't03-unknown-revoke');
t03Expect(static fn () => $oauth->userinfo((string) $machineTokens['access_token'], 't03-machine-userinfo'), 'SAND_IAM_OAUTH_TOKEN_INVALID');

$grant = OAuthGrant::where('client_id', (int) $public->id)->order('id', 'desc')->find();
t03Assert($grant !== null, 'authorization grant is missing');
try {
    OAuthToken::create(['application_id' => (int) $applicationA->id, 'client_id' => (int) $secondPublic->id, 'grant_id' => (int) $grant->id, 'identity_id' => $grant->identity_id, 'token_type' => 'access', 'token_hash' => hash('sha256', 't03-invalid-db-binding'), 'token_id' => 'ot_' . bin2hex(random_bytes(16)), 'scope' => 'openid', 'expire_time' => date('Y-m-d H:i:s', time() + 60), 'status' => 1]);
    t03Fail('cross-client token/grant database association was accepted');
} catch (\Throwable) {
}

$reflection = new ReflectionClass($oauth);
$sign = $reflection->getMethod('jwt');
$verifyJwt = $reflection->getMethod('verifyJwt');
$mutations = [
    array_replace($machineClaims, ['iss' => 'https://wrong.example.test/api/sand-iam/v1']),
    array_replace($machineClaims, ['aud' => 'https://api.example.test/wrong']),
    array_replace($machineClaims, ['token_use' => 'id_token']),
];
foreach ($mutations as $index => $claims) {
    $jwt = $sign->invoke($oauth, $claims, $claims['token_use'] === 'id_token' ? 'JWT' : 'at+jwt');
    t03Expect(static fn () => $verifyJwt->invoke($oauth, (string) $jwt, 'access_token', 'https://api.example.test/inventory'), 'SAND_IAM_OAUTH_TOKEN_INVALID');
}
$parts = explode('.', (string) $machineTokens['access_token']);
$parts[0] = rtrim(strtr(base64_encode(json_encode(['alg' => 'none', 'typ' => 'at+jwt', 'kid' => 't03-ephemeral-rsa'], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
t03Expect(static fn () => $verifyJwt->invoke($oauth, implode('.', $parts), 'access_token', 'https://api.example.test/inventory'), 'SAND_IAM_OAUTH_TOKEN_INVALID');

$logoutVerifier = str_repeat('c', 43);
$logoutPayload = array_replace($payload, ['code_challenge' => t03Challenge($logoutVerifier), 'state' => 'logout-auth-state']);
$logoutTokens = t03AuthorizeAndExchange($oauth, $logoutPayload, (string) $secondSession['access_token'], $logoutVerifier, 't03-logout-auth');
$sameSessionVerifier = str_repeat('g', 43);
$sameSessionPayload = ['response_type' => 'code', 'client_id' => 't03-public-rp-2', 'redirect_uri' => 'https://rp2.example.test/callback', 'scope' => 'openid profile', 'nonce' => 't03-second-client-nonce', 'code_challenge_method' => 'S256', 'code_challenge' => t03Challenge($sameSessionVerifier)];
$sameSessionTokens = t03AuthorizeAndExchange($oauth, $sameSessionPayload, (string) $secondSession['access_token'], $sameSessionVerifier, 't03-logout-same-session-client');
t03Expect(static fn () => $oauth->logout(['id_token_hint' => $logoutTokens['id_token'], 'post_logout_redirect_uri' => 'https://rp.example.test/not-registered'], 't03-logout-wrong-redirect'), 'SAND_IAM_OIDC_POST_LOGOUT_REDIRECT_URI_INVALID');
$logout = $oauth->logout(['id_token_hint' => $logoutTokens['id_token'], 'post_logout_redirect_uri' => $logoutRedirect, 'state' => 't03-logout-state'], 't03-logout');
t03Assert(($logout === null ? '' : t03Query($logout['redirect_uri'])['state'] ?? '') === 't03-logout-state', 'logout did not return validated state');
t03Expect(static fn () => $oauth->userinfo((string) $logoutTokens['access_token'], 't03-after-logout'), 'SAND_IAM_OAUTH_TOKEN_INVALID');
t03Expect(static fn () => $oauth->userinfo((string) $sameSessionTokens['access_token'], 't03-after-logout-same-session-client'), 'SAND_IAM_OAUTH_TOKEN_INVALID');
t03Assert(count($human->sessions((string) $otherUser['access_token'])) === 1, 'logout revoked an unrelated application session');

$applicationA->save(['status' => 2]);
t03Expect(static fn () => $oauth->verifyAccessTokenForAudience((string) $machineTokens['access_token'], 'https://api.example.test/inventory'), 'SAND_IAM_OAUTH_TOKEN_INVALID');
t03Expect(static fn () => $oauth->token(['grant_type' => 'client_credentials', 'client_id' => 't03-machine', 'client_secret' => $machineSecret, 'scope' => 'inventory.read'], 't03-disabled-application'), 'SAND_IAM_OAUTH_INVALID_CLIENT');
$applicationA->save(['status' => 1]);
$organization->save(['status' => 2]);
t03Expect(static fn () => $oauth->verifyAccessTokenForAudience((string) $machineTokens['access_token'], 'https://api.example.test/inventory'), 'SAND_IAM_OAUTH_TOKEN_INVALID');
t03Expect(static fn () => $oauth->token(['grant_type' => 'client_credentials', 'client_id' => 't03-machine', 'client_secret' => $machineSecret, 'scope' => 'inventory.read'], 't03-disabled-organization'), 'SAND_IAM_OAUTH_INVALID_CLIENT');
$organization->save(['status' => 1]);

$audit = json_encode(AuditLog::where('organization_id', (int) $organization->id)->select()->toArray(), JSON_UNESCAPED_SLASHES);
t03Assert(is_string($audit), 'OAuth audit rows could not be encoded');
foreach ([$password, $machineSecret, $verifier, (string) $tokens['access_token'], (string) $tokens['refresh_token'], $code, 't03-nonce', $bound['csrf']] as $sensitive) {
    t03Assert(!str_contains($audit, $sensitive), 'OAuth secret material leaked into audit rows');
}

echo "OAuth/OIDC ORM integration passed\n";
