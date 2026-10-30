<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Ipsocode\Acl\Models\AclTeam;
use Ipsocode\Acl\Support\KeyType;
use Ipsocode\Acl\Support\Teams;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * Creates the package's team table. The package loads this migration only when
     * acl.models.team is unset or names the package's team model.
     */
    public function up(): void
    {
        $name = (new AclTeam)->getTable();
        $tenant = Teams::tenantColumn();
        $inherited = config()->string('acl.column_names.teams_inherit_key');
        $key = KeyType::resolve();

        Schema::create($name, function (Blueprint $table) use ($name, $tenant, $inherited, $key): void {
            $key->primary($table);

            // A team without a tenant is shared by every tenant.
            if ($tenant !== null) {
                $table->string($tenant)->nullable();
            }

            // The inherited team is a row of this same table.
            $key->reference($table, $inherited)->nullable()->constrained($name);

            $table->string('name');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique($tenant === null ? ['name'] : [$tenant, 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists((new AclTeam)->getTable());
    }
};
