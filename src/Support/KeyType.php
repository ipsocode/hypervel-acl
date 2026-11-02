<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Support;

use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\Schema\ColumnDefinition;
use Hypervel\Database\Schema\ForeignIdColumnDefinition;
use Hypervel\Permission\Guard;
use Hypervel\Support\Facades\Schema;
use Hypervel\Support\Str;

enum KeyType: string
{
    case Id = 'id';
    case Uuid = 'uuid';
    case Ulid = 'ulid';

    /**
     * Get the key type of the package's tables: acl.key_type, else the one the models generate.
     */
    public static function resolve(): self
    {
        $configured = Config::keyType();

        return $configured !== null ? self::from($configured) : self::fromModels();
    }

    /**
     * Get the key type for a migration: acl.key_type, else the permissions table if it exists, else the models'.
     */
    public static function forMigration(): self
    {
        $configured = Config::keyType();

        if ($configured !== null) {
            return self::from($configured);
        }

        return self::fromPermissionsTable() ?? self::fromModels();
    }

    /**
     * Get the key type the role model generates, else the one the first guard's user model does.
     */
    public static function fromModels(): self
    {
        $role = self::fromModel(Config::roleModel());

        if ($role !== self::Id) {
            return $role;
        }

        $user = Guard::getModelForGuard(Config::guards()[0]);

        return $user === null ? self::Id : self::fromModel($user);
    }

    /**
     * Get the key type of the permissions table's id column, or null when the table is missing or names none.
     */
    public static function fromPermissionsTable(): ?self
    {
        return self::fromTable(Config::permissionsTable(), 'id');
    }

    /**
     * Get the key type of a table's column, or null when the table or column is missing or names none.
     */
    public static function fromTable(string $table, string $column): ?self
    {
        if (Schema::hasTable($table)) {
            foreach (Schema::getColumns($table) as $definition) {
                if ($definition['name'] === $column) {
                    return self::fromColumn($definition['type'], $definition['type_name']);
                }
            }
        }

        return null;
    }

    /**
     * Get the key type a database column definition describes, if it names one.
     */
    public static function fromColumn(string $type, string $typeName): ?self
    {
        $type = strtolower($type);
        $typeName = strtolower($typeName);

        return match (true) {
            $typeName === 'uuid' || str_contains($type, '(36)') => self::Uuid,
            str_contains($type, '(26)') => self::Ulid,
            str_contains($typeName, 'int') => self::Id,
            default => null,
        };
    }

    /**
     * Get the key type a role model generates.
     *
     * @param class-string $model
     */
    public static function fromModel(string $model): self
    {
        $traits = class_uses_recursive($model);

        return match (true) {
            in_array(HasUuids::class, $traits, true) => self::Uuid,
            in_array(HasUlids::class, $traits, true) => self::Ulid,
            default => self::Id,
        };
    }

    /**
     * Add the primary key column to a table.
     */
    public function primary(Blueprint $table, string $column = 'id'): ColumnDefinition
    {
        return match ($this) {
            self::Id => $table->id($column),
            self::Uuid => $table->uuid($column)->primary(),
            self::Ulid => $table->ulid($column)->primary(),
        };
    }

    /**
     * Add a foreign key column that references a key of this type.
     */
    public function reference(Blueprint $table, string $column): ForeignIdColumnDefinition
    {
        return match ($this) {
            self::Id => $table->foreignId($column),
            self::Uuid => $table->foreignUuid($column),
            self::Ulid => $table->foreignUlid($column),
        };
    }

    /**
     * Generate a new key, or null when the database assigns it.
     */
    public function newId(): ?string
    {
        return match ($this) {
            self::Id => null,
            self::Uuid => (string) Str::uuid7(),
            self::Ulid => (string) Str::ulid(),
        };
    }
}
