<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

/** Editable, secret-free source package. Applied runs remain separate evidence. */
final class InitializationDraft extends NoSoftDeleteSandIamModel
{
    protected $table = 'sand_iam_initialization_draft';
    protected $json = ['manifest'];
    protected $jsonAssoc = true;
}
