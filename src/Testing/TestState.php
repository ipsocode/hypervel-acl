<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Testing;

use Hypervel\Testing\PHPUnit\AfterEachTestCleanup;
use Ipsocode\Acl\Models\AclTeam;
use Ipsocode\Acl\Models\AclWorkspace;
use Ipsocode\Acl\Models\Permission;
use Ipsocode\Acl\Models\Role;

/**
 * Package-level test-state registrar.
 *
 * Declared via `extra.hypervel.test-state` in composer.json and discovered
 * during PHPUnit extension bootstrap, so this package's worker-lifetime state is
 * reset in consuming applications too.
 */
class TestState
{
    public static function register(): void
    {
        AfterEachTestCleanup::flushUsing('ipsocode/hypervel-acl', fn () => static::flushState());
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        Role::flushConfiguredKeyType();
        Permission::flushConfiguredKeyType();
        AclTeam::flushConfiguredKeyType();
        AclWorkspace::flushConfiguredKeyType();
    }
}
