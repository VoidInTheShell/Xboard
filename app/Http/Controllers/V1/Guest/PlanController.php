<?php

namespace App\Http\Controllers\V1\Guest;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Services\PlanService;
use Auth;
use Illuminate\Http\Request;

class PlanController extends Controller
{

    protected $planService;
    public function __construct(PlanService $planService)
    {
        $this->planService = $planService;
    }
    public function fetch(Request $request)
    {
        $user = Auth::guard('sanctum')->user();
        if ((bool) admin_setting('self_use_mode', 0)
            && (!$user || (!$user->is_admin && !$user->is_staff))) {
            throw new ApiException('自用模式下普通用户不可访问套餐与订单功能。', 403);
        }
        $plan = $this->planService->getAvailablePlans();
        return $this->success(PlanResource::collection($plan));
    }
}
