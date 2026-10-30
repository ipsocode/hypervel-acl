<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Feature;

use Hypervel\Testing\PHPUnit\AfterEachTestCleanup;
use Ipsocode\Acl\Models\AclTeam;
use Ipsocode\Acl\Models\Role;
use Ipsocode\Acl\Testing\TestState;
use Ipsocode\Acl\Tests\TestCase;

class ConfiguredKeyTypeTest extends TestCase
{
    public function testTheKeyTypeIsResolvedOncePerModelClass(): void
    {
        $this->assertFalse((new AclTeam)->usesUniqueIds());

        // The key type cannot change while a worker runs, so a later change is not read again.
        config(['acl.key_type' => 'uuid']);

        $this->assertFalse((new AclTeam)->usesUniqueIds());
        $this->assertNotSame('string', (new AclTeam)->getKeyType());
    }

    public function testFlushingTheStateResolvesTheKeyTypeAgain(): void
    {
        $this->assertFalse((new AclTeam)->usesUniqueIds());
        $this->assertFalse((new Role)->usesUniqueIds());

        config(['acl.key_type' => 'uuid']);
        TestState::flushState();

        $this->assertTrue((new AclTeam)->usesUniqueIds());
        $this->assertTrue((new Role)->usesUniqueIds());
        $this->assertSame('string', (new AclTeam)->getKeyType());
    }

    public function testTheRegistrarFlushesTheKeyTypeAfterEachTest(): void
    {
        TestState::register();

        $this->assertFalse((new AclTeam)->usesUniqueIds());
        config(['acl.key_type' => 'uuid']);

        AfterEachTestCleanup::runCallbacks();

        $this->assertTrue((new AclTeam)->usesUniqueIds());
    }
}
