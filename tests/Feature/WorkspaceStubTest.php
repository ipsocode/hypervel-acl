<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Feature;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Ipsocode\Acl\Models\AclWorkspace;
use Ipsocode\Acl\Support\Workspaces;
use Ipsocode\Acl\Tests\Fixtures\UuidRole;
use Ipsocode\Acl\Tests\TestCase;
use RuntimeException;

class WorkspaceStubTest extends TestCase
{
    protected function stub(): object
    {
        return require __DIR__ . '/../../database/migrations/add_workspace_column.php.stub';
    }

    /**
     * Get the type name the database reports for a column.
     */
    protected function columnTypeName(string $table, string $column): string
    {
        foreach (Schema::getColumns($table) as $definition) {
            if ($definition['name'] === $column) {
                return $definition['type_name'];
            }
        }

        $this->fail("No column {$column}.");
    }

    /**
     * Get the columns of the primary key of a table.
     *
     * @return list<string>
     */
    protected function primaryOf(string $table): array
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['primary']) {
                return $index['columns'];
            }
        }

        return [];
    }

    /**
     * Determine whether a table has an index of exactly the columns.
     *
     * @param list<string> $columns
     */
    protected function hasIndex(string $table, array $columns, bool $unique = false): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['columns'] === $columns && (! $unique || $index['unique'])) {
                return true;
            }
        }

        return false;
    }

    public function testTheStubDoesNothingWithWorkspacesOff(): void
    {
        $this->stub()->up();

        $this->assertFalse(Schema::hasColumn('roles', 'workspace_id'));
    }

    public function testTheStubPartitionsThePermissionTablesOnce(): void
    {
        config(['acl.workspace' => true, 'acl.column_names.workspace_foreign_key' => 'workspace_id']);

        $team = config('permission.column_names.team_foreign_key');

        $this->stub()->up();

        foreach (['permissions', 'roles', 'role_has_permissions', 'model_has_permissions', 'model_has_roles'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'workspace_id'), $table);
        }

        $this->assertTrue($this->hasIndex('permissions', ['workspace_id', 'name', 'guard_name'], unique: true));
        $this->assertFalse($this->hasIndex('permissions', ['name', 'guard_name']));
        $this->assertTrue($this->hasIndex('roles', ['workspace_id', $team, 'name', 'guard_name'], unique: true));
        $this->assertFalse($this->hasIndex('roles', [$team, 'name', 'guard_name']));
        $this->assertSame(['workspace_id', 'permission_id', 'role_id'], $this->primaryOf('role_has_permissions'));
        $this->assertSame(['workspace_id', $team, 'permission_id', 'model_id', 'model_type'], $this->primaryOf('model_has_permissions'));
        $this->assertSame(['workspace_id', $team, 'role_id', 'model_id', 'model_type'], $this->primaryOf('model_has_roles'));
        $this->assertTrue($this->hasIndex('model_has_roles', ['workspace_id', 'model_type', 'model_id']));

        // Run again, the tables are left as they are.
        $this->stub()->up();
        $this->stub()->down();

        $this->assertSame(['workspace_id', $team, 'role_id', 'model_id', 'model_type'], $this->primaryOf('model_has_roles'));
    }

    public function testTheStubKeepsExistingRowsInTheHomeWorkspace(): void
    {
        config(['acl.workspace' => true, 'acl.column_names.workspace_foreign_key' => 'workspace_id']);

        $id = \Hypervel\Support\Facades\DB::table('permissions')->insertGetId(['name' => 'articles.view', 'guard_name' => 'web']);

        $this->stub()->up();

        $this->assertEquals(0, \Hypervel\Support\Facades\DB::table('permissions')->where('id', $id)->value('workspace_id'));
    }

    public function testPartitionColumnsFollowAUuidRoleModelsKeys(): void
    {
        config([
            'acl.workspace' => true,
            'acl.models.workspace' => AclWorkspace::class,
            'acl.key_type' => null,
            'permission.models.role' => UuidRole::class,
            'acl.column_names.workspace_foreign_key' => 'workspace_id',
        ]);

        $this->assertFalse(Workspaces::usesIdKeys());

        Schema::create('uuid_partitioned', function (Blueprint $table): void {
            $table->id();
            Workspaces::addColumn($table);
        });

        $this->assertStringNotContainsString('int', strtolower($this->columnTypeName('uuid_partitioned', 'workspace_id')));
    }

    public function testTheStubNeedsTheWorkspaceColumnNamed(): void
    {
        config(['acl.workspace' => true, 'acl.column_names.workspace_foreign_key' => null]);

        $this->expectException(RuntimeException::class);

        $this->stub()->up();
    }
}
