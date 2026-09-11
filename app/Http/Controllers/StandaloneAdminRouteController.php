<?php

namespace App\Http\Controllers;

use App\Services\UpdateService;

class StandaloneAdminRouteController extends Controller
{
    public function entry()
    {
        return response($this->securePath(), 200, [
            'Cache-Control' => 'no-store, private',
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    public function original()
    {
        return response()
            ->view('admin', $this->adminViewData())
            ->header('Cache-Control', 'no-store, private');
    }

    private function securePath(): string
    {
        return (string) admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
        );
    }

    private function adminViewData(): array
    {
        return [
            'title' => admin_setting('app_name', 'XBoard'),
            'theme_sidebar' => admin_setting('frontend_theme_sidebar', 'light'),
            'theme_header' => admin_setting('frontend_theme_header', 'dark'),
            'theme_color' => admin_setting('frontend_theme_color', 'default'),
            'background_url' => admin_setting('frontend_background_url'),
            'version' => app(UpdateService::class)->getCurrentVersion(),
            'logo' => admin_setting('logo'),
            'secure_path' => $this->securePath(),
        ];
    }
}
