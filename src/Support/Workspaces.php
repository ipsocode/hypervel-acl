<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Support;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\Schema\ColumnDefinition;
use Ipsocode\Acl\Models\AclWorkspace;
use Ipsocode\Acl\Workspace;
use RuntimeException;

class Workspaces
{
    /**
     * Determine whether workspaces partition the permission tables.
     */
    public static function enabled(): bool
    {
        return Config::workspaceEnabled();
    }

    /**
     * Get the partition column of the permission tables.
     *
     * @throws RuntimeException when acl.workspace is on and acl.column_names.workspace_foreign_key names no column
     */
    public static function column(): string
    {
        Config::ensureWorkspaceEnabled();

        $column = Config::workspaceColumn();

        throw_if($column === null || $column === '', 'Error: workspace on config/acl.php not loaded. Run [php artisan config:clear] and try again.');

        return $column;
    }

    /**
     * Get the workspace model's column that holds the parent workspace: acl.column_names.workspace_parent.
     */
    public static function parentColumn(): string
    {
        return Config::workspaceParentColumn();
    }

    /**
     * Get the class of the workspace model, or null when workspaces are flat partitions the application identifies itself.
     *
     * @return null|class-string<Model>
     */
    public static function model(): ?string
    {
        return Config::workspaceModel();
    }

    /**
     * Determine whether the package's workspace model is in use, so the package creates the workspace table.
     */
    public static function usesPackageModel(): bool
    {
        return static::model() === AclWorkspace::class;
    }

    /**
     * Determine whether workspace keys are auto-incrementing ids: a flat workspace is a string.
     */
    public static function usesIdKeys(): bool
    {
        $model = static::model();

        if ($model === null) {
            return false;
        }

        // The package's workspace model takes its key type from the configuration, as HasConfiguredKey does.
        return (static::usesPackageModel() ? KeyType::resolve() : KeyType::fromModel($model)) === KeyType::Id;
    }

    /**
     * Add the partition column to a table, defaulting to the home workspace.
     */
    public static function addColumn(Blueprint $table): ColumnDefinition
    {
        $column = static::column();

        return static::usesIdKeys()
            ? $table->unsignedBigInteger($column)->default(Workspace::HOME)
            : $table->string($column)->default((string) Workspace::HOME);
    }

    /**
     * Start a query on the workspace model.
     *
     * @return Builder<Model>
     */
    public static function query(): Builder
    {
        /** @var class-string<Model> $model */
        $model = static::model();

        return $model::query();
    }

    /**
     * Get the keys of the workspaces beneath the given ones, however far down, stopping at a cycle.
     *
     * @param list<int|string> $keys
     * @return list<int|string>
     */
    public static function descendantKeys(array $keys): array
    {
        if (static::model() === null) {
            return [];
        }

        $parent = static::parentColumn();
        $seen = array_fill_keys(array_map('strval', $keys), true);
        $queue = $keys;
        $found = [];

        while ($queue !== []) {
            $query = static::query()->withoutGlobalScope('acl_tenant');
            $next = [];

            foreach ($query->whereIn($parent, $queue)->pluck($query->getModel()->getKeyName()) as $key) {
                if (! isset($seen[(string) $key])) {
                    $seen[(string) $key] = true;
                    $found[] = $key;
                    $next[] = $key;
                }
            }

            $queue = $next;
        }

        return $found;
    }

    /**
     * Get the keys of a workspace and of the workspaces above it, nearest first, stopping at a cycle.
     *
     * @return list<int|string>
     */
    public static function ancestorKeys(int|string $key): array
    {
        $chain = [$key];

        if (static::model() === null) {
            return $chain;
        }

        $parent = static::parentColumn();
        $seen = [(string) $key => true];

        for ($above = static::parentOf($key, $parent); $above !== null && ! isset($seen[(string) $above]); $above = static::parentOf($above, $parent)) {
            $seen[(string) $above] = true;
            $chain[] = $above;
        }

        return $chain;
    }

    /**
     * Get the keys of the home workspace and of every workspace of the model, which must be set.
     *
     * @return list<int|string>
     */
    public static function partitions(): array
    {
        $query = static::query();

        return [Workspace::HOME, ...array_values($query->pluck($query->getModel()->getKeyName())->all())];
    }

    protected static function parentOf(int|string $key, string $parent): int|string|null
    {
        return static::query()->withoutGlobalScope('acl_tenant')->whereKey($key)->value($parent);
    }
}
