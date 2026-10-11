<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function (): void {
            $links = [
                'clash-party' => 'mihomo://install-config?url={url}&name={name}',
                'clash-verge' => 'clash-verge://install-config?name={name}&url={url}',
                'flclash' => 'flclash://install-config?url={url}',
                'clash-mi' => 'clashmi://install-config?url={url}&name={name}&overwrite=false',
            ];
            foreach ($links as $slug => $url) {
                DB::table('v2_client_app')->where('slug', $slug)
                    ->where('quick_import_url', 'clash://install-config?url={url}&name={name}')
                    ->update(['quick_import_url' => $url]);
            }

            foreach ([
                'hiddify' => 'hiddify://install-config?url={url}#{name}',
                'surfboard' => 'surfboard:///install-config?url={url}',
            ] as $slug => $url) {
                DB::table('v2_client_app')->where('slug', $slug)->where('is_builtin', true)
                    ->where('quick_import_enabled', false)->whereNull('quick_import_url')
                    ->update(['quick_import_enabled' => true, 'quick_import_url' => $url]);
            }

            DB::table('v2_client_app')->where('slug', 'quantumult-x')
                ->where('quick_import_url', 'quantumult-x:///update-resource?remote-resource={url}')
                ->update([
                    'quick_import_url' => 'quantumult-x:///add-resource?remote-resource={resource}',
                    'subscription_template' => 'quantumultx',
                ]);

            DB::table('v2_client_app')->where('slug', 'shadowrocket')
                ->where('quick_import_url', 'shadowrocket://add/sub://{base64url}')
                ->whereIn('subscription_template', ['general', 'clashmeta'])
                ->update(['subscription_template' => 'shadowrocket']);
        });
    }

    public function down(): void
    {
        // Data repair: do not restore broken links or overwrite later administrator edits.
    }
};
