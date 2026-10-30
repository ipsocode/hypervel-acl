<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Http\Requests;

use Closure;
use Hypervel\Foundation\Http\FormRequest;
use Hypervel\Permission\PermissionRegistrar;
use Ipsocode\Acl\Support\Config;
use Ipsocode\Acl\Support\Teams;

class TeamRequest extends FormRequest
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
        $tenant = app(PermissionRegistrar::class)->getPermissionsTeamId();
        $column = Teams::tenantColumn();
        /** @var null|string $current */
        $current = $this->route('team');

        $rules = [
            'name' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) use ($column, $tenant, $current): void {
                $taken = Teams::query()->withoutGlobalScope('acl_tenant')
                    ->where('name', $value)
                    ->when($column !== null, fn ($query) => $query->where($column, Teams::query()->find($tenant)?->tenant()))
                    ->when($current !== null, fn ($query) => $query->whereKeyNot($current))
                    ->exists();

                if ($taken) {
                    $fail('The name has already been taken.');
                }
            }],
            'meta' => ['sometimes', 'nullable', 'array'],
        ];

        $rules[Config::teamsInheritKey()] = ['sometimes', 'nullable', function (string $attribute, mixed $value, Closure $fail) use ($column, $tenant, $current): void {
            $query = Teams::query()->whereKey($value);
            $own = Teams::query()->find($tenant);

            if ($column !== null) {
                $query->where($column, $own?->tenant());
            }

            if (! $query->exists()) {
                $fail('The inherited team does not exist in the tenant.');

                return;
            }

            $inherited = $query->first();
            $seen = [];

            for (; $inherited !== null && ! isset($seen[$inherited->getKey()]); $inherited = $inherited->inheritedTeam()) {
                $seen[$inherited->getKey()] = true;

                if ($current !== null && (string) $inherited->getKey() === (string) $current) {
                    $fail('A team cannot inherit itself, directly or through the teams it inherits.');

                    return;
                }
            }
        }];

        return $rules;
    }
}
