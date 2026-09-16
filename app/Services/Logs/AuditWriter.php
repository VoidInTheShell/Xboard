<?php
namespace App\Services\Logs;

use App\Models\{AdminAuditLog,McpKey,User};
use App\Services\ChangeEventService;
use App\Http\Middleware\RequestLog;
use Illuminate\Http\Request;

class AuditWriter
{
    public static function write(Request $request, string $action, int $status, ?User $actor = null): void
    {
        $actor ??= $request->user();
        if (!$actor?->is_admin || !LogSettings::enabled('audit') || !LogBudget::accepts(12288)) return;
        if ($status>=400 && !LogSettings::get()['auditFailures']) return;
        $key=$request->attributes->get('mcp_key');
        $data=RequestLog::redactRequestData($request->all());
        $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        if (strlen($json)>8192) $json=json_encode(['summary'=>'请求字段超出审计记录大小限制。']);
        AdminAuditLog::create(['admin_id'=>$actor->id,'actor_type'=>$key instanceof McpKey?'mcp':'admin',
            'mcp_key_id'=>$key instanceof McpKey?$key->id:null,
            'request_id'=>app(ChangeEventService::class)->requestId($request),
            'client_id'=>preg_match('/^[A-Za-z0-9_-]{1,64}$/',(string)$request->header('X-Xboard-Admin-Client-Id'))?$request->header('X-Xboard-Admin-Client-Id'):null,
            'action'=>mb_substr($action,0,64),'status_code'=>$status,'method'=>$request->method(),
            'uri'=>mb_substr('/'.$request->path(),0,512),'request_data'=>$json,'ip'=>$request->ip(),
            'created_at'=>time(),'updated_at'=>time()]);
    }

    public static function attempt(Request $request, string $action, int $status, ?User $actor = null): void
    {
        // Optional failure/read audit cannot replace the original response/error.
        try { self::write($request,$action,$status,$actor); } catch (\Throwable) {}
    }
}
