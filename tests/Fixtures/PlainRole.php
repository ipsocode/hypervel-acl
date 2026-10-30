<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Fixtures;

use Ipsocode\Acl\Concerns\DefinesRole;
use Ipsocode\Acl\Contracts\RoleDefinition;

enum PlainRole: string implements RoleDefinition
{
    use DefinesRole;

    case Plain = 'plain';

    public function permissions(): array
    {
        return ['x'];
    }
}
