<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Feature;

use ArrayObject;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Support\Facades\Artisan;
use InvalidArgumentException;
use Ipsocode\Acl\Models\AclTeam;
use Ipsocode\Acl\Models\Role;
use Ipsocode\Acl\Tenant;
use Ipsocode\Acl\Tests\TestCase;
use Workbench\App\Models\User;

class TenantConsoleTest extends TestCase
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

    protected ArrayObject $report;

    protected function setUpInCoroutine(): void
    {
        $report = $this->report = new ArrayObject;

        // Reports what a command sees: its tenant, the roles it can look up, and the tenant a new role is stamped with.
        Artisan::command('acl-test:probe', function () use ($report): void {
            $created = Role::create(['name' => 'probe']);

            $report->exchangeArray([
                'tenant' => Tenant::id(),
                'roles' => Role::query()->orderBy('id')->pluck('name')->all(),
                'stamped' => $created->getAttributes()['tenant'] ?? null,
            ]);
        });
    }

    /**
     * Run the probe command and decode what it reported.
     *
     * @param array<string, string> $options
     * @return array{tenant: null|string, roles: list<string>, stamped: null|string}
     */
    protected function probe(array $options): array
    {
        $this->artisan('acl-test:probe', $options)->assertExitCode(0);

        /** @var array{tenant: null|string, roles: list<string>, stamped: null|string} */
        return $this->report->getArrayCopy();
    }

    public function testATeamRunsItsCommandInItsOwnTenant(): void
    {
        $acme = AclTeam::create(['name' => 'acme team', 'tenant' => 'acme']);
        Role::create(['name' => 'acme-only', 'tenant' => 'acme']);
        Role::create(['name' => 'globex-only', 'tenant' => 'globex']);

        $report = $this->probe(['--team' => (string) $acme->id]);

        $this->assertSame('acme', $report['tenant']);
        $this->assertSame('acme', $report['stamped']);
        $this->assertSame(['acme-only', 'probe'], $report['roles']);
    }

    public function testTenantSelectsTheTenantToSeed(): void
    {
        Role::create(['name' => 'acme-only', 'tenant' => 'acme']);

        $report = $this->probe(['--tenant' => 'globex']);

        $this->assertSame('globex', $report['tenant']);
        $this->assertSame('globex', $report['stamped']);
        $this->assertNotContains('acme-only', $report['roles']);
    }

    public function testATenantThatAgreesWithTheTeamIsAccepted(): void
    {
        $acme = AclTeam::create(['name' => 'acme team', 'tenant' => 'acme']);

        $this->assertSame('acme', $this->probe(['--team' => (string) $acme->id, '--tenant' => 'acme'])['tenant']);
    }

    public function testATenantThatDisagreesWithTheTeamFails(): void
    {
        $acme = AclTeam::create(['name' => 'acme team', 'tenant' => 'acme']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The team [{$acme->id}] belongs to tenant [acme], not [globex].");

        $this->artisan('acl-test:probe', ['--team' => (string) $acme->id, '--tenant' => 'globex']);
    }

    public function testATeamCommandAssignsTheRoleOfItsOwnTenant(): void
    {
        $acme = AclTeam::create(['name' => 'acme team', 'tenant' => 'acme']);
        $globexAdmin = Tenant::run('globex', fn () => Role::create(['name' => 'admin']));
        $acmeAdmin = Tenant::run('acme', fn () => Role::create(['name' => 'admin']));
        $user = User::factory()->create();

        Artisan::command('acl-test:assign {user}', function (int $user): void {
            User::findOrFail($user)->assignRole('admin');
        });

        $this->artisan('acl-test:assign', ['user' => $user->id, '--team' => (string) $acme->id])->assertExitCode(0);

        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($acme->id);
        Tenant::set('acme');

        $this->assertSame([$acmeAdmin->id], User::find($user->id)->roles->pluck('id')->all());
        $this->assertNotSame($globexAdmin->id, $acmeAdmin->id);
    }
}
