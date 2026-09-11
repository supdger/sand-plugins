<?php

declare(strict_types=1);

namespace plugin\SandIam\app\api\controller;

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\ApplicationExperience;
use plugin\SandIam\app\model\Organization;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use support\Request;
use support\Response;

final class ExperienceController extends BaseController
{
    public function read(Request $request): Response
    {
        if ((int) config('plugin.sand-iam.app.application_experience_enabled', 0) !== 1) throw new ApiException('SAND_IAM_APPLICATION_EXPERIENCE_UNAVAILABLE', 503);
        $organizationCode = trim((string) $request->get('organization_code', ''));
        $applicationCode = trim((string) $request->get('application_code', ''));
        $organization = Organization::where('code', $organizationCode)->where('status', 1)->find();
        $application = $organization ? Application::where('organization_id', (int) $organization->id)->where('code', $applicationCode)->where('status', 1)->find() : null;
        $experience = $application ? ApplicationExperience::where('application_id', (int) $application->id)->where('status', 1)->find() : null;
        if ($application === null || $experience === null) throw new ApiException('SAND_IAM_APPLICATION_EXPERIENCE_NOT_FOUND', 404);
        return $this->success([
            'organization_code' => (string) $organization->code,
            'application_code' => (string) $application->code,
            'brand_name' => (string) $experience->brand_name,
            'logo_url' => (string) ($experience->logo_url ?? ''),
            'primary_color' => (string) $experience->primary_color,
            'theme_mode' => (string) $experience->theme_mode,
            'default_locale' => (string) $experience->default_locale,
            'terms_url' => (string) ($experience->terms_url ?? ''),
            'privacy_url' => (string) ($experience->privacy_url ?? ''),
            'registration_mode' => (string) $experience->registration_mode,
            'login_methods' => is_array($experience->login_methods ?? null) ? array_values($experience->login_methods) : [],
            'registration_fields' => is_array($experience->registration_fields ?? null) ? array_values($experience->registration_fields) : [],
        ])->withHeader('Cache-Control', 'public, max-age=60');
    }
}
