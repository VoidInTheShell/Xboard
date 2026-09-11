<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\GiftCardCode;
use App\Models\GiftCardTemplate;
use App\Models\GiftCardUsage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class GiftCardController extends Controller
{
    /**
     * 获取礼品卡模板列表
     */
    public function templates(Request $request)
    {
        $request->validate([
            'type' => 'integer|min:1|max:10',
            'status' => 'integer|in:0,1',
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:1000',
        ]);

        $query = GiftCardTemplate::query();

        if ($request->has('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $perPage = $request->input('per_page', 15);
        $templates = $query->orderBy('sort', 'asc')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $templates->setCollection(
            $templates->getCollection()
                ->map(fn (GiftCardTemplate $template) => $this->templatePayload($template, true))
                ->values()
        );

        return $this->paginate($templates);
    }

    /**
     * 创建礼品卡模板
     */
    public function createTemplate(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => [
                'required',
                'integer',
                Rule::in(array_keys(GiftCardTemplate::getTypeMap()))
            ],
            'status' => 'boolean',
            'conditions' => 'nullable|array',
            'rewards' => 'required|array',
            'limits' => 'nullable|array',
            'special_config' => 'nullable|array',
            'icon' => 'nullable|string|max:255',
            'background_image' => 'nullable|string|url|max:255',
            'theme_color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'sort' => 'integer|min:0',
        ], [
            'name.required' => '礼品卡名称不能为空',
            'type.required' => '礼品卡类型不能为空',
            'type.in' => '无效的礼品卡类型',
            'rewards.required' => '奖励配置不能为空',
            'theme_color.regex' => '主题色格式不正确',
            'background_image.url' => '背景图片必须是有效的URL',
        ]);

        try {
            $template = GiftCardTemplate::create([
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'type' => $request->input('type'),
                'status' => $request->input('status', true),
                'conditions' => $request->input('conditions'),
                'rewards' => $request->input('rewards'),
                'limits' => $request->input('limits'),
                'special_config' => $request->input('special_config'),
                'icon' => $request->input('icon'),
                'background_image' => $request->input('background_image'),
                'theme_color' => $request->input('theme_color', '#1890ff'),
                'sort' => $request->input('sort', 0),
                'admin_id' => $request->user()->id,
                'created_at' => time(),
                'updated_at' => time(),
            ]);

            return $this->success($this->templatePayload($template));
        } catch (\Exception $e) {
            Log::error('创建礼品卡模板失败', [
                'admin_id' => $request->user()->id,
                'template_name' => $request->input('name'),
                'template_type' => $request->input('type'),
                'error' => $e->getMessage(),
            ]);
            return $this->fail([500, '创建失败']);
        }
    }

    /**
     * 更新礼品卡模板
     */
    public function updateTemplate(Request $request)
    {
        $validatedData = $request->validate([
            'id' => 'required|integer|exists:v2_gift_card_template,id',
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string',
            'type' => [
                'sometimes',
                'required',
                'integer',
                Rule::in(array_keys(GiftCardTemplate::getTypeMap()))
            ],
            'status' => 'sometimes|boolean',
            'conditions' => 'sometimes|nullable|array',
            'rewards' => 'sometimes|required|array',
            'limits' => 'sometimes|nullable|array',
            'special_config' => 'sometimes|nullable|array',
            'icon' => 'sometimes|nullable|string|max:255',
            'background_image' => 'sometimes|nullable|string|url|max:255',
            'theme_color' => 'sometimes|nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'sort' => 'sometimes|integer|min:0',
        ]);

        $template = GiftCardTemplate::find($validatedData['id']);
        if (!$template) {
            return $this->fail([404, '模板不存在']);
        }

        try {
            $updateData = collect($validatedData)->except('id')->all();

            if (empty($updateData)) {
                return $this->success($this->templatePayload($template));
            }

            $updateData['updated_at'] = time();

            $template->update($updateData);

            return $this->success($this->templatePayload($template->fresh()));
        } catch (\Exception $e) {
            Log::error('更新礼品卡模板失败', [
                'admin_id' => $request->user()->id,
                'template_id' => $template->id,
                'error' => $e->getMessage(),
            ]);
            return $this->fail([500, '更新失败']);
        }
    }

    /**
     * 删除礼品卡模板
     */
    public function deleteTemplate(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|exists:v2_gift_card_template,id',
        ]);

        $template = GiftCardTemplate::find($request->input('id'));
        if (!$template) {
            return $this->fail([404, '模板不存在']);
        }

        // 检查是否有关联的兑换码
        if ($template->codes()->exists()) {
            return $this->fail([400, '该模板下存在兑换码，无法删除']);
        }

        try {
            $template->delete();
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error('删除礼品卡模板失败', [
                'admin_id' => $request->user()->id,
                'template_id' => $template->id,
                'error' => $e->getMessage(),
            ]);
            return $this->fail([500, '删除失败']);
        }
    }

    /**
     * 生成兑换码
     */
    public function generateCodes(Request $request)
    {
        $request->validate([
            'template_id' => 'required|integer|exists:v2_gift_card_template,id',
            'count' => 'required|integer|min:1|max:10000',
            'prefix' => 'nullable|string|max:10|regex:/^[A-Z0-9]*$/',
            'expires_hours' => 'nullable|integer|min:1',
            'max_usage' => 'integer|min:1|max:1000',
        ], [
            'template_id.required' => '请选择礼品卡模板',
            'count.required' => '请指定生成数量',
            'count.max' => '单次最多生成10000个兑换码',
            'prefix.regex' => '前缀只能包含大写字母和数字',
        ]);

        $template = GiftCardTemplate::find($request->input('template_id'));
        if (!$template->isAvailable()) {
            return $this->fail([400, '模板已被禁用']);
        }

        try {
            $options = [
                'prefix' => $request->input('prefix', 'GC'),
                'max_usage' => $request->input('max_usage', 1),
            ];

            if ($request->has('expires_hours')) {
                $options['expires_at'] = time() + ($request->input('expires_hours') * 3600);
            }

            $batchId = GiftCardCode::batchGenerate(
                $request->input('template_id'),
                $request->input('count'),
                $options
            );

            // 查询本次生成的所有兑换码
            $codes = GiftCardCode::where('batch_id', $batchId)->get();

            // 判断是否导出 CSV
            if ($request->input('download_csv')) {
                $headers = [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                    'Content-Disposition' => 'attachment; filename="gift_codes.csv"',
                ];
                $callback = function () use ($codes, $template) {
                    $handle = fopen('php://output', 'w');
                    fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));
                    // 表头
                    fputcsv($handle, [
                        '兑换码',
                        '前缀',
                        '有效期',
                        '最大使用次数',
                        '批次号',
                        '创建时间',
                        '模板名称',
                        '模板类型',
                        '模板奖励',
                        '状态',
                        '使用者',
                        '使用时间',
                        '备注'
                    ]);
                    foreach ($codes as $code) {
                        $expireDate = $code->expires_at ? date('Y-m-d H:i:s', $code->expires_at) : '长期有效';
                        $createDate = date('Y-m-d H:i:s', $code->created_at);
                        $templateName = $template->name ?? '';
                        $templateType = $template->type ?? '';
                        $templateRewards = $template->rewards ? json_encode($template->rewards, JSON_UNESCAPED_UNICODE) : '';
                        // 状态判断
                        $status = $code->status_name;
                        // 导出允许返回完整兑换码，但不返回用户 ID 等关联身份数据。
                        $usedBy = $code->user_id !== null ? '已使用' : '';
                        $usedAt = $code->used_at ? date('Y-m-d H:i:s', $code->used_at) : '';
                        $remark = $code->remark ?? '';
                        fputcsv($handle, [
                            $code->code,
                            $code->prefix ?? '',
                            $expireDate,
                            $code->max_usage,
                            $code->batch_id,
                            $createDate,
                            $templateName,
                            $templateType,
                            $templateRewards,
                            $status,
                            $usedBy,
                            $usedAt,
                            $remark,
                        ]);
                    }
                    fclose($handle);
                };
                return response()->streamDownload($callback, 'gift_codes.csv', $headers);
            }

            Log::info('批量生成兑换码', [
                'admin_id' => $request->user()->id,
                'template_id' => $request->input('template_id'),
                'count' => $request->input('count'),
                'batch_id' => $batchId,
            ]);

            return $this->success([
                'batch_id' => $batchId,
                'count' => $request->input('count'),
                'message' => '生成成功',
            ]);
        } catch (\Exception $e) {
            Log::error('生成兑换码失败', [
                'admin_id' => $request->user()->id,
                'template_id' => $request->input('template_id'),
                'count' => $request->input('count'),
                'error' => $e->getMessage(),
            ]);
            return $this->fail([500, '生成失败']);
        }
    }

    /**
     * 获取兑换码列表
     */
    public function codes(Request $request)
    {
        $request->validate([
            'template_id' => 'integer|exists:v2_gift_card_template,id',
            'batch_id' => 'string',
            'status' => 'integer|in:0,1,2,3',
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:500',
        ]);

        $query = GiftCardCode::with(['template', 'user']);

        if ($request->has('template_id')) {
            $query->where('template_id', $request->input('template_id'));
        }

        if ($request->has('batch_id')) {
            $query->where('batch_id', $request->input('batch_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $perPage = $request->input('per_page', 15);
        $codes = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $codes->setCollection(
            $codes->getCollection()
                ->map(fn (GiftCardCode $code) => $this->codePayload($code))
                ->values()
        );

        return $this->paginate($codes);
    }

    /**
     * 禁用/启用兑换码
     */
    public function toggleCode(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|exists:v2_gift_card_code,id',
            'action' => 'required|string|in:disable,enable',
        ]);

        try {
            return DB::transaction(function () use ($request) {
                $code = GiftCardCode::whereKey($request->input('id'))
                    ->lockForUpdate()
                    ->first();

                if (!$code) {
                    return $this->fail([404, '兑换码不存在']);
                }

                $action = $request->input('action');
                $status = (int) $code->status;
                $usageCount = (int) ($code->usage_count ?? 0);
                $maxUsage = (int) ($code->max_usage ?? 0);

                if ($action === 'disable') {
                    if (
                        $status !== GiftCardCode::STATUS_UNUSED
                        || $code->isExpired()
                        || $usageCount >= $maxUsage
                    ) {
                        return $this->fail([422, '只有仍可兑换且未使用的兑换码可以停用']);
                    }

                    $code->status = GiftCardCode::STATUS_DISABLED;
                    $code->saveOrFail();
                } else {
                    if (
                        $status !== GiftCardCode::STATUS_DISABLED
                        || $code->isExpired()
                        || $usageCount >= $maxUsage
                    ) {
                        return $this->fail([422, '只有未过期且仍可兑换的已禁用兑换码可以启用']);
                    }

                    $code->status = GiftCardCode::STATUS_UNUSED;
                    $code->saveOrFail();
                }

                return $this->success([
                    'message' => $action === 'disable' ? '已禁用' : '已启用',
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('兑换码状态更新失败', [
                'admin_id' => $request->user()->id,
                'code_id' => $request->input('id'),
                'action' => $request->input('action'),
                'error' => $e->getMessage(),
            ]);
            return $this->fail([500, '操作失败']);
        }
    }

    /**
     * 导出兑换码
     */
    public function exportCodes(Request $request)
    {
        $request->validate([
            'batch_id' => 'required|string|exists:v2_gift_card_code,batch_id',
        ]);

        $codes = GiftCardCode::where('batch_id', $request->input('batch_id'))
            ->orderBy('created_at', 'asc')
            ->get(['code']);

        $content = $codes->pluck('code')->implode("\n");

        return response($content)
            ->header('Content-Type', 'text/plain')
            ->header('Content-Disposition', 'attachment; filename="gift_cards_' . $request->input('batch_id') . '.txt"');
    }

    /**
     * 获取使用记录
     */
    public function usages(Request $request)
    {
        $request->validate([
            'template_id' => 'integer|exists:v2_gift_card_template,id',
            'user_id' => 'integer|exists:v2_user,id',
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:500',
        ]);

        $query = GiftCardUsage::with(['template', 'code', 'user', 'inviteUser']);

        if ($request->has('template_id')) {
            $query->where('template_id', $request->input('template_id'));
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        $perPage = $request->input('per_page', 15);
        $usages = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $usages->setCollection(
            $usages->getCollection()
                ->map(fn (GiftCardUsage $usage) => $this->usagePayload($usage))
                ->values()
        );
        return $this->paginate($usages);
    }

    /**
     * 获取统计数据
     */
    public function statistics(Request $request)
    {
        $request->validate([
            'start_date' => 'date_format:Y-m-d',
            'end_date' => 'date_format:Y-m-d',
        ]);

        $startDate = $request->input('start_date', date('Y-m-d', strtotime('-30 days')));
        $endDate = $request->input('end_date', date('Y-m-d'));

        // 总体统计
        $totalStats = [
            'templates_count' => GiftCardTemplate::count(),
            'active_templates_count' => GiftCardTemplate::where('status', 1)->count(),
            'codes_count' => GiftCardCode::count(),
            'used_codes_count' => GiftCardCode::where('status', GiftCardCode::STATUS_USED)->count(),
            'usages_count' => GiftCardUsage::count(),
        ];

        // 每日使用统计
        $driver = DB::connection()->getDriverName();
        $dateExpression = "date(created_at, 'unixepoch')"; // Default for SQLite
        if ($driver === 'mysql') {
            $dateExpression = 'DATE(FROM_UNIXTIME(created_at))';
        } elseif ($driver === 'pgsql') {
            $dateExpression = 'date(to_timestamp(created_at))';
        }

        $dailyUsages = GiftCardUsage::selectRaw("{$dateExpression} as date, COUNT(*) as count")
            ->whereRaw("{$dateExpression} BETWEEN ? AND ?", [$startDate, $endDate])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // 类型统计
        $typeStats = GiftCardUsage::with('template')
            ->selectRaw('template_id, COUNT(*) as count')
            ->groupBy('template_id')
            ->get()
            ->map(function ($item) {
                return [
                    'template_name' => $item->template->name ?? '',
                    'type_name' => $item->template->type_name ?? '',
                    'count' => $item->count ?? 0,
                ];
            });

        return $this->success([
            'total_stats' => $totalStats,
            'daily_usages' => $dailyUsages,
            'type_stats' => $typeStats,
        ]);
    }

    /**
     * 获取所有可用的礼品卡类型
     */
    public function types()
    {
        return $this->success(GiftCardTemplate::getTypeMap());
    }

    /**
     * 更新单个兑换码
     */
    public function updateCode(Request $request)
    {
        $validatedData = $request->validate([
            'id' => 'required|integer|exists:v2_gift_card_code,id',
            'expires_at' => 'sometimes|nullable|integer',
            'max_usage' => 'sometimes|integer|min:1|max:1000',
            'status' => 'sometimes|integer|in:0,1,2,3',
        ]);

        try {
            return DB::transaction(function () use ($request, $validatedData) {
                $code = GiftCardCode::whereKey($validatedData['id'])
                    ->lockForUpdate()
                    ->first();

                if (!$code) {
                    return $this->fail([404, '兑换码不存在']);
                }

                $updateData = collect($validatedData)->except('id')->all();
                if (empty($updateData)) {
                    return $this->success($this->codePayload($code));
                }

                $maxUsage = array_key_exists('max_usage', $updateData)
                    ? (int) $updateData['max_usage']
                    : (int) ($code->max_usage ?? 0);
                if ($maxUsage < (int) ($code->usage_count ?? 0)) {
                    return $this->fail([422, '最大使用次数不能低于已兑换次数']);
                }

                $expiresAt = array_key_exists('expires_at', $updateData)
                    ? ($updateData['expires_at'] === null ? null : (int) $updateData['expires_at'])
                    : ($code->expires_at === null ? null : (int) $code->expires_at);
                $targetStatus = array_key_exists('status', $updateData)
                    ? (int) $updateData['status']
                    : null;

                $statusError = $this->statusTransitionError(
                    $code,
                    $targetStatus,
                    $expiresAt,
                    $maxUsage
                );
                if ($statusError !== null) {
                    return $this->fail([422, $statusError]);
                }

                if (array_key_exists('status', $updateData)) {
                    $updateData['status'] = $targetStatus;
                }
                if (array_key_exists('max_usage', $updateData)) {
                    $updateData['max_usage'] = $maxUsage;
                }
                if (array_key_exists('expires_at', $updateData)) {
                    $updateData['expires_at'] = $expiresAt;
                }

                $updateData['updated_at'] = time();
                $code->fill($updateData);
                $code->saveOrFail();

                return $this->success($this->codePayload($code->fresh()));
            });
        } catch (\Throwable $e) {
            Log::error('更新礼品卡信息失败', [
                'admin_id' => $request->user()->id,
                'code_id' => $validatedData['id'],
                'error' => $e->getMessage(),
            ]);
            return $this->fail([500, '更新失败']);
        }
    }

    /**
     * 删除礼品卡
     */
    public function deleteCode(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|exists:v2_gift_card_code,id',
        ]);

        try {
            return DB::transaction(function () use ($request) {
                $code = GiftCardCode::whereKey($request->input('id'))
                    ->lockForUpdate()
                    ->first();

                if (!$code) {
                    return $this->fail([404, '兑换码不存在']);
                }

                // 检查是否已被使用
                if (
                    $code->status === GiftCardCode::STATUS_USED
                    || (int) ($code->usage_count ?? 0) > 0
                ) {
                    return $this->fail([400, '该礼品卡已被使用，无法删除']);
                }

                // 检查是否有关联的使用记录
                if ($code->usages()->exists()) {
                    return $this->fail([400, '该礼品卡存在使用记录，无法删除']);
                }

                $code->delete();
                return $this->success(['message' => '删除成功']);
            });
        } catch (\Throwable $e) {
            Log::error('删除礼品卡失败', [
                'admin_id' => $request->user()->id,
                'code_id' => $request->input('id'),
                'error' => $e->getMessage(),
            ]);
            return $this->fail([500, '删除失败']);
        }
    }

    /**
     * 将模板转换为 Admin 可用的白名单响应。
     */
    private function templatePayload(GiftCardTemplate $template, bool $withStats = false): array
    {
        $payload = [
            'id' => $template->id,
            'name' => $template->name,
            'description' => $template->description,
            'type' => $template->type,
            'type_name' => $template->type_name,
            'status' => $template->status,
            'conditions' => $template->conditions,
            'rewards' => $template->rewards,
            'limits' => $template->limits,
            'special_config' => $template->special_config,
            'icon' => $template->icon,
            'background_image' => $template->background_image,
            'theme_color' => $template->theme_color,
            'sort' => $template->sort,
            'created_at' => $template->created_at,
            'updated_at' => $template->updated_at,
        ];

        if ($withStats) {
            $payload['codes_count'] = $template->codes()->count();
            $payload['used_count'] = $template->usages()->count();
        }

        return $payload;
    }

    /**
     * 将兑换码转换为不含原码和原始关联模型的白名单响应。
     */
    private function codePayload(GiftCardCode $code): array
    {
        $code->loadMissing([
            'template:id,name',
            'user:id,email',
        ]);

        $maskedCode = GiftCardCode::maskCode($code->code);
        $status = $code->effectiveStatus();

        return [
            'id' => $code->id,
            'template_id' => $code->template_id,
            'template_name' => $code->template?->name ?? '',
            'code' => $maskedCode,
            'code_masked' => $maskedCode,
            'batch_id' => $code->batch_id,
            'status' => $status,
            'status_name' => GiftCardCode::getStatusMap()[$status] ?? '未知状态',
            'user_email' => self::maskEmail($code->user?->email),
            'used_at' => $code->used_at,
            'expires_at' => $code->expires_at,
            'usage_count' => $code->usage_count,
            'max_usage' => $code->max_usage,
            'created_at' => $code->created_at,
        ];
    }

    /**
     * 将使用记录转换为不含原码、用户 ID 和关系对象的白名单响应。
     */
    private function usagePayload(GiftCardUsage $usage): array
    {
        $usage->loadMissing([
            'code:id,code',
            'template:id,name',
            'user:id,email',
            'inviteUser:id,email',
        ]);

        $maskedCode = GiftCardCode::maskCode($usage->code?->code);

        return [
            'id' => $usage->id,
            'code_id' => $usage->code_id,
            'code' => $maskedCode,
            'code_masked' => $maskedCode,
            'template_name' => $usage->template?->name ?? '',
            'user_email' => self::maskEmail($usage->user?->email),
            'invite_user_email' => self::maskEmail($usage->inviteUser?->email),
            'rewards_given' => $usage->rewards_given,
            'invite_rewards' => $usage->invite_rewards,
            'multiplier_applied' => $usage->multiplier_applied,
            'created_at' => $usage->created_at,
        ];
    }

    /**
     * 只暴露邮箱前缀的固定掩码，不返回原始关联用户邮箱。
     */
    private static function maskEmail(?string $email): ?string
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        $email = trim($email);
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }

        return substr($email, 0, min(3, $at)) . '***@***';
    }

    /**
     * 校验兑换码状态变更，避免管理员请求绕过兑换状态保护。
     */
    private function statusTransitionError(
        GiftCardCode $code,
        ?int $targetStatus,
        ?int $expiresAt,
        int $maxUsage
    ): ?string {
        if ($targetStatus === null || $targetStatus === (int) $code->status) {
            return null;
        }

        $currentStatus = (int) $code->status;
        $usageCount = (int) ($code->usage_count ?? 0);
        $hasCapacity = $usageCount < $maxUsage;
        $expired = $expiresAt !== null && $expiresAt < time();

        if ($targetStatus === GiftCardCode::STATUS_USED) {
            return '已使用状态只能由兑换流程产生';
        }

        if ($currentStatus === GiftCardCode::STATUS_USED) {
            return '已使用兑换码不能改写状态';
        }

        if ($targetStatus === GiftCardCode::STATUS_EXPIRED) {
            return null;
        }

        if (
            $currentStatus === GiftCardCode::STATUS_EXPIRED
            && $targetStatus === GiftCardCode::STATUS_UNUSED
            && !$expired
            && $hasCapacity
        ) {
            return null;
        }

        if (
            $currentStatus === GiftCardCode::STATUS_UNUSED
            && $targetStatus === GiftCardCode::STATUS_DISABLED
            && !$expired
            && $hasCapacity
        ) {
            return null;
        }

        if (
            $currentStatus === GiftCardCode::STATUS_DISABLED
            && $targetStatus === GiftCardCode::STATUS_UNUSED
            && !$expired
            && $hasCapacity
        ) {
            return null;
        }

        return '该兑换码当前状态不支持此变更';
    }
}
