<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('v2_server', 'fallback_site')) {
            Schema::table('v2_server', function (Blueprint $table) {
                $table->json('fallback_site')->nullable()->after('cert_config');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('v2_server', 'fallback_site')) {
            Schema::table('v2_server', fn (Blueprint $table) => $table->dropColumn('fallback_site'));
        }
    }
};
