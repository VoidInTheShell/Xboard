<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_server_certificate', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('machine_id');
            $table->string('name');
            $table->string('source_type', 32);
            $table->json('domains');
            $table->boolean('auto_renew')->default(true);
            $table->unsignedBigInteger('revision')->default(1);
            $table->string('email')->nullable();
            $table->string('dns_provider')->nullable();
            $table->text('dns_credentials')->nullable();
            $table->text('certificate_path')->nullable();
            $table->text('private_key_path')->nullable();
            $table->longText('certificate_content')->nullable();
            $table->longText('private_key_content')->nullable();
            $table->string('status', 32)->default('pending');
            $table->timestamp('not_before_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('fingerprint', 128)->nullable();
            $table->timestamp('last_renewed_at')->nullable();
            $table->timestamp('next_renewal_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->foreign('machine_id')
                ->references('id')
                ->on('v2_server_machine')
                ->cascadeOnDelete();
            $table->index(['machine_id', 'status']);
        });

        Schema::create('v2_server_certificate_binding', function (Blueprint $table) {
            $table->id();
            $table->uuid('certificate_id');
            $table->unsignedBigInteger('server_id');
            $table->string('target_type', 48)->default('managed_inbound');
            $table->string('target_id', 128);
            $table->string('target_name')->nullable();
            $table->string('instance_name')->nullable();
            $table->string('protocol', 64)->nullable();
            $table->string('usage', 24)->default('server');
            $table->timestamps();

            $table->foreign('certificate_id')
                ->references('id')
                ->on('v2_server_certificate')
                ->cascadeOnDelete();
            $table->foreign('server_id')
                ->references('id')
                ->on('v2_server')
                ->cascadeOnDelete();
            $table->unique(
                ['certificate_id', 'server_id', 'target_type', 'target_id', 'usage'],
                'v2_server_cert_binding_unique'
            );
            $table->index(['server_id', 'usage']);
        });

        Schema::table('v2_server', function (Blueprint $table) {
            $table->uuid('certificate_id')->nullable()->after('machine_id');
            $table->string('certificate_ref_mode', 32)->nullable()->after('certificate_id');
            $table->foreign('certificate_id')
                ->references('id')
                ->on('v2_server_certificate')
                ->nullOnDelete();
            $table->index(['machine_id', 'certificate_id']);
        });
    }

    public function down(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            $table->dropForeign(['certificate_id']);
            $table->dropIndex(['machine_id', 'certificate_id']);
            $table->dropColumn(['certificate_id', 'certificate_ref_mode']);
        });

        Schema::dropIfExists('v2_server_certificate_binding');
        Schema::dropIfExists('v2_server_certificate');
    }
};
