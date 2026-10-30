<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Feature;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Support\Facades\Schema;
use Ipsocode\Acl\Exceptions\UserAlreadyInTeam;
use Ipsocode\Acl\Models\AclTeam;
use Ipsocode\Acl\Models\Permission;
use Ipsocode\Acl\Models\Role;
use Ipsocode\Acl\Permission\TenantPermissionRegistrar;
use Ipsocode\Acl\Tenant;
use Ipsocode\Acl\Tests\TestCase;
use RuntimeException;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\AclSeeder;

use function Hypervel\Coroutine\parallel;

class TenantTest extends TestCase
{
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        $app->get('config')->set('acl.tenant', true);
        $app->get('config')->set('acl.column_names.tenant_foreign_key', 'tenant');
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/tenant-migrations');
    }

    /**
     * Create a team of a tenant holding the given roles, and a user of the tenant in it.
     *
     * @param list<string> $roles
     * @return array{AclTeam, User}
     */
    protected function member(string $tenant, array $roles): array
    {
        $team = AclTeam::create(['name' => "{$tenant} team", 'tenant' => $tenant]);
        $team->syncTeamRoles($roles);

        $user = User::factory()->create();
        $user->forceFill(['tenant' => $tenant])->save();
        $team->addUser($user);

        return [$team, $user];
    }

    /**
     * Check a permission as the user, in the team's tenant and as the team.
     */
    protected function can(User $user, AclTeam $team, string $permission): bool
    {
        return Tenant::run($team->tenant(), function () use ($user, $team, $permission): bool {
            $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($team);

            return User::find($user->id)->can($permission);
        });
    }

    public function testTheTenantColumnIsOnThePermissionAndTeamTables(): void
    {
        foreach (['roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions', 'acl_teams'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'tenant'), $table);
        }

        $this->assertSame('acme', AclTeam::create(['name' => 'Ops', 'tenant' => 'acme'])->tenant());
    }

    public function testTheFrameworkRegistrarIsTheTenantOne(): void
    {
        $this->assertInstanceOf(TenantPermissionRegistrar::class, $this->app->make(PermissionRegistrar::class));
    }

    public function testRunRestoresThePreviousTenant(): void
    {
        $this->assertFalse(Tenant::check());

        Tenant::run('acme', function (): void {
            $this->assertSame('acme', Tenant::id());
            Tenant::run('globex', fn () => $this->assertSame('globex', Tenant::id()));
            $this->assertSame('acme', Tenant::id());
            $this->assertSame('ran', Tenant::run(null, fn () => 'ran'));
        });

        $this->assertNull(Tenant::id());

        try {
            Tenant::run('acme', fn () => throw new RuntimeException('failed'));
        } catch (RuntimeException) {
        }

        $this->assertNull(Tenant::id());
    }

    public function testTenantsAreIsolatedBetweenCoroutines(): void
    {
        [$acme, $globex] = parallel([
            function (): int|string|null {
                Tenant::set('acme');
                usleep(5000);

                return Tenant::id();
            },
            function (): int|string|null {
                Tenant::set('globex');
                usleep(5000);

                return Tenant::id();
            },
        ]);

        $this->assertSame('acme', $acme);
        $this->assertSame('globex', $globex);
    }

    public function testRolesAndPermissionsAreScopedToTheTenantAndShareTheUntenanted(): void
    {
        (new AclSeeder)->run();

        Tenant::run('acme', function (): void {
            Role::create(['name' => 'auditor', 'team_id' => null]);
            Permission::create(['name' => 'reports.view']);
        });

        $this->assertSame('acme', Tenant::run('acme', fn () => Role::query()->where('name', 'auditor')->value('tenant')));
        $this->assertFalse(Tenant::run('globex', fn () => Role::query()->where('name', 'auditor')->exists()));
        $this->assertFalse(Tenant::run('globex', fn () => Permission::query()->where('name', 'reports.view')->exists()));

        // The seeded roles have no tenant: every tenant shares them.
        $this->assertTrue(Tenant::run('globex', fn () => Role::query()->where('name', 'viewer')->exists()));
        $this->assertTrue(Tenant::run('acme', fn () => Role::query()->where('name', 'viewer')->exists()));
    }

    public function testEachTenantChecksAgainstItsOwnCatalog(): void
    {
        (new AclSeeder)->run();

        foreach (['acme' => 'reports.view', 'globex' => 'invoices.view'] as $tenant => $permission) {
            Tenant::run($tenant, function () use ($permission): void {
                Role::create(['name' => 'auditor', 'team_id' => null])->givePermissionTo(Permission::create(['name' => $permission]));
            });
        }

        [$acmeTeam, $acmeUser] = $this->member('acme', ['auditor']);
        [$globexTeam, $globexUser] = $this->member('globex', ['auditor']);

        // Acme's catalog is loaded and cached first; globex must still see its own.
        $this->assertTrue($this->can($acmeUser, $acmeTeam, 'reports.view'));
        $this->assertTrue($this->can($globexUser, $globexTeam, 'invoices.view'));
        $this->assertFalse($this->can($globexUser, $globexTeam, 'reports.view'));
        $this->assertFalse($this->can($acmeUser, $acmeTeam, 'invoices.view'));
    }

    public function testEachTenantHasItsOwnCacheKey(): void
    {
        $registrar = $this->app->make(PermissionRegistrar::class);

        $acme = Tenant::run('acme', fn () => $registrar->getCacheKey());
        $globex = Tenant::run('globex', fn () => $registrar->getCacheKey());

        $this->assertNotSame($acme, $globex);
        $this->assertStringStartsWith($registrar->getCacheKey() . ':tenant:', $acme);
    }

    public function testAChangeInOneTenantIsSeenThere(): void
    {
        (new AclSeeder)->run();

        [$team, $user] = $this->member('acme', ['viewer']);

        $this->assertFalse($this->can($user, $team, 'reports.view'));

        Tenant::run('acme', fn () => Role::findByName('viewer')->givePermissionTo(Permission::create(['name' => 'reports.view'])));

        $this->assertTrue($this->can($user, $team, 'reports.view'));
    }

    public function testTeamsAreScopedAndStampedWithTheTenant(): void
    {
        $shared = AclTeam::create(['name' => 'Shared']);
        $acme = Tenant::run('acme', fn () => AclTeam::create(['name' => 'Ops']));

        $this->assertSame('acme', $acme->tenant());
        $this->assertNull($shared->tenant());
        $this->assertSame(['Ops', 'Shared'], Tenant::run('acme', fn () => AclTeam::query()->orderBy('name')->pluck('name')->all()));
        $this->assertSame(['Shared'], Tenant::run('globex', fn () => AclTeam::query()->pluck('name')->all()));
    }

    public function testAUserIsInOneTeamPerTenantButOneInEachTenant(): void
    {
        (new AclSeeder)->run();

        $acme = AclTeam::create(['name' => 'Ops', 'tenant' => 'acme']);
        $acmeSales = AclTeam::create(['name' => 'Sales', 'tenant' => 'acme']);
        $globex = AclTeam::create(['name' => 'Ops', 'tenant' => 'globex']);
        $user = User::factory()->create();

        foreach ([$acme, $acmeSales, $globex] as $team) {
            $team->syncTeamRoles(['viewer']);
        }

        $acme->addUser($user);
        $globex->addUser($user);

        // Acting in globex does not hide the user's acme team from the rule.
        $this->expectException(UserAlreadyInTeam::class);
        Tenant::run('globex', fn () => $acmeSales->addUser($user));
    }

    public function testTheMiddlewareActsInTheUsersTenant(): void
    {
        (new AclSeeder)->run();

        [$admins, $admin] = $this->member('acme', ['manager']);

        // The admin is in a globex team too; acting in acme picks the acme one.
        $globex = AclTeam::create(['name' => 'Globex', 'tenant' => 'globex']);
        $globex->syncTeamRoles(['viewer']);
        $globex->addUser($admin);

        $this->actingAs($admin)->getJson('acl/roles')->assertOk();
        $this->actingAs($admin)->getJson('acl/teams')->assertOk()->assertJsonPath('data.0.name', 'acme team')->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson("acl/teams/{$globex->id}")->assertNotFound();
    }

    public function testTheApiStaysInTheCurrentTeamsTenant(): void
    {
        (new AclSeeder)->run();

        [$admins, $admin] = $this->member('acme', ['manager']);
        $foreign = AclTeam::create(['name' => 'Admins', 'tenant' => 'globex']);

        $this->actingAs($admin);

        $this->postJson('acl/teams', ['name' => 'Ops', 'tenant' => 'globex'])->assertCreated()->assertJsonPath('data.tenant', 'acme');
        $this->getJson('acl/teams')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("acl/teams/{$foreign->id}")->assertNotFound();
        $this->postJson('acl/teams', ['name' => 'Bad', 'inherit_id' => $foreign->id])->assertUnprocessable();
    }
}
