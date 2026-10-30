<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Feature;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Permission\Models\Role;
use Hypervel\Support\Facades\Schema;
use Ipsocode\Acl\Models\AclTeam;
use Ipsocode\Acl\Support\KeyType;
use Ipsocode\Acl\Tests\Fixtures\UlidRole;
use Ipsocode\Acl\Tests\Fixtures\UuidRole;
use Ipsocode\Acl\Tests\TestCase;
use Str;

class KeyTypeTest extends TestCase
{
    public function testColumnDefinitionsNameTheirKeyType(): void
    {
        $this->assertSame(KeyType::Uuid, KeyType::fromColumn('char(36)', 'char'));
        $this->assertSame(KeyType::Uuid, KeyType::fromColumn('uuid', 'uuid'));
        $this->assertSame(KeyType::Ulid, KeyType::fromColumn('char(26)', 'char'));
        $this->assertSame(KeyType::Id, KeyType::fromColumn('bigint unsigned', 'bigint'));
        $this->assertNull(KeyType::fromColumn('text', 'text'));
    }

    public function testRoleModelsNameTheirKeyType(): void
    {
        $this->assertSame(KeyType::Id, KeyType::fromModel(Role::class));
        $this->assertSame(KeyType::Uuid, KeyType::fromModel(UuidRole::class));
        $this->assertSame(KeyType::Ulid, KeyType::fromModel(UlidRole::class));
    }

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

    public function testNewKeys(): void
    {
        $this->assertNull(KeyType::Id->newId());
        $this->assertTrue(Str::isUuid(KeyType::Uuid->newId()));
        $this->assertTrue(Str::isUlid(KeyType::Ulid->newId()));
    }

    public function testTeamsKeepIntegerIdsByDefault(): void
    {
        $team = AclTeam::create(['name' => 'T']);

        $this->assertIsInt($team->id);
        $this->assertTrue($team->getIncrementing());
        $this->assertSame('int', $team->getKeyType());
    }
}
