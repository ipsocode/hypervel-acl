<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Permission\Guard;
use Hypervel\Support\Facades\Schema;
use Ipsocode\Acl\Support\KeyType;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Support\Workspaces;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * With workspaces on, the org roles of the users: a user holds a team in a workspace, and its
     * roles apply there and beneath. This replaces the team membership table, which holds one
     * team per user; the chain rule between a user's rows is enforced before a row is written.
     */
    public function up(): void
    {
        $teamModel = Teams::model();
        $team = new $teamModel;
        $userModel = Guard::getModelForGuard(config()->array('acl.guards')[0]);
        $tenant = Teams::tenantColumn();
        $pivotTeam = Teams::teamPivotKey();
        $pivotUser = Teams::userPivotKey();
        $workspace = Workspaces::column();

        $user = new $userModel;

        Schema::create(Teams::WORKSPACE_MEMBERS_TABLE, function (Blueprint $table) use ($teamModel, $team, $user, $userModel, $tenant, $pivotTeam, $pivotUser, $workspace): void {
            (Teams::usesPackageModel() ? KeyType::resolve() : KeyType::fromModel($teamModel))->reference($table, $pivotTeam);
            KeyType::fromModel($userModel)->reference($table, $pivotUser);

            // The home workspace is no row, so there is no table to reference.
            Workspaces::addColumn($table);

            if ($tenant !== null) {
                $table->string($tenant)->nullable();
            }

            $table->timestamps(3);

            $table->foreign($pivotTeam)
                ->references($team->getKeyName())
                ->on($team->getTable())
                ->cascadeOnDelete();

            $table->foreign($pivotUser)
                ->references($user->getKeyName())
                ->on($user->getTable())
                ->cascadeOnDelete();

            $table->primary([$pivotTeam, $workspace, $pivotUser], Teams::WORKSPACE_MEMBERS_TABLE . '_primary');
            $table->index([$pivotUser, $workspace]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(Teams::WORKSPACE_MEMBERS_TABLE);
    }
};
