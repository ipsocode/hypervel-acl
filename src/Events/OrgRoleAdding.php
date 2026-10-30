<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Events;

use Hypervel\Database\Eloquent\Model;
use Ipsocode\Acl\Contracts\Team;

/**
 * Dispatched before a user is given a team in a workspace, with workspaces on. A listener
 * throws to refuse it.
 */
class OrgRoleAdding
{
    /**
     * @param Model&Team $team
     */
    public function __construct(public readonly Team $team, public readonly Model $user, public readonly int|string $workspace)
    {
    }
}
