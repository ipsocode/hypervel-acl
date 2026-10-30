<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Concerns;

use Ipsocode\Acl\Contracts\RoleDefinition;

trait DefinesRole
{
    /**
     * Get the guard the role belongs to.
     */
    public function guard(): string
    {
        return config('acl.guards')[0];
    }

    /**
     * Get the denied permission names.
     *
     * @return list<string>
     */
    public function deniedPermissions(): array
    {
        return [];
    }

    /**
     * Describe the role.
     */
    public function description(): string
    {
        return '';
    }

    /**
     * Get the role whose permissions this role also holds.
     */
    public function mirrors(): ?RoleDefinition
    {
        return null;
    }
}
