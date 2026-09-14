<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Services\FallbackSiteService;
use Illuminate\Http\Request;

class FallbackController extends Controller
{
    public function templates(FallbackSiteService $fallbackSites)
    {
        return $this->success([
            'default' => FallbackSiteService::defaultConfig()['template'],
            'templates' => $fallbackSites->templates(),
            'modes' => ['builtin', 'upload', 'proxy', 'raw'],
            'max_upload_bytes' => FallbackSiteService::MAX_PAGE_BYTES,
        ]);
    }

    public function upload(Request $request, FallbackSiteService $fallbackSites)
    {
        $request->validate([
            'page' => 'required|file|mimes:html,htm|max:512',
        ]);

        return $this->success($fallbackSites->upload($request->file('page')));
    }
}
