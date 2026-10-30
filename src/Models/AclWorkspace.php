<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Models;

use Hypervel\Database\Eloquent\Attributes\Scope;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Ipsocode\Acl\Concerns\BelongsToTenant;
use Ipsocode\Acl\Concerns\HasConfiguredKey;
use Ipsocode\Acl\Support\Workspaces;
use Ipsocode\Acl\Workspaces\Reach;

class AclWorkspace extends Model
{
    use BelongsToTenant;
    use HasConfiguredKey;

    protected array $guarded = [];

    protected array $casts = ['meta' => 'array'];

    /**
     * Keep the teams' reach right as workspaces are added, moved and deleted.
     */
    protected static function booted(): void
    {
        static::created(static fn (self $workspace) => app(Reach::class)->workspaceChanged($workspace, moved: false));

        static::updated(static function (self $workspace): void {
            if ($workspace->wasChanged(Workspaces::parentColumn())) {
                app(Reach::class)->workspaceChanged($workspace, moved: true);
            }
        });

        static::deleting(static fn (self $workspace) => app(Reach::class)->workspaceRemoved($workspace));
    }

    /**
     * Get the table name: table_names.workspace, else acl_workspaces.
     */
    public function getTable(): string
    {
        return config('acl.table_names.workspace') ?? 'acl_workspaces';
    }

    /**
     * Get the workspace this one is beneath.
     *
     * @return BelongsTo<static, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, Workspaces::parentColumn());
    }

    /**
     * Get the workspaces directly beneath this one.
     *
     * @return HasMany<static, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(static::class, Workspaces::parentColumn());
    }

    /**
     * Get the keys of this workspace and the ones above it, nearest first.
     *
     * @return list<int|string>
     */
    public function ancestorKeys(): array
    {
        return Workspaces::ancestorKeys($this->getKey());
    }

    /**
     * Get the keys of the workspaces beneath this one, however far down.
     *
     * @return list<int|string>
     */
    public function descendantKeys(): array
    {
        return Workspaces::descendantKeys([$this->getKey()]);
    }

    /**
     * Limit the query to the given workspace and the ones beneath it.
     *
     * @param Builder<static> $query
     */
    #[Scope]
    protected function under(Builder $query, int|string $key): void
    {
        $query->whereKey([$key, ...Workspaces::descendantKeys([$key])]);
    }
}
