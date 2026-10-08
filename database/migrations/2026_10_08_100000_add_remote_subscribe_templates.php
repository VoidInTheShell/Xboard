<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_subscribe_templates', function (Blueprint $table) {
            $table->text('remote_url')->nullable();
            $table->boolean('auto_update')->default(false);
            $table->unsignedInteger('interval_hours')->default(24);
            $table->unsignedBigInteger('revision')->default(1);
            $table->unsignedBigInteger('current_history_id')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_updated_at')->nullable();
            $table->timestamp('next_update_at')->nullable()->index();
            $table->text('last_error')->nullable();
        });
        Schema::create('v2_subscribe_template_history', function (Blueprint $table) {
            $table->id();
            $table->string('name', 32)->index();
            $table->mediumText('content');
            $table->string('source', 16);
            $table->unsignedInteger('bytes');
            $table->timestamp('created_at');
        });

        // Preserve the effective pre-upgrade template without changing its raw value.
        foreach (['singbox', 'clash', 'clashmeta', 'stash', 'surge', 'surfboard'] as $name) {
            DB::table('v2_subscribe_templates')->insertOrIgnore([
                'name' => $name, 'content' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $raw = DB::table('v2_subscribe_templates')->where('name', $name)->value('content');
            $content = is_string($raw) && trim($raw) !== ''
                ? $raw : (\App\Models\SubscribeTemplate::defaultContent($name) ?? '');
            $id = DB::table('v2_subscribe_template_history')->insertGetId([
                'name' => $name, 'content' => $content, 'source' => 'manual',
                'bytes' => strlen($content), 'created_at' => now(),
            ]);
            DB::table('v2_subscribe_templates')->where('name', $name)->update(['current_history_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_subscribe_template_history');
        Schema::table('v2_subscribe_templates', function (Blueprint $table) {
            $table->dropIndex(['next_update_at']);
            $table->dropColumn(['remote_url', 'auto_update', 'interval_hours', 'revision', 'current_history_id',
                'last_checked_at', 'last_updated_at', 'next_update_at', 'last_error']);
        });
    }
};
