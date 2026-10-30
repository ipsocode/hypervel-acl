<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Http\Controllers;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\Request;
use Hypervel\Permission\Guard;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Routing\Controller;
use Hypervel\Validation\ValidationException;
use Ipsocode\Acl\Contracts\Team;
use Ipsocode\Acl\Exceptions\UserAlreadyInTeam;
use Ipsocode\Acl\Http\Requests\SyncRolesRequest;
use Ipsocode\Acl\Http\Requests\TeamRequest;
use Ipsocode\Acl\Http\Resources\TeamResource;
use Ipsocode\Acl\Support\Teams;
use Symfony\Component\HttpFoundation\Response;

class TeamController extends Controller
{
    public function __construct(protected PermissionRegistrar $registrar)
    {
    }

    public function index(): Response
    {
        return TeamResource::collection($this->teams()->orderBy('name')->get())->response();
    }

    public function store(TeamRequest $request): Response
    {
        $team = Teams::query()->create($this->attributes($request));

        return (new TeamResource($team))->response()->setStatusCode(201);
    }

    public function show(int|string $team): Response
    {
        return (new TeamResource($this->teams()->findOrFail($team)))->response();
    }

    public function update(TeamRequest $request, int|string $team): Response
    {
        $team = $this->teams()->findOrFail($team);
        $team->update($this->attributes($request));

        return (new TeamResource($team))->response();
    }

    public function destroy(int|string $team): Response
    {
        $this->teams()->findOrFail($team)->delete();

        return response()->noContent();
    }

    public function roles(SyncRolesRequest $request, int|string $team): Response
    {
        $team = $this->teams()->findOrFail($team);
        $team->syncTeamRoles($request->validated('roles'));

        return (new TeamResource($team))->response();
    }

    public function users(int|string $team): Response
    {
        return response()->json(['data' => $this->teams()->findOrFail($team)->memberKeys()]);
    }

    public function addUser(Request $request, int|string $team): Response
    {
        $team = $this->teams()->findOrFail($team);
        $user = $this->userModel()::query()->whereKey($request->validate(['user_id' => ['required']])['user_id'])->firstOrFail();

        try {
            $team->addUser($user);
        } catch (UserAlreadyInTeam $exception) {
            throw ValidationException::withMessages(['user_id' => $exception->getMessage()]);
        }

        return response()->noContent(201);
    }

    public function removeUser(int|string $team, int|string $user): Response
    {
        $this->teams()->findOrFail($team)->removeUser($this->userModel()::query()->whereKey($user)->firstOrFail());

        return response()->noContent();
    }

    /**
     * Get the teams of the current team's tenant.
     *
     * @return Builder<Model&Team>
     */
    protected function teams(): Builder
    {
        $query = Teams::query();
        $column = Teams::tenantColumn();

        return $column === null ? $query : $query->where($column, Teams::query()->find($this->registrar->getPermissionsTeamId())?->tenant());
    }

    /**
     * Get the attributes to save, with the tenant forced to the current team's.
     *
     * @return array<string, mixed>
     */
    protected function attributes(TeamRequest $request): array
    {
        $attributes = $request->validated();
        $column = Teams::tenantColumn();

        if ($column !== null) {
            $attributes[$column] = Teams::query()->find($this->registrar->getPermissionsTeamId())?->tenant();
        }

        return $attributes;
    }

    /**
     * @return class-string<Model>
     */
    protected function userModel(): string
    {
        return Guard::getModelForGuard(config('acl.guards')[0]);
    }
}
