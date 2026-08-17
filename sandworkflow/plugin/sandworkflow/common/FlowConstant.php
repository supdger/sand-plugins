<?php
declare(strict_types=1);

namespace plugin\sandworkflow\common;

class FlowConstant
{
    public const NODE = [
        'START' => 0,
        'APPROVE' => 1,
        'COPY' => 2,
        'CONDITION' => 3,
        'EXCLUSIVE_GATEWAY' => 4,
        'TRANSACT' => 5,
        'TRIGGER' => 6,
        'END' => 9,
    ];

    public const CMD = [
        'START' => 0,
        'AUTO_REJECTED' => 1,
        'AUTO_APPROVED' => 2,
        'REJECTED' => 3,
        'APPROVED' => 4,
        'CANCELED' => 5,
        'ASSIGN' => 6,
        'BACK' => 7,
        'ADD_SIGN' => 8,
        'DEL_SIGN' => 9,
        'ADD_BEFORE_SIGN' => 10,
        'ADD_AFTER_SIGN' => 11,
        'COPY' => 12,
        'FORWARD' => 13,
        'COMMENT' => 14,
        'TRANSACT' => 15,
        'TRANSFER' => 16,
    ];

    public const STATUS = [
        'UNDERWAY' => 0,
        'APPROVED' => 1,
        'REJECTED' => 2,
        'CANCELLED' => 3,
    ];

    public const ASSIGNEE = [
        'SELF' => 0,
        'SUPERIOR' => 1,
        'DEPARTMENT_LEADER' => 2,
        'ROLE' => 3,
        'ASSIGNEE' => 4,
        'MULTISTEP_LEADER' => 5,
        'MULTISTEP_DEPARTMENT_LEADER' => 6,
        'INITIATOR_CHOICE' => 7,
    ];
}
