<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Feature;

use Hypervel\Http\Request;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Ipsocode\Acl\Exceptions\UserAlreadyInTeam;
use Ipsocode\Acl\Http\Middleware\ResolveAcl;
use Ipsocode\Acl\Models\AclTeam;
use Ipsocode\Acl\Models\AclWorkspace;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Tenant;
use Ipsocode\Acl\Tests\WorkspaceTestCase;
use Ipsocode\Acl\Workspace;
use Ipsocode\Acl\Workspaces\Reach;
use Ipsocode\Acl\Workspaces\RequestWorkspaceResolver;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\AclSeeder;

use function Hypervel\Coroutine\parallel;

class WorkspaceTest extends WorkspaceTestCase
{
    protected AclWorkspace $corporate;

    protected AclWorkspace $north;

    protected AclWorkspace $branchA;

    protected AclWorkspace $branchB;

    protected AclWorkspace $south;

    protected function setUpInCoroutine(): void
    {
        $this->corporate = AclWorkspace::create(['name' => 'Corporate']);
        $this->north = AclWorkspace::create(['name' => 'North', 'parent_id' => $this->corporate->id]);
        $this->branchA = AclWorkspace::create(['name' => 'Branch A', 'parent_id' => $this->north->id]);
        $this->branchB = AclWorkspace::create(['name' => 'Branch B', 'parent_id' => $this->north->id]);
        $this->south = AclWorkspace::create(['name' => 'South', 'parent_id' => $this->corporate->id]);

        (new AclSeeder)->run();
    }

    /**
     * Run a request as the user in a workspace, and return the response status.
     */
    protected function requestIn(User $user, int|string|null $workspace): void
    {
        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);
        $request->setHypervelSession($this->app->make('session')->driver());

        if ($workspace !== null) {
            $request->headers->set('X-Workspace', (string) $workspace);
        }

        $this->app->make(ResolveAcl::class)->handle($request, fn (): Response => new Response);
    }

    /**
     * Count the role rows a user holds in the given workspaces.
     */
    protected function rows(User $user, AclWorkspace|int ...$workspaces): int
    {
        return DB::table('model_has_roles')
            ->where('model_type', $user->getMorphClass())
            ->whereIn('workspace_id', array_map(fn (AclWorkspace|int $workspace) => $workspace instanceof AclWorkspace ? $workspace->id : $workspace, $workspaces))
            ->count();
    }

    /**
     * Check a permission as the user in a workspace, as the team.
     */
    protected function canIn(User $user, AclTeam $team, AclWorkspace|int $workspace, string $permission): bool
    {
        return Workspace::run($workspace instanceof AclWorkspace ? $workspace->id : $workspace, function () use ($user, $team, $permission): bool {
            $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($team);

            return User::find($user->id)->can($permission);
        });
    }

    /**
     * Create the Ops team with the editor role, and a member holding it in North.
     *
     * @return array{AclTeam, User}
     */
    protected function ops(): array
    {
        $ops = AclTeam::create(['name' => 'Ops']);
        $ops->syncTeamRoles(['editor']);

        $user = User::factory()->create();
        $ops->addUser($user, $this->north->id);

        return [$ops, $user];
    }

    public function testTheWorkspaceIsAPartitionOfTheFramework(): void
    {
        $registrar = $this->app->make(PermissionRegistrar::class);

        $this->assertSame('workspace_id', $registrar->resolvePartition()->column);
        $this->assertSame(Workspace::HOME, $registrar->resolvePartition()->value);

        Workspace::run($this->north->id, fn () => $this->assertSame($this->north->id, $registrar->resolvePartition()->value));
        $this->assertSame(Workspace::HOME, Workspace::id());
    }

    public function testWorkspacesAreIsolatedBetweenCoroutines(): void
    {
        [$north, $south] = parallel([
            function (): int|string {
                Workspace::set($this->north->id);
                usleep(5000);

                return Workspace::id();
            },
            function (): int|string {
                Workspace::set($this->south->id);
                usleep(5000);

                return Workspace::id();
            },
        ]);

        $this->assertSame($this->north->id, $north);
        $this->assertSame($this->south->id, $south);

        Workspace::set('');
        $this->assertSame(Workspace::HOME, Workspace::id());
    }

    public function testTheSeederCreatesTheRolesInEveryPartition(): void
    {
        foreach ([Workspace::HOME, $this->corporate->id, $this->branchB->id] as $workspace) {
            $this->assertTrue(Workspace::run($workspace, fn () => \Ipsocode\Acl\Models\Role::query()->where('name', 'editor')->exists()), (string) $workspace);
        }

        $this->assertSame(6, DB::table('roles')->where('name', 'editor')->count());
    }

    public function testEachWorkspaceAndTenantHasItsOwnCacheKey(): void
    {
        $registrar = $this->app->make(PermissionRegistrar::class);

        $home = $registrar->getCacheKey();
        $north = Workspace::run($this->north->id, fn () => $registrar->getCacheKey());
        $tenant = Tenant::run('acme', fn () => Workspace::run($this->north->id, fn () => $registrar->getCacheKey()));

        $this->assertCount(3, array_unique([$home, $north, $tenant]));
        $this->assertStringContainsString(':tenant:', $tenant);
    }

    public function testTheTreeAnswersAncestorsAndDescendants(): void
    {
        $this->assertSame([$this->branchA->id, $this->north->id, $this->corporate->id], $this->branchA->ancestorKeys());
        $this->assertEqualsCanonicalizing([$this->north->id, $this->branchA->id, $this->branchB->id, $this->south->id], $this->corporate->descendantKeys());
        $this->assertSame($this->north->id, $this->branchA->parent->id);
        $this->assertCount(2, $this->north->children);
        $this->assertEqualsCanonicalizing([$this->north->id, $this->branchA->id, $this->branchB->id], AclWorkspace::under($this->north->id)->pluck('id')->all());

        // A cycle written around the model ends the walk.
        DB::table('acl_workspaces')->where('id', $this->corporate->id)->update(['parent_id' => $this->branchA->id]);
        $this->assertCount(3, $this->branchA->ancestorKeys());
        $this->assertCount(4, $this->corporate->descendantKeys());
    }

    public function testAnOrgRoleAppliesInItsWorkspaceAndBeneath(): void
    {
        $reach = $this->app->make(Reach::class);

        $this->assertEqualsCanonicalizing([$this->north->id, $this->branchA->id, $this->branchB->id], $reach->beneath($this->north->id));
        $this->assertCount(6, $reach->beneath(Workspace::HOME));
        $this->assertTrue($reach->covers($this->north->id, $this->branchA->id));
        $this->assertTrue($reach->covers($this->north->id, $this->north->id));
        $this->assertTrue($reach->covers(Workspace::HOME, $this->south->id));
        $this->assertFalse($reach->covers($this->north->id, $this->south->id));
        $this->assertFalse($reach->covers($this->branchA->id, $this->north->id));
    }

    public function testAnOrgRolesRolesApplyInItsReachOnly(): void
    {
        [$ops, $user] = $this->ops();

        // Given in North when it was added; Branch A is rebuilt lazily.
        $this->assertSame(1, $this->rows($user, $this->north));
        $this->assertTrue($this->canIn($user, $ops, $this->north, 'articles.edit'));
        $this->assertFalse($this->canIn($user, $ops, $this->branchA, 'articles.edit'));

        $this->requestIn($user, $this->branchA->id);

        $this->assertTrue($this->canIn($user, $ops, $this->branchA, 'articles.edit'));
        $this->assertSame(1, $this->rows($user, $this->branchA));
        $this->assertFalse($this->canIn($user, $ops, $this->south, 'articles.edit'));
    }

    public function testASecondRequestInASyncedWorkspaceRebuildsNothing(): void
    {
        [$ops, $user] = $this->ops();
        $key = "{$ops->id}:{$this->north->id}";

        $this->requestIn($user, $this->north->id);
        $synced = session(ResolveAcl::SESSION_KEY);
        $this->requestIn($user, $this->north->id);

        $this->assertSame($synced, session(ResolveAcl::SESSION_KEY));
        $this->assertSame([$key], array_keys($synced['at']));

        $this->requestIn($user, $this->branchA->id);
        $this->assertSame([$key, "{$ops->id}:{$this->branchA->id}"], array_keys(session(ResolveAcl::SESSION_KEY)['at']));
    }

    public function testASessionSyncedBeforeTheTimesWerePerWorkspaceIsRebuilt(): void
    {
        [$ops, $user] = $this->ops();

        session([ResolveAcl::SESSION_KEY => ['at' => '2026-01-01 00:00:00']]);

        $this->requestIn($user, $this->north->id);

        $this->assertSame(["{$ops->id}:{$this->north->id}"], array_keys(session(ResolveAcl::SESSION_KEY)['at']));
    }

    public function testATeamChangeMakesEveryWorkspaceStale(): void
    {
        [$ops, $user] = $this->ops();
        $key = "{$ops->id}:{$this->north->id}";

        $this->requestIn($user, $this->north->id);
        $this->requestIn($user, $this->branchA->id);
        $this->assertSame(2, $this->rows($user, $this->north, $this->branchA));

        $ops->syncTeamRoles(['viewer']);

        // The members' rows are gone from every partition at once.
        $this->assertSame(0, $this->rows($user, Workspace::HOME, $this->north, $this->branchA));

        $before = session(ResolveAcl::SESSION_KEY)['at'];
        $this->requestIn($user, $this->north->id);
        $this->assertNotSame($before[$key], session(ResolveAcl::SESSION_KEY)['at'][$key]);
        $this->assertSame(1, $this->rows($user, $this->north));
    }

    public function testAWorkspaceNoOrgRoleCoversIsRefused(): void
    {
        [, $user] = $this->ops();

        $this->expectExceptionMessage('No org role of the user covers the workspace.');

        $this->requestIn($user, $this->south->id);
    }

    public function testAUserWithNoOrgRoleIsRefused(): void
    {
        $this->expectExceptionMessage('The user holds no org role.');

        $this->requestIn(User::factory()->create(), null);
    }

    public function testTheOnlyOrgRolesWorkspaceIsTheDefault(): void
    {
        [, $user] = $this->ops();

        $this->requestIn($user, null);

        $this->assertEquals($this->north->id, Workspace::id());
    }

    public function testAHomeOrgRoleCoversEveryWorkspaceAndIsTheDefault(): void
    {
        $ops = AclTeam::create(['name' => 'Ops']);
        $user = User::factory()->create();
        $ops->addUser($user);

        $this->requestIn($user, null);
        $this->assertEquals(Workspace::HOME, Workspace::id());

        $this->requestIn($user, $this->branchB->id);
        $this->assertEquals($this->branchB->id, Workspace::id());
    }

    public function testSeveralOrgRolesNeedAWorkspaceToChooseBetween(): void
    {
        [$ops, $user] = $this->ops();
        $sales = AclTeam::create(['name' => 'Sales']);
        $sales->addUser($user, $this->south->id);

        $this->requestIn($user, $this->south->id);
        $this->assertSame($sales->id, $this->app->make(PermissionRegistrar::class)->getPermissionsTeamId());

        $this->requestIn($user, $this->branchA->id);
        $this->assertSame($ops->id, $this->app->make(PermissionRegistrar::class)->getPermissionsTeamId());

        // Neither org role covers the home workspace.
        $this->expectExceptionMessage('No org role of the user covers the workspace.');
        $this->requestIn($user, Workspace::HOME);
    }

    public function testTheSelectedWorkspaceIsKeptInTheSession(): void
    {
        [$ops, $user] = $this->ops();

        $this->requestIn($user, $this->branchA->id);
        $this->assertEquals($this->branchA->id, session(RequestWorkspaceResolver::SESSION_KEY));

        // A later request naming none acts in the selection.
        Workspace::set(null);
        $this->requestIn($user, null);
        $this->assertEquals($this->branchA->id, Workspace::id());

        // A selection the user's org roles no longer cover is refused once, then forgotten.
        $ops->removeUser($user);
        $sales = AclTeam::create(['name' => 'Sales']);
        $sales->addUser($user, $this->south->id);

        try {
            $this->requestIn($user, null);
            $this->fail('The workspace should have been refused.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertNull(session(RequestWorkspaceResolver::SESSION_KEY));
        $this->requestIn($user, null);
        $this->assertEquals($this->south->id, Workspace::id());
    }

    public function testOrgRolesInWorkspacesThatReferenceEachOtherAreRefused(): void
    {
        [, $user] = $this->ops();
        $sales = AclTeam::create(['name' => 'Sales']);

        foreach ([$this->north, $this->branchA, $this->corporate, Workspace::HOME] as $workspace) {
            try {
                $sales->addUser($user, $workspace instanceof AclWorkspace ? $workspace->id : $workspace);
                $this->fail('The org role should have been refused.');
            } catch (UserAlreadyInTeam) {
            }
        }

        $this->assertSame(1, DB::table(Teams::ORGANIZATIONS_TABLE)->where('user_id', $user->id)->count());

        // A sibling chain is fine, and so is adding the same org role again.
        $sales->addUser($user, $this->south->id);
        $sales->addUser($user, $this->south->id);

        $this->assertSame(2, DB::table(Teams::ORGANIZATIONS_TABLE)->where('user_id', $user->id)->count());
    }

    public function testAHomeOrgRoleExcludesEveryOtherOne(): void
    {
        $ops = AclTeam::create(['name' => 'Ops']);
        $user = User::factory()->create();
        $ops->addUser($user, $this->north->id);

        $this->expectException(UserAlreadyInTeam::class);

        AclTeam::create(['name' => 'Sales'])->addUser($user);
    }

    public function testRemovingAnOrgRoleDeletesItsRolesInEveryWorkspace(): void
    {
        [$ops, $user] = $this->ops();

        foreach ([$this->branchA, $this->branchB] as $workspace) {
            $this->requestIn($user, $workspace->id);
        }

        $this->assertSame(3, $this->rows($user, $this->north, $this->branchA, $this->branchB));

        $ops->removeUser($user, $this->north->id);

        $this->assertSame(0, $this->rows($user, Workspace::HOME, $this->north, $this->branchA, $this->branchB));
        $this->assertSame(0, DB::table(Teams::ORGANIZATIONS_TABLE)->count());
    }

    public function testRemovingOneOfATeamsOrgRolesRebuildsTheOthers(): void
    {
        [$ops, $user] = $this->ops();
        $ops->addUser($user, $this->south->id);

        $this->requestIn($user, $this->south->id);
        $this->assertSame(1, $this->rows($user, $this->south));

        $ops->removeUser($user, $this->north->id);

        $this->assertSame(0, $this->rows($user, $this->north, $this->south));

        $this->requestIn($user, $this->south->id);
        $this->assertSame(1, $this->rows($user, $this->south));
    }

    public function testDeletingATeamReleasesItsMembersInEveryWorkspace(): void
    {
        [$ops, $user] = $this->ops();

        $this->requestIn($user, $this->branchA->id);

        $ops->delete();

        $this->assertSame(0, $this->rows($user, Workspace::HOME, $this->north, $this->branchA));
    }

    public function testMovingAWorkspaceMarksTheTeamsThatHoldAnOrgRoleAroundIt(): void
    {
        [, $user] = $this->ops();
        $reach = $this->app->make(Reach::class);

        $this->requestIn($user, $this->branchA->id);
        $this->assertTrue($reach->covers($this->north->id, $this->branchA->id));

        $this->branchA->update(['parent_id' => $this->south->id]);

        $this->assertFalse($reach->covers($this->north->id, $this->branchA->id));
        $this->assertSame(0, $this->rows($user, $this->branchA, $this->north));

        // Renaming moves nothing.
        $this->requestIn($user, $this->branchB->id);
        $this->branchB->update(['name' => 'Branch C']);
        $this->assertSame(1, $this->rows($user, $this->branchB));
    }

    public function testMovingAWorkspaceDoesNotRunMoreWritesForMoreTeams(): void
    {
        $writesFor = function (int $teams): int {
            foreach (range(1, $teams) as $number) {
                $team = AclTeam::create(['name' => "Team {$teams}.{$number}"]);
                $team->syncTeamRoles(['editor']);
                $team->addUser(User::factory()->create(), $this->branchB->id);
            }

            $writes = 0;
            DB::listen(function ($query) use (&$writes): void {
                $writes += preg_match('/^(insert|delete|update|savepoint|begin)/i', $query->sql);
            });

            $this->branchB->update(['parent_id' => $this->south->id]);
            $this->branchB->update(['parent_id' => $this->north->id]);

            return $writes;
        };

        $few = $writesFor(1);
        $many = $writesFor(4);

        $this->assertSame($few, $many);
    }

    public function testMovingAWorkspaceNoOrgRoleReachesMarksNothing(): void
    {
        $writes = 0;
        DB::listen(function ($query) use (&$writes): void {
            $writes += preg_match('/^delete from .model_has_roles/i', $query->sql);
        });

        $this->branchB->update(['parent_id' => $this->south->id]);

        $this->assertSame(0, $writes);
    }

    public function testAddingAUserInACachedWorkspaceRunsNoAncestorQuery(): void
    {
        $ops = AclTeam::create(['name' => 'Ops']);
        $ops->syncTeamRoles(['editor']);
        $user = User::factory()->create();

        $this->app->make(Reach::class)->ancestorsOf($this->branchA->id);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $ops->addUser($user, $this->branchA->id);

        $this->assertSame([], array_filter($queries, fn (string $sql): bool => str_contains($sql, 'select "parent_id" from "acl_workspaces"')));
    }

    public function testANewWorkspaceIsCoveredByTheOrgRolesAboveIt(): void
    {
        $reach = $this->app->make(Reach::class);

        $branchC = AclWorkspace::create(['name' => 'Branch C', 'parent_id' => $this->north->id]);
        $root = AclWorkspace::create(['name' => 'Root']);

        $this->assertTrue($reach->covers($this->north->id, $branchC->id));
        $this->assertFalse($reach->covers($this->north->id, $root->id));
    }

    public function testDeletingAWorkspaceRemovesItsPartition(): void
    {
        [$ops, $user] = $this->ops();

        $this->requestIn($user, $this->branchB->id);
        $this->assertTrue(DB::table('roles')->where('workspace_id', $this->branchB->id)->exists());

        $this->branchB->delete();

        $this->assertFalse(DB::table('roles')->where('workspace_id', $this->branchB->id)->exists());
        $this->assertFalse(DB::table('model_has_roles')->where('workspace_id', $this->branchB->id)->exists());
    }

    public function testEveryTeamInheritsTheTenantsGlobalsTeam(): void
    {
        $ops = AclTeam::create(['name' => 'Ops']);
        $globals = Teams::globals();

        $this->assertSame('Globals', $globals->name);
        $this->assertSame($globals->id, $ops->inherit_id);
        $this->assertNull($globals->inherit_id);
        $this->assertTrue($globals->is(Teams::globals()));

        $own = AclTeam::create(['name' => 'Own', 'inherit_id' => $ops->id]);
        $this->assertSame($ops->id, $own->inherit_id);
    }

    public function testAGlobalsTeamCreatedByAConcurrentRequestIsReadBack(): void
    {
        $column = Teams::tenantColumn();
        $missed = false;

        // The other request's insert has landed, but this request's lookup missed it.
        DB::table((new AclTeam)->getTable())->insert(['name' => Teams::GLOBALS, ...($column === null ? [] : [$column => Tenant::id()])]);

        AclTeam::addGlobalScope('missed', function ($query) use (&$missed): void {
            if (! $missed) {
                $missed = true;
                $query->whereRaw('1 = 0');
            }
        });

        $globals = Teams::globals();

        $this->assertTrue($missed);
        $this->assertSame(1, AclTeam::query()->withoutGlobalScope('missed')->where('name', Teams::GLOBALS)->count());
        $this->assertTrue($globals->is(Teams::globals()));
    }

    public function testGlobalsRolesReachEveryMembersWorkspace(): void
    {
        Teams::globals()->syncTeamRoles(['viewer']);

        [$ops, $user] = $this->ops();

        $this->requestIn($user, $this->north->id);

        $this->assertTrue($this->canIn($user, $ops, $this->north, 'articles.view'));
        $this->assertTrue($this->canIn($user, $ops, $this->north, 'articles.edit'));
    }

    public function testTheWorkspaceOptionSetsTheCommandsWorkspace(): void
    {
        $this->artisan('acl:setup', ['--workspace' => $this->north->id])->expectsConfirmation('Proceed with the migration creation?', 'no');

        $this->assertEquals($this->north->id, Workspace::id());
    }

    public function testTheWorkspaceTablesExist(): void
    {
        $this->assertTrue(Schema::hasTable('acl_workspaces'));
        $this->assertTrue(Schema::hasTable(Teams::ORGANIZATIONS_TABLE));
        $this->assertTrue(Schema::hasColumn('roles', 'workspace_id'));
    }

    public function testTheOrgRolesAreKeyedByWorkspaceFirst(): void
    {
        $primary = collect(Schema::getIndexes(Teams::ORGANIZATIONS_TABLE))->firstWhere('primary', true);

        $this->assertSame(['workspace_id', 'team_id', 'user_id'], $primary['columns']);
    }
}
