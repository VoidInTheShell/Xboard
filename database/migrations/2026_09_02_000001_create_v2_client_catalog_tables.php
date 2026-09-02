<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_client_app', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 96)->unique();
            $table->string('name', 80);
            $table->text('description');
            $table->string('logo_mode', 16)->default('url');
            $table->text('logo_url')->nullable();
            $table->string('logo_path')->nullable();
            $table->json('tags')->nullable();
            $table->text('download_url');
            $table->text('docs_url')->nullable();
            $table->boolean('quick_import_enabled')->default(false);
            $table->text('quick_import_url')->nullable();
            $table->string('subscription_template', 32);
            $table->boolean('is_builtin')->default(false);
            $table->timestamps();
        });

        Schema::create('v2_client_app_scope', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_app_id');
            $table->string('device_type', 16);
            $table->string('platform', 32);
            $table->unsignedInteger('sort_order')->default(10);
            $table->timestamps();

            $table->foreign('client_app_id')
                ->references('id')
                ->on('v2_client_app')
                ->cascadeOnDelete();
            $table->unique(['client_app_id', 'device_type', 'platform'], 'client_app_scope_unique');
            $table->index(['device_type', 'platform', 'sort_order'], 'client_app_scope_sort_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_client_app_scope');
        Schema::dropIfExists('v2_client_app');
    }
};
