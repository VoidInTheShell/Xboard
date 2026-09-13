<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('v2_usage_head', fn(Blueprint $t) => $t->boolean('devices_complete')->default(true)); }
    public function down(): void { Schema::table('v2_usage_head', fn(Blueprint $t) => $t->dropColumn('devices_complete')); }
};
