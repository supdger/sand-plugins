<?php

use Webman\Route;
use plugin\sandworkflow\app\controller\DefinitionController;
use plugin\sandworkflow\app\controller\GroupController;
use plugin\sandworkflow\app\controller\IndexController;
use plugin\sandworkflow\app\controller\InstanceController;
use plugin\sandworkflow\app\controller\TaskController;

Route::group('/admin', function () {
    Route::get('/index/listUsers', [IndexController::class, 'listUsers']);
    Route::get('/index/listRoles', [IndexController::class, 'listRoles']);
    Route::get('/index/listDepts', [IndexController::class, 'listDepts']);

    fastRoute('group', GroupController::class);
    Route::get('/group/all', [GroupController::class, 'all']);

    fastRoute('definition', DefinitionController::class);
    Route::get('/definition/available', [DefinitionController::class, 'available']);
    Route::get('/definition/versions', [DefinitionController::class, 'versions']);
    Route::post('/definition/publish', [DefinitionController::class, 'publish']);

    fastRoute('instance', InstanceController::class);
    Route::get('/instance/pendingList', [InstanceController::class, 'pendingList']);
    Route::get('/instance/myList', [InstanceController::class, 'myList']);
    Route::get('/instance/processedList', [InstanceController::class, 'processedList']);
    Route::get('/instance/copyList', [InstanceController::class, 'copyList']);
    Route::get('/instance/getDetail', [InstanceController::class, 'getDetail']);
    Route::post('/instance/start', [InstanceController::class, 'start']);
    Route::post('/instance/approve', [InstanceController::class, 'approve']);
    Route::post('/instance/reject', [InstanceController::class, 'reject']);
    Route::post('/instance/cancel', [InstanceController::class, 'cancel']);
    Route::post('/instance/comment', [InstanceController::class, 'comment']);
    Route::post('/instance/transfer', [InstanceController::class, 'transfer']);
    Route::post('/instance/addSign', [InstanceController::class, 'addSign']);
    Route::post('/instance/delSign', [InstanceController::class, 'delSign']);
    Route::post('/instance/backNodeList', [InstanceController::class, 'backNodeList']);
    Route::post('/instance/back', [InstanceController::class, 'back']);

    Route::get('/task/pendingList', [TaskController::class, 'pendingList']);
});

Route::disableDefaultRoute('sandworkflow');
