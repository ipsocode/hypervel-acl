<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Http\Resources;

use Hypervel\Http\Resources\Json\JsonResource;

/**
 * @mixin \Hypervel\Permission\Models\Role
 */
class RoleResource extends JsonResource
{
    /**
     * @param mixed $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'guard_name' => $this->guard_name,
            'permissions' => $this->whenLoaded('permissions', fn () => $this->getAllPermissions()->pluck('name')->values()),
            'denied' => $this->whenLoaded('permissions', fn () => $this->getDeniedPermissions()->pluck('name')->values()),
        ];
    }
}
