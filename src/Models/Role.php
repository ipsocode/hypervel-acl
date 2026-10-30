<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Models;

use Hypervel\Permission\Models\Role as BaseRole;
use Ipsocode\Acl\Concerns\BelongsToTenant;

/**
 * The framework's role, scoped to the current tenant; name it in permission.models.role.
 */
class Role extends BaseRole
{
    use BelongsToTenant;
}
