<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_server_certificate') || Schema::hasColumn('v2_server_certificate', 'revision')) {
            return;
        }

        Schema::table('v2_server_certificate', function (Blueprint $table): void {
            $table->unsignedBigInteger('revision')->default(1)->after('auto_renew');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('v2_server_certificate') && Schema::hasColumn('v2_server_certificate', 'revision')) {
            Schema::table('v2_server_certificate', function (Blueprint $table): void {
                $table->dropColumn('revision');
            });
        }
    }
};
