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

    public function testStatusCanBeLimitedToAFeature(): void
    {
        config([
            'acl.tenant' => true,
            'acl.column_names.tenant_foreign_key' => 'tenant',
            'acl.workspace' => true,
        ]);

        $this->artisan('acl:status', ['--only' => ['tenant']])
            ->expectsOutputToContain('Permission package')
            ->expectsOutputToContain('add_tenant_column')
            ->doesntExpectOutputToContain('add_workspace_column')
            ->doesntExpectOutputToContain('add_team_index')
            ->doesntExpectOutputToContain('Guards')
            ->assertExitCode(0);

        $this->artisan('acl:status', ['--only' => ['workspace']])
            ->expectsOutputToContain('Workspace model')
            ->expectsOutputToContain('add_workspace_column')
            ->expectsOutputToContain('add_team_index')
            ->doesntExpectOutputToContain('add_tenant_column')
            ->assertExitCode(0);

        $this->artisan('acl:status', ['--only' => ['tenant', 'workspace']])
            ->expectsOutputToContain('add_tenant_column')
            ->expectsOutputToContain('add_workspace_column')
            ->assertExitCode(0);
    }

    public function testALimitedStatusKeepsTheGeneralProblems(): void
    {
        config(['acl.tenant' => true, 'acl.column_names.tenant_foreign_key' => 'tenant', 'acl.key_type' => 'uuid']);

        $this->artisan('acl:status', ['--only' => ['tenant']])
            ->expectsOutputToContain('permissions table uses')
            ->doesntExpectOutputToContain('Guards')
            ->assertExitCode(1);

        config(['acl.key_type' => null]);
        Schema::dropIfExists('users');

        $this->artisan('acl:status', ['--only' => ['workspace']])
            ->expectsOutputToContain('users table users does not exist')
            ->assertExitCode(1);
    }

    public function testALimitedStatusKeepsTheProblemsOfItsFeatureAndOfThePermissionPackage(): void
    {
        config(['acl.tenant' => true, 'acl.column_names.tenant_foreign_key' => null]);

        $this->artisan('acl:status', ['--only' => ['tenant']])->expectsOutputToContain('names no column')->assertExitCode(1);
        $this->artisan('acl:status', ['--only' => ['workspace']])->doesntExpectOutputToContain('names no column')->assertExitCode(0);

        config(['permission.teams' => false]);
        $this->artisan('acl:status', ['--only' => ['workspace']])->expectsOutputToContain('not active')->assertExitCode(1);
    }

    public function testStatusRejectsAnUnknownFeature(): void
    {
        $this->artisan('acl:status', ['--only' => ['teams']])
            ->expectsOutputToContain('--only takes tenant or workspace, not teams.')
            ->assertExitCode(1);
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
