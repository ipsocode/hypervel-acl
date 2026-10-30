<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Ipsocode\Acl\Models\AclWorkspace;
use Ipsocode\Acl\Support\KeyType;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Support\Workspaces;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * Creates the package's workspace table, a tree: a workspace is beneath at most one other.
     * The package loads this migration only when acl.models.workspace names the package's model.
     */
    public function up(): void
    {
        $name = (new AclWorkspace)->getTable();
        $tenant = Teams::tenantColumn();
        $parent = Workspaces::parentColumn();
        $key = KeyType::resolve();

        Schema::create($name, function (Blueprint $table) use ($name, $tenant, $parent, $key): void {
            $key->primary($table);

            if ($tenant !== null) {
                $table->string($tenant)->nullable();
            }

            // The parent is a row of this same table; a workspace with children cannot be deleted.
            $key->reference($table, $parent)->nullable()->constrained($name);

            $table->string('name');
            $table->string('kind')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique($tenant === null ? [$parent, 'name'] : [$tenant, $parent, 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists((new AclWorkspace)->getTable());
    }
};
