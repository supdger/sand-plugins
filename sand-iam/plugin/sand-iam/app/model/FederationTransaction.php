<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class FederationTransaction extends AbstractSandIamModel
{
    protected $table = 'sand_iam_federation_transaction';
    protected $hidden = ['state_hash', 'browser_binding_hash', 'nonce_hash', 'assertion_hash', 'encrypted_pkce_verifier', 'handoff_state'];
}
