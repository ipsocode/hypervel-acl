<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Fixtures;

use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Permission\Models\Role;

class UuidRole extends Role
{
    use HasUuids;
}
