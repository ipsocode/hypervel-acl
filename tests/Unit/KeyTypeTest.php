<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Unit;

use Hypervel\Permission\Models\Role;
use Hypervel\Support\Str;
use Hypervel\Testing\UnitTestCase;
use Ipsocode\Acl\Support\KeyType;
use Ipsocode\Acl\Tests\Fixtures\UlidRole;
use Ipsocode\Acl\Tests\Fixtures\UuidRole;

class KeyTypeTest extends UnitTestCase
{
    public function testColumnDefinitionsNameTheirKeyType(): void
    {
        $this->assertSame(KeyType::Uuid, KeyType::fromColumn('char(36)', 'char'));
        $this->assertSame(KeyType::Uuid, KeyType::fromColumn('uuid', 'uuid'));
        $this->assertSame(KeyType::Ulid, KeyType::fromColumn('char(26)', 'char'));
        $this->assertSame(KeyType::Id, KeyType::fromColumn('bigint unsigned', 'bigint'));
        $this->assertNull(KeyType::fromColumn('text', 'text'));
    }

    public function testColumnDefinitionsAreMatchedWithoutRegardToCase(): void
    {
        $this->assertSame(KeyType::Uuid, KeyType::fromColumn('UUID', 'UUID'));
        $this->assertSame(KeyType::Id, KeyType::fromColumn('BIGINT UNSIGNED', 'BIGINT'));
    }

    public function testRoleModelsNameTheirKeyType(): void
    {
        $this->assertSame(KeyType::Id, KeyType::fromModel(Role::class));
        $this->assertSame(KeyType::Uuid, KeyType::fromModel(UuidRole::class));
        $this->assertSame(KeyType::Ulid, KeyType::fromModel(UlidRole::class));
    }

    public function testNewKeys(): void
    {
        $this->assertNull(KeyType::Id->newId());
        $this->assertTrue(Str::isUuid(KeyType::Uuid->newId()));
        $this->assertTrue(Str::isUlid(KeyType::Ulid->newId()));
    }
}
