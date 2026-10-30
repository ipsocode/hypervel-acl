<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Ipsocode\Acl\Support\Workspaces;

// The framework's permission tables as its partitioned schema lays them out, with teams.
return new class extends Migration {
    public function up(): void
    {
        $column = Workspaces::column();

        Schema::create('permissions', function (Blueprint $table) use ($column): void {
            Workspaces::addColumn($table);
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();

            $table->unique([$column, 'name', 'guard_name']);
        });

        Schema::create('roles', function (Blueprint $table) use ($column): void {
            Workspaces::addColumn($table);
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();

            $table->unique([$column, 'team_id', 'name', 'guard_name']);
        });

        Schema::create('model_has_permissions', function (Blueprint $table) use ($column): void {
            Workspaces::addColumn($table);
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->boolean('is_denied')->default(false);
            $table->unsignedBigInteger('team_id');

            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->primary([$column, 'team_id', 'permission_id', 'model_id', 'model_type']);
        });

        Schema::create('model_has_roles', function (Blueprint $table) use ($column): void {
            Workspaces::addColumn($table);
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->unsignedBigInteger('team_id');

            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->primary([$column, 'team_id', 'role_id', 'model_id', 'model_type']);
        });

        Schema::create('role_has_permissions', function (Blueprint $table) use ($column): void {
            Workspaces::addColumn($table);
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->boolean('is_denied')->default(false);

            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->primary([$column, 'permission_id', 'role_id']);
        });
    }
};
