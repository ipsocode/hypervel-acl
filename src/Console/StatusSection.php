<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Console;

enum StatusSection: string
{
    case Package = 'package';
    case General = 'general';
    case Tenant = 'tenant';
    case Workspace = 'workspace';

    /**
     * Get the sections a report can be limited to.
     *
     * @return list<self>
     */
    public static function features(): array
    {
        return [self::Tenant, self::Workspace];
    }
}
