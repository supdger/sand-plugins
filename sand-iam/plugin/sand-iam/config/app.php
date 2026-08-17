<?php

return [
    'debug' => true,
    'controller_suffix' => 'Controller',
    'controller_reuse' => false,
    'version' => '0.1.0',
    // Deployment-managed secret. Empty means runtime context issuance fails closed.
    'context_signing_key' => env('SAND_IAM_CONTEXT_SIGNING_KEY', ''),
];
