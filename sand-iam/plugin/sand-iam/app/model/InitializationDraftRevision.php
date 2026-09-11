<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

/** Append-only history for an initialization draft. */
final class InitializationDraftRevision extends NoSoftDeleteSandIamModel
{
    protected $table = 'sand_iam_initialization_draft_revision';
    protected $json = ['manifest'];
    protected $jsonAssoc = true;
}
