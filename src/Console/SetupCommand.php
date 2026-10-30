<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Console;

use Hypervel\Console\Command;
use Hypervel\Permission\Support\Config as PermissionConfig;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Facades\File;
use Hypervel\Support\Facades\Schema;
use Ipsocode\Acl\Console\Concerns\ChecksPermissionPackage;
use Ipsocode\Acl\Console\Concerns\FindsTeamMigrations;
use Ipsocode\Acl\Support\Config;
use Ipsocode\Acl\Support\Frontend;
use Ipsocode\Acl\Support\KeyType;

class SetupCommand extends Command
{
    use ChecksPermissionPackage;
    use FindsTeamMigrations;

    protected ?string $signature = 'acl:setup {--force : Overwrite the migrations already generated} {--pretend : List the migrations without writing them}';

    protected string $description = 'Generate the migrations the permission tables and teams need, and publish the bundled UI assets';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->ensurePermissionPackage(migrated: false)) {
            return self::FAILURE;
        }

        $force = $this->option('force');
        $pretend = $this->option('pretend');

        $teamKey = PermissionConfig::teamForeignKey();
        $roles = config('permission.table_names.roles');

        if (Schema::hasTable($roles) && ! Schema::hasColumn($roles, $teamKey)) {
            $this->error('The permission tables have no team columns: run permission:setup-teams and migrate first.');

            return self::FAILURE;
        }

        if (! $pretend) {
            $this->chooseKeyType();
        }

        // The bundled UI's assets are refreshed on every run: a package update changes them.
        if (! $pretend && Config::uiEnabled() && Config::uiFrontend() === Frontend::Bundled) {
            $this->call('vendor:publish', ['--tag' => 'acl-assets', '--force' => true]);
        }

        $stubs = $this->teamStubs();

        $existing = array_values(array_filter($stubs, fn (string $stub) => $this->alreadyExistingMigrations($stub) !== []));
        $pending = array_values(array_diff($stubs, $existing));
        $publish = ! collect($this->migrationNames())->contains(fn (string $name) => str_ends_with($name, '_create_permission_tables.php'));

        $this->line('');
        $this->info('The ACL setup is going to add the migrations the permission tables need');

        if ($existing && ! $force) {
            $this->line('');

            $this->warn("These migrations already exist and are skipped:\n - " . implode("\n - ", $existing));
        }

        $overwrite = $force ? $existing : [];

        if ($pending === [] && $overwrite === [] && ! $publish) {
            return self::SUCCESS;
        }

        $this->line('');

        if ($pretend) {
            $this->info('These migrations would be written:');

            foreach ([...($publish ? ['create_permission_tables'] : []), ...$pending, ...$overwrite] as $stub) {
                $this->line(' - ' . $stub . (in_array($stub, $overwrite, true) ? ' (overwritten)' : ''));
            }

            return self::SUCCESS;
        }

        // A script passes --force or --no-interaction and is never asked.
        if (! $force && $this->input->isInteractive() && ! $this->confirm('Proceed with the migration creation?', true)) {
            return self::SUCCESS;
        }

        // The permission tables come first: our migrations alter and reference them.
        if ($publish) {
            $this->call('vendor:publish', ['--tag' => 'permission-migrations']);
        }

        // Ours must sort after the permission migration, whatever clock published it.
        $time = collect($this->migrationNames())
            ->map(fn (string $name) => preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', $name, $match) ? Date::createFromFormat('Y_m_d_His', $match[1]) : null)
            ->filter()
            ->push(now())
            ->max();

        foreach ($pending as $stub) {
            $time = $time->addSecond();

            copy(__DIR__ . "/../../database/migrations/{$stub}.php.stub", $this->getMigrationPath($stub, $time->format('Y_m_d_His')));
        }

        foreach ($overwrite as $stub) {
            foreach ($this->alreadyExistingMigrations($stub) as $name) {
                copy(__DIR__ . "/../../database/migrations/{$stub}.php.stub", database_path("migrations/{$name}"));
            }
        }

        $this->info('Migrations created successfully.');

        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Ask which key type the package's tables use, when nothing decides it yet, and keep the answer in .env.
     *
     * The permission tables decide it once they exist, and a script is never asked.
     */
    protected function chooseKeyType(): void
    {
        if (config('acl.key_type') !== null || KeyType::fromPermissionsTable() !== null || ! $this->input->isInteractive()) {
            return;
        }

        /** @var string $type */
        $type = $this->choice(
            'Which key type should the package\'s tables use? The user model\'s key must be the same type as the morph key of the permission tables',
            array_column(KeyType::cases(), 'value'),
            KeyType::forMigration()->value,
        );

        $path = $this->hypervel->environmentFilePath();
        $contents = File::exists($path) ? File::get($path) : '';

        File::put($path, preg_match('/^ACL_KEY_TYPE=.*$/m', $contents) === 1
            ? preg_replace('/^ACL_KEY_TYPE=.*$/m', "ACL_KEY_TYPE={$type}", $contents)
            : $contents . ($contents === '' || str_ends_with($contents, "\n") ? '' : "\n") . "ACL_KEY_TYPE={$type}\n");

        config(['acl.key_type' => $type]);

        $this->info("ACL_KEY_TYPE={$type} was written to {$path}.");
    }

    /**
     * Get the migration path.
     */
    protected function getMigrationPath(string $stub, string $date): string
    {
        return database_path("migrations/{$date}_{$stub}.php");
    }
}
