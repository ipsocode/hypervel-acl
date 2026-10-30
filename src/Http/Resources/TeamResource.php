<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Http\Resources;

use Hypervel\Http\Resources\Json\JsonResource;
use Ipsocode\Acl\Contracts\Team;

/**
 * @mixin Team
 */
class TeamResource extends JsonResource
{
    /**
     * @param mixed $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->getKey(), // @phpstan-ignore method.notFound (the team is a model)
            'name' => $this->name, // @phpstan-ignore property.notFound (the team is a model)
            'tenant' => $this->tenant(),
            'inherit_id' => $this->inheritedTeam()?->getKey(),
            'roles' => $this->ownRoleNames(),
        ];
    }
}
