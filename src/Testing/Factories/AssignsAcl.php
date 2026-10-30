<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Testing\Factories;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Permission\PermissionRegistrar;
use Ipsocode\Acl\Contracts\Team;
use LogicException;

/**
 * States for a user factory that assign roles and teams.
 */
trait AssignsAcl
{
    protected ?Model $aclTeam = null;

    /**
     * Assign roles directly, under the team set by inTeam(), after the model is created.
     */
    public function role(string ...$roles): static
    {
        $team = $this->aclTeam;

        return $this->afterCreating(function ($model) use ($team, $roles): void {
            app(PermissionRegistrar::class)->setPermissionsTeamId($this->aclTeamFor($team));

            $model->assignRole(...$roles); // @phpstan-ignore method.notFound (the model uses HasRoles)
        });
    }

    /**
     * Add the model to the team set by inTeam(), giving it the team's roles, after it is created.
     */
    public function member(): static
    {
        $team = $this->aclTeam;

        return $this->afterCreating(function ($model) use ($team): void {
            $this->aclTeamFor($team)->addUser($model);
        });
    }

    /**
     * Assign in the given team.
     */
    public function inTeam(Model $team): static
    {
        $instance = $this->newInstance();
        $instance->aclTeam = $team;

        return $instance;
    }

    /**
     * @return Model&Team
     */
    protected function aclTeamFor(?Model $team): Team
    {
        return $team ?? throw new LogicException('Assigning roles needs a team: use inTeam().');
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function newInstance(array $arguments = []): static
    {
        $instance = parent::newInstance($arguments);
        $instance->aclTeam = $this->aclTeam;

        return $instance;
    }
}
