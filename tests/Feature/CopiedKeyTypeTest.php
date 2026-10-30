<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Feature;

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Support\Str;
use Ipsocode\Acl\Models\AclTeam;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Tests\Fixtures\UlidRole;
use Ipsocode\Acl\Tests\Fixtures\UuidRole;
use Ipsocode\Acl\Tests\TestCase;
use Workbench\App\Models\User;

class CopiedKeyTypeTest extends TestCase
{
    public function testTablesCopyAUuidPermissionsTable(): void
    {
        $this->recreateTablesCopying('uuid_permissions', fn (Blueprint $table) => $table->uuid('id')->primary(), UuidRole::class);

        $team = AclTeam::create(['name' => 'Ops']);
        $inheriting = AclTeam::create(['name' => 'Sales', 'inherit_id' => $team->id]);
        $user = User::factory()->create();

        $team->addUser($user);

        $this->assertTrue(Str::isUuid($team->id));
        $this->assertSame($team->id, $inheriting->inherit_id);
        $this->assertSame($team->id, DB::table(Teams::ORGANIZATIONS_TABLE)->where('user_id', $user->id)->value('team_id'));
    }

    public function testTablesCopyTheRoleModelWhenThePermissionsTableIsMissing(): void
    {
        $this->recreateTablesCopying('ulid_permissions_not_created', null, UlidRole::class);

        $team = AclTeam::create(['name' => 'Ops']);
        $user = User::factory()->create();

        $team->addUser($user);

        $this->assertTrue(Str::isUlid($team->id));
        $this->assertSame($team->id, DB::table(Teams::ORGANIZATIONS_TABLE)->where('user_id', $user->id)->value('team_id'));
    }

    /**
     * Run the package's table migrations again, with no acl.key_type, against a permissions table.
     */
    private function recreateTablesCopying(string $permissions, ?callable $key, string $role): void
    {
        if ($key !== null) {
            Schema::create($permissions, function (Blueprint $table) use ($key): void {
                $key($table);
                $table->string('name');
            });
        }

        config([
            'acl.key_type' => null,
            'permission.table_names.permissions' => $permissions,
            'permission.models.role' => $role,
        ]);

        Schema::dropIfExists(Teams::ORGANIZATIONS_TABLE);
        Schema::dropIfExists((new AclTeam)->getTable());

        foreach (['000002_create_acl_teams_table', '000003_create_acl_organizations_table'] as $migration) {
            /** @var Migration $instance */
            $instance = require dirname(__DIR__, 2) . "/database/migrations/2026_10_29_{$migration}.php";
            $instance->up();
        }
    }
}
