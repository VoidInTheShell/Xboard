<?php

namespace App\Http\Requests\User;

use App\Exceptions\ApiException;
use Illuminate\Foundation\Http\FormRequest;

class OrderSave extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        return !((bool) admin_setting('self_use_mode', 0)
            && $user
            && !$user->is_admin
            && !$user->is_staff);
    }

    protected function failedAuthorization(): void
    {
        throw new ApiException('自用模式下普通用户不可访问套餐与订单功能。', 403);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'plan_id' => 'required',
            'period' => 'required|in:month_price,quarter_price,half_year_price,year_price,two_year_price,three_year_price,onetime_price,reset_price'
        ];
    }

    public function messages()
    {
        return [
            'plan_id.required' => __('Plan ID cannot be empty'),
            'period.required' => __('Plan period cannot be empty'),
            'period.in' => __('Wrong plan period')
        ];
    }
}
