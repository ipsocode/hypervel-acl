<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Http\Rules;

use Closure;
use Hypervel\Contracts\Validation\ValidationRule;
use Hypervel\Support\Facades\DB;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Support\Workspaces;
use Ipsocode\Acl\Workspace;
use Ipsocode\Acl\Workspaces\Reach;

class WorkspaceParent implements ValidationRule
{
    /**
     * Run on null and omitted values too: no parent places a workspace at the top of the tree.
     */
    public bool $implicit = true;

    public function __construct(protected Reach $reach, protected int|string|null $current)
    {
    }

    /**
     * Validate that the parent is a workspace beneath the one the request acts in, and not the
     * workspace being moved or one beneath it; only the home workspace may place one at the top.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== null && ! is_int($value) && ! is_string($value)) {
            $fail('The parent workspace must be a workspace key.');

            return;
        }

        if ($value === null || $value === '') {
            if ((string) Workspace::id() !== (string) Workspace::HOME) {
                $fail('Only an org role in the home workspace places a workspace at the top.');
            }

            return;
        }

        if (! $this->reach->within(Workspaces::query()->whereKey($value))->exists()) {
            $fail('The parent workspace is not one the org role covers.');

            return;
        }

        if ($this->current !== null && in_array((string) $value, array_map('strval', [$this->current, ...Workspaces::descendantKeys([$this->current])]), true)) {
            $fail('A workspace cannot be beneath itself or a workspace beneath it.');

            return;
        }

        if ($this->current !== null && $this->relatesOrgRoles($value)) {
            $fail('The move would leave a user with org roles in workspaces that reference each other.');
        }
    }

    /**
     * Determine whether a user holds an org role in the moved workspace or beneath it and another
     * in the new parent or above it, which the move would make reference each other.
     */
    protected function relatesOrgRoles(int|string $parent): bool
    {
        $column = Workspaces::column();
        $user = Teams::userPivotKey();

        return DB::table(Teams::ORGANIZATIONS_TABLE)
            ->whereIn($column, [$this->current, ...Workspaces::descendantKeys([$this->current])])
            ->whereIn($user, DB::table(Teams::ORGANIZATIONS_TABLE)->whereIn($column, Workspaces::ancestorKeys($parent))->select($user))
            ->exists();
    }
}
