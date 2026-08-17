<?php

use plugin\SandIam\app\admin\controller\ApplicationController;
use plugin\SandIam\app\admin\controller\AdminOrganizationGrantController;
use plugin\SandIam\app\admin\controller\AuditLogController;
use plugin\SandIam\app\admin\controller\CredentialController;
use plugin\SandIam\app\admin\controller\EnvironmentController;
use plugin\SandIam\app\admin\controller\IdentityController;
use plugin\SandIam\app\admin\controller\IdentityBindingController;
use plugin\SandIam\app\admin\controller\IdentityRoleController;
use plugin\SandIam\app\admin\controller\IdentityUserTypeController;
use plugin\SandIam\app\admin\controller\OrganizationController;
use plugin\SandIam\app\admin\controller\PolicyController;
use plugin\SandIam\app\admin\controller\ResourceController;
use plugin\SandIam\app\admin\controller\RoleController;
use plugin\SandIam\app\admin\controller\ServiceActionController;
use plugin\SandIam\app\admin\controller\ServiceController;
use plugin\SandIam\app\admin\controller\ServiceGrantController;
use plugin\SandIam\app\admin\controller\UserTypeController;
use plugin\SandIam\app\admin\controller\WorkloadClientController;
use plugin\SandIam\app\api\controller\RuntimeContextController;
use plugin\sandadmin\app\middleware\CheckAuth;
use plugin\sandadmin\app\middleware\CheckLogin;
use plugin\sandadmin\app\middleware\SystemLog;
use Webman\Route;

Route::group('/app/sand-iam/admin', static function (): void {
    foreach ([
        'organization' => OrganizationController::class,
        'application' => ApplicationController::class,
        'environment' => EnvironmentController::class,
        'client' => WorkloadClientController::class,
        'service' => ServiceController::class,
        'action' => ServiceActionController::class,
        'grant' => ServiceGrantController::class,
        'identity' => IdentityController::class,
        'identity-binding' => IdentityBindingController::class,
        'role' => RoleController::class,
        'user-type' => UserTypeController::class,
        'resource' => ResourceController::class,
        'policy' => PolicyController::class,
        'admin-organization-grant' => AdminOrganizationGrantController::class,
    ] as $segment => $controller) {
        Route::get('/' . $segment . '/index', [$controller, 'index']);
        Route::get('/' . $segment . '/read', [$controller, 'read']);
        Route::post('/' . $segment . '/save', [$controller, 'save']);
        Route::post('/' . $segment . '/update', [$controller, 'update']);
        Route::post('/' . $segment . '/disable', [$controller, 'disable']);
    }
    Route::post('/grant/revoke', [ServiceGrantController::class, 'revoke']);
    Route::post('/policy/publish', [PolicyController::class, 'publish']);
    Route::post('/policy/revoke', [PolicyController::class, 'revoke']);
    Route::get('/identity-role/index', [IdentityRoleController::class, 'index']);
    Route::post('/identity-role/grant', [IdentityRoleController::class, 'grant']);
    Route::post('/identity-role/revoke', [IdentityRoleController::class, 'revoke']);
    Route::get('/identity-user-type/index', [IdentityUserTypeController::class, 'index']);
    Route::post('/identity-user-type/grant', [IdentityUserTypeController::class, 'grant']);
    Route::post('/identity-user-type/revoke', [IdentityUserTypeController::class, 'revoke']);
    Route::get('/audit/index', [AuditLogController::class, 'index']);
    Route::get('/audit/read', [AuditLogController::class, 'read']);
    Route::get('/credential/index', [CredentialController::class, 'index']);
    Route::get('/credential/read', [CredentialController::class, 'read']);
    Route::post('/credential/issue', [CredentialController::class, 'issue']);
    Route::post('/credential/rotate', [CredentialController::class, 'rotate']);
    Route::post('/credential/revoke', [CredentialController::class, 'revoke']);
})->middleware([CheckLogin::class, CheckAuth::class, SystemLog::class]);

Route::post('/app/sand-iam/runtime/context/issue', [RuntimeContextController::class, 'issue']);
Route::post('/app/sand-iam/runtime/context/verify', [RuntimeContextController::class, 'verify']);
Route::post('/app/sand-iam/runtime/environment/verify', [RuntimeContextController::class, 'verifyEnvironment']);

Route::disableDefaultRoute(RuntimeContextController::class);
