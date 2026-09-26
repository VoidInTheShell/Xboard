<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrafficLogResource;
use App\Models\StatUser;
use App\Services\StatisticalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatController extends Controller
{
    public function getTrafficLog(Request $request)
    {
        // The user dashboard needs to tell "statistics disabled" apart from
        // "no data yet", so the payload always carries the feature flag.
        if (!\App\Services\Logs\LogSettings::enabled('legacy')) {
            return $this->success(['enabled' => false, 'logs' => []]);
        }
        $startDate = now()->startOfMonth()->timestamp;
        $records = StatUser::query()
            ->where('user_id', $request->user()->id)
            ->where('record_at', '>=', $startDate)
            ->orderBy('record_at', 'DESC')
            ->get();

        $data = TrafficLogResource::collection(collect($records));
        return $this->success(['enabled' => true, 'logs' => $data]);
    }
}
