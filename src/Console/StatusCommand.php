<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Console;

use Closure;
use Hypervel\Console\Command;
use Hypervel\Permission\Guard;
use Hypervel\Permission\Support\Config as PermissionConfig;
use Hypervel\Support\Facades\Schema;
use Ipsocode\Acl\Console\Concerns\ChecksPermissionPackage;
use Ipsocode\Acl\Console\Concerns\FindsTeamMigrations;
use Ipsocode\Acl\Support\Config;
use Ipsocode\Acl\Support\KeyType;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Support\Workspaces;

class StatusCommand extends Command
{
    use ChecksPermissionPackage;
    use FindsTeamMigrations;

    protected ?string $signature = 'acl:status {--only=* : Limit the report to a feature: tenant or workspace}';

    protected string $description = 'Show the state of the permission package, the enabled ACL features and the migrations';

    /**
     * Execute the console command.
     *
     * Each setting, migration and problem belongs to a section: the permission package's is always
     * reported, a feature's when the report is not limited or is limited to it, and the rest only
     * when it is not limited. The general problems are reported either way: the key type and the
     * users table decide the tables every feature's migrations build on.
     */
    public function handle(): int
    {
        /** @var list<string> $only */
        $only = $this->option('only');

        $features = array_map(fn (StatusSection $section) => $section->value, StatusSection::features());

        if (($unknown = array_diff($only, $features)) !== []) {
            $this->error('--only takes ' . implode(' or ', $features) . ', not ' . implode(', ', $unknown) . '.');

            return self::FAILURE;
        }

        $limited = array_map(StatusSection::from(...), $only);
        $shown = fn (array $row): bool => $row[0] === StatusSection::Package || $limited === [] || in_array($row[0], $limited, true);
        $problems = [];
        $active = ($problem = $this->permissionPackageProblem()) === null;

        if (! $active) {
            $problems[] = [StatusSection::Package, $problem];
        }

        if (Config::tenantEnabled() && in_array(Config::tenantForeignKey(), [null, ''], true)) {
            $problems[] = [StatusSection::Tenant, 'acl.tenant is on but acl.column_names.tenant_foreign_key names no column.'];
        }

        $configured = Config::keyType();
        $actual = KeyType::fromPermissionsTable();

        if ($configured !== null && $actual !== null && $actual->value !== $configured) {
            $problems[] = [StatusSection::General, "acl.key_type is {$configured} but the permissions table uses {$actual->value} keys: set it to null or to {$actual->value}."];
        }

        foreach ($this->userTableProblems() as $problem) {
            $problems[] = [StatusSection::General, $problem];
        }

        $this->table(['Setting', 'Value'], $this->rows($shown, [
            [StatusSection::Package, 'Permission package', $active ? 'active' : 'not active'],
            [StatusSection::General, 'Teams', $this->state(PermissionConfig::teamsEnabled())],
            [StatusSection::Tenant, 'Tenant', $this->state(Config::tenantEnabled())],
            [StatusSection::Workspace, 'Workspace', $this->state(Workspaces::enabled())],
            [StatusSection::General, 'Team model', Teams::model()],
            [StatusSection::Workspace, 'Workspace model', Workspaces::model() ?? '-'],
            [StatusSection::General, 'Key type', KeyType::forMigration()->value],
            [StatusSection::General, 'Guards', implode(', ', Config::guards())],
        ]));

        // The tenant column is unknown without a name, so its stub cannot be listed then.
        $stubs = Config::tenantEnabled() && Config::tenantForeignKey() === null
            ? []
            : $this->teamStubSections();

        $this->table(['Migration', 'State'], $this->rows($shown, [
            [StatusSection::General, 'create_permission_tables', $this->published('create_permission_tables')],
            ...array_map(fn (string $stub, StatusSection $section) => [$section, $stub, $this->published($stub)], array_keys($stubs), $stubs),
        ]));

        $problems = array_values(array_filter($problems, fn (array $problem): bool => $problem[0] === StatusSection::General || $shown($problem)));

        foreach ($problems as [, $problem]) {
            $this->error($problem);
        }

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Keep the rows of the shown sections, without their section.
     *
     * @param Closure(array{StatusSection, string}): bool $shown
     * @param list<array{StatusSection, string, string}|array{StatusSection, string}> $rows
     * @return list<array<int, string>>
     */
    protected function rows(Closure $shown, array $rows): array
    {
        return array_values(array_map(fn (array $row) => array_slice($row, 1), array_filter($rows, $shown)));
    }

    /**
     * Describe whether a feature is on.
     */
    protected function state(bool $enabled): string
    {
        return $enabled ? 'on' : 'off';
    }

    /**
     * Describe whether the migration for a stub is published or still pending.
     */
    protected function published(string $stub): string
    {
        return $this->alreadyExistingMigrations($stub) === [] ? 'pending' : 'published';
    }

    /**
     * Describe what is wrong with the users table the org roles table references.
     *
     * @return list<string>
     */
    protected function userTableProblems(): array
    {
        $guard = Config::guards()[0];

        if (($model = Guard::getModelForGuard($guard)) === null) {
            return ["The {$guard} guard has no user model: set the model of its auth provider."];
        }

        $user = new $model;

        if (! Schema::hasTable($user->getTable())) {
            return ["The users table {$user->getTable()} does not exist: the org roles table references it, so migrate it first."];
        }

        $column = KeyType::fromTable($user->getTable(), $user->getKeyName());
        $generated = KeyType::fromModel($model);

        if ($column === null || $column === $generated) {
            return [];
        }

        return ["The {$user->getTable()} table uses {$column->value} keys but {$model} generates {$generated->value} keys: the org roles table copies the model's, so give the model the matching HasUuids or HasUlids trait."];
    }
}
