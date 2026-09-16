<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('v2_notice', 'popup')) {
            Schema::table('v2_notice', function (Blueprint $table) {
                $table->boolean('popup')->default(false)->after('show');
            });
        }

        Schema::table('v2_notice', function (Blueprint $table) {
            $table->boolean('pinned')->default(false)->after('show');
            $table->boolean('require_ack')->default(false)->after('pinned');
            $table->unsignedInteger('revision')->default(1)->after('require_ack');
        });

        Schema::create('v2_notice_acknowledgement', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('notice_id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('notice_revision');
            $table->unsignedInteger('acknowledged_at');
            $table->unique(['notice_id', 'user_id', 'notice_revision'], 'notice_ack_user_revision_unique');
            $table->index(['user_id', 'notice_id'], 'notice_ack_user_notice_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_notice_acknowledgement');

        Schema::table('v2_notice', function (Blueprint $table) {
            $table->dropColumn(['pinned', 'require_ack', 'revision']);
        });
    }
};
