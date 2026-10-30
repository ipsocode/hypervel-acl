<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Feature;

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
}
