<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('v2_client_app', 'is_enabled')) {
            Schema::table('v2_client_app', function (Blueprint $table) {
                $table->boolean('is_enabled')->default(true);
            });
        }

        if (!Schema::hasTable('v2_client_app_recommendation')) {
            Schema::create('v2_client_app_recommendation', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('client_app_scope_id');
                $table->string('device_type', 16);
                $table->string('platform', 32);
                $table->boolean('is_manual')->default(false);
                $table->timestamps();

                $table->foreign('client_app_scope_id', 'client_recommendation_scope_fk')
                    ->references('id')
                    ->on('v2_client_app_scope')
                    ->cascadeOnDelete();
                $table->unique(['device_type', 'platform'], 'client_recommendation_platform_unique');
                $table->index('client_app_scope_id', 'client_recommendation_scope_index');
            });
        }

        $this->backfillRecommendations();
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_client_app_recommendation');

        if (Schema::hasColumn('v2_client_app', 'is_enabled')) {
            Schema::table('v2_client_app', function (Blueprint $table) {
                $table->dropColumn('is_enabled');
            });
        }
    }

    private function backfillRecommendations(): void
    {
        if (!Schema::hasTable('v2_client_app_recommendation')) {
            return;
        }

        $scopes = DB::table('v2_client_app_scope as scope')
            ->join('v2_client_app as client', 'client.id', '=', 'scope.client_app_id')
            ->where('client.is_enabled', true)
            ->orderBy('scope.device_type')
            ->orderBy('scope.platform')
            ->orderBy('scope.sort_order')
            ->orderBy('scope.id')
            ->select([
                'scope.id',
                'scope.device_type',
                'scope.platform',
            ])
            ->get();

        $seen = [];
        foreach ($scopes as $scope) {
            $key = $scope->device_type . ':' . $scope->platform;
            if (isset($seen[$key])) {
                continue;
            }

            DB::table('v2_client_app_recommendation')->insertOrIgnore([
                'client_app_scope_id' => $scope->id,
                'device_type' => $scope->device_type,
                'platform' => $scope->platform,
                'is_manual' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $seen[$key] = true;
        }
    }
};
