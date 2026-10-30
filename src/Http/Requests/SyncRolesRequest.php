<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Http\Requests;

use Closure;
use Hypervel\Foundation\Http\FormRequest;
use Hypervel\Permission\PermissionRegistrar;

class SyncRolesRequest extends FormRequest
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
        return [
            'roles' => ['present', 'array'],
            'roles.*' => ['string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! app(PermissionRegistrar::class)->getRoleClass()::query()->where('name', $value)->exists()) {
                    $fail('The role does not exist.');
                }
            }],
        ];
    }
}
