<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class ServiceGrant extends AbstractSandIamModel
{
    protected $table = 'sand_iam_service_grant';

    protected $json = ['quota_policy', 'network_policy'];
    protected $jsonAssoc = true;

    /**
     * An omitted quota is unlimited, but its persisted JSONB representation is
     * still an object.  PHP's empty array would otherwise be encoded as `[]`
     * and violate the database constraint that protects the grant contract.
     */
    protected function setQuotaPolicyAttr(mixed $value): mixed
    {
        return $value === [] ? (object) [] : $value;
    }
}
