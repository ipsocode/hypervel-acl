<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Http\Controllers;

use Hypervel\Http\Request;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Routing\Controller;
use Ipsocode\Acl\Http\Resources\RoleResource;
use Symfony\Component\HttpFoundation\Response;

class RoleController extends Controller
{
    public function index(): Response
    {
        return RoleResource::collection(app(PermissionRegistrar::class)->getRoleClass()::query()->orderBy('name')->get())->response();
    }

    public function store(Request $request): Response
    {
        $data = $request->validate(['name' => ['required', 'string'], 'guard_name' => ['sometimes', 'string']]);

        // A role made through the API is a system role: global, so every team can hold it.
        $data[app(PermissionRegistrar::class)->teamsKey] = null;

        return (new RoleResource(app(PermissionRegistrar::class)->getRoleClass()::create($data)))->response()->setStatusCode(201);
    }

    public function show(int|string $role): Response
    {
        return (new RoleResource(app(PermissionRegistrar::class)->getRoleClass()::query()->with('permissions')->findOrFail($role)))->response();
    }

    public function update(Request $request, int|string $role): Response
    {
        $role = app(PermissionRegistrar::class)->getRoleClass()::query()->findOrFail($role);
        $role->update($request->validate(['name' => ['required', 'string']]));

        return (new RoleResource($role))->response();
    }

    public function destroy(int|string $role): Response
    {
        app(PermissionRegistrar::class)->getRoleClass()::query()->findOrFail($role)->delete();

        return response()->noContent();
    }

    public function permissions(Request $request, int|string $role): Response
    {
        $data = $request->validate([
            'allowed' => ['present', 'array'],
            'allowed.*' => ['string'],
            'denied' => ['sometimes', 'array'],
            'denied.*' => ['string'],
        ]);

        $role = app(PermissionRegistrar::class)->getRoleClass()::query()->findOrFail($role);
        $role->syncPermissionEffects(allowed: $data['allowed'], denied: $data['denied'] ?? []);

        return (new RoleResource($role->load('permissions')))->response();
    }
}
