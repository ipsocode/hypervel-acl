<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Feature;

use Hypervel\Http\Request;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Facades\DB;
use Ipsocode\Acl\Access\AccessSync;
use Ipsocode\Acl\Exceptions\UserAlreadyInTeam;
use Ipsocode\Acl\Http\Middleware\ResolveAcl;
use Ipsocode\Acl\Models\AclTeam;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\AclSeeder;

class TeamTest extends TestCase
{
    protected function setUpInCoroutine(): void
    {
        (new AclSeeder)->run();
    }

    /**
     * @param list<string> $roles
     */
    protected function team(string $name, array $roles = []): AclTeam
    {
        $team = AclTeam::create(['name' => $name]);
        $team->syncTeamRoles($roles);

        return $team;
    }

    /**
     * @return list<string>
     */
    protected function rolesOf(User $user, AclTeam $team): array
    {
        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($team);

        return User::find($user->id)->getRoleNames()->sort()->values()->all();
    }

    public function testMembersHoldTheTeamsRoles(): void
    {
        $ops = $this->team('Ops', ['editor']);
        $user = User::factory()->create();

        $ops->addUser($user);

        $this->assertSame(['editor'], $this->rolesOf($user, $ops));
        $this->assertSame([$user->id], $ops->memberKeys());

        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($ops);

        $this->assertTrue(User::find($user->id)->can('articles.edit'));
        $this->assertFalse(User::find($user->id)->can('articles.delete'));
    }

    public function testChecksSeeOnlyTheCurrentTeamsRoles(): void
    {
        $ops = $this->team('Ops', ['editor']);
        $other = $this->team('Other', ['viewer']);
        $user = User::factory()->create();

        $ops->addUser($user);

        $this->assertSame([], $this->rolesOf($user, $other));
    }

    public function testRemovingAUserTakesTheTeamsRolesAway(): void
    {
        $ops = $this->team('Ops', ['viewer']);
        $user = User::factory()->create();

        $ops->addUser($user);
        $ops->removeUser($user);

        $this->assertSame([], $this->rolesOf($user, $ops));
        $this->assertSame([], $ops->memberKeys());
    }

    public function testAUserIsInOneTeamPerTenant(): void
    {
        $ops = $this->team('Ops', ['viewer']);
        $sales = $this->team('Sales', ['viewer']);
        $user = User::factory()->create();

        $ops->addUser($user);

        try {
            $sales->addUser($user);
            $this->fail('A second team in the tenant was accepted.');
        } catch (UserAlreadyInTeam) {
        }

        $ops->removeUser($user);
        $sales->addUser($user);

        $this->assertSame(['viewer'], $this->rolesOf($user, $sales));
    }

    public function testAddingAUserAgainKeepsTheTeam(): void
    {
        $ops = $this->team('Ops', ['viewer']);
        $user = User::factory()->create();

        $ops->addUser($user);
        $ops->addUser($user);

        $this->assertSame(['viewer'], $this->rolesOf($user, $ops));
    }

    public function testTheSignupTeamHoldsAPermissionlessMemberRole(): void
    {
        $signup = $this->team('signup', ['member']);
        $user = User::factory()->create();

        $signup->addUser($user);

        $this->assertSame(['member'], $this->rolesOf($user, $signup));

        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($signup);

        $this->assertFalse(User::find($user->id)->can('articles.view'));
    }

    public function testATeamWithoutRolesGivesNone(): void
    {
        $ops = $this->team('Ops', ['restricted']);
        $user = User::factory()->create();

        $ops->addUser($user);

        $this->assertSame(['restricted'], $ops->ownRoleNames());
        $this->assertSame(['restricted'], $this->rolesOf($user, $ops));
        $this->assertSame([], $this->app->make(AccessSync::class)->rolesFor($this->team('Empty')));
    }

    public function testChangingTheTeamsRolesRebuildsItsMembers(): void
    {
        $ops = $this->team('Ops', ['viewer']);
        $user = User::factory()->create();

        $ops->addUser($user);
        $ops->syncTeamRoles(['editor']);

        // The member's roles are deleted at once, and built again on their next request.
        $this->assertSame([], $this->rolesOf($user, $ops));
        $this->assertSame(['editor'], $ops->ownRoleNames());

        $this->nextRequest($user);
        $this->assertSame(['editor'], $this->rolesOf($user, $ops));

        $ops->syncTeamRoles(['restricted']);
        $this->nextRequest($user);
        $this->assertSame(['restricted'], $this->rolesOf($user, $ops));
    }

    public function testDeletingATeamReleasesItsMembers(): void
    {
        $ops = $this->team('Ops', ['viewer']);
        $user = User::factory()->create();

        $ops->addUser($user);
        $ops->delete();

        $this->assertSame([], $this->rolesOf($user, $ops));
        $this->assertSame(0, \Hypervel\Support\Facades\DB::table('model_has_roles')->count());
    }

    public function testDeletingATeamRunsTheSameQueriesWhateverItsMemberCount(): void
    {
        $queries = function (int $members): int {
            $team = $this->team("Team {$members}", ['viewer']);

            User::factory()->count($members)->create()->each(fn (User $user) => $team->addUser($user));

            $count = 0;
            DB::listen(function () use (&$count): void {
                ++$count;
            });

            $team->delete();

            return $count;
        };

        $this->assertSame($queries(1), $queries(4));
    }

    public function testDeletingATeamRemovesTheRolesOfMembersThatNoLongerResolve(): void
    {
        $ops = $this->team('Ops', ['viewer']);
        $user = User::factory()->create();

        $ops->addUser($user);
        DB::table($user->getTable())->delete();
        $ops->delete();

        $this->assertSame(0, DB::table('model_has_roles')->count());
    }

    public function testChangingATeamBumpsTheMembershipTimeBeforeTheRolesAreDeleted(): void
    {
        $ops = $this->team('Ops', ['viewer']);
        $ops->addUser(User::factory()->create());

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->app->make(AccessSync::class)->markChanged($ops);

        $bumped = array_key_first(array_filter($statements, fn (string $sql): bool => str_starts_with($sql, 'update "acl_organizations"')));
        $deleted = array_key_first(array_filter($statements, fn (string $sql): bool => str_starts_with($sql, 'delete from "model_has_roles"')));

        $this->assertNotNull($bumped);
        $this->assertNotNull($deleted);
        $this->assertLessThan($deleted, $bumped);
    }

    public function testTheSessionRemembersTheSyncSoAnUpToDateMemberIsNotRebuilt(): void
    {
        $ops = $this->team('Ops', ['viewer']);
        $user = User::factory()->create();
        $ops->addUser($user);

        $this->nextRequest($user);
        $synced = session(ResolveAcl::SESSION_KEY);

        $this->assertSame(["{$ops->id}:0"], array_keys($synced['at']));

        $this->nextRequest($user);
        $this->assertSame($synced, session(ResolveAcl::SESSION_KEY));

        // A change to the team marks the membership; the next request syncs again.
        $this->travel(1)->seconds();
        $ops->syncTeamRoles(['editor']);
        $this->nextRequest($user);

        $this->assertNotSame($synced['at'], session(ResolveAcl::SESSION_KEY)['at']);
        $this->assertSame(['editor'], $this->rolesOf($user, $ops));
    }

    public function testARequestWithoutASessionRebuildsOnlyAfterTheMembershipChanged(): void
    {
        $ops = $this->team('Ops', ['viewer']);
        $user = User::factory()->create();
        $ops->addUser($user);

        $request = function () use ($user): void {
            $request = Request::create('/');
            $request->setUserResolver(fn () => $user);

            $this->app->make(ResolveAcl::class)->handle($request, fn (): Response => new Response);
        };

        $request();
        $this->assertSame(['viewer'], $this->rolesOf($user, $ops));

        $writes = 0;
        DB::listen(function ($query) use (&$writes): void {
            $writes += preg_match('/^(insert|delete|update)/', $query->sql);
        });

        $request();
        $this->assertSame(0, $writes);

        $this->travel(1)->seconds();
        $ops->syncTeamRoles(['editor']);
        $request();

        $this->assertSame(['editor'], $this->rolesOf($user, $ops));
    }

    public function testReAddingAMemberMakesASyncRecordedBeforeStale(): void
    {
        $ops = AclTeam::create(['name' => 'Ops']);
        $user = User::factory()->create();
        $sync = $this->app->make(AccessSync::class);

        $ops->addUser($user);
        $ops->removeUser($user);
        $synced = Date::now()->format(Teams::DATE_FORMAT);

        $this->travel(1)->seconds();
        $ops->addUser($user);

        $changed = DB::table(Teams::ORGANIZATIONS_TABLE)->where(Teams::userPivotKey(), $user->getKey())->value('updated_at');

        $this->assertNotNull($changed);
        $this->assertTrue($sync->isStale($synced, $changed));
    }

    public function testEqualTimestampsCountAsStale(): void
    {
        $sync = $this->app->make(AccessSync::class);

        $this->assertTrue($sync->isStale('2026-10-30 10:00:00.500', '2026-10-30 10:00:00.500'));
        $this->assertTrue($sync->isStale('2026-10-30 10:00:00.400', '2026-10-30 10:00:00.500'));
        $this->assertFalse($sync->isStale('2026-10-30 10:00:00.600', '2026-10-30 10:00:00.500'));
        $this->assertTrue($sync->isStale(null, '2026-10-30 10:00:00.500'));
        $this->assertTrue($sync->isStale(null, null));
        $this->assertFalse($sync->isStale('2026-10-30 10:00:00.500', null));
    }

    public function testATeamWithoutRolesStillHasItsMembers(): void
    {
        $ops = $this->team('Ops');
        $user = User::factory()->create();

        $ops->addUser($user);

        $this->assertSame([$user->id], $ops->memberKeys());
        $this->assertSame([], $this->rolesOf($user, $ops));
    }

    public function testDeletingAUserOrATeamDeletesTheMembership(): void
    {
        $ops = $this->team('Ops', ['viewer']);
        $sales = $this->team('Sales', ['viewer']);
        [$leaving, $staying] = User::factory()->count(2)->create()->all();

        $ops->addUser($leaving);
        $sales->addUser($staying);

        $leaving->delete();
        $sales->delete();

        $this->assertSame(0, DB::table(Teams::ORGANIZATIONS_TABLE)->count());
    }
}
