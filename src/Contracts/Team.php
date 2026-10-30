<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Contracts;

use Hypervel\Database\Eloquent\Model;

/**
 * The team model named in acl.models.team. A team is an organizational role: one set of
 * roles, held by every member under the team's id. Concerns\IsTeam supplies all of it.
 */
interface Team
{
    /**
     * Get the team whose roles this team also holds.
     *
     * @return null|(Model&Team)
     */
    public function inheritedTeam(): ?self;

    /**
     * Get the tenant the team belongs to, or null when acl.tenant is off.
     */
    public function tenant(): int|string|null;

    /**
     * Get the names of the roles assigned to the team itself.
     *
     * @return list<string>
     */
    public function ownRoleNames(): array;

    /**
     * Get the keys of the team's members.
     *
     * @return list<int|string>
     */
    public function memberKeys(): array;

    /**
     * Replace the team's own roles, stored under the team's own id.
     *
     * @param list<string> $names
     */
    public function syncTeamRoles(array $names): void;

    /**
     * Add a user to the team, giving the user the team's roles.
     */
    public function addUser(Model $user): void;

    /**
     * Remove a user from the team, taking the team's roles away.
     */
    public function removeUser(Model $user): void;
}
