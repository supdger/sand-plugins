<?php

use plugin\sandadmin\app\middleware\CheckAuth;
use plugin\sandadmin\app\middleware\CheckLogin;
use plugin\sandadmin\app\middleware\SystemLog;

return [
    '' => [
        CheckLogin::class,
        CheckAuth::class,
        SystemLog::class,
    ],
];
