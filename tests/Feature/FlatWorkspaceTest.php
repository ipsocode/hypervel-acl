<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Feature;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\DB;
use Ipsocode\Acl\Exceptions\UserAlreadyInTeam;
use Ipsocode\Acl\Http\Middleware\ResolveAcl;
use Ipsocode\Acl\Models\AclTeam;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Support\Workspaces;
use Ipsocode\Acl\Tests\WorkspaceTestCase;
use Ipsocode\Acl\Workspace;
use Ipsocode\Acl\Workspaces\Reach;
use Symfony\Component\HttpFoundation\Response;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\AclSeeder;

class FlatWorkspaceTest extends WorkspaceTestCase
{
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        $app->get('config')->set('acl.models.workspace', null);
    }

    public function testFlatWorkspacesAreStringPartitionsTheApplicationIdentifies(): void
    {
        $this->assertFalse(Workspaces::usesIdKeys());
        $this->assertSame(['berlin'], Workspaces::ancestorKeys('berlin'));
        $this->assertSame([], Workspaces::descendantKeys(['berlin']));
    }

    public function testAnOrgRoleCoversOnlyItsOwnWorkspace(): void
    {
        $reach = $this->app->make(Reach::class);

        $this->assertTrue($reach->covers('berlin', 'berlin'));
        $this->assertFalse($reach->covers('berlin', 'paris'));
        $this->assertTrue($reach->covers(Workspace::HOME, 'paris'));
    }

    public function testFlatWorkspacesAreSiblingsOfEachOther(): void
    {
        $ops = AclTeam::create(['name' => 'Ops']);
        $sales = AclTeam::create(['name' => 'Sales']);
        $user = User::factory()->create();

        $ops->addUser($user, 'berlin');
        $sales->addUser($user, 'paris');

        $this->assertSame(2, DB::table(Teams::ORGANIZATIONS_TABLE)->where('user_id', $user->id)->count());

        $this->expectException(UserAlreadyInTeam::class);

        $sales->addUser($user, 'berlin');
    }

    public function testRolesApplyInTheWorkspaceTheRequestNames(): void
    {
        $ops = AclTeam::create(['name' => 'Ops']);
        $user = User::factory()->create();

        foreach ([Workspace::HOME, 'berlin', 'paris'] as $workspace) {
            Workspace::run($workspace, fn () => (new AclSeeder)->run());
        }

        $ops->syncTeamRoles(['editor']);
        $ops->addUser($user);

        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);
        $request->setHypervelSession($this->app->make('session')->driver());
        $request->headers->set('X-Workspace', 'berlin');

        $this->app->make(ResolveAcl::class)->handle($request, fn () => new Response);

        $this->assertSame('berlin', Workspace::id());
        $this->assertSame(1, DB::table('model_has_roles')->where('model_type', $user->getMorphClass())->where('workspace_id', 'berlin')->count());
        $this->assertSame(0, DB::table('model_has_roles')->where('model_type', $user->getMorphClass())->where('workspace_id', 'paris')->count());
    }

    public function testOnlyTheReachRouteExists(): void
    {
        $this->assertFalse($this->app->make('router')->has('acl.workspaces.index'));
    }
}
