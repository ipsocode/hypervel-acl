<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Models;

use Hypervel\Permission\Models\Permission as BasePermission;
use Ipsocode\Acl\Concerns\BelongsToTenant;

/**
 * The framework's permission, scoped to the current tenant; name it in permission.models.permission.
 */
class Permission extends BasePermission
{
    use BelongsToTenant;
}
