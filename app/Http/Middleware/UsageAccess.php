<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Usage\UsageAccessService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UsageAccess
{
    public function handle(Request $request, Closure $next, string $kind)
    {
        try {
            $response = $next($request);
        } catch (\Throwable $error) {
            // Authentication validation commonly throws before producing a
            // response. Record its failure without swallowing the exception.
            $this->recordFailure($request, $kind);
            throw $error;
        }
        if (!\App\Services\Usage\UsageSettings::get('enabled')) return $response;
        if ($kind === 'login' && $response->getStatusCode() < 400) return $response;
        $user = $request->user() ?? $request->user('sanctum');
        if ($kind === 'login' && $request->filled('email')) {
            $user = User::query()->where('email', $request->input('email'))->first(['id']);
        }
        if ($user) {
            try {
                app(UsageAccessService::class)->record($request, $user->id,
                    $kind === 'login' ? 'panel' : 'subscription',
                    $kind === 'login' ? '登录' : '拉取订阅',
                    $response->getStatusCode() < 400 ? '成功' : '失败');
            } catch (\Throwable) {
                // Observability must not break authentication or subscription delivery.
                Log::warning('Usage access recording failed', ['kind' => $kind]);
            }
        }
        return $response;
    }

    private function recordFailure(Request $request, string $kind): void
    {
        if (!\App\Services\Usage\UsageSettings::get('enabled')) return;
        try {
            $user = $request->user() ?? $request->user('sanctum');
            if ($kind === 'login' && is_string($request->input('email'))) $user = User::query()->where('email', mb_substr($request->input('email'), 0, 254))->first(['id']);
            if ($user) app(UsageAccessService::class)->record($request, $user->id,
                $kind === 'login' ? 'panel' : 'subscription', $kind === 'login' ? '登录' : '拉取订阅', '失败');
        } catch (\Throwable) { Log::warning('Usage failed access recording failed', ['kind' => $kind]); }
    }
}
