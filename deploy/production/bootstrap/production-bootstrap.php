<?php

declare(strict_types=1);

use App\Models\McpKey;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\McpKeyService;
use App\Services\UserService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function requiredEnvironment(string $name): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        throw new RuntimeException("{$name} is required");
    }

    return trim($value);
}

function requiredSecretFile(string $name): string
{
    $path = getenv($name);
    if ($path === false || $path === '' || !is_readable($path)) {
        throw new RuntimeException("{$name} does not reference a readable file");
    }

    $value = rtrim((string) file_get_contents($path), "\r\n");
    if ($value === '') {
        throw new RuntimeException("{$name} references an empty file");
    }

    return $value;
}

function writeOneTimeSecret(string $path, string $value): void
{
    if (!str_starts_with($path, '/runtime-secrets/')) {
        throw new RuntimeException('MCP_KEY_OUTPUT_FILE must be below /runtime-secrets');
    }

    $temp = $path . '.tmp';
    if (file_put_contents($temp, $value . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Unable to write the one-time MCP key');
    }
    chmod($temp, 0600);
    if (!rename($temp, $path)) {
        throw new RuntimeException('Unable to install the one-time MCP key');
    }
}

if (getenv('PRODUCTION_BOOTSTRAP_CONFIRM') !== '1') {
    throw new RuntimeException('PRODUCTION_BOOTSTRAP_CONFIRM=1 is required');
}

$panelUrl = rtrim(requiredEnvironment('APP_URL'), '/');
$adminAccount = strtolower(requiredEnvironment('ADMIN_ACCOUNT'));
$testUserEmail = strtolower(requiredEnvironment('PRODUCTION_TEST_USER_EMAIL'));
$mcpKeyOutput = requiredEnvironment('MCP_KEY_OUTPUT_FILE');
$adminPassword = requiredSecretFile('ADMIN_PASSWORD_FILE');
$testUserPassword = requiredSecretFile('TEST_USER_PASSWORD_FILE');
$serverToken = requiredSecretFile('SERVER_TOKEN_FILE');

if (filter_var($panelUrl, FILTER_VALIDATE_URL) === false || !str_starts_with($panelUrl, 'https://')) {
    throw new RuntimeException('APP_URL must be an absolute HTTPS URL');
}
foreach ([$adminAccount, $testUserEmail] as $email) {
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('Bootstrap email is invalid');
    }
}
if (strlen($adminPassword) < 8 || strlen($testUserPassword) < 8) {
    throw new RuntimeException('Bootstrap passwords must contain at least 8 characters');
}
if (strlen($serverToken) < 32) {
    throw new RuntimeException('SERVER_TOKEN must contain at least 32 characters');
}

$mcpSecret = DB::transaction(function () use (
    $panelUrl,
    $adminAccount,
    $testUserEmail,
    $adminPassword,
    $testUserPassword,
    $serverToken,
): string {
    admin_setting([
        'app_url' => $panelUrl,
        'secure_path' => 'unitedearthgov',
        'server_token' => $serverToken,
        'server_ws_enable' => 1,
        'server_pull_interval' => 10,
        'server_push_interval' => 10,
        'app_name' => 'UEG-Net',
        'app_description' => 'UEG global CDN access',
        'mcp_enabled' => 1,
    ]);

    $admin = User::byEmail($adminAccount)->firstOrFail();
    $admin->password = Hash::make($adminPassword);
    $admin->is_admin = true;
    $admin->is_staff = false;
    $admin->banned = false;
    $admin->save();

    $group = ServerGroup::query()->where('name', 'Production Access')->first();
    if ($group === null) {
        $group = new ServerGroup();
        $group->name = 'Production Access';
        $group->save();
    }

    $testUser = User::byEmail($testUserEmail)->first();
    if ($testUser === null) {
        $testUser = app(UserService::class)->createUser([
            'email' => $testUserEmail,
            'password' => $testUserPassword,
        ]);
    }
    $testUser->email = $testUserEmail;
    $testUser->password = Hash::make($testUserPassword);
    $testUser->group_id = $group->id;
    $testUser->plan_id = null;
    $testUser->transfer_enable = 100 * 1024 * 1024 * 1024;
    $testUser->u = 0;
    $testUser->d = 0;
    $testUser->banned = false;
    $testUser->is_admin = false;
    $testUser->is_staff = false;
    $testUser->expired_at = null;
    $testUser->save();

    McpKey::query()
        ->where('admin_id', $admin->id)
        ->where('name', 'Production Codex')
        ->whereNull('revoked_at')
        ->update(['revoked_at' => time(), 'updated_at' => time()]);

    $created = app(McpKeyService::class)->create($admin, [
        'name' => 'Production Codex',
        'scope' => 'full',
        'domains' => McpKeyService::DOMAINS,
        'client' => 'codex',
        'expires_in_days' => 365,
    ]);
    return $created['secret'];
});

writeOneTimeSecret($mcpKeyOutput, $mcpSecret);
unset($mcpSecret);
Cache::flush();
fwrite(STDOUT, "Production bootstrap complete: admin_path=unitedearthgov test_user={$testUserEmail} mcp_key=created-once\n");
