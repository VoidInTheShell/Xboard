<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_admin_audit_log', function (Blueprint $t) {
            $t->unsignedSmallInteger('status_code')->default(200)->index();
        });
        Schema::create('v2_log_daily', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('bucket')->index();
            $t->string('layer',16); $t->unsignedBigInteger('user_id')->default(0);
            $t->unsignedBigInteger('node_id')->default(0); $t->unsignedBigInteger('machine_id')->default(0);
            $t->unsignedBigInteger('up')->default(0); $t->unsignedBigInteger('down')->default(0);
            $t->unsignedBigInteger('billed_up')->default(0); $t->unsignedBigInteger('billed_down')->default(0);
            $t->unique(['bucket','layer','user_id','node_id','machine_id'],'log_daily_scope');
        });
        Schema::create('v2_log_archive', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('bucket')->index();
            $t->unsignedInteger('rows'); $t->unsignedInteger('raw_bytes');
            $t->longText('payload'); // base64 gzip, portable across supported DBs
            $t->unsignedBigInteger('created_at');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('v2_log_archive'); Schema::dropIfExists('v2_log_daily');
        Schema::table('v2_admin_audit_log',fn(Blueprint $t)=>$t->dropColumn('status_code'));
    }
};
