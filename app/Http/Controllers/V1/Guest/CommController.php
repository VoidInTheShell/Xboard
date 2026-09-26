<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\Plugin\HookManager;
use App\Utils\Dict;
use App\Utils\Helper;
use Illuminate\Support\Facades\Http;

class CommController extends Controller
{
    public function config()
    {
        $data = [
            'tos_url' => admin_setting('tos_url'),
            'is_email_verify' => (int) admin_setting('email_verify', 0) ? 1 : 0,
            'is_invite_force' => (int) admin_setting('invite_force', 0) ? 1 : 0,
            'email_whitelist_suffix' => (int) admin_setting('email_whitelist_enable', 0)
                ? Helper::getEmailSuffix()
                : 0,
            'is_captcha' => (int) admin_setting('captcha_enable', 0) ? 1 : 0,
            'captcha_type' => admin_setting('captcha_type', 'recaptcha'),
            'recaptcha_site_key' => admin_setting('recaptcha_site_key'),
            'recaptcha_v3_site_key' => admin_setting('recaptcha_v3_site_key'),
            'recaptcha_v3_score_threshold' => admin_setting('recaptcha_v3_score_threshold', 0.5),
            'turnstile_site_key' => admin_setting('turnstile_site_key'),
            'app_name' => admin_setting('app_name', 'XBoard'),
            'app_description' => admin_setting('app_description'),
            'app_url' => admin_setting('app_url'),
            'logo' => admin_setting('logo'),
            'user_logo' => admin_setting('user_logo'),
            'user_login_title' => admin_setting('user_login_title'),
            'user_login_description' => admin_setting('user_login_description'),
            'self_use_mode' => (int) admin_setting('self_use_mode', 0) ? 1 : 0,
            'admin_login_background' => admin_setting('admin_login_background'),
            'admin_login_glass_opacity' => (int) admin_setting('admin_login_glass_opacity', 60),
            'admin_login_mask_opacity' => (int) admin_setting('admin_login_mask_opacity', 40),
            'admin_login_theme_color' => admin_setting('admin_login_theme_color', ''),
            'user_hidden_menus' => (array) admin_setting('user_hidden_menus', []),
            'admin_hidden_menus' => (array) admin_setting('admin_hidden_menus', []),
            'user_support_enabled' => (bool) admin_setting('user_support_enabled', 1),
            'user_support_description' => admin_setting('user_support_description'),
            'user_support_telegram_label' => admin_setting('user_support_telegram_label'),
            'user_support_telegram_url' => admin_setting('user_support_telegram_url'),
            'user_support_group_label' => admin_setting('user_support_group_label'),
            'user_support_group_url' => admin_setting('user_support_group_url'),
            'user_support_ticket_enabled' => (bool) admin_setting('user_support_ticket_enabled', 1),
            'user_support_knowledge_enabled' => (bool) admin_setting('user_support_knowledge_enabled', 1),
            'notice_acknowledgement' => true,
            // 保持向后兼容
            'is_recaptcha' => (int) admin_setting('captcha_enable', 0) ? 1 : 0,
        ];

        $data = HookManager::filter('guest_comm_config', $data);

        return $this->success($data);
    }
}
