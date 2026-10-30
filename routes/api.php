<?php

declare(strict_types=1);

use Hypervel\Support\Facades\Route;
use Ipsocode\Acl\Http\Controllers\PermissionController;
use Ipsocode\Acl\Http\Controllers\RoleController;
use Ipsocode\Acl\Http\Controllers\TeamController;
use Ipsocode\Acl\Http\Controllers\WorkspaceController;
use Ipsocode\Acl\Support\Workspaces;

// The management routes, included beneath the public API's group and beneath the UI's internal one.
$config = config('acl.routes');
$gate = static fn (string $name): string => 'permission:' . ($config['permissions'][$name] ?? $config['name'] . $name);

Route::get('roles', [RoleController::class, 'index'])->name('roles.index')->middleware($gate('roles.index'));
Route::post('roles', [RoleController::class, 'store'])->name('roles.store')->middleware($gate('roles.store'));
Route::get('roles/{role}', [RoleController::class, 'show'])->name('roles.show')->middleware($gate('roles.show'));
Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update')->middleware($gate('roles.update'));
Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy')->middleware($gate('roles.destroy'));
Route::put('roles/{role}/permissions', [RoleController::class, 'permissions'])->name('roles.permissions')->middleware($gate('roles.permissions'));

Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index')->middleware($gate('permissions.index'));
Route::post('permissions', [PermissionController::class, 'store'])->name('permissions.store')->middleware($gate('permissions.store'));
Route::get('permissions/{permission}', [PermissionController::class, 'show'])->name('permissions.show')->middleware($gate('permissions.show'));
Route::put('permissions/{permission}', [PermissionController::class, 'update'])->name('permissions.update')->middleware($gate('permissions.update'));
Route::delete('permissions/{permission}', [PermissionController::class, 'destroy'])->name('permissions.destroy')->middleware($gate('permissions.destroy'));

Route::get('teams', [TeamController::class, 'index'])->name('teams.index')->middleware($gate('teams.index'));
Route::post('teams', [TeamController::class, 'store'])->name('teams.store')->middleware($gate('teams.store'));
Route::get('teams/{team}', [TeamController::class, 'show'])->name('teams.show')->middleware($gate('teams.show'));
Route::put('teams/{team}', [TeamController::class, 'update'])->name('teams.update')->middleware($gate('teams.update'));
Route::delete('teams/{team}', [TeamController::class, 'destroy'])->name('teams.destroy')->middleware($gate('teams.destroy'));
Route::put('teams/{team}/roles', [TeamController::class, 'roles'])->name('teams.roles')->middleware($gate('teams.roles'));
Route::get('teams/{team}/users', [TeamController::class, 'users'])->name('teams.users')->middleware($gate('teams.users'));
Route::post('teams/{team}/users', [TeamController::class, 'addUser'])->name('teams.users.add')->middleware($gate('teams.users.add'));
Route::delete('teams/{team}/users/{user}', [TeamController::class, 'removeUser'])->name('teams.users.remove')->middleware($gate('teams.users.remove'));

if (Workspaces::enabled()) {
    Route::get('reach', [WorkspaceController::class, 'reach'])->name('reach')->middleware($gate('reach'));
}

if (Workspaces::enabled() && Workspaces::model() !== null) {
    Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index')->middleware($gate('workspaces.index'));
    Route::post('workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store')->middleware($gate('workspaces.store'));
    Route::get('workspaces/{aclWorkspace}', [WorkspaceController::class, 'show'])->name('workspaces.show')->middleware($gate('workspaces.show'));
    Route::put('workspaces/{aclWorkspace}', [WorkspaceController::class, 'update'])->name('workspaces.update')->middleware($gate('workspaces.update'));
    Route::delete('workspaces/{aclWorkspace}', [WorkspaceController::class, 'destroy'])->name('workspaces.destroy')->middleware($gate('workspaces.destroy'));
}
