<?php

declare(strict_types=1);

namespace plugin\SandIam\app\api\controller;

use plugin\SandIam\app\service\HumanAuthService;
use plugin\SandIam\app\service\FederationService;
use plugin\SandIam\app\service\MfaService;
use plugin\SandIam\app\service\RequestId;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use support\Request;
use support\Response;

final class AuthController extends BaseController
{
    public function register(Request $request): Response { return $this->success((new HumanAuthService())->register($request->post(), $this->ip($request), $this->requestId($request)))->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache'); }
    public function login(Request $request): Response { return $this->success((new HumanAuthService())->login($request->post(), $this->ip($request), $this->requestId($request)))->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache'); }
    public function refresh(Request $request): Response { return $this->success((new HumanAuthService())->refresh((string) $request->post('refresh_token', ''), $this->ip($request), $this->requestId($request)))->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache'); }
    public function logout(Request $request): Response { (new HumanAuthService())->logout($this->bearer($request), $this->requestId($request)); return $this->success('已退出'); }
    public function forgotPassword(Request $request): Response { (new HumanAuthService())->requestVerification(array_merge($request->post(), ['purpose' => 'password_reset', '_password_reset_endpoint' => true, '_ip' => $this->ip($request)]), $this->requestId($request)); return $this->success('如账号存在，重置验证码已发送'); }
    public function resetPassword(Request $request): Response { (new HumanAuthService())->resetPassword(array_merge($request->post(), ['_ip' => $this->ip($request)]), $this->requestId($request)); return $this->success('密码已重置，请重新登录'); }
    public function changePassword(Request $request): Response { (new HumanAuthService())->changePassword($this->bearer($request), (string) $request->post('current_password', ''), (string) $request->post('new_password', ''), $this->ip($request), $this->requestId($request)); return $this->success('密码已修改，所有设备需要重新登录'); }
    public function requestVerification(Request $request): Response { (new HumanAuthService())->requestVerification(array_merge($request->post(), ['_ip' => $this->ip($request)]), $this->requestId($request)); return $this->success('如账号存在，验证码已发送'); }
    public function confirmVerification(Request $request): Response { (new HumanAuthService())->confirmVerification(array_merge($request->post(), ['_ip' => $this->ip($request)]), $this->requestId($request)); return $this->success('验证已完成'); }
    public function sessions(Request $request): Response { return $this->success((new HumanAuthService())->sessions($this->bearer($request))); }
    public function revokeSession(Request $request): Response { (new HumanAuthService())->revokeSession($this->bearer($request), (int) $request->post('id', 0), $this->requestId($request)); return $this->success('会话已撤销'); }
    public function mfaFactors(Request $request): Response { return $this->success((new MfaService())->factors($this->bearer($request))); }
    public function totpStart(Request $request): Response { return $this->sensitive((new MfaService())->totpStart($this->bearer($request), (string) $request->post('name', ''), (string) $request->post('current_password', ''), $this->requestId($request), $this->ip($request))); }
    public function totpConfirm(Request $request): Response { return $this->sensitive((new MfaService())->totpConfirm($this->bearer($request), (int) $request->post('factor_id', 0), (string) $request->post('code', ''), $this->requestId($request))); }
    public function mfaRename(Request $request): Response { (new MfaService())->rename($this->bearer($request), (int) $request->post('factor_id', 0), (string) $request->post('name', ''), $this->requestId($request), (string) $request->post('type', 'totp')); return $this->success('设备名称已更新'); }
    public function mfaRevoke(Request $request): Response { (new MfaService())->revoke($this->bearer($request), (int) $request->post('factor_id', 0), (string) $request->post('password', ''), $this->requestId($request), (string) $request->post('type', 'totp'), $this->ip($request)); return $this->success('认证方式已撤销'); }
    public function recoveryRegenerate(Request $request): Response { return $this->sensitive((new MfaService())->regenerateRecoveryCodes($this->bearer($request), (string) $request->post('password', ''), $this->requestId($request), $this->ip($request))); }
    public function mfaChallengeVerify(Request $request): Response { return $this->sensitive((new MfaService())->verifyLoginChallenge($request->post(), $this->ip($request), $this->requestId($request))); }
    public function stepUpPassword(Request $request): Response { return $this->sensitive((new HumanAuthService())->stepUpPassword($this->bearer($request), (string) $request->post('password', ''), $this->ip($request), $this->requestId($request))); }
    public function stepUpMfaStart(Request $request): Response { return $this->sensitive((new MfaService())->beginStepUp($this->bearer($request), $this->requestId($request))); }
    public function federationUnlink(Request $request): Response { (new FederationService())->unlink($this->bearer($request), (int) $request->post('binding_id', 0), $this->requestId($request)); return $this->success('身份源绑定已解除'); }
    public function passkeyRegistrationOptions(Request $request): Response { return $this->sensitive((new MfaService())->passkeyRegistrationOptions($this->bearer($request), (string) $request->post('name', ''), (string) $request->post('current_password', ''), $this->requestId($request), $this->ip($request))); }
    public function passkeyRegistrationFinish(Request $request): Response { (new MfaService())->passkeyRegistrationFinish($this->bearer($request), $request->post(), $this->requestId($request), $this->ip($request)); return $this->success('通行密钥已添加'); }
    public function passkeyAuthenticationOptions(Request $request): Response { return $this->sensitive((new MfaService())->passkeyAuthenticationOptions($request->post(), $this->requestId($request), $this->ip($request))); }
    public function passkeyAuthenticationFinish(Request $request): Response { return $this->sensitive((new MfaService())->passkeyAuthenticationFinish($request->post(), $this->ip($request), $this->requestId($request))); }
    private function bearer(Request $request): string { $value = trim((string) $request->header('Authorization', '')); $token = strncasecmp($value, 'Bearer ', 7) === 0 ? trim(substr($value, 7)) : ''; if ($token === '') throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401); return $token; }
    private function sensitive(array $data): Response { return $this->success($data)->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache'); }
    private function ip(Request $request): string { return (string) $request->getRealIp(); }
    private function requestId(Request $request): string { return RequestId::fromRequestCached($request); }
}
