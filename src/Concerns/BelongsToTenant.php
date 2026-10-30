<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Concerns;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Tenant;

/**
 * Scopes a model to the current tenant, with acl.tenant on: its queries see the tenant's rows
 * and the shared ones without a tenant, and a model created in a tenant is stamped with it.
 *
 * Route-model binding runs through the scope too, so another tenant's record resolves to a 404.
 */
trait BelongsToTenant
{
    /**
     * Add the tenant scope and stamp new models with the current tenant.
     */
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(Teams::TENANT_SCOPE, static function (Builder $builder): void {
            $column = Teams::tenantColumn();
            $tenant = Tenant::id();

            if ($column !== null && $tenant !== null) {
                $qualified = $builder->qualifyColumn($column);

                $builder->where(fn (Builder $query) => $query->whereNull($qualified)->orWhere($qualified, $tenant));
            }
        });

        static::creating(static function (Model $model): void {
            $column = Teams::tenantColumn();

            if ($column !== null && Tenant::check() && ($model->getAttributes()[$column] ?? null) === null) {
                $model->setAttribute($column, Tenant::id());
            }
        });
    }
}
