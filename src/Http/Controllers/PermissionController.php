<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Http\Controllers;

use Hypervel\Http\Request;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Routing\Controller;
use Ipsocode\Acl\Http\Resources\PermissionResource;
use Symfony\Component\HttpFoundation\Response;

class PermissionController extends Controller
{
    public function index(): Response
    {
        return PermissionResource::collection(app(PermissionRegistrar::class)->getPermissionClass()::query()->orderBy('name')->get())->response();
    }

    public function store(Request $request): Response
    {
        $data = $request->validate(['name' => ['required', 'string'], 'guard_name' => ['sometimes', 'string']]);

        return (new PermissionResource(app(PermissionRegistrar::class)->getPermissionClass()::findOrCreate($data['name'], $data['guard_name'] ?? null)))->response()->setStatusCode(201);
    }

    public function show(int|string $permission): Response
    {
        return (new PermissionResource(app(PermissionRegistrar::class)->getPermissionClass()::query()->findOrFail($permission)))->response();
    }

    public function update(Request $request, int|string $permission): Response
    {
        $permission = app(PermissionRegistrar::class)->getPermissionClass()::query()->findOrFail($permission);
        $permission->update($request->validate(['name' => ['required', 'string']]));

        return (new PermissionResource($permission))->response();
    }

    public function destroy(int|string $permission): Response
    {
        app(PermissionRegistrar::class)->getPermissionClass()::query()->findOrFail($permission)->delete();

        return response()->noContent();
    }
}
