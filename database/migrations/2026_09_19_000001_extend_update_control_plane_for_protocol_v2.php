<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_update_executor', function (Blueprint $table) {
            $table->string('updater_version', 100)->nullable()->after('protocol');
            $table->unsignedInteger('state_schema')->default(1)->after('updater_version');
            $table->string('installation_method', 16)->nullable()->after('state_schema');
            $table->string('handoff_phase', 32)->nullable()->after('installation_method');
        });

        Schema::table('v2_update_task', function (Blueprint $table) {
            $table->string('target_updater_version', 100)->nullable()->after('target_version');
            $table->string('handoff_phase', 32)->nullable()->after('status');
            $table->string('recovery_step', 160)->nullable()->after('message');
        });

        Schema::create('v2_server_enrollment', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('machine_id')->index();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('machine_id')->references('id')->on('v2_server_machine')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_server_enrollment');

        Schema::table('v2_update_task', function (Blueprint $table) {
            $table->dropColumn(['target_updater_version', 'handoff_phase', 'recovery_step']);
        });

        Schema::table('v2_update_executor', function (Blueprint $table) {
            $table->dropColumn(['updater_version', 'state_schema', 'installation_method', 'handoff_phase']);
        });
    }
};
