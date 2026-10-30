<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Permission\Guard;
use Hypervel\Support\Facades\Schema;
use Ipsocode\Acl\Support\KeyType;
use Ipsocode\Acl\Support\Teams;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * A team's members. updated_at is when the team, or a team it inherits, last changed its
     * roles: a member whose session was synced before then is rebuilt on their next request.
     */
    public function up(): void
    {
        $teamModel = Teams::model();
        $team = new $teamModel;
        $userModel = Guard::getModelForGuard(config()->array('acl.guards')[0]);
        $tenant = Teams::tenantColumn();
        $pivotTeam = Teams::teamPivotKey();
        $pivotUser = Teams::userPivotKey();

        $user = new $userModel;

        Schema::create(Teams::MEMBERS_TABLE, function (Blueprint $table) use ($teamModel, $team, $user, $userModel, $tenant, $pivotTeam, $pivotUser): void {
            (Teams::usesPackageModel() ? KeyType::resolve() : KeyType::fromModel($teamModel))->reference($table, $pivotTeam);
            KeyType::fromModel($userModel)->reference($table, $pivotUser);

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

            $table->primary([$pivotTeam, $pivotUser], Teams::MEMBERS_TABLE . '_team_user_primary');

            // One team per user in each tenant; the database holds the rule too.
            $table->unique($tenant === null ? [$pivotUser] : [$tenant, $pivotUser]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(Teams::MEMBERS_TABLE);
    }
};
