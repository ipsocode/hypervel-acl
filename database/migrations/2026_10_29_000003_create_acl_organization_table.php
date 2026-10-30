<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Permission\Guard;
use Hypervel\Support\Facades\Schema;
use Ipsocode\Acl\Support\Config;
use Ipsocode\Acl\Support\KeyType;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Support\Workspaces;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * The org roles: each row is a user holding a team, in a workspace when workspaces are on,
     * else in the home workspace. updated_at is when the team, or a team it inherits, last
     * changed its roles: a member whose session was synced before then is rebuilt on their next
     * request. With workspaces on, a user may hold several rows; the rule between them is
     * enforced before a row is written.
     */
    public function up(): void
    {
        $teamModel = Teams::model();
        $userModel = Guard::getModelForGuard(Config::guards()[0]);
        $team = new $teamModel;
        $user = new $userModel;
        $tenant = Teams::tenantColumn();
        $pivotTeam = Teams::teamPivotKey();
        $pivotUser = Teams::userPivotKey();
        $workspaces = Workspaces::enabled();
        $workspace = $workspaces ? Workspaces::column() : null;

        Schema::create(Teams::ORGANIZATION_TABLE, function (Blueprint $table) use ($teamModel, $team, $user, $userModel, $tenant, $pivotTeam, $pivotUser, $workspaces, $workspace): void {
            (Teams::usesPackageModel() ? KeyType::resolve() : KeyType::fromModel($teamModel))->reference($table, $pivotTeam);
            KeyType::fromModel($userModel)->reference($table, $pivotUser);

            if ($workspaces) {
                // The home workspace is no row, so no foreign key; Reach deletes a workspace's rows with it.
                Workspaces::addColumn($table);
            }

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

            if ($workspaces) {
                // The workspace leads, as Reach finds the rows of a workspace.
                $table->primary([$workspace, $pivotTeam, $pivotUser], Teams::ORGANIZATION_TABLE . '_workspace_team_user_primary');
                $table->index([$pivotUser, $workspace]);
            } else {
                $table->primary([$pivotTeam, $pivotUser], Teams::ORGANIZATION_TABLE . '_team_user_primary');

                // One team per user in each tenant; the database holds the rule too.
                $table->unique($tenant === null ? [$pivotUser] : [$tenant, $pivotUser]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(Teams::ORGANIZATION_TABLE);
    }
};
