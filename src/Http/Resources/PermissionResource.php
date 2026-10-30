<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Http\Resources;

use Hypervel\Http\Resources\Json\JsonResource;

/**
 * @mixin \Hypervel\Permission\Models\Permission
 */
class PermissionResource extends JsonResource
{
    /**
     * @param mixed $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'guard_name' => $this->guard_name];
    }
}
