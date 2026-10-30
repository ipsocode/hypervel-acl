<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Http\Controllers;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\Request;
use Hypervel\Routing\Controller;
use Ipsocode\Acl\Http\Requests\WorkspaceRequest;
use Ipsocode\Acl\Http\Resources\WorkspaceResource;
use Ipsocode\Acl\Models\AclWorkspace;
use Ipsocode\Acl\Support\Workspaces;
use Ipsocode\Acl\Workspace;
use Ipsocode\Acl\Workspaces\Reach;
use Symfony\Component\HttpFoundation\Response;

class WorkspaceController extends Controller
{
    public function __construct(protected Reach $reach)
    {
    }

    public function index(Request $request): Response
    {
        $query = $this->workspaces();

        if ($request->string('under')->isNotEmpty()) {
            $under = $request->string('under')->toString();

            $query->whereKey([$under, ...Workspaces::descendantKeys([$under])]);
        }

        if ($request->query('kind') !== null) {
            $query->where('kind', $request->query('kind'));
        }

        return WorkspaceResource::collection($query->orderBy('name')->get())->response();
    }

    public function store(WorkspaceRequest $request): Response
    {
        $workspace = Workspaces::query()->create($request->validated());

        return (new WorkspaceResource($workspace))->response()->setStatusCode(201);
    }

    public function show(int|string $aclWorkspace): Response
    {
        /** @var AclWorkspace $workspace */
        $workspace = $this->workspaces()->findOrFail($aclWorkspace);

        return (new WorkspaceResource($workspace))->additional(['ancestors' => $workspace->ancestorKeys()])->response();
    }

    public function update(WorkspaceRequest $request, int|string $aclWorkspace): Response
    {
        $workspace = $this->workspaces()->findOrFail($aclWorkspace);
        $workspace->update($request->validated());

        return (new WorkspaceResource($workspace))->response();
    }

    public function destroy(int|string $aclWorkspace): Response
    {
        /** @var AclWorkspace $workspace */
        $workspace = $this->workspaces()->findOrFail($aclWorkspace);

        abort_if($workspace->children()->exists(), 409, 'The workspace has workspaces beneath it.');

        $workspace->delete();

        return response()->noContent();
    }

    public function reach(): Response
    {
        return response()->json(['data' => $this->reach->beneath(Workspace::id())]);
    }

    /**
     * Get the workspaces of the tenant beneath the one the request acts in.
     *
     * @return Builder<Model>
     */
    protected function workspaces(): Builder
    {
        return Workspaces::query()->whereKey($this->reach->beneath(Workspace::id()));
    }
}
