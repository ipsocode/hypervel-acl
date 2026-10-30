<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Feature;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Permission\Guard;
use Hypervel\Support\Facades\Schema;
use Ipsocode\Acl\Models\AclTeam;
use Ipsocode\Acl\Models\Permission;
use Ipsocode\Acl\Models\Role;
use Ipsocode\Acl\Support\KeyType;
use Ipsocode\Acl\Tests\Fixtures\UlidRole;
use Ipsocode\Acl\Tests\Fixtures\UuidRole;
use Ipsocode\Acl\Tests\Fixtures\UuidUser;
use Ipsocode\Acl\Tests\TestCase;

class KeyTypeTest extends TestCase
{
    public function testMigrationsCopyThePermissionsTableThenTheRoleModel(): void
    {
        $this->assertSame(KeyType::Id, KeyType::forMigration());

        config(['acl.key_type' => 'ulid']);
        $this->assertSame(KeyType::Ulid, KeyType::forMigration());
        $this->assertSame(KeyType::Ulid, KeyType::resolve());
        config(['acl.key_type' => null]);

        config(['permission.models.role' => UuidRole::class]);
        $this->assertSame(KeyType::Uuid, KeyType::resolve());

        // An id column that names no type, a table without an id, and no table at all fall back to the model.
        Schema::create('odd_keys', fn (Blueprint $table) => $table->text('id'));
        Schema::create('no_keys', fn (Blueprint $table) => $table->text('code'));

        foreach (['odd_keys', 'no_keys', 'missing_table'] as $table) {
            config(['permission.table_names.permissions' => $table]);
            $this->assertSame(KeyType::Uuid, KeyType::forMigration(), $table);
        }
    }

    public function testTeamsKeepIntegerIdsByDefault(): void
    {
        $team = AclTeam::create(['name' => 'T']);

        $this->assertIsInt($team->id);
        $this->assertTrue($team->getIncrementing());
        $this->assertSame('int', $team->getKeyType());
    }

    public function testRolesAndPermissionsFollowTheConfiguredKeyType(): void
    {
        foreach ([Role::class, Permission::class] as $model) {
            $this->assertFalse((new $model)->usesUniqueIds());
            $this->assertSame('int', (new $model)->getKeyType());

            config(['acl.key_type' => 'uuid']);
            $this->assertTrue((new $model)->usesUniqueIds());
            $this->assertSame('string', (new $model)->getKeyType());
            $this->assertFalse((new $model)->getIncrementing());

            config(['acl.key_type' => null]);
        }
    }

    public function testTheUserModelDecidesWhenNeitherTheConfigNorTheRoleModelDoes(): void
    {
        $this->assertSame(KeyType::Id, KeyType::resolve());

        config(['auth.providers.users.model' => UuidUser::class]);
        Guard::flushState();
        $this->assertSame(KeyType::Uuid, KeyType::resolve());

        // A migrated permissions table is copied before any model is read.
        $this->assertSame(KeyType::Id, KeyType::forMigration());
        config(['permission.table_names.permissions' => 'missing_table']);
        $this->assertSame(KeyType::Uuid, KeyType::forMigration());

        // The role model, when it names a type, wins over the user model.
        config(['permission.models.role' => UlidRole::class]);
        $this->assertSame(KeyType::Ulid, KeyType::resolve());

        config(['permission.models.role' => Role::class, 'auth.guards.web.provider' => null]);
        Guard::flushState();
        $this->assertSame(KeyType::Id, KeyType::resolve());
    }
}
