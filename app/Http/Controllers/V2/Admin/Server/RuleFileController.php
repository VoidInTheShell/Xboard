<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\XrayRuleFile;
use App\Services\XrayRuleFileService;
use Illuminate\Http\Request;

class RuleFileController extends Controller
{
    public function __construct(private readonly XrayRuleFileService $files)
    {
    }

    public function fetch(Request $request)
    {
        $node = $this->node($request);
        return $this->success(['files' => $this->files->files($node)]);
    }

    public function validateFile(Request $request)
    {
        [$node, $input, $file] = $this->input($request);
        $this->files->validateInput($node, $input, $file);
        return $this->success(['valid' => true]);
    }

    public function save(Request $request)
    {
        [$node, $input, $file] = $this->input($request);
        return $this->success($this->files->snapshot($this->files->save($node, $input, $file)));
    }

    public function download(Request $request)
    {
        $node = $this->node($request);
        $file = $this->file($request);
        return $this->success($this->files->snapshot($this->files->requestDownload($node, $file)));
    }

    public function drop(Request $request)
    {
        $node = $this->node($request);
        $file = $this->file($request);
        $this->files->drop($node, $file);
        return $this->success(true);
    }

    private function input(Request $request): array
    {
        $node = $this->node($request);
        $input = $request->validate([
            'id' => 'nullable|integer|exists:v2_xray_rule_file,id',
            'name' => 'sometimes|string|max:255',
            'source' => 'sometimes|string|max:32',
            'url' => 'nullable|string|max:2048',
            'auto_update' => 'sometimes|boolean',
            'update_interval_hours' => 'sometimes|integer|min:1|max:8760',
        ]);
        $file = isset($input['id']) ? XrayRuleFile::query()->findOrFail($input['id']) : null;
        return [$node, $input, $file];
    }

    private function node(Request $request): Server
    {
        $params = $request->validate(['node_id' => 'required|integer|exists:v2_server,id']);
        return Server::query()->findOrFail($params['node_id']);
    }

    private function file(Request $request): XrayRuleFile
    {
        $params = $request->validate(['id' => 'required|integer|exists:v2_xray_rule_file,id']);
        return XrayRuleFile::query()->findOrFail($params['id']);
    }
}
