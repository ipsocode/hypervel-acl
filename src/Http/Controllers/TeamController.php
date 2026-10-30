<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Http\Controllers;

use Closure;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Http\Request;
use Hypervel\Permission\Guard;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Routing\Controller;
use Hypervel\Support\Facades\DB;
use Hypervel\Validation\ValidationException;
use Ipsocode\Acl\Contracts\Team;
use Ipsocode\Acl\Exceptions\UserAlreadyInTeam;
use Ipsocode\Acl\Http\Requests\SyncRolesRequest;
use Ipsocode\Acl\Http\Requests\TeamRequest;
use Ipsocode\Acl\Http\Resources\TeamResource;
use Ipsocode\Acl\Support\Config;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Support\Workspaces;
use Ipsocode\Acl\Workspace;
use Ipsocode\Acl\Workspaces\Reach;
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
        $team = $this->teams()->findOrFail($team);

        abort_if(Teams::query()->withoutGlobalScope('acl_tenant')->where(Config::teamsInheritKey(), $team->getKey())->exists(), 409, 'The team is inherited by other teams.');

        $team->delete();

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
        $team = $this->teams()->findOrFail($team);

        return response()->json(['data' => Workspaces::enabled() && (string) Workspace::id() !== (string) Workspace::HOME
            ? $this->memberKeysWithin($team)
            : $team->memberKeys()]);
    }

    public function addUser(Request $request, int|string $team): Response
    {
        $team = $this->teams()->findOrFail($team);
        $input = $request->validate(['user_id' => ['required'], 'workspace' => ['sometimes', 'nullable', $this->workspaceKey(...)]]);
        $user = $this->userModel()::query()->whereKey($input['user_id'])->firstOrFail();
        $workspace = $input['workspace'] ?? Workspace::id();

        if (Workspaces::enabled() && ! app(Reach::class)->reaches($workspace)) {
            throw ValidationException::withMessages(['workspace' => 'The workspace is not one the org role covers.']);
        }

        try {
            $team->addUser($user, $workspace);
        } catch (UserAlreadyInTeam $exception) {
            throw ValidationException::withMessages(['user_id' => $exception->getMessage()]);
        }

        return response()->noContent(201);
    }

    public function removeUser(Request $request, int|string $team, int|string $user): Response
    {
        $team = $this->teams()->findOrFail($team);
        $user = $this->userModel()::query()->whereKey($user)->firstOrFail();

        if (! Workspaces::enabled()) {
            $team->removeUser($user);
        } elseif ($request->filled('workspace')) {
            $workspace = $request->string('workspace')->toString();

            if (! app(Reach::class)->covers(Workspace::id(), $workspace)) {
                throw ValidationException::withMessages(['workspace' => 'The workspace is not one the org role covers.']);
            }

            $team->removeUser($user, $workspace);
        } elseif ((string) Workspace::id() === (string) Workspace::HOME) {
            $team->removeUser($user);
        } else {
            // Without a workspace, the org roles within the reach of the request's own.
            $team->removeUser($user, $this->memberWorkspaces($team, $user->getKey()));
        }

        return response()->noContent();
    }

    /**
     * Validate that a workspace is one key, not a list or an object.
     */
    protected function workspaceKey(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_int($value) && ! is_string($value)) {
            $fail('The workspace must be a workspace key.');
        }
    }

    /**
     * Get the keys of the team's members holding an org role in the workspace the request acts in or beneath it.
     *
     * @param Model&Team $team
     * @return list<int|string>
     */
    protected function memberKeysWithin(Team $team): array
    {
        /** @var list<int|string> $keys */
        $keys = $this->membersWithin($team)->distinct()->pluck(Teams::userPivotKey())->all();

        return $keys;
    }

    /**
     * Get the workspaces within the reach of the request in which the user holds an org role of the team.
     *
     * @param Model&Team $team
     * @return list<int|string>
     */
    protected function memberWorkspaces(Team $team, int|string $user): array
    {
        /** @var list<int|string> $workspaces */
        $workspaces = $this->membersWithin($team)->where(Teams::userPivotKey(), $user)->distinct()->pluck(Workspaces::column())->all();

        return $workspaces;
    }

    /**
     * Start a query on the team's org roles within the reach of the request.
     *
     * @param Model&Team $team
     */
    protected function membersWithin(Team $team): QueryBuilder
    {
        return DB::table(Teams::ORGANIZATIONS_TABLE)
            ->where(Teams::teamPivotKey(), $team->getKey())
            ->whereIn(Workspaces::column(), app(Reach::class)->beneath(Workspace::id()));
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
        return Guard::getModelForGuard(Config::guards()[0]);
    }
}
