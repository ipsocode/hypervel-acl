<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Feature;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Permission\Guard;
use Hypervel\Support\Facades\Schema;
use Ipsocode\Acl\Tests\Fixtures\UuidUser;
use Ipsocode\Acl\Tests\TestCase;

class StatusTest extends TestCase
{
    public function testStatusReportsTheFeaturesAndPendingMigrations(): void
    {
        config(['acl.tenant' => true, 'acl.column_names.tenant_foreign_key' => 'tenant']);

        $this->artisan('acl:status')
            ->expectsOutputToContain('active')
            ->expectsOutputToContain('add_tenant_column')
            ->expectsOutputToContain('pending')
            ->assertExitCode(0);
    }

    public function testStatusFailsWhenThePermissionPackageIsNotActive(): void
    {
        config(['permission.teams' => false]);

        $this->artisan('acl:status')
            ->expectsOutputToContain('not active')
            ->expectsOutputToContain('permission:setup-teams')
            ->assertExitCode(1);
    }

    public function testStatusFailsWhenTheTenantColumnIsMissing(): void
    {
        config(['acl.tenant' => true, 'acl.column_names.tenant_foreign_key' => null]);

        $this->artisan('acl:status')->expectsOutputToContain('names no column')->assertExitCode(1);
    }

    public function testStatusReportsTheKeyTypeAndFailsWhenItDiffersFromThePermissionsTable(): void
    {
        $this->artisan('acl:status')->expectsOutputToContain('Key type')->assertExitCode(0);

        config(['acl.key_type' => 'uuid']);

        $this->artisan('acl:status')->expectsOutputToContain('permissions table uses id keys')->assertExitCode(1);
    }

    public function testStatusFailsWhenTheUserModelGeneratesAnotherKeyTypeThanItsTable(): void
    {
        Schema::dropIfExists('users');
        Schema::create('users', fn (Blueprint $table) => $table->id());
        $this->useUserModel(UuidUser::class);

        $this->artisan('acl:status')->expectsOutputToContain('uses id keys')->expectsOutputToContain('active')->assertExitCode(1);
    }

    public function testStatusFailsWhenTheUsersTableOrGuardModelIsMissing(): void
    {
        Schema::dropIfExists('users');

        $this->artisan('acl:status')->expectsOutputToContain('does not exist')->assertExitCode(1);

        config(['auth.guards.web.provider' => null]);
        Guard::flushState();
        $this->artisan('acl:status')->expectsOutputToContain('has no user model')->assertExitCode(1);
    }

    /**
     * @param class-string $model
     */
    protected function useUserModel(string $model): void
    {
        config(['auth.providers.users.model' => $model]);
        Guard::flushState();
    }
}
