<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    protected PlanService $planService;

    public function __construct(PlanService $planService)
    {
        $this->planService = $planService;
    }
    public function fetch(Request $request)
    {
        $user = User::find($request->user()->id);
        $this->assertPurchaseAccess($user);
        if ($request->input('id')) {
            $plan = Plan::where('id', $request->input('id'))->first();
            if (!$plan) {
                return $this->fail([400, __('Subscription plan does not exist')]);
            }
            if (!$this->planService->isPlanAvailableForUser($plan, $user)) {
                return $this->fail([400, __('Subscription plan does not exist')]);
            }
            return $this->success(PlanResource::make($plan));
        }

        $plans = $this->planService->getAvailablePlans();
        return $this->success(PlanResource::collection($plans));
    }

    private function assertPurchaseAccess(User $user): void
    {
        if ((bool) admin_setting('self_use_mode', 0) && !$user->is_admin && !$user->is_staff) {
            throw new ApiException('自用模式下普通用户不可访问套餐与订单功能。', 403);
        }
    }
}
