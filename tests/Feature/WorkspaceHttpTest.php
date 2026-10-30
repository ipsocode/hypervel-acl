<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Feature;

use Hypervel\Database\QueryException;
use Hypervel\Http\Request;
use Hypervel\Permission\Support\Config;
use Hypervel\Support\Facades\DB;
use Ipsocode\Acl\Models\AclTeam;
use Ipsocode\Acl\Models\AclWorkspace;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Tests\WorkspaceTestCase;
use Ipsocode\Acl\Workspaces\Reach;
use Ipsocode\Acl\Workspaces\RequestWorkspaceResolver;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\AclSeeder;

class WorkspaceHttpTest extends WorkspaceTestCase
{
    protected AclWorkspace $corporate;

    protected AclWorkspace $north;

    protected AclWorkspace $branch;

    protected AclWorkspace $elsewhere;

    protected AclTeam $admins;

    protected function setUpInCoroutine(): void
    {
        $this->corporate = AclWorkspace::create(['name' => 'Corporate', 'kind' => 'corporate']);
        $this->north = AclWorkspace::create(['name' => 'North', 'kind' => 'region', 'parent_id' => $this->corporate->id]);
        $this->branch = AclWorkspace::create(['name' => 'Branch', 'kind' => 'branch', 'parent_id' => $this->north->id]);
        $this->elsewhere = AclWorkspace::create(['name' => 'Elsewhere']);

        (new AclSeeder)->run();

        $this->admins = AclTeam::create(['name' => 'Admins']);
        $this->admins->syncTeamRoles(['manager']);

        // An org role in the home workspace covers the whole tree.
        $admin = User::factory()->create();
        $this->admins->addUser($admin);

        $this->actingAs($admin);
    }

    public function testWorkspacesAreListedBeneathTheOneTheRequestActsIn(): void
    {
        $this->getJson('acl/workspaces')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('acl/workspaces?under=' . $this->north->id)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('acl/workspaces?kind=branch')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Branch');

        $this->withHeader('X-Workspace', (string) $this->north->id)->getJson('acl/workspaces')->assertOk()->assertJsonCount(2, 'data');
    }

    public function testAWorkspaceNameIsUniqueBeneathItsParent(): void
    {
        $sibling = AclWorkspace::create(['name' => 'Sibling', 'parent_id' => $this->corporate->id]);

        $this->postJson('acl/workspaces', ['name' => 'North', 'parent_id' => $this->corporate->id])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('acl/workspaces', ['name' => 'North', 'parent_id' => $this->north->id])->assertCreated();
        $this->putJson("acl/workspaces/{$sibling->id}", ['name' => 'North'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->putJson("acl/workspaces/{$sibling->id}", ['name' => 'Sibling', 'parent_id' => $this->corporate->id])->assertOk();
        $this->postJson('acl/workspaces', ['name' => 'Elsewhere', 'parent_id' => null])->assertCreated();
    }

    public function testAWorkspaceIsShownWithItsAncestors(): void
    {
        $this->getJson("acl/workspaces/{$this->branch->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Branch')
            ->assertJsonPath('data.parent_id', $this->north->id)
            ->assertJsonPath('ancestors', [$this->branch->id, $this->north->id, $this->corporate->id]);

        $this->withHeader('X-Workspace', (string) $this->north->id)->getJson("acl/workspaces/{$this->elsewhere->id}")->assertNotFound();
    }

    public function testAWorkspaceIsCreatedBeneathOneTheTeamReaches(): void
    {
        $id = $this->postJson('acl/workspaces', ['name' => 'South', 'parent_id' => $this->corporate->id])->assertCreated()->json('data.id');

        $this->getJson("acl/workspaces/{$id}")->assertOk();
        $this->withHeader('X-Workspace', (string) $this->north->id)->postJson('acl/workspaces', ['name' => 'Nowhere', 'parent_id' => $this->elsewhere->id])->assertUnprocessable();
        $this->postJson('acl/workspaces', ['name' => 'Missing', 'parent_id' => 9999])->assertUnprocessable();
        $this->postJson('acl/workspaces', [])->assertUnprocessable();
    }

    public function testAWorkspaceIsMovedButNeverBeneathItself(): void
    {
        $this->putJson("acl/workspaces/{$this->branch->id}", ['name' => 'Branch', 'parent_id' => $this->corporate->id])->assertOk();
        $this->assertSame($this->corporate->id, $this->branch->refresh()->parent_id);

        $this->putJson("acl/workspaces/{$this->corporate->id}", ['name' => 'Corporate', 'parent_id' => $this->north->id])->assertUnprocessable();
        $this->putJson("acl/workspaces/{$this->north->id}", ['name' => 'North', 'parent_id' => $this->north->id])->assertUnprocessable();
        $this->putJson("acl/workspaces/{$this->north->id}", ['name' => 'North', 'parent_id' => null])->assertOk();
    }

    public function testAWorkspaceWithChildrenCannotBeDeleted(): void
    {
        $this->deleteJson("acl/workspaces/{$this->north->id}")->assertStatus(409);
        $this->deleteJson("acl/workspaces/{$this->branch->id}")->assertNoContent();
        $this->deleteJson("acl/workspaces/{$this->north->id}")->assertNoContent();
    }

    public function testAUserIsGivenAndLosesAnOrgRoleInAWorkspace(): void
    {
        $ops = AclTeam::create(['name' => 'Ops']);
        $user = User::factory()->create();

        $this->postJson("acl/teams/{$ops->id}/users", ['user_id' => $user->id, 'workspace' => $this->north->id])->assertCreated();
        $this->assertSame(1, DB::table(Teams::ORGANIZATIONS_TABLE)->where('user_id', $user->id)->count());

        // The workspace chain rule is a validation error.
        $this->postJson("acl/teams/{$ops->id}/users", ['user_id' => $user->id, 'workspace' => $this->branch->id])->assertUnprocessable();

        $this->deleteJson("acl/teams/{$ops->id}/users/{$user->id}?workspace={$this->north->id}")->assertNoContent();
        $this->assertSame(0, DB::table(Teams::ORGANIZATIONS_TABLE)->where('user_id', $user->id)->count());
    }

    public function testTheReachIsTheWorkspaceTheRequestActsInAndBeneath(): void
    {
        $this->getJson('acl/reach')->assertOk()->assertJsonCount(5, 'data');
        $this->withHeader('X-Workspace', (string) $this->north->id)->getJson('acl/reach')->assertOk()->assertJsonCount(2, 'data');
    }

    public function testAnOrgRoleBelowHomeActsInItsWorkspace(): void
    {
        $ops = AclTeam::create(['name' => 'Ops']);
        $ops->syncTeamRoles(['manager']);
        $user = User::factory()->create();
        $ops->addUser($user, $this->north->id);

        $this->actingAs($user)->getJson('acl/workspaces')->assertOk()->assertJsonCount(2, 'data');
    }

    public function testAUserIsAddedInTheWorkspaceTheRequestActsInUnlessTheCallerCannotReachIt(): void
    {
        $ops = AclTeam::create(['name' => 'Ops']);
        $user = User::factory()->create();

        $this->withHeader('X-Workspace', (string) $this->north->id)->postJson("acl/teams/{$ops->id}/users", ['user_id' => $user->id])->assertCreated();
        $this->assertSame($this->north->id, DB::table(Teams::ORGANIZATIONS_TABLE)->where('user_id', $user->id)->value('workspace_id'));

        $other = User::factory()->create();
        $this->withHeader('X-Workspace', (string) $this->north->id)->postJson("acl/teams/{$ops->id}/users", ['user_id' => $other->id, 'workspace' => $this->elsewhere->id])->assertUnprocessable();
        $this->withHeader('X-Workspace', (string) $this->north->id)->postJson("acl/teams/{$ops->id}/users", ['user_id' => $other->id, 'workspace' => 0])->assertUnprocessable();
        $this->postJson("acl/teams/{$ops->id}/users", ['user_id' => $other->id, 'workspace' => 9999])->assertUnprocessable();
        $this->assertSame(0, DB::table(Teams::ORGANIZATIONS_TABLE)->where('user_id', $other->id)->count());
    }

    public function testOnlyTheHomeWorkspaceCreatesOrMovesAWorkspaceToTheTop(): void
    {
        $this->postJson('acl/workspaces', ['name' => 'Root'])->assertCreated();

        $this->withHeader('X-Workspace', (string) $this->north->id)->postJson('acl/workspaces', ['name' => 'Root'])->assertUnprocessable();
        $this->withHeader('X-Workspace', (string) $this->north->id)->postJson('acl/workspaces', ['name' => 'Root', 'parent_id' => null])->assertUnprocessable();
        $this->withHeader('X-Workspace', (string) $this->north->id)->putJson("acl/workspaces/{$this->branch->id}", ['name' => 'Branch', 'parent_id' => null])->assertUnprocessable();
        $this->withHeader('X-Workspace', (string) $this->north->id)->putJson("acl/workspaces/{$this->branch->id}", ['name' => 'Renamed'])->assertOk();
        $this->assertSame($this->north->id, $this->branch->refresh()->parent_id);
    }

    public function testAWorkspaceThatDoesNotExistIsRefusedAndNotRemembered(): void
    {
        $this->withHeader('X-Workspace', '987654')->getJson('acl/teams')->assertForbidden();
        $this->assertNull(cache()->get(Reach::CACHE_KEY_PREFIX . '987654'));

        $reach = $this->app->make(Reach::class);

        $this->assertSame([987654], $reach->ancestorsOf(987654));
        $this->assertNull(cache()->get(Reach::CACHE_KEY_PREFIX . '987654'));
        $this->assertSame([$this->north->id, $this->corporate->id], $reach->ancestorsOf($this->north->id));
        $this->assertSame([$this->north->id, $this->corporate->id], cache()->get(Reach::CACHE_KEY_PREFIX . $this->north->id)['ancestors']);
    }

    public function testADeleteThatFailsKeepsTheWorkspacesRoles(): void
    {
        $roles = DB::table(Config::rolesTable())->where('workspace_id', $this->north->id)->count();
        $this->assertGreaterThan(0, $roles);

        try {
            $this->north->delete();
            $this->fail('The workspace has others beneath it.');
        } catch (QueryException) {
            $this->assertSame($roles, DB::table(Config::rolesTable())->where('workspace_id', $this->north->id)->count());
        }
    }

    public function testAnOrgRoleBelowHomeRemovesAndListsOnlyWithinItsReach(): void
    {
        $ops = AclTeam::create(['name' => 'Ops']);
        $south = AclWorkspace::create(['name' => 'South', 'parent_id' => $this->corporate->id]);
        $inNorth = User::factory()->create();
        $inSouth = User::factory()->create();
        $ops->addUser($inNorth, $this->branch->id);
        $ops->addUser($inSouth, $south->id);

        $this->admins->addUser($user = User::factory()->create(), $this->north->id);
        $this->admins->syncTeamRoles(['manager']);

        $this->getJson("acl/teams/{$ops->id}/users")->assertOk()->assertJsonCount(2, 'data');

        // The header stays on the test's requests from here on.
        $north = $this->withHeader('X-Workspace', (string) $this->north->id);

        $north->getJson("acl/teams/{$ops->id}/users")->assertOk()->assertJsonPath('data', [$inNorth->id]);

        $north->deleteJson("acl/teams/{$ops->id}/users/{$inSouth->id}?workspace={$south->id}")->assertUnprocessable();
        $north->deleteJson("acl/teams/{$ops->id}/users/{$inSouth->id}")->assertNoContent();
        $this->assertSame(1, DB::table(Teams::ORGANIZATIONS_TABLE)->where('user_id', $inSouth->id)->count());

        $north->deleteJson("acl/teams/{$ops->id}/users/{$inNorth->id}")->assertNoContent();
        $this->assertSame(0, DB::table(Teams::ORGANIZATIONS_TABLE)->where('user_id', $inNorth->id)->count());
    }

    public function testAWorkspaceKeyMustBeOneKey(): void
    {
        $ops = AclTeam::create(['name' => 'Ops']);
        $user = User::factory()->create();

        $this->postJson("acl/teams/{$ops->id}/users", ['user_id' => $user->id, 'workspace' => [1]])->assertUnprocessable();
        $this->postJson('acl/workspaces', ['name' => 'Odd', 'parent_id' => [$this->north->id]])->assertUnprocessable();
    }

    public function testAMoveThatRelatesTwoOrgRolesIsRefused(): void
    {
        $ops = AclTeam::create(['name' => 'Ops']);
        $sales = AclTeam::create(['name' => 'Sales']);
        $user = User::factory()->create();
        $ops->addUser($user, $this->branch->id);
        $sales->addUser($user, $this->elsewhere->id);

        // Elsewhere would end up above Branch, where the user holds another org role.
        $this->putJson("acl/workspaces/{$this->branch->id}", ['name' => 'Branch', 'parent_id' => $this->elsewhere->id])->assertUnprocessable();
        $this->assertSame($this->north->id, $this->branch->refresh()->parent_id);

        $sales->removeUser($user);
        $this->putJson("acl/workspaces/{$this->branch->id}", ['name' => 'Branch', 'parent_id' => $this->elsewhere->id])->assertOk();
    }

    public function testACachedWorkspaceNeedsNoQuery(): void
    {
        $reach = $this->app->make(Reach::class);
        $reach->exists($this->north->id);

        DB::enableQueryLog();
        $reach->exists($this->north->id);
        $reach->ancestorsOf($this->north->id);

        $this->assertSame([], array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'acl_workspaces')));
    }

    public function testTheCachedTreeIsForgottenAgainWhenTheTransactionCommits(): void
    {
        $reach = $this->app->make(Reach::class);
        $reach->ancestorsOf($this->branch->id);

        DB::transaction(function (): void {
            $this->branch->update(['parent_id' => $this->corporate->id]);

            // A request in the meantime reads the old parent and caches it.
            cache()->forever(Reach::CACHE_KEY_PREFIX . $this->branch->id, ['tenant' => null, 'ancestors' => [$this->branch->id, $this->north->id, $this->corporate->id]]);
        });

        $this->assertSame([$this->branch->id, $this->corporate->id], $reach->ancestorsOf($this->branch->id));
    }

    public function testTheHomeWorkspaceRemovesEveryOrgRoleOfATeamWhenNoneIsNamed(): void
    {
        $ops = AclTeam::create(['name' => 'Ops']);
        $user = User::factory()->create();
        $ops->addUser($user, $this->north->id);

        $this->deleteJson("acl/teams/{$ops->id}/users/{$user->id}")->assertNoContent();
        $this->assertSame(0, DB::table(Teams::ORGANIZATIONS_TABLE)->where('user_id', $user->id)->count());
    }

    public function testAnEmptyWorkspaceHeaderForgetsTheSelection(): void
    {
        $resolver = $this->app->make(RequestWorkspaceResolver::class);
        $session = $this->app->make('session')->driver();

        $request = fn (?string $header): Request => tap(Request::create('/'), function (Request $request) use ($session, $header): void {
            $request->setHypervelSession($session);
            $header === null || $request->headers->set('X-Workspace', $header);
        });

        $this->assertSame('5', $resolver->resolve($request('5')));
        $this->assertSame('5', $resolver->resolve($request(null)));
        $this->assertNull($resolver->resolve($request('')));
        $this->assertNull($resolver->resolve($request(null)));
    }
}
