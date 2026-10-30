<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Feature;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Tests\WorkspaceTestCase;

class WorkspaceTeamIndexTest extends WorkspaceTestCase
{
    protected function stub(): object
    {
        return require __DIR__ . '/../../database/migrations/add_team_index.php.stub';
    }

    public function testANewInstallIndexesTheTeamColumn(): void
    {
        $this->assertTrue(Schema::hasIndex(Teams::ORGANIZATIONS_TABLE, [Teams::teamPivotKey()]));
    }

    public function testTheStubAddsTheIndexToAnUpgradedTableOnce(): void
    {
        $team = Teams::teamPivotKey();

        Schema::table(Teams::ORGANIZATIONS_TABLE, fn (Blueprint $table) => $table->dropIndex([$team]));
        $this->assertFalse(Schema::hasIndex(Teams::ORGANIZATIONS_TABLE, [$team]));

        $this->stub()->up();
        $this->assertTrue(Schema::hasIndex(Teams::ORGANIZATIONS_TABLE, [$team]));

        // A second run finds it and changes nothing.
        $this->stub()->up();
        $this->assertTrue(Schema::hasIndex(Teams::ORGANIZATIONS_TABLE, [$team]));

        $this->stub()->down();
        $this->assertTrue(Schema::hasIndex(Teams::ORGANIZATIONS_TABLE, [$team]));
    }

    public function testTheStubSkipsAMissingTable(): void
    {
        Schema::drop(Teams::ORGANIZATIONS_TABLE);

        $this->stub()->up();

        $this->assertFalse(Schema::hasTable(Teams::ORGANIZATIONS_TABLE));
    }
}
