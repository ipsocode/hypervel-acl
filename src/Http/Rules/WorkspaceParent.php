<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Http\Rules;

use Closure;
use Hypervel\Contracts\Validation\ValidationRule;
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
        if ($value === null || $value === '') {
            if ((string) Workspace::id() !== (string) Workspace::HOME) {
                $fail('Only an org role in the home workspace places a workspace at the top.');
            }

            return;
        }

        if (! Workspaces::query()->whereKey($value)->whereKey($this->reach->beneath(Workspace::id()))->exists()) {
            $fail('The parent workspace is not one the org role covers.');

            return;
        }

        if ($this->current !== null && in_array((string) $value, array_map('strval', [$this->current, ...Workspaces::descendantKeys([$this->current])]), true)) {
            $fail('A workspace cannot be beneath itself or a workspace beneath it.');
        }
    }
}
