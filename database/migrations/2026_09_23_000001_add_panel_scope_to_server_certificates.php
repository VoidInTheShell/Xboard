<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_server_certificate', function (Blueprint $table) {
            $table->string('scope', 16)->default('machine')->after('machine_id');
        });

        // Existing rows are machine resources; panel certificates are created
        // with an explicit scope from now on.
        DB::table('v2_server_certificate')->whereNull('scope')->orWhere('scope', '')->update(['scope' => 'machine']);

        Schema::table('v2_server_certificate', function (Blueprint $table) {
            $table->unsignedBigInteger('machine_id')->nullable()->change();
            $table->index(['scope', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('v2_server_certificate', function (Blueprint $table) {
            $table->dropIndex(['scope', 'status']);
        });

        // Panel certificates would violate the NOT NULL constraint on the way
        // back; delete them instead of failing the whole rollback.
        DB::table('v2_server_certificate')->where('scope', 'panel')->delete();

        Schema::table('v2_server_certificate', function (Blueprint $table) {
            $table->unsignedBigInteger('machine_id')->nullable(false)->change();
            $table->dropColumn('scope');
        });
    }
};
