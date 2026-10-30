<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Feature;

use Hypervel\Http\Request;
use Hypervel\Permission\Models\Permission;
use Hypervel\Permission\Models\Role;
use Ipsocode\Acl\Http\Middleware\ResolveAcl;
use Ipsocode\Acl\Models\AclTeam;
use Ipsocode\Acl\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\AclSeeder;

class HttpTest extends TestCase
{
    protected User $admin;

    protected AclTeam $admins;

    protected function setUpInCoroutine(): void
    {
        (new AclSeeder)->run();

        $this->admins = AclTeam::create(['name' => 'Admins']);
        $this->admin = User::factory()->create();

        $this->admins->syncTeamRoles(['manager']);
        $this->admins->addUser($this->admin);

        $this->actingAs($this->admin);
    }

    public function testRoutesRequireTheirPermission(): void
    {
        $viewer = AclTeam::create(['name' => 'Viewers']);
        $nobody = User::factory()->create();
        $viewer->syncTeamRoles(['viewer']);
        $viewer->addUser($nobody);

        $this->actingAs($nobody)->getJson('acl/roles')->assertForbidden();
        $this->actingAs($this->admin)->getJson('acl/roles')->assertOk();
    }

    public function testRoleCrudAndPermissionSync(): void
    {
        $this->postJson('acl/roles', ['name' => 'auditor'])->assertCreated()->assertJsonPath('data.name', 'auditor');
        $role = Role::query()->where('name', 'auditor')->first();
        $this->assertNull($role->team_id);
        $id = $role->id;

        $this->putJson("acl/roles/{$id}", ['name' => 'auditor2'])->assertOk();
        $this->putJson("acl/roles/{$id}/permissions", ['allowed' => ['articles.view'], 'denied' => ['articles.edit']])->assertOk();
        $this->getJson("acl/roles/{$id}")->assertOk()->assertJsonPath('data.permissions.0', 'articles.view');
        $this->getJson('acl/roles')->assertOk();
        $this->deleteJson("acl/roles/{$id}")->assertNoContent();
        $this->getJson("acl/roles/{$id}")->assertNotFound();
    }

    public function testPermissionCrud(): void
    {
        $this->postJson('acl/permissions', ['name' => 'reports.view'])->assertCreated();
        $id = Permission::query()->where('name', 'reports.view')->value('id');

        $this->getJson('acl/permissions')->assertOk();
        $this->getJson("acl/permissions/{$id}")->assertOk();
        $this->putJson("acl/permissions/{$id}", ['name' => 'reports.read'])->assertOk();
        $this->deleteJson("acl/permissions/{$id}")->assertNoContent();
    }

    public function testTeamCrudAndRoles(): void
    {
        $id = $this->postJson('acl/teams', ['name' => 'Ops', 'meta' => ['a' => 1]])->assertCreated()->assertJsonPath('data.name', 'Ops')->json('data.id');

        $this->getJson('acl/teams')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("acl/teams/{$id}")->assertOk();
        $this->putJson("acl/teams/{$id}", ['name' => 'Operations'])->assertOk()->assertJsonPath('data.name', 'Operations');

        $this->putJson("acl/teams/{$id}/roles", ['roles' => ['editor']])->assertOk()->assertJsonPath('data.roles', ['editor']);
        $this->putJson("acl/teams/{$id}/roles", ['roles' => ['ghost']])->assertUnprocessable();

        $this->deleteJson("acl/teams/{$id}")->assertNoContent();
        $this->getJson("acl/teams/{$id}")->assertNotFound();
    }

    public function testTeamUsers(): void
    {
        $ops = AclTeam::create(['name' => 'Ops']);
        $ops->syncTeamRoles(['viewer']);
        $user = User::factory()->create();

        $this->postJson("acl/teams/{$ops->id}/users", ['user_id' => $user->id])->assertCreated();
        $this->getJson("acl/teams/{$ops->id}/users")->assertOk()->assertJsonPath('data', [$user->id]);

        // The user is in Ops already: a second team of the tenant is refused.
        $this->postJson("acl/teams/{$this->admins->id}/users", ['user_id' => $user->id])->assertUnprocessable();
        $this->postJson("acl/teams/{$ops->id}/users", [])->assertUnprocessable();

        $this->deleteJson("acl/teams/{$ops->id}/users/{$user->id}")->assertNoContent();
        $this->getJson("acl/teams/{$ops->id}/users")->assertOk()->assertJsonPath('data', []);
    }

    public function testAUserOutsideEveryTeamIsRefused(): void
    {
        $this->actingAs(User::factory()->create())->getJson('acl/roles')->assertForbidden();
    }

    public function testTheNextRequestUsesTheTeamsChangedRoles(): void
    {
        $this->getJson('acl/roles')->assertOk();

        // The admins lose the manager role: the very next request is refused.
        $this->admins->syncTeamRoles(['viewer']);

        $this->getJson('acl/roles')->assertForbidden();
    }

    public function testAGuestPassesWithoutATeam(): void
    {
        $request = Request::create('/x');
        $request->setUserResolver(fn () => null);

        $response = $this->app->make(ResolveAcl::class)->handle($request, fn (): Response => new Response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull(getPermissionsTeamId());
    }
}
