<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Contracts;

use BackedEnum;

/**
 * A role of the catalogue; the enum's value is the role name.
 */
interface RoleDefinition extends BackedEnum
{
    /**
     * Get the guard the role belongs to.
     */
    public function guard(): string;

    /**
     * Get the allowed permission names, wildcards included.
     *
     * @return list<string>
     */
    public function permissions(): array;

    /**
     * Get the denied permission names, wildcards included.
     *
     * @return list<string>
     */
    public function deniedPermissions(): array;

    /**
     * Describe the role.
     */
    public function description(): string;

    /**
     * Get the role whose permissions this role also holds.
     */
    public function mirrors(): ?self;
}
