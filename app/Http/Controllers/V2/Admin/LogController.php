<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\Logs\{LogSettings,LogStorage,LogFiles,LogRedactor,LogArchive};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LogController extends Controller
{
    public function settings() { return $this->success(['settings'=>LogSettings::get(true),'status'=>app(LogStorage::class)->status()]); }
    public function save(Request $request) { return $this->success(LogSettings::save($request->all())); }
    public function stats() { return $this->success(app(LogStorage::class)->status()); }

    private function filters(Request $request): array
    {
        return $request->validate(['page'=>'sometimes|integer|min:1|max:100000','per_page'=>'sometimes|integer|between:1,100',
            'keyword'=>'nullable|string|max:200','source'=>'nullable|string|max:64','status'=>'nullable|string|max:24',
            'from'=>'nullable|date_format:Y-m-d','to'=>'nullable|date_format:Y-m-d'.($request->filled('from')?'|after_or_equal:from':'')]);
    }

    private function dates($query, array $filters, string $column, bool $timestamp = true)
    {
        foreach (['from'=>'>=','to'=>'<='] as $key=>$operator) if (!empty($filters[$key])) {
            $date=\Carbon\CarbonImmutable::parse($filters[$key],'Asia/Shanghai');
            $date=$key==='to'?$date->endOfDay():$date->startOfDay();
            $query->where($column,$operator,$timestamp?$date->timestamp:$date->toDateTimeString());
        }
        return $query;
    }

    public function mail(Request $request)
    {
        $f=$this->filters($request); $q=DB::table('v2_mail_log');
        $this->dates($q,$f,'created_at');
        if (!empty($f['keyword'])) $q->where(fn($q)=>$q->where('email','like','%'.$f['keyword'].'%')->orWhere('subject','like','%'.$f['keyword'].'%'));
        if (!empty($f['source']) && $f['source']!=='all') $q->whereIn('template_name',[$f['source'],'db:'.$f['source'],'mail.default.'.$f['source']]);
        if (($f['status']??'all')==='成功') $q->whereNull('error');
        elseif (($f['status']??'all')==='失败') $q->whereNotNull('error');
        $total=$q->count();
        $rows=$q->orderByDesc('id')->forPage($f['page']??1,$f['per_page']??20)
            ->get(['id','email','subject','template_name',DB::raw('SUBSTR(error,1,8192) as error'),'created_at']);
        return $this->success(['data'=>$rows->map(fn($r)=>[
            'id'=>(string)$r->id,'time'=>$this->time((int)$r->created_at),
            'source'=>preg_replace('/^(?:db:|mail\.default\.)/','',$r->template_name),
            'target'=>$r->email,'title'=>LogRedactor::text($r->subject,255),'status'=>$r->error===null?'成功':'失败',
            'detail'=>$r->error===null?'邮件服务已接收发送请求。':LogRedactor::text($r->error,2048),
        ]),'total'=>$total]);
    }

    public function runtime(Request $request)
    {
        $f=$this->filters($request); $source=$f['source']??'all';
        $map=['应用'=>'app','备份'=>'backup','弃用警告'=>'deprecation'];
        abort_unless($source==='all'||$source==='队列'||isset($map[$source]),422,'无效日志来源');
        $rows=$source==='队列'?[]:app(LogFiles::class)->records($map[$source]??null);
        if ($source==='all'||$source==='队列') {
            $q=$this->dates(DB::table('failed_jobs'),$f,'failed_at',false);
            // Never return serialized jobs: they can contain passwords and mail bodies.
            foreach ($q->orderByDesc('id')->limit(500)->get(['id','queue','failed_at',DB::raw('SUBSTR(exception,1,8192) as exception')]) as $r) {
                $message=LogRedactor::text(explode("\n",$r->exception)[0],2048);
                $rows[]=['id'=>'queue:'.$r->id,'at'=>strtotime($r->failed_at),'source'=>'队列',
                    'target'=>'面板数据库','title'=>mb_substr($message,0,180),'status'=>'Error','detail'=>$message];
            }
        }
        $rows=array_values(array_filter($rows,function($r) use ($f) {
            $date=$this->time($r['at']);
            return (empty($f['from'])||substr($date,0,10)>=$f['from']) && (empty($f['to'])||substr($date,0,10)<=$f['to'])
                && (empty($f['status'])||$f['status']==='all'||$r['status']===$f['status'])
                && (empty($f['keyword'])||mb_stripos($r['detail'],$f['keyword'])!==false);
        }));
        usort($rows,fn($a,$b)=>$b['at']<=>$a['at']); $total=count($rows);
        $rows=array_slice($rows,(($f['page']??1)-1)*($f['per_page']??20),$f['per_page']??20);
        return $this->success(['data'=>array_map(fn($r)=>array_diff_key($r,['at'=>true])+['time'=>$this->time($r['at'])],$rows),
            'total'=>$total,'bounded'=>true,'notice'=>'显示日志文件最近 2 MiB 与最多 500 条失败任务中的匹配记录。']);
    }

    public function archive(Request $request)
    {
        $f=$request->validate(['from'=>'required|integer|min:0','to'=>'required|integer|gte:from',
            'user_id'=>'nullable|integer|min:1','node_id'=>'nullable|integer|min:1','machine_id'=>'nullable|integer|min:1']);
        return $this->success(app(LogArchive::class)->query($f,$f['from'],$f['to']));
    }

    private function time(int $at): string { return \Carbon\CarbonImmutable::createFromTimestampUTC($at)->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'); }
}
