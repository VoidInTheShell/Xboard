<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Notice;
use App\Models\NoticeAcknowledgement;
use Illuminate\Http\Request;

class NoticeController extends Controller
{
    public function fetch(Request $request)
    {
        $current = $request->input('current') ? $request->input('current') : 1;
        $pageSize = 5;
        $model = Notice::orderByDesc('pinned')
            ->orderBy('sort', 'ASC')
            ->orderBy('id', 'DESC')
            ->where('show', true);
        $total = $model->count();
        $res = $model->forPage($current, $pageSize)
            ->get();

        $acknowledged = NoticeAcknowledgement::where('user_id', $request->user()->id)
            ->whereIn('notice_id', $res->pluck('id'))
            ->get(['notice_id', 'notice_revision'])
            ->mapWithKeys(fn ($item) => ["{$item->notice_id}:{$item->notice_revision}" => true]);
        $res->each(function (Notice $notice) use ($acknowledged) {
            $notice->setAttribute(
                'acknowledged',
                $notice->require_ack && $acknowledged->has("{$notice->id}:{$notice->revision}")
            );
        });
        return response([
            'data' => $res,
            'total' => $total
        ]);
    }

    public function acknowledge(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer|min:1',
            'revision' => 'required|integer|min:1',
        ]);
        $notice = Notice::where('show', true)->find($data['id']);
        if (!$notice) {
            return $this->fail([404, '公告不存在或已停止展示']);
        }
        if (!$notice->require_ack) {
            return $this->fail([422, '该公告无需手动确认']);
        }
        if ((int) $notice->revision !== (int) $data['revision']) {
            return $this->fail([409, '公告内容已更新，请重新阅读后确认']);
        }

        NoticeAcknowledgement::updateOrCreate([
            'notice_id' => $notice->id,
            'user_id' => $request->user()->id,
            'notice_revision' => $notice->revision,
        ], [
            'acknowledged_at' => time(),
        ]);

        return $this->success(true);
    }
}
