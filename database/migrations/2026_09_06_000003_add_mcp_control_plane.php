<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_mcp_key', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->index();
            $table->string('name', 64);
            $table->char('token_hash', 64)->unique();
            $table->string('token_prefix', 16)->default('xbmcp_');
            $table->string('token_suffix', 12);
            $table->string('scope', 16)->default('full');
            $table->text('domains')->nullable();
            $table->string('client', 32)->nullable();
            $table->unsignedInteger('expires_at')->nullable()->index();
            $table->unsignedInteger('revoked_at')->nullable()->index();
            $table->unsignedInteger('last_used_at')->nullable();
            $table->string('last_ip', 128)->nullable();
            $table->string('last_client', 96)->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
        });

        Schema::create('v2_change_state', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('version')->default(0);
            $table->unsignedInteger('updated_at');
        });

        DB::table('v2_change_state')->insert([
            'id' => 1,
            'version' => 0,
            'updated_at' => time(),
        ]);

        Schema::create('v2_change_event', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('version')->unique();
            $table->string('domain', 32)->index();
            $table->string('resource', 96)->index();
            $table->string('resource_id', 128)->nullable();
            $table->string('action', 128);
            $table->string('actor_type', 16)->index();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('client_id', 64)->nullable()->index();
            $table->uuid('request_id')->index();
            $table->unsignedInteger('created_at')->index();
        });

        $addActorType = !Schema::hasColumn('v2_admin_audit_log', 'actor_type');
        $addMcpKeyId = !Schema::hasColumn('v2_admin_audit_log', 'mcp_key_id');
        $addRequestId = !Schema::hasColumn('v2_admin_audit_log', 'request_id');
        $addClientId = !Schema::hasColumn('v2_admin_audit_log', 'client_id');

        if ($addActorType || $addMcpKeyId || $addRequestId || $addClientId) {
            Schema::table('v2_admin_audit_log', function (Blueprint $table) use ($addActorType, $addMcpKeyId, $addRequestId, $addClientId) {
                if ($addActorType) {
                    $table->string('actor_type', 16)->default('admin')->index();
                }
                if ($addMcpKeyId) {
                    $table->unsignedBigInteger('mcp_key_id')->nullable()->index();
                }
                if ($addRequestId) {
                    $table->uuid('request_id')->nullable()->index();
                }
                if ($addClientId) {
                    $table->string('client_id', 64)->nullable()->index();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('v2_admin_audit_log')) {
            $columns = collect(['actor_type', 'mcp_key_id', 'request_id', 'client_id'])
                ->filter(fn(string $column) => Schema::hasColumn('v2_admin_audit_log', $column))
                ->values()
                ->all();
            if ($columns !== []) {
                Schema::table('v2_admin_audit_log', fn(Blueprint $table) => $table->dropColumn($columns));
            }
        }

        Schema::dropIfExists('v2_change_event');
        Schema::dropIfExists('v2_change_state');
        Schema::dropIfExists('v2_mcp_key');
    }
};
