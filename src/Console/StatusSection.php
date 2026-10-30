<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Console;

/**
 * The part of the ACL setup a row or problem of acl:status belongs to.
 */
enum StatusSection: string
{
    case Package = 'package';
    case General = 'general';
    case Tenant = 'tenant';
    case Workspace = 'workspace';

    /**
     * Get the sections acl:status can be limited to.
     *
     * @return list<self>
     */
    public static function features(): array
    {
        return [self::Tenant, self::Workspace];
    }
}
