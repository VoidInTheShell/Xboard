<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_usage_ip_counter', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('node_id');
            $t->string('resource', 64);
            $t->unsignedBigInteger('up');
            $t->unsignedBigInteger('down');
            $t->unsignedBigInteger('sampled_at');
            $t->unique(['node_id', 'resource']);
            $t->index('sampled_at');
        });
        Schema::create('v2_usage_ip_traffic', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('ip', 45);
            $t->unsignedTinyInteger('family');
            $t->unsignedBigInteger('node_id');
            $t->unsignedBigInteger('machine_id')->default(0);
            $t->unsignedBigInteger('bucket');
            $t->unsignedBigInteger('up')->default(0);
            $t->unsignedBigInteger('down')->default(0);
            $t->unsignedInteger('measured')->default(0);
            $t->unsignedInteger('missing')->default(0);
            $t->unsignedBigInteger('first_seen');
            $t->unsignedBigInteger('last_seen');
            $t->unique(['user_id', 'ip', 'node_id', 'machine_id', 'bucket'], 'usage_ip_bucket_unique');
            $t->index(['user_id', 'bucket']);
            $t->index(['node_id', 'bucket']);
            $t->index(['machine_id', 'bucket']);
            $t->index(['ip', 'bucket']);
            $t->index('bucket');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('v2_usage_ip_traffic');
        Schema::dropIfExists('v2_usage_ip_counter');
    }
};
