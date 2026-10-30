<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Testing\Factories;

use Closure;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Support\Collection;
use Ipsocode\Acl\Contracts\Team;
use LogicException;

/**
 * States for a user factory that assign roles and teams.
 */
trait AssignsAcl
{
    /**
     * @var null|(Model&Team)
     */
    protected ?Model $aclTeam = null;

    /**
     * @var list<Closure(Model, Model&Team): void>
     */
    protected array $aclSteps = [];

    /**
     * Assign roles directly, under the team set by inTeam(), after the model is created.
     */
    public function role(string ...$roles): static
    {
        return $this->withAclStep(function (Model $model, Model $team) use ($roles): void {
            app(PermissionRegistrar::class)->setPermissionsTeamId($team);

            $model->assignRole(...$roles); // @phpstan-ignore method.notFound (the model uses HasRoles)
        });
    }

    /**
     * Add the model to the team set by inTeam(), giving it the team's roles, after it is created.
     */
    public function member(): static
    {
        return $this->withAclStep(fn (Model $model, Model $team) => $team->addUser($model)); // @phpstan-ignore method.notFound (the team is an AclTeam)
    }

    /**
     * Assign in the given team.
     *
     * @param Model&Team $team
     */
    public function inTeam(Model $team): static
    {
        $instance = $this->newInstance();
        $instance->aclTeam = $team;

        return $instance;
    }

    /**
     * Run a step on each created model, in the team set by whichever inTeam() call comes last.
     *
     * @param Closure(Model, Model&Team): void $step
     */
    protected function withAclStep(Closure $step): static
    {
        $instance = $this->newInstance();
        $instance->aclSteps[] = $step;

        return $instance;
    }

    /**
     * Run the role and member steps once the models exist.
     */
    protected function callAfterCreating(Collection $instances, ?Model $parent = null): void
    {
        parent::callAfterCreating($instances, $parent);

        if ($this->aclSteps === []) {
            return;
        }

        $team = $this->aclTeam ?? throw new LogicException('Assigning roles needs a team: use inTeam().');

        foreach ($instances as $model) {
            foreach ($this->aclSteps as $step) {
                $step($model, $team);
            }
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function newInstance(array $arguments = []): static
    {
        $instance = parent::newInstance($arguments);
        $instance->aclTeam = $this->aclTeam;
        $instance->aclSteps = $this->aclSteps;

        return $instance;
    }
}
