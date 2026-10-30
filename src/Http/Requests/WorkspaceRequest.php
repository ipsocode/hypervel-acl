<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Http\Requests;

use Closure;
use Hypervel\Foundation\Http\FormRequest;
use Ipsocode\Acl\Http\Rules\WorkspaceParent;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Support\Workspaces;
use Ipsocode\Acl\Tenant;
use Ipsocode\Acl\Workspaces\Reach;

class WorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var null|string $current */
        $current = $this->route('aclWorkspace');

        return [
            'name' => ['required', 'string', fn (string $attribute, mixed $value, Closure $fail) => $this->name($value, $current, $fail)],
            'kind' => ['sometimes', 'nullable', 'string'],
            'meta' => ['sometimes', 'nullable', 'array'],
            Workspaces::parentColumn() => [...($current === null ? [] : ['sometimes']), new WorkspaceParent(app(Reach::class), $current)],
        ];
    }

    /**
     * Fail a name another workspace of the same tenant and parent already has, as the unique index
     * would; a workspace at the top has no parent, which the index does not compare.
     *
     * @param null|int|string $current
     * @param Closure(string): mixed $fail
     */
    protected function name(mixed $name, int|string|null $current, Closure $fail): void
    {
        $parent = Workspaces::parentColumn();
        $column = Teams::tenantColumn();

        $moved = $current === null ? null : Workspaces::query()->withoutGlobalScope('acl_tenant')->find($current);
        $beneath = $this->has($parent) ? $this->input($parent) : $moved?->getAttribute($parent);

        if ($beneath === null || $beneath === '') {
            return;
        }

        $taken = Workspaces::query()->withoutGlobalScope('acl_tenant')
            ->where('name', $name)
            ->where($parent, $beneath)
            ->when($column !== null, fn ($query) => $query->where($column, $moved === null ? Tenant::id() : $moved->getAttribute($column)))
            ->when($current !== null, fn ($query) => $query->whereKeyNot($current))
            ->exists();

        if ($taken) {
            $fail('The name has already been taken.');
        }
    }
}
