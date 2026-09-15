<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_update_executor', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('kind', 16);
            // One executor per physical host serializes all of its installation instances.
            $table->string('scope')->unique();
            $table->unsignedBigInteger('machine_id')->nullable();
            $table->string('secret_hash', 64);
            $table->boolean('enabled')->default(true);
            $table->boolean('blocked')->default(false);
            $table->string('architecture', 32)->nullable();
            $table->unsignedInteger('protocol')->default(1);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
        Schema::create('v2_update_instance', function (Blueprint $table) {
            $table->id();
            $table->uuid('executor_id')->index();
            $table->string('instance_id', 80);
            $table->string('component', 32);
            $table->string('name');
            $table->string('version', 100)->nullable();
            $table->string('installation_method', 16);
            $table->boolean('ready')->default(false);
            $table->string('reason')->nullable();
            $table->json('capabilities')->nullable();
            $table->timestamps();
            $table->unique(['executor_id', 'instance_id']);
        });
        Schema::create('v2_update_task', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('executor_id')->index();
            $table->unsignedBigInteger('instance_record_id');
            $table->string('instance_id', 80);
            $table->string('component', 32);
            $table->string('target_name');
            $table->string('target_version', 100);
            $table->string('channel', 16);
            $table->string('status', 32)->default('queued');
            $table->string('idempotency_key', 160)->unique();
            $table->unsignedBigInteger('created_by');
            $table->json('manifest');
            $table->json('previous_versions');
            $table->string('claim_token', 64)->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->text('message')->nullable();
            $table->json('result')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['executor_id', 'status']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('v2_update_task');
        Schema::dropIfExists('v2_update_instance');
        Schema::dropIfExists('v2_update_executor');
    }
};
