<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Fixtures;

use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Permission\Models\Role;

class UlidRole extends Role
{
    use HasUlids;
}
