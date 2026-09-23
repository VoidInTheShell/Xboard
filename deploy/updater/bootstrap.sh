#!/bin/sh
set -eu

umask 077

: "${XBOARD_PANEL_URL:?XBOARD_PANEL_URL is required}"
: "${XBOARD_ADMIN_VERSION:?XBOARD_ADMIN_VERSION is required}"
: "${XBOARD_DEPLOY_DIR:?XBOARD_DEPLOY_DIR is required}"
: "${XBOARD_COMPOSE_FILE:?XBOARD_COMPOSE_FILE is required}"
: "${XBOARD_ENV_FILE:?XBOARD_ENV_FILE is required}"
: "${XBOARD_HEALTH_URL:?XBOARD_HEALTH_URL is required}"
: "${XBOARD_COMPOSE_PROJECT:?XBOARD_COMPOSE_PROJECT is required}"
: "${XBOARD_BACKEND_CONTAINER:?XBOARD_BACKEND_CONTAINER is required}"

# The updater image normally tracks the Admin image. Keep the default for
# existing Compose files while allowing an explicit updater version when the
# two images are published independently.
: "${XBOARD_UPDATER_VERSION:=${XBOARD_ADMIN_VERSION}}"
: "${XBOARD_ADMIN_HEALTH_URL:=http://xboard-admin/healthz}"
: "${XBOARD_THEME_HEALTH_URL:=http://xboard-theme/healthz}"
: "${XBOARD_COMPOSE_EXTRA_FILE:=}"
# Compose service keys for the registered targets. The defaults match
# compose.sample.yaml; deployments built from a different Compose file (for
# example the staging/production layouts whose theme service is named
# "theme") override these through the same environment file.
: "${XBOARD_BACKEND_COMPOSE_SERVICE:=xboard}"
: "${XBOARD_ADMIN_COMPOSE_SERVICE:=xboard-admin}"
: "${XBOARD_THEME_COMPOSE_SERVICE:=xboard-theme}"
# Optional Caddy entry registration (compose.entry.sample.yaml). When enabled,
# the updater renders ${XBOARD_DEPLOY_DIR}/entry/Caddyfile from the
# panel-scope certificate resources and falls back to these seed domains.
: "${XBOARD_ENTRY_ENABLED:=}"
: "${XBOARD_PANEL_DOMAIN:=}"

config_dir=/etc/xboard-updater
state_dir=/var/lib/xboard-updater
compose_env_source=/bootstrap/deploy.env
token_file="$config_dir/token"
config_file="$config_dir/config.json"

install -d -m 700 "$config_dir" "$state_dir" "$state_dir/backups"

# Keep the panel URL contract at the enrollment boundary. HTTP is only
# accepted for loopback development endpoints; a public panel must use HTTPS.
# Version values are used as Docker tags, so reject path separators and other
# characters that could turn the configured image into an unintended reference.
export XBOARD_PANEL_URL XBOARD_ADMIN_VERSION XBOARD_UPDATER_VERSION XBOARD_DEPLOY_DIR XBOARD_COMPOSE_EXTRA_FILE
export XBOARD_THEME_HEALTH_URL XBOARD_THEME_COMPOSE_SERVICE XBOARD_ENTRY_ENABLED XBOARD_PANEL_DOMAIN
php -r '
$panelURL = getenv("XBOARD_PANEL_URL");
$parts = is_string($panelURL) ? parse_url($panelURL) : false;
$scheme = is_array($parts) ? strtolower((string) ($parts["scheme"] ?? "")) : "";
$host = is_array($parts) ? strtolower((string) ($parts["host"] ?? "")) : "";
$loopback = in_array($host, ["127.0.0.1", "localhost", "::1", "[::1]"], true);
if (!is_array($parts) || $host === "" || isset($parts["user"], $parts["pass"], $parts["query"], $parts["fragment"]) || ($scheme !== "https" && !($scheme === "http" && $loopback))) {
    fwrite(STDERR, "XBOARD_PANEL_URL must be an HTTPS URL (or loopback HTTP)\n");
    exit(1);
}
foreach (["XBOARD_ADMIN_VERSION", "XBOARD_UPDATER_VERSION"] as $name) {
    $value = getenv($name);
    if (!is_string($value) || !preg_match("/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D", $value)) {
        fwrite(STDERR, $name . " is not a valid image tag\n");
        exit(1);
    }
}
'
case "$XBOARD_DEPLOY_DIR" in
    /*) ;;
    *)
        echo 'XBOARD_DEPLOY_DIR must be an absolute path' >&2
        exit 1
        ;;
esac
if [ "$XBOARD_DEPLOY_DIR" = "/" ]; then
    echo 'XBOARD_DEPLOY_DIR must not be the filesystem root' >&2
    exit 1
fi

# A restart of the one-shot Compose service must not rotate a live executor.
# If an older config has a valid identity but lacks the Admin target, retain
# its token/identity and rewrite the complete desired config below. Only a
# missing or malformed identity requires provisioning a new credential.
executor_id=""
if [ -s "$token_file" ] && [ -s "$config_file" ]; then
    executor_id="$(CONFIG_FILE="$config_file" php -r '
$path = getenv("CONFIG_FILE");
$raw = @file_get_contents($path);
$value = is_string($raw) ? json_decode($raw, true) : null;
$id = is_array($value) ? ($value["executor_id"] ?? null) : null;
if (!is_string($id) || !preg_match("/^[A-Za-z0-9][A-Za-z0-9_.-]{0,79}$/D", $id)) {
    exit(1);
}
echo $id;
')" || executor_id=""
fi

if [ -z "$executor_id" ]; then
    # The command prints the credential only once. Parse it in memory and
    # never echo the command output, so the token cannot enter container logs.
    provisioned=$(php /www/artisan update:executor panel \
        --name 'Compose 面板 Updater' \
        --updater-version "$XBOARD_UPDATER_VERSION" \
        --installation-method compose \
        --rotate 2>/dev/null | tail -n 1)
    token=$(printf '%s\n' "$provisioned" | php -r '
$value = json_decode(stream_get_contents(STDIN), true);
if (!is_array($value) || !is_string($value["token"] ?? null) || !is_string($value["executor_id"] ?? null)) {
    exit(1);
}
echo $value["token"];
')
    executor_id=$(printf '%s\n' "$provisioned" | php -r '
$value = json_decode(stream_get_contents(STDIN), true);
if (!is_array($value) || !is_string($value["executor_id"] ?? null)) {
    exit(1);
}
echo $value["executor_id"];
')

    test -n "$token"
    token_tmp=$(mktemp "$config_dir/.token.XXXXXX")
    trap 'rm -f "$token_tmp"' EXIT INT TERM
    printf '%s' "$token" > "$token_tmp"
    chmod 600 "$token_tmp"
    mv -f "$token_tmp" "$token_file"
    trap - EXIT INT TERM
    unset token provisioned
fi
chmod 600 "$token_file"

# The hook is copied into the same private volume as the generated credential;
# it calls the fixed backend container and never accepts a task-provided shell
# command or path.
install -m 700 /bootstrap/panel-hook.sh "$config_dir/panel-hook.sh"
printf '%s\n' "$XBOARD_BACKEND_CONTAINER" > "$config_dir/backend-container"
chmod 600 "$config_dir/backend-container"
install -m 600 "$compose_env_source" "$config_dir/deploy.env"

export XBOARD_COMPOSE_FILE XBOARD_ENV_FILE XBOARD_HEALTH_URL XBOARD_ADMIN_HEALTH_URL XBOARD_THEME_HEALTH_URL
export XBOARD_COMPOSE_PROJECT XBOARD_BACKEND_CONTAINER
export XBOARD_BACKEND_COMPOSE_SERVICE XBOARD_ADMIN_COMPOSE_SERVICE XBOARD_THEME_COMPOSE_SERVICE
export XBOARD_ENTRY_ENABLED XBOARD_PANEL_DOMAIN
export CONFIG_FILE="$config_file" TOKEN_FILE="$token_file" STATE_DIR="$state_dir"
export HANDOFF_PATH="$state_dir/handoff.json" EXECUTOR_ID="$executor_id"
php -r '
$path = getenv("CONFIG_FILE");
$executorId = getenv("EXECUTOR_ID");
if (!is_string($executorId) || !preg_match("/^[A-Za-z0-9][A-Za-z0-9_.-]{0,79}$/D", $executorId)) {
    exit(1);
}

$hook = [
    "quiesce" => ["/etc/xboard-updater/panel-hook.sh", "quiesce", "{task_dir}"],
    "backup" => ["/etc/xboard-updater/panel-hook.sh", "backup", "{task_dir}"],
    "migrate" => ["/etc/xboard-updater/panel-hook.sh", "migrate", "{task_dir}"],
    "verify" => ["/etc/xboard-updater/panel-hook.sh", "verify", "{task_dir}"],
    "restore" => ["/etc/xboard-updater/panel-hook.sh", "restore", "{task_dir}"],
    "resume" => ["/etc/xboard-updater/panel-hook.sh", "resume", "{task_dir}"],
];
$composeEnvFile = "/etc/xboard-updater/deploy.env";
$extraCompose = [];
$extraComposeFile = getenv("XBOARD_COMPOSE_EXTRA_FILE");
if (is_string($extraComposeFile) && $extraComposeFile !== "") {
    $extraCompose[] = $extraComposeFile;
}
$targets = [
    [
        "id" => "backend",
        "name" => "Xboard 后端",
        "component" => "xboard",
        "method" => "compose",
        "compose_file" => getenv("XBOARD_COMPOSE_FILE"),
        "compose_env_file" => $composeEnvFile,
        "compose_extra_files" => $extraCompose,
        "compose_project" => getenv("XBOARD_COMPOSE_PROJECT"),
        "compose_service" => getenv("XBOARD_BACKEND_COMPOSE_SERVICE") ?: "xboard",
        "health_url" => getenv("XBOARD_HEALTH_URL"),
    ] + $hook,
    [
        "id" => "admin",
        "name" => "Xboard Admin",
        "component" => "xboard-admin",
        "method" => "compose",
        "compose_file" => getenv("XBOARD_COMPOSE_FILE"),
        "compose_env_file" => $composeEnvFile,
        "compose_extra_files" => $extraCompose,
        "compose_project" => getenv("XBOARD_COMPOSE_PROJECT"),
        "compose_service" => getenv("XBOARD_ADMIN_COMPOSE_SERVICE") ?: "xboard-admin",
        "health_url" => getenv("XBOARD_ADMIN_HEALTH_URL"),
    ],
    [
        "id" => "theme",
        "name" => "DK Theme",
        "component" => "dk_theme",
        "method" => "compose",
        "compose_file" => getenv("XBOARD_COMPOSE_FILE"),
        "compose_env_file" => $composeEnvFile,
        "compose_extra_files" => $extraCompose,
        "compose_project" => getenv("XBOARD_COMPOSE_PROJECT"),
        "compose_service" => getenv("XBOARD_THEME_COMPOSE_SERVICE") ?: "xboard-theme",
        "health_url" => getenv("XBOARD_THEME_HEALTH_URL"),
    ],
];

$themeHealthUrl = getenv("XBOARD_THEME_HEALTH_URL");
if (!is_string($themeHealthUrl) || filter_var($themeHealthUrl, FILTER_VALIDATE_URL) === false) {
    fwrite(STDERR, "XBOARD_THEME_HEALTH_URL is not a valid URL\n");
    exit(1);
}

$entryEnabled = getenv("XBOARD_ENTRY_ENABLED") === "1";
$seedDomains = [];
$panelDomainEnv = getenv("XBOARD_PANEL_DOMAIN");
if (is_string($panelDomainEnv) && trim($panelDomainEnv) !== "") {
    foreach (explode(",", $panelDomainEnv) as $seedDomain) {
        $seedDomain = strtolower(trim($seedDomain));
        if (!preg_match(
            "/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,}$/D",
            $seedDomain
        )) {
            fwrite(STDERR, "XBOARD_PANEL_DOMAIN contains an invalid domain: " . $seedDomain . "\n");
            exit(1);
        }
        $seedDomains[] = $seedDomain;
    }
    $seedDomains = array_values(array_unique($seedDomains));
}
if ($entryEnabled && $seedDomains === []) {
    $panelUrl = getenv("XBOARD_PANEL_URL");
    $parts = is_string($panelUrl) ? parse_url($panelUrl) : false;
    $host = is_array($parts) ? strtolower((string) ($parts["host"] ?? "")) : "";
    $loopbackHosts = ["127.0.0.1", "localhost", "::1", "[::1]"];
    $isLoopback = in_array($host, $loopbackHosts, true);
    $isIp = $host !== "" && filter_var(trim($host, "[]"), FILTER_VALIDATE_IP) !== false;
    if ($host === "" || $isLoopback || $isIp) {
        fwrite(STDERR, "XBOARD_ENTRY_ENABLED=1 requires XBOARD_PANEL_DOMAIN or a public HTTPS panel URL host\n");
        exit(1);
    }
    $seedDomains[] = $host;
}
$config = [
    "panel_url" => getenv("XBOARD_PANEL_URL"),
    "executor_id" => $executorId,
    "token_file" => getenv("TOKEN_FILE"),
    "state_dir" => getenv("STATE_DIR"),
    "handoff_path" => getenv("HANDOFF_PATH"),
    "installation_method" => "compose",
    "deployment_dir" => getenv("XBOARD_DEPLOY_DIR"),
    "updater_container" => "xboard-updater",
    "updater_service" => "xboard-updater",
    "updater_image" => "ghcr.io/voidintheshell/xboard-admin-updater:" . getenv("XBOARD_UPDATER_VERSION"),
    "targets" => $targets,
];
if ($entryEnabled) {
    $config["panel_entry"] = [
        "enabled" => true,
        "caddy_container" => "xboard-entry",
        "caddyfile_path" => getenv("XBOARD_DEPLOY_DIR") . "/entry/Caddyfile",
        "caddy_config_path" => "/etc/caddy/Caddyfile",
        "caddy_data_path" => "/entry-caddy-data",
        "caddy_internal_data_path" => "/data",
        "cert_material_dir" => "/entry-certs",
        "seed_domains" => $seedDomains,
        "theme_upstream" => "http://xboard-theme:80",
    ];
}
$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($encoded)) {
    exit(1);
}
$tmp = tempnam(dirname($path), ".config-");
if ($tmp === false || file_put_contents($tmp, $encoded) !== strlen($encoded)) {
    if ($tmp !== false) {
        @unlink($tmp);
    }
    exit(1);
}
chmod($tmp, 0600);
if (!rename($tmp, $path)) {
    @unlink($tmp);
    exit(1);
}
chmod($path, 0600);
'
