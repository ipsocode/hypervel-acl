<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Feature;

use Hypervel\Database\QueryException;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\DB;
use Ipsocode\Acl\Access\AccessSync;
use Ipsocode\Acl\Models\AclTeam;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Tests\TestCase;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\AclSeeder;

class TeamInheritanceTest extends TestCase
{
    protected function setUpInCoroutine(): void
    {
        (new AclSeeder)->run();
    }

    /**
     * @return list<string>
     */
    protected function rolesOf(User $user, AclTeam $team): array
    {
        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($team);

        return User::find($user->id)->getRoleNames()->sort()->values()->all();
    }

    public function testATeamHoldsTheRolesOfTheTeamsItInherits(): void
    {
        $base = AclTeam::create(['name' => 'Base']);
        $lead = AclTeam::create(['name' => 'Lead', 'inherit_id' => $base->id]);
        $user = User::factory()->create();

        $base->syncTeamRoles(['restricted']);
        $lead->syncTeamRoles(['manager']);
        $lead->addUser($user);

        $this->assertEqualsCanonicalizing(['manager', 'restricted'], $this->rolesOf($user, $lead));
    }

    public function testChangesToAnInheritedTeamReachItsDependents(): void
    {
        $base = AclTeam::create(['name' => 'Base']);
        $mid = AclTeam::create(['name' => 'Mid', 'inherit_id' => $base->id]);
        $top = AclTeam::create(['name' => 'Top', 'inherit_id' => $mid->id]);
        $user = User::factory()->create();

        $base->syncTeamRoles(['viewer']);
        $mid->syncTeamRoles(['restricted']);
        $top->syncTeamRoles(['member']);
        $top->addUser($user);

        $this->assertEqualsCanonicalizing(['member', 'restricted', 'viewer'], $this->rolesOf($user, $top));

        // The change is to the team at the bottom of the chain, and the member is two teams up.
        $base->syncTeamRoles(['editor']);
        $this->nextRequest($user);

        $this->assertEqualsCanonicalizing(['editor', 'member', 'restricted'], $this->rolesOf($user, $top));
        $this->assertSame([$mid->id, $top->id], $this->app->make(AccessSync::class)->dependents($base));
    }

    public function testChangingTheInheritedTeamRebuildsTheMembers(): void
    {
        $base = AclTeam::create(['name' => 'Base']);
        $lead = AclTeam::create(['name' => 'Lead']);
        $user = User::factory()->create();

        $base->syncTeamRoles(['viewer']);
        $lead->syncTeamRoles(['member']);
        $lead->addUser($user);

        $this->assertSame(['member'], $this->rolesOf($user, $lead));

        $lead->update(['inherit_id' => $base->id]);
        $this->nextRequest($user);

        $this->assertSame(['member', 'viewer'], $this->rolesOf($user, $lead));
    }

    public function testATeamWithoutAnInheritedTeamHoldsOnlyItsOwnRoles(): void
    {
        $base = AclTeam::create(['name' => 'Base', 'inherit_id' => null]);
        $lead = AclTeam::create(['name' => 'Lead', 'inherit_id' => $base->id]);
        $user = User::factory()->create();

        $base->syncTeamRoles(['viewer']);
        $lead->syncTeamRoles(['member']);
        $base->addUser($user);

        $this->assertNull($base->inheritedTeam());
        $this->assertSame(['viewer'], $this->rolesOf($user, $base));
        $this->assertSame([], $this->app->make(AccessSync::class)->dependents($lead));
    }

    public function testClearingTheInheritedTeamTakesItsRolesAway(): void
    {
        $base = AclTeam::create(['name' => 'Base']);
        $lead = AclTeam::create(['name' => 'Lead', 'inherit_id' => $base->id]);
        $user = User::factory()->create();

        $base->syncTeamRoles(['viewer']);
        $lead->syncTeamRoles(['member']);
        $lead->addUser($user);

        $this->assertSame(['member', 'viewer'], $this->rolesOf($user, $lead));

        $lead->update(['inherit_id' => null]);
        $this->nextRequest($user);

        $this->assertNull($lead->fresh()->inheritedTeam());
        $this->assertSame(['member'], $this->rolesOf($user, $lead));
    }

    public function testCyclesDoNotLoop(): void
    {
        $a = AclTeam::create(['name' => 'A']);
        $b = AclTeam::create(['name' => 'B', 'inherit_id' => $a->id]);
        $a->forceFill(['inherit_id' => $b->id])->saveQuietly();
        $user = User::factory()->create();

        $a->syncTeamRoles(['viewer']);
        $b->syncTeamRoles(['member']);
        $this->assertEqualsCanonicalizing(['member', 'viewer'], $this->app->make(AccessSync::class)->rolesFor($b));

        $this->assertCount(1, $this->app->make(AccessSync::class)->dependents($a));
    }

    public function testTheApiRejectsADuplicateTeamName(): void
    {
        $admins = AclTeam::create(['name' => 'Admins']);
        $other = AclTeam::create(['name' => 'Other']);
        $admin = User::factory()->create();

        $admins->syncTeamRoles(['manager']);
        $admins->addUser($admin);

        $this->actingAs($admin);

        $this->postJson('acl/teams', ['name' => 'Other'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->putJson("acl/teams/{$admins->id}", ['name' => 'Other'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->putJson("acl/teams/{$other->id}", ['name' => 'Other'])->assertOk();
    }

    public function testTheApiRejectsAnInheritanceCycleOrAForeignTeam(): void
    {
        $admins = AclTeam::create(['name' => 'Admins']);
        $other = AclTeam::create(['name' => 'Other']);
        $admin = User::factory()->create();

        $admins->syncTeamRoles(['manager']);
        $admins->addUser($admin);

        $this->actingAs($admin);

        $this->putJson("acl/teams/{$admins->id}", ['name' => 'Admins', 'inherit_id' => $other->id])->assertOk()->assertJsonPath('data.inherit_id', $other->id);
        $this->putJson("acl/teams/{$other->id}", ['name' => 'Other', 'inherit_id' => $admins->id])->assertUnprocessable();
        $this->putJson("acl/teams/{$other->id}", ['name' => 'Other', 'inherit_id' => $other->id])->assertUnprocessable();
        $this->postJson('acl/teams', ['name' => 'New', 'inherit_id' => 99999])->assertUnprocessable();
        $this->postJson('acl/teams', ['name' => 'New', 'inherit_id' => null])->assertCreated();
    }

    public function testTheChainIsCachedUntilAnInheritedTeamChanges(): void
    {
        $sync = $this->app->make(AccessSync::class);
        $base = AclTeam::create(['name' => 'Base']);
        $mid = AclTeam::create(['name' => 'Mid', 'inherit_id' => $base->id]);
        $top = AclTeam::create(['name' => 'Top', 'inherit_id' => $mid->id]);
        $other = AclTeam::create(['name' => 'Other']);

        $this->assertSame([$top->id, $mid->id, $base->id], $sync->chainOf($top));

        // A write that skips model events leaves the cached chain as it was.
        DB::table('acl_teams')->where('id', $mid->id)->update(['inherit_id' => null]);
        $this->assertSame([$top->id, $mid->id, $base->id], $sync->chainOf($top));

        // Changing a team's inherited team forgets its chain and those of the teams below it.
        $mid->update(['inherit_id' => $other->id]);
        $this->assertSame([$top->id, $mid->id, $other->id], $sync->chainOf($top));
        $this->assertSame([$mid->id, $other->id], $sync->chainOf($mid));
    }

    public function testDeletingATeamForgetsItsChain(): void
    {
        $sync = $this->app->make(AccessSync::class);
        $team = AclTeam::create(['name' => 'Ops']);
        $sync->chainOf($team);

        $this->assertTrue(Cache::has(AccessSync::CHAIN_CACHE_KEY_PREFIX . $team->id));

        $team->delete();

        $this->assertFalse(Cache::has(AccessSync::CHAIN_CACHE_KEY_PREFIX . $team->id));
    }

    public function testDeletingATeamOthersInheritLeavesItsMembersRoles(): void
    {
        $base = AclTeam::create(['name' => 'Base']);
        AclTeam::create(['name' => 'Lead', 'inherit_id' => $base->id]);
        $user = User::factory()->create();

        $base->syncTeamRoles(['viewer']);
        $base->addUser($user);

        try {
            $base->delete();
            $this->fail('The delete should have been refused by the inherit key.');
        } catch (QueryException) {
            // The purge ran inside the transaction that the failed delete rolled back.
        }

        $this->assertSame(['viewer'], $this->rolesOf($user, $base));
    }

    public function testDependentsAreCachedAndFollowTeamsAddedMovedAndDeleted(): void
    {
        $sync = $this->app->make(AccessSync::class);
        $base = AclTeam::create(['name' => 'Base']);
        $mid = AclTeam::create(['name' => 'Mid', 'inherit_id' => $base->id]);
        $other = AclTeam::create(['name' => 'Other']);

        $this->assertSame([$mid->id], $sync->dependents($base));
        $this->assertTrue(Cache::has(AccessSync::DEPENDENTS_CACHE_KEY_PREFIX . $base->id));

        // A new team two levels down reaches the cached list of the team at the top.
        $top = AclTeam::create(['name' => 'Top', 'inherit_id' => $mid->id]);
        $this->assertSame([$mid->id, $top->id], $sync->dependents($base));

        // Moving Mid away takes it and Top from Base, and gives both to Other.
        $mid->update(['inherit_id' => $other->id]);
        $this->assertSame([], $sync->dependents($base));
        $this->assertSame([$mid->id, $top->id], $sync->dependents($other));

        $top->delete();
        $this->assertSame([$mid->id], $sync->dependents($other));
    }

    public function testAChangeDeletesTheRolesOfTheTeamsMembersAndThoseBelowIt(): void
    {
        $base = AclTeam::create(['name' => 'Base']);
        $lead = AclTeam::create(['name' => 'Lead', 'inherit_id' => $base->id]);
        $other = AclTeam::create(['name' => 'Other']);
        [$leadUser, $otherUser] = User::factory()->count(2)->create()->all();

        foreach ([$base, $lead, $other] as $team) {
            $team->syncTeamRoles(['viewer']);
        }

        $lead->addUser($leadUser);
        $other->addUser($otherUser);
        $before = DB::table('acl_teams')->pluck('updated_at', 'id');

        $this->travel(1)->seconds();
        $this->app->make(AccessSync::class)->markChanged($base);

        // Lead's member holds nothing until their next request; Other's member is untouched.
        $this->assertSame([], $this->rolesOf($leadUser, $lead));
        $this->assertSame(['viewer'], $this->rolesOf($otherUser, $other));

        // The teams keep their own roles.
        $this->assertSame(['viewer'], $lead->ownRoleNames());

        $changed = DB::table(Teams::ORGANIZATIONS_TABLE)->pluck('updated_at', Teams::userPivotKey());
        $teams = DB::table('acl_teams')->pluck('updated_at', 'id');

        // Stored to the millisecond, which the staleness check compares at.
        $this->assertMatchesRegularExpression('/\.\d{3}$/', (string) $changed[$leadUser->id]);
        $this->assertNull($changed[$otherUser->id]);
        $this->assertNotSame($before[$lead->id], $teams[$lead->id]);
        $this->assertSame($before[$other->id], $teams[$other->id]);

        $this->nextRequest($leadUser);
        $this->assertSame(['viewer'], $this->rolesOf($leadUser, $lead));
    }
}
