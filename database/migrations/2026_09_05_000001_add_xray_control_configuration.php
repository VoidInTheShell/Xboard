<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            $table->json('xray_config')->nullable();
            $table->json('outbound_bindings')->nullable();
            $table->unsignedBigInteger('config_revision')->default(0);
            $table->json('xray_apply')->nullable();
        });
        Schema::table('v2_server_machine', function (Blueprint $table) {
            $table->json('xray_config')->nullable();
        });
        Schema::create('v2_outbound', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('config');
            $table->boolean('enabled')->default(true);
            $table->string('source_type', 32)->default('manual');
            $table->unsignedBigInteger('source_node_id')->nullable();
            $table->string('resolution_mode', 16)->default('pinned');
            // Service credentials are encrypted by the Eloquent model.  A
            // text column is intentional: Laravel's encrypted cast produces
            // ciphertext, not JSON, and must never be put in a JSON column.
            $table->text('service_credential')->nullable();
            $table->json('source_snapshot')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_node_id']);
            $table->foreign('source_node_id')->references('id')->on('v2_server')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_outbound');
        Schema::table('v2_server', fn (Blueprint $table) => $table->dropColumn(['xray_config', 'outbound_bindings', 'config_revision', 'xray_apply']));
        Schema::table('v2_server_machine', fn (Blueprint $table) => $table->dropColumn('xray_config'));
    }
};
