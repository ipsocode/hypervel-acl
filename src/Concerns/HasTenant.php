<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Concerns;

use Ipsocode\Acl\Support\Teams;

/**
 * Gives the user model the tenant ResolveAcl sets for its requests.
 */
trait HasTenant
{
    /**
     * Get the tenant the user acts in: its acl.column_names.tenant_foreign_key column by default.
     *
     * Override it where the tenant comes from elsewhere, such as a selected account.
     */
    public function tenantKey(): int|string|null
    {
        $column = Teams::tenantColumn();

        return $column === null ? null : $this->getAttribute($column);
    }
}
