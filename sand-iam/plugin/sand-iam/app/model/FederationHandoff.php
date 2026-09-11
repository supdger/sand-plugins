<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

/** One-time browser federation handoff, persisted without its plaintext code. */
final class FederationHandoff extends AbstractSandIamModel
{
    protected $table = 'sand_iam_federation_handoff';
    protected $hidden = ['code_hash'];
}
