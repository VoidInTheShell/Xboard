<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('v2_usage_ip_traffic', fn(Blueprint $t) => $t->unsignedInteger('gaps')->default(0)); }
    public function down(): void { Schema::table('v2_usage_ip_traffic', fn(Blueprint $t) => $t->dropColumn('gaps')); }
};
