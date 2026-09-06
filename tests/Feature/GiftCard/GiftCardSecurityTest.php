<?php

namespace Tests\Feature\GiftCard;

use App\Http\Controllers\V1\User\GiftCardController as UserGiftCardController;
use App\Http\Controllers\V2\Admin\GiftCardController as AdminGiftCardController;
use App\Models\GiftCardCode;
use App\Models\GiftCardTemplate;
use App\Models\GiftCardUsage;
use App\Models\User;
use App\Services\GiftCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GiftCardSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_template_and_code_lists_write_mapped_data_back_to_paginators(): void
    {
        $admin = $this->makeUser(['is_admin' => true]);
        $usedBy = $this->makeUser(['email' => 'used-owner@example.com']);
        $template = $this->makeTemplate();
        $code = $this->makeCode($template, [
            'status' => GiftCardCode::STATUS_USED,
            'user_id' => $usedBy->id,
            'usage_count' => 1,
            'max_usage' => 1,
        ]);
        $this->makeUsage($template, $code, $usedBy);

        Sanctum::actingAs($admin);

        $templateRow = $this->getJson($this->routePath(AdminGiftCardController::class . '@templates'))
            ->assertOk()
            ->json('data.0');

        $this->assertSame(1, $templateRow['codes_count']);
        $this->assertSame(1, $templateRow['used_count']);
        $this->assertArrayNotHasKey('admin_id', $templateRow);

        $codeResponse = $this->getJson($this->routePath(AdminGiftCardController::class . '@codes'))
            ->assertOk();
        $codeRow = $codeResponse->json('data.0');

        $this->assertSame(GiftCardCode::maskCode($code->code), $codeRow['code']);
        $this->assertSame($codeRow['code'], $codeRow['code_masked']);
        $this->assertArrayNotHasKey('template', $codeRow);
        $this->assertArrayNotHasKey('user', $codeRow);
        $this->assertArrayNotHasKey('user_id', $codeRow);
        $this->assertStringNotContainsString($code->code, $codeResponse->getContent());
        $this->assertStringNotContainsString($usedBy->email, $codeResponse->getContent());
        $this->assertStringNotContainsString($usedBy->token, $codeResponse->getContent());
        $this->assertStringNotContainsString($usedBy->uuid, $codeResponse->getContent());
    }

    public function test_admin_usage_list_only_returns_flattened_masked_fields(): void
    {
        $admin = $this->makeUser(['is_admin' => true]);
        $user = $this->makeUser(['email' => 'redeemer@example.com']);
        $inviteUser = $this->makeUser(['email' => 'inviter@example.com']);
        $template = $this->makeTemplate();
        $code = $this->makeCode($template, [
            'status' => GiftCardCode::STATUS_USED,
            'user_id' => $user->id,
            'usage_count' => 1,
            'max_usage' => 1,
        ]);
        $usage = $this->makeUsage($template, $code, $user, $inviteUser);

        Sanctum::actingAs($admin);

        $response = $this->getJson($this->routePath(AdminGiftCardController::class . '@usages'))
            ->assertOk();
        $row = $response->json('data.0');

        $this->assertSame($usage->id, $row['id']);
        $this->assertSame($code->id, $row['code_id']);
        $this->assertSame(GiftCardCode::maskCode($code->code), $row['code']);
        $this->assertSame($row['code'], $row['code_masked']);
        $this->assertSame('red***@***', $row['user_email']);
        $this->assertSame('inv***@***', $row['invite_user_email']);
        $this->assertArrayNotHasKey('code_relation', $row);
        $this->assertArrayNotHasKey('template', $row);
        $this->assertArrayNotHasKey('user', $row);
        $this->assertArrayNotHasKey('inviteUser', $row);
        $this->assertStringNotContainsString($code->code, $response->getContent());
        $this->assertStringNotContainsString($user->email, $response->getContent());
        $this->assertStringNotContainsString($inviteUser->email, $response->getContent());
        $this->assertStringNotContainsString($user->token, $response->getContent());
        $this->assertStringNotContainsString($inviteUser->uuid, $response->getContent());
    }

    public function test_toggle_code_allows_only_live_unused_disabled_transition(): void
    {
        $admin = $this->makeUser(['is_admin' => true]);
        $template = $this->makeTemplate();
        $unused = $this->makeCode($template);
        $disabled = $this->makeCode($template, [
            'status' => GiftCardCode::STATUS_DISABLED,
        ]);
        $used = $this->makeCode($template, [
            'status' => GiftCardCode::STATUS_USED,
            'usage_count' => 1,
            'max_usage' => 2,
        ]);
        $expired = $this->makeCode($template, [
            'status' => GiftCardCode::STATUS_EXPIRED,
            'expires_at' => time() - 60,
        ]);

        Sanctum::actingAs($admin);
        $togglePath = $this->routePath(AdminGiftCardController::class . '@toggleCode');

        $this->postJson($togglePath, [
            'id' => $unused->id,
            'action' => 'disable',
        ])->assertOk();
        $this->assertSame(
            GiftCardCode::STATUS_DISABLED,
            (int) $unused->fresh()->status
        );

        $this->postJson($togglePath, [
            'id' => $disabled->id,
            'action' => 'enable',
        ])->assertOk();
        $this->assertSame(
            GiftCardCode::STATUS_UNUSED,
            (int) $disabled->fresh()->status
        );

        $this->postJson($togglePath, [
            'id' => $used->id,
            'action' => 'disable',
        ])->assertStatus(422);
        $this->assertSame(GiftCardCode::STATUS_USED, (int) $used->fresh()->status);

        $this->postJson($togglePath, [
            'id' => $expired->id,
            'action' => 'enable',
        ])->assertStatus(422);
        $this->assertSame(
            GiftCardCode::STATUS_EXPIRED,
            (int) $expired->fresh()->status
        );
    }

    public function test_update_code_cannot_reset_used_state_or_reduce_usage_capacity(): void
    {
        $admin = $this->makeUser(['is_admin' => true]);
        $template = $this->makeTemplate();
        $code = $this->makeCode($template, [
            'status' => GiftCardCode::STATUS_USED,
            'usage_count' => 1,
            'max_usage' => 2,
        ]);

        Sanctum::actingAs($admin);
        $path = $this->routePath(AdminGiftCardController::class . '@updateCode');

        $this->postJson($path, [
            'id' => $code->id,
            'status' => GiftCardCode::STATUS_UNUSED,
        ])->assertStatus(422);
        $this->assertSame(GiftCardCode::STATUS_USED, (int) $code->fresh()->status);

        $this->postJson($path, [
            'id' => $code->id,
            'max_usage' => 0,
        ])->assertUnprocessable();
        $this->assertSame(2, (int) $code->fresh()->max_usage);

        $this->postJson($path, [
            'id' => $code->id,
            'status' => GiftCardCode::STATUS_USED,
            'max_usage' => 3,
        ])->assertOk()
            ->assertJsonPath('data.code', GiftCardCode::maskCode($code->code))
            ->assertJsonMissingPath('data.template')
            ->assertJsonMissingPath('data.user');

        $this->assertSame(3, (int) $code->fresh()->max_usage);
        $this->assertSame(GiftCardCode::STATUS_USED, (int) $code->fresh()->status);
    }

    public function test_delete_code_keeps_used_or_recorded_codes_intact(): void
    {
        $admin = $this->makeUser(['is_admin' => true]);
        $user = $this->makeUser(['email' => 'delete-owner@example.com']);
        $template = $this->makeTemplate();
        $used = $this->makeCode($template, [
            'status' => GiftCardCode::STATUS_USED,
            'user_id' => $user->id,
            'usage_count' => 1,
        ]);
        $recorded = $this->makeCode($template);
        $this->makeUsage($template, $recorded, $user);

        Sanctum::actingAs($admin);
        $path = $this->routePath(AdminGiftCardController::class . '@deleteCode');

        $this->postJson($path, ['id' => $used->id])
            ->assertStatus(400);
        $this->postJson($path, ['id' => $recorded->id])
            ->assertStatus(400);

        $this->assertDatabaseHas('v2_gift_card_code', ['id' => $used->id]);
        $this->assertDatabaseHas('v2_gift_card_code', ['id' => $recorded->id]);
    }

    public function test_partial_multi_use_code_remains_redeemable_and_is_not_admin_disabled(): void
    {
        $admin = $this->makeUser(['is_admin' => true]);
        $previousUser = $this->makeUser(['email' => 'previous@example.com']);
        $redeemingUser = $this->makeUser(['email' => 'current@example.com']);
        $template = $this->makeTemplate([
            'rewards' => ['balance' => 25],
        ]);
        $code = $this->makeCode($template, [
            'status' => GiftCardCode::STATUS_USED,
            'user_id' => $previousUser->id,
            'usage_count' => 1,
            'max_usage' => 2,
        ]);
        $this->makeUsage($template, $code, $previousUser);

        Sanctum::actingAs($admin);
        $this->postJson($this->routePath(AdminGiftCardController::class . '@toggleCode'), [
            'id' => $code->id,
            'action' => 'disable',
        ])->assertStatus(422);

        $result = (new GiftCardService($code->code))
            ->setUser($redeemingUser)
            ->redeem();

        $this->assertArrayNotHasKey('code', $result);
        $this->assertSame(25, (int) $redeemingUser->fresh()->balance);
        $this->assertSame(2, (int) $code->fresh()->usage_count);
        $this->assertSame(GiftCardCode::STATUS_USED, (int) $code->fresh()->status);
        $this->assertSame(2, $code->usages()->count());
    }

    public function test_user_check_history_and_detail_keep_code_masked(): void
    {
        $user = $this->makeUser(['email' => 'member@example.com']);
        $template = $this->makeTemplate();
        $code = $this->makeCode($template, [
            'batch_id' => 'batch_user_test',
        ]);

        Sanctum::actingAs($user);
        $checkPath = $this->routePath(UserGiftCardController::class . '@check');
        $historyPath = $this->routePath(UserGiftCardController::class . '@history');
        $detailPath = $this->routePath(UserGiftCardController::class . '@detail');

        $check = $this->postJson($checkPath, ['code' => $code->code])
            ->assertOk();
        $this->assertSame(
            GiftCardCode::maskCode($code->code),
            $check->json('data.code_info.code')
        );
        $this->assertSame(
            GiftCardCode::maskCode($code->code),
            $check->json('data.code_info.code_masked')
        );
        $this->assertStringNotContainsString($code->code, $check->getContent());

        $redeemPath = $this->routePath(UserGiftCardController::class . '@redeem');
        $this->postJson($redeemPath, ['code' => $code->code])
            ->assertOk();
        $usage = $code->usages()->latest('id')->firstOrFail();

        $history = $this->getJson($historyPath)->assertOk();
        $this->assertSame(
            GiftCardCode::maskCode($code->code),
            $history->json('data.0.code')
        );
        $this->assertStringNotContainsString($code->code, $history->getContent());

        $detail = $this->getJson($detailPath . '?id=' . $usage->id)
            ->assertOk();
        $this->assertSame(
            GiftCardCode::maskCode($code->code),
            $detail->json('data.code')
        );
        $this->assertArrayNotHasKey('id', $detail->json('data.invite_user') ?? []);
        $this->assertStringNotContainsString($code->code, $detail->getContent());
    }

    public function test_expired_at_is_reported_without_writing_status_during_listing(): void
    {
        $admin = $this->makeUser(['is_admin' => true]);
        $template = $this->makeTemplate();
        $code = $this->makeCode($template, [
            'expires_at' => time() - 60,
        ]);

        Sanctum::actingAs($admin);
        $row = $this->getJson($this->routePath(AdminGiftCardController::class . '@codes'))
            ->assertOk()
            ->json('data.0');

        $this->assertSame(GiftCardCode::STATUS_EXPIRED, (int) $row['status']);
        $this->assertSame('已过期', $row['status_name']);
        $this->assertSame(
            GiftCardCode::STATUS_UNUSED,
            (int) $code->fresh()->status
        );
    }

    public function test_explicit_export_is_the_only_admin_list_boundary_for_a_complete_code(): void
    {
        $admin = $this->makeUser(['is_admin' => true]);
        $template = $this->makeTemplate();
        $code = $this->makeCode($template, [
            'batch_id' => 'batch_export_test',
        ]);

        Sanctum::actingAs($admin);
        $response = $this->get($this->routePath(AdminGiftCardController::class . '@exportCodes') . '?batch_id=' . $code->batch_id)
            ->assertOk();

        $this->assertSame($code->code, trim($response->getContent()));
        $this->assertStringNotContainsString((string) $admin->id . ',', $response->getContent());
    }

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'email' => Str::lower(Str::random(16)) . '@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'uuid' => (string) Str::uuid(),
            'token' => Str::random(32),
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
            'expired_at' => 0,
            'remind_expire' => 1,
            'remind_traffic' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }

    private function makeTemplate(array $overrides = []): GiftCardTemplate
    {
        return GiftCardTemplate::create(array_merge([
            'name' => 'Security test template',
            'description' => 'Security test template',
            'type' => GiftCardTemplate::TYPE_GENERAL,
            'status' => 1,
            'conditions' => null,
            'rewards' => ['balance' => 10],
            'limits' => null,
            'special_config' => null,
            'icon' => null,
            'background_image' => null,
            'theme_color' => '#1890ff',
            'sort' => 0,
            'admin_id' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }

    private function makeCode(GiftCardTemplate $template, array $overrides = []): GiftCardCode
    {
        return GiftCardCode::create(array_merge([
            'template_id' => $template->id,
            'code' => 'GC' . strtoupper(Str::random(12)),
            'batch_id' => 'batch_security_test',
            'status' => GiftCardCode::STATUS_UNUSED,
            'user_id' => null,
            'used_at' => null,
            'expires_at' => null,
            'actual_rewards' => null,
            'usage_count' => 0,
            'max_usage' => 1,
            'metadata' => null,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }

    private function makeUsage(
        GiftCardTemplate $template,
        GiftCardCode $code,
        User $user,
        ?User $inviteUser = null
    ): GiftCardUsage {
        return GiftCardUsage::create([
            'code_id' => $code->id,
            'template_id' => $template->id,
            'user_id' => $user->id,
            'invite_user_id' => $inviteUser?->id,
            'rewards_given' => ['balance' => 10],
            'invite_rewards' => null,
            'user_level_at_use' => null,
            'plan_id_at_use' => null,
            'multiplier_applied' => 1.0,
            'user_agent' => 'gift-card-security-test',
            'notes' => null,
            'created_at' => time(),
        ]);
    }

    private function routePath(string $action): string
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            if ($route->getActionName() === $action) {
                return '/' . ltrim($route->uri(), '/');
            }
        }

        $this->fail("Route not found for {$action}");
    }
}
