<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\developer\ProviderPresetCatalog;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

/** Read-only external IdP presets. Draft generation never persists a provider. */
final class IdentityProviderPresetController
{
    #[Permission('SandIAM 身份源读取', 'sand_iam:identity_provider:read')]
    public function index(Request $request): Response
    {
        return json(['data' => ProviderPresetCatalog::all()])->withHeader('Cache-Control', 'no-store');
    }

    #[Permission('SandIAM 身份源读取', 'sand_iam:identity_provider:read')]
    public function read(Request $request): Response
    {
        return json(ProviderPresetCatalog::read((string) $request->input('code', '')))->withHeader('Cache-Control', 'no-store');
    }

    #[Permission('SandIAM 身份源读取', 'sand_iam:identity_provider:read')]
    public function draft(Request $request): Response
    {
        $input = $request->post();
        if (!is_array($input)) $input = [];
        $draft = ProviderPresetCatalog::draft((string) ($input['code'] ?? ''), $input);

        return json($draft)->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
    }
}
