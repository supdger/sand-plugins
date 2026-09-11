<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class CasTicket extends AbstractSandIamModel
{
    protected $table = 'sand_iam_cas_ticket';
    protected $hidden = ['ticket_hash'];
}
