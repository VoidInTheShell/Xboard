<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_usage_online_scope_history', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->default(0);
            $t->unsignedBigInteger('node_id')->default(0);
            $t->unsignedBigInteger('machine_id')->default(0);
            $t->unsignedBigInteger('bucket');
            $t->unsignedInteger('users')->default(0);
            $t->unsignedInteger('devices')->default(0);
            $t->unique(['user_id', 'node_id', 'machine_id', 'bucket'], 'usage_online_scope_unique');
            $t->index(['user_id', 'bucket']);
            $t->index(['node_id', 'bucket']);
            $t->index(['machine_id', 'bucket']);
            $t->index('bucket');
        });
    }
    public function down(): void { Schema::dropIfExists('v2_usage_online_scope_history'); }
};
