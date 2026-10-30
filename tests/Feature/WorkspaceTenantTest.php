<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Feature;

use Hypervel\Contracts\Foundation\Application;
use Ipsocode\Acl\Models\AclWorkspace;
use Ipsocode\Acl\Tenant;
use Ipsocode\Acl\Tests\WorkspaceTestCase;
use Ipsocode\Acl\Workspaces\Reach;

class WorkspaceTenantTest extends WorkspaceTestCase
{
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        $app->get('config')->set('acl.tenant', true);
        $app->get('config')->set('acl.column_names.tenant_foreign_key', 'tenant');
    }

    public function testAWorkspaceOfAnotherTenantDoesNotExist(): void
    {
        $theirs = Tenant::run('other', fn () => AclWorkspace::create(['name' => 'Theirs']));
        $shared = AclWorkspace::create(['name' => 'Shared']);
        $reach = $this->app->make(Reach::class);

        $this->assertSame('other', $theirs->tenant);
        $this->assertNull($shared->tenant);

        $this->assertFalse(Tenant::run('acme', fn () => $reach->exists($theirs->id)));
        $this->assertTrue(Tenant::run('other', fn () => $reach->exists($theirs->id)));

        // A workspace without a tenant belongs to all, and so does a request with none.
        $this->assertTrue(Tenant::run('acme', fn () => $reach->exists($shared->id)));
        $this->assertTrue($reach->exists($theirs->id));
    }
}
