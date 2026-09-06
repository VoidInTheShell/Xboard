<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_outbound', function (Blueprint $table) {
            $table->json('config_override')->nullable()->after('config');
        });
    }

    public function down(): void
    {
        Schema::table('v2_outbound', function (Blueprint $table) {
            $table->dropColumn('config_override');
        });
    }
};
