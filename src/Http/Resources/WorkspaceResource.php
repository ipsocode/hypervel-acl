<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Http\Resources;

use Hypervel\Http\Resources\Json\JsonResource;
use Ipsocode\Acl\Models\AclWorkspace;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Support\Workspaces;

/**
 * @mixin AclWorkspace
 */
class WorkspaceResource extends JsonResource
{
    /**
     * @param mixed $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $tenant = Teams::tenantColumn();

        return [
            'id' => $this->getKey(),
            'name' => $this->getAttribute('name'),
            'kind' => $this->getAttribute('kind'),
            'tenant' => $tenant === null ? null : $this->getAttribute($tenant),
            'parent_id' => $this->getAttribute(Workspaces::parentColumn()),
            'meta' => $this->getAttribute('meta'),
        ];
    }
}
