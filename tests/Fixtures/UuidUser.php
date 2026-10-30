<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Fixtures;

use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Workbench\App\Models\User;

class UuidUser extends User
{
    use HasUuids;

    protected ?string $table = 'users';
}
