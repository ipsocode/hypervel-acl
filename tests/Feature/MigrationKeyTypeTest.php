<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Feature;

use Hypervel\Support\Facades\Schema;
use Ipsocode\Acl\Models\AclTeam;
use Ipsocode\Acl\Support\KeyType;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class MigrationKeyTypeTest extends TestCase
{
    #[DataProvider('keyTypes')]
    public function testTeamAndOrganizationKeyColumnsShareOneType(string $type): void
    {
        $teams = (new AclTeam)->getTable();
        $pivotTeam = Teams::teamPivotKey();
        $pivotUser = Teams::userPivotKey();

        Schema::dropIfExists(Teams::ORGANIZATIONS_TABLE);
        Schema::dropIfExists($teams);
        config(['acl.key_type' => $type]);

        foreach (['2026_10_29_000002_create_acl_teams_table', '2026_10_29_000003_create_acl_organizations_table'] as $migration) {
            (require dirname(__DIR__, 2) . "/database/migrations/{$migration}.php")->up();
        }

        $columnType = static function (string $table, string $column): string {
            foreach (Schema::getColumns($table) as $definition) {
                if ($definition['name'] === $column) {
                    return $definition['type'];
                }
            }

            return '';
        };

        $expected = $columnType($teams, 'id');

        $this->assertNotSame('', $expected);
        $this->assertSame($expected, $columnType(Teams::ORGANIZATIONS_TABLE, $pivotTeam));
        $this->assertSame($expected, $columnType(Teams::ORGANIZATIONS_TABLE, $pivotUser));
        $this->assertSame($type === 'id', $expected === $columnType(config()->string('permission.table_names.model_has_roles'), config()->string('permission.column_names.model_morph_key')));
        $this->assertSame(KeyType::from($type), KeyType::forMigration());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function keyTypes(): array
    {
        return ['id' => ['id'], 'uuid' => ['uuid'], 'ulid' => ['ulid']];
    }

    public function testShippedConfigCopiesThePermissionTables(): void
    {
        $this->assertNull((require dirname(__DIR__, 2) . '/config/acl.php')['key_type']);
    }
}
