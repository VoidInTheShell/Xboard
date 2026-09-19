<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_subscribe_templates')) {
            return;
        }

        $clashPath = base_path('resources/rules/default.clash.yaml');
        $clashMetaPath = base_path('resources/rules/default.clashmeta.yaml');
        if (!File::isFile($clashPath) || !File::isFile($clashMetaPath)) {
            return;
        }

        $clash = File::get($clashPath);
        $clashMeta = File::get($clashMetaPath);
        $row = DB::table('v2_subscribe_templates')
            ->where('name', 'clashmeta')
            ->first(['id', 'content']);
        if (!$row) {
            return;
        }

        // Only repair rows produced by the old fallback.  A user-edited
        // Clash Meta template must never be overwritten during an upgrade.
        if (trim((string) $row->content) === '' || hash_equals(hash('sha256', $clash), hash('sha256', (string) $row->content))) {
            DB::table('v2_subscribe_templates')
                ->where('id', $row->id)
                ->update(['content' => $clashMeta, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Do not replace a possibly user-edited template on rollback.
    }
};
