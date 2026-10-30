<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Ipsocode\Acl\Database\Seeders\AclSeeder as BaseAclSeeder;
use Workbench\App\Enums\Role;

class AclSeeder extends BaseAclSeeder
{
    protected function definitions(): array
    {
        return Role::cases();
    }
}
