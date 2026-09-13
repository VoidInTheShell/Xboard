<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_usage_head', function (Blueprint $t) {
            $t->id();
            $t->string('scope', 16);
            $t->unsignedBigInteger('source_id');
            $t->string('epoch', 64)->default('');
            $t->unsignedBigInteger('sampled_at')->default(0);
            $t->unique(['scope', 'source_id']);
        });
        Schema::create('v2_usage_stream', function (Blueprint $t) {
            $t->id();
            $t->string('scope', 16);
            $t->unsignedBigInteger('source_id');
            $t->string('epoch', 64);
            $t->unsignedBigInteger('sequence')->default(0);
            $t->unsignedBigInteger('sampled_at');
            $t->unique(['scope', 'source_id', 'epoch']);
            $t->index('sampled_at');
        });
        Schema::create('v2_usage_counter', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('stream_id');
            $t->string('resource', 100);
            $t->unsignedBigInteger('up')->default(0);
            $t->unsignedBigInteger('down')->default(0);
            $t->unique(['stream_id', 'resource']);
        });
        Schema::create('v2_usage_traffic', function (Blueprint $t) {
            $t->id();
            $t->string('layer', 16);
            $t->unsignedBigInteger('machine_id')->default(0);
            $t->unsignedBigInteger('node_id')->default(0);
            $t->unsignedBigInteger('user_id')->default(0);
            $t->string('resource', 100)->default('');
            $t->unsignedBigInteger('bucket');
            $t->unsignedBigInteger('up')->default(0);
            $t->unsignedBigInteger('down')->default(0);
            $t->unsignedBigInteger('billed_up')->default(0);
            $t->unsignedBigInteger('billed_down')->default(0);
            $t->unique(['layer', 'node_id', 'user_id', 'machine_id', 'resource', 'bucket'], 'usage_traffic_bucket_unique');
            $t->index(['user_id', 'bucket']);
            $t->index(['node_id', 'bucket']);
            $t->index(['machine_id', 'bucket']);
            $t->index(['layer', 'bucket']);
        });
        Schema::create('v2_usage_identity', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('identity_hash', 64);
            $t->string('source', 16);
            $t->unsignedBigInteger('first_seen');
            $t->unsignedBigInteger('last_seen');
            $t->unique(['user_id', 'identity_hash']);
            $t->index(['first_seen', 'user_id']);
            $t->index('last_seen');
        });
        Schema::create('v2_usage_source', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('identity_id');
            $t->string('ip', 45);
            $t->unsignedBigInteger('first_seen');
            $t->unsignedBigInteger('last_seen');
            $t->unsignedBigInteger('first_node_id');
            $t->unique(['identity_id', 'ip']);
            $t->index(['user_id', 'first_seen']);
            $t->index('first_seen');
        });
        Schema::create('v2_usage_online', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('node_id');
            $t->unsignedBigInteger('machine_id')->default(0);
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('source_id');
            $t->string('platform', 32)->default('unknown');
            $t->unsignedBigInteger('up_speed')->nullable();
            $t->unsignedBigInteger('down_speed')->nullable();
            $t->unsignedBigInteger('sampled_at');
            $t->unique(['node_id', 'source_id']);
            $t->index(['user_id', 'sampled_at']);
            $t->index(['machine_id', 'sampled_at']);
            $t->index('sampled_at');
        });
        Schema::create('v2_usage_online_history', function (Blueprint $t) {
            $t->id();
            $t->string('scope', 16);
            $t->unsignedBigInteger('scope_id');
            $t->unsignedBigInteger('bucket');
            $t->unsignedInteger('users')->default(0);
            $t->unsignedInteger('devices')->default(0);
            $t->unique(['scope', 'scope_id', 'bucket']);
            $t->index('bucket');
        });
        Schema::create('v2_usage_event', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('kind', 16);
            $t->string('action', 24);
            $t->string('result', 16);
            $t->string('ip', 45)->default('');
            $t->string('platform', 32)->default('unknown');
            $t->string('user_agent', 512)->default('');
            $t->string('device_hash', 64)->nullable();
            $t->string('path', 128)->default('');
            $t->unsignedBigInteger('recorded_at');
            $t->index(['user_id', 'recorded_at', 'id']);
            $t->index(['kind', 'recorded_at', 'id']);
            $t->index('recorded_at');
        });
        Schema::create('v2_usage_review', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('actor_id');
            $t->string('signal', 100);
            $t->unsignedBigInteger('reviewed_at');
            $t->unique(['actor_id', 'signal']);
        });
        Schema::table('v2_server_machine', function (Blueprint $t) {
            $t->json('traffic_policy')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('v2_server_machine', fn(Blueprint $t) => $t->dropColumn('traffic_policy'));
        foreach (['review', 'event', 'online_history', 'online', 'source', 'identity', 'traffic', 'counter', 'stream', 'head'] as $name) {
            Schema::dropIfExists('v2_usage_' . $name);
        }
    }
};
