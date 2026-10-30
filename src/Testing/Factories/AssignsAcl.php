<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Testing\Factories;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Permission\PermissionRegistrar;
use Ipsocode\Acl\Contracts\Team;
use LogicException;
use stdClass;

/**
 * States for a user factory that assign roles and teams.
 */
trait AssignsAcl
{
    protected ?stdClass $aclScope = null;

    /**
     * Assign roles directly, under the team set by inTeam(), after the model is created.
     */
    public function role(string ...$roles): static
    {
        $scope = $this->aclScope();

        return $this->afterCreating(function ($model) use ($scope, $roles): void {
            app(PermissionRegistrar::class)->setPermissionsTeamId($this->aclTeamFor($scope));

            $model->assignRole(...$roles); // @phpstan-ignore method.notFound (the model uses HasRoles)
        });
    }

    /**
     * Add the model to the team set by inTeam(), giving it the team's roles, after it is created.
     */
    public function member(): static
    {
        $scope = $this->aclScope();

        return $this->afterCreating(function ($model) use ($scope): void {
            $this->aclTeamFor($scope)->addUser($model);
        });
    }

    /**
     * Assign in the given team.
     */
    public function inTeam(Model $team): static
    {
        $this->aclScope()->team = $team;

        return $this->newInstance();
    }

    protected function aclScope(): stdClass
    {
        return $this->aclScope ??= new stdClass;
    }

    /**
     * @return Model&Team
     */
    protected function aclTeamFor(stdClass $scope): Team
    {
        return $scope->team ?? throw new LogicException('Assigning roles needs a team: use inTeam().');
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function newInstance(array $arguments = []): static
    {
        $instance = parent::newInstance($arguments);
        $instance->aclScope = $this->aclScope;

        return $instance;
    }
}
