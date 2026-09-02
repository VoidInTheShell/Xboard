const CARD_ID = "ueg-self-use-mode";
const FRONTEND_CONFIG_ROUTE = "/config/system/frontend";
const TOKEN_STORAGE_KEY = "XBOARD_ACCESS_TOKEN";
const NAVIGATION_ID = "ueg-frontend-config-navigation";
const MOBILE_NAVIGATION_ID = "ueg-frontend-config-mobile-navigation";
const SYSTEM_CONFIG_ROUTE_PATTERN = /\/config\/system(?:\/[^/?#]*)?\/?$/;
const CLIENT_SETTINGS_ROUTE = "/config/system/app";
const CLIENT_SETTINGS_ROOT_ID = "ueg-client-settings-root";
const CLIENT_EDITOR_ID = "ueg-client-editor";
const CLIENT_SOURCE_HIDDEN_CLASS = "ueg-client-settings-source-hidden";
const CLIENT_PREVIEW_MODE = document.body?.dataset.clientSettingsPreview === "true";
const CLIENT_PREVIEW_EDITOR = CLIENT_PREVIEW_MODE
  ? new URLSearchParams(window.location.search).get("editor")
  : null;

const CLIENT_TEMPLATE_OPTIONS = [
  { value: "singbox", label: "Sing-box" },
  { value: "clash", label: "Clash" },
  { value: "clashmeta", label: "Clash Meta" },
  { value: "stash", label: "Stash" },
  { value: "surge", label: "Surge" },
  { value: "surfboard", label: "Surfboard" },
];

const CLIENT_DEVICE_OPTIONS = [
  { value: "desktop", label: "桌面端" },
  { value: "mobile", label: "移动端" },
];

const CLIENT_PLATFORM_OPTIONS = [
  { value: "windows", label: "Windows", device: "desktop" },
  { value: "mac-intel", label: "Mac (Intel)", device: "desktop" },
  { value: "mac-apple-silicon", label: "Mac (Apple Silicon)", device: "desktop" },
  { value: "linux", label: "Linux", device: "desktop" },
  { value: "ios", label: "iOS", device: "mobile" },
  { value: "android", label: "Android", device: "mobile" },
];

const DEFAULT_CLIENTS = [
  {
    id: "clash-party",
    name: "Clash Party",
    logoMode: "url",
    logoUrl: "https://github.com/mihomo-party-org.png?size=160",
    logoDataUrl: "",
    logoFileName: "",
    description: "原 Mihomo Party。面向桌面端的 Mihomo 图形客户端，适合日常规则分流与多订阅管理。",
    deviceTypes: ["desktop"],
    platforms: ["windows", "mac-intel", "mac-apple-silicon", "linux"],
    tags: ["推荐", "原 Mihomo Party"],
    downloadUrl: "https://github.com/mihomo-party-org/clash-party/releases",
    quickImportEnabled: true,
    quickImportUrl: "clash://install-config?url={url}&name={name}",
    subscriptionTemplate: "clashmeta",
  },
  {
    id: "clash-verge",
    name: "Clash Verge",
    logoMode: "url",
    logoUrl: "https://github.com/clash-verge-rev.png?size=160",
    logoDataUrl: "",
    logoFileName: "",
    description: "跨平台 Mihomo 桌面客户端，适合 Windows、macOS 与 Linux 用户。",
    deviceTypes: ["desktop"],
    platforms: ["windows", "mac-intel", "mac-apple-silicon", "linux"],
    tags: ["桌面端", "Mihomo"],
    downloadUrl: "https://github.com/clash-verge-rev/clash-verge-rev/releases",
    quickImportEnabled: true,
    quickImportUrl: "clash://install-config?url={url}&name={name}",
    subscriptionTemplate: "clashmeta",
  },
  {
    id: "flclash",
    name: "FlClash",
    logoMode: "url",
    logoUrl: "https://github.com/chen08209.png?size=160",
    logoDataUrl: "",
    logoFileName: "",
    description: "基于 Flutter 的跨平台 Clash Meta 客户端，同一条配置覆盖桌面端与 Android。",
    deviceTypes: ["desktop", "mobile"],
    platforms: ["windows", "mac-intel", "mac-apple-silicon", "linux", "android"],
    tags: ["跨平台", "Android"],
    downloadUrl: "https://github.com/chen08209/FlClash/releases",
    quickImportEnabled: true,
    quickImportUrl: "clash://install-config?url={url}&name={name}",
    subscriptionTemplate: "clashmeta",
  },
  {
    id: "clash-mi",
    name: "Clash Mi",
    logoMode: "url",
    logoUrl: "https://github.com/KaringX.png?size=160",
    logoDataUrl: "",
    logoFileName: "",
    description: "基于 Flutter 与 Mihomo 的现代客户端，本目录默认向用户展示 Android 入口。",
    deviceTypes: ["mobile"],
    platforms: ["android"],
    tags: ["Android", "Mihomo"],
    downloadUrl: "https://github.com/KaringX/clashmi/releases",
    quickImportEnabled: true,
    quickImportUrl: "clash://install-config?url={url}&name={name}",
    subscriptionTemplate: "clashmeta",
  },
  {
    id: "clash-meta-android",
    name: "Clash Meta",
    logoMode: "url",
    logoUrl: "https://github.com/MetaCubeX.png?size=160",
    logoDataUrl: "",
    logoFileName: "",
    description: "MetaCubeX 提供的 Android 图形客户端，面向 Clash Meta 配置与规则体系。",
    deviceTypes: ["mobile"],
    platforms: ["android"],
    tags: ["Android", "Clash Meta"],
    downloadUrl: "https://github.com/MetaCubeX/ClashMetaForAndroid/releases",
    quickImportEnabled: true,
    quickImportUrl: "clashmeta://install-config?url={url}&name={name}",
    subscriptionTemplate: "clashmeta",
  },
  {
    id: "hiddify",
    name: "Hiddify",
    logoMode: "url",
    logoUrl: "https://github.com/hiddify.png?size=160",
    logoDataUrl: "",
    logoFileName: "",
    description: "界面简洁的多协议客户端，支持 Sing-box、Clash 与 Clash Meta 等订阅格式。",
    deviceTypes: ["mobile"],
    platforms: ["android"],
    tags: ["Android", "Sing-box"],
    downloadUrl: "https://github.com/hiddify/hiddify-app/releases",
    quickImportEnabled: false,
    quickImportUrl: "",
    subscriptionTemplate: "singbox",
  },
  {
    id: "surfboard",
    name: "Surfboard",
    logoMode: "url",
    logoUrl: "https://github.com/getsurfboard.png?size=160",
    logoDataUrl: "",
    logoFileName: "",
    description: "面向 Android 的规则代理客户端，适合使用 Surfboard 配置模板的用户。",
    deviceTypes: ["mobile"],
    platforms: ["android"],
    tags: ["Android", "Surfboard"],
    downloadUrl: "https://github.com/getsurfboard/surfboard/releases",
    quickImportEnabled: false,
    quickImportUrl: "",
    subscriptionTemplate: "surfboard",
  },
];

function getPlatformDevice(platform) {
  return CLIENT_PLATFORM_OPTIONS.find((option) => option.value === platform)?.device || null;
}

function buildPreviewClients() {
  const categoryOrders = new Map();
  return DEFAULT_CLIENTS.map((client) => {
    const scopes = client.platforms.map((platform) => {
      const deviceType = getPlatformDevice(platform);
      const key = `${deviceType}:${platform}`;
      const sortOrder = (categoryOrders.get(key) || 0) + 10;
      categoryOrders.set(key, sortOrder);
      return { deviceType, platform, sortOrder };
    });
    return {
      ...client,
      tags: [...client.tags],
      deviceTypes: [...client.deviceTypes],
      platforms: [...client.platforms],
      scopes,
      docsUrl: client.docsUrl || "",
      hasUploadedLogo: Boolean(client.logoDataUrl),
      isBuiltin: true,
    };
  });
}

function normalizeClientRecord(client) {
  const scopes = (client.scopes || []).map((scope) => ({
    deviceType: scope.device_type ?? scope.deviceType,
    platform: scope.platform,
    sortOrder: Number(scope.sort_order ?? scope.sortOrder ?? 0),
  }));
  const platforms = [...new Set(scopes.map((scope) => scope.platform))];
  const deviceTypes = [...new Set(scopes.map((scope) => scope.deviceType))];
  return {
    id: client.id,
    slug: client.slug || String(client.id),
    name: client.name || "",
    logoMode: client.logo_mode ?? client.logoMode ?? "url",
    logoUrl: client.logo_url ?? client.logoUrl ?? "",
    logoDataUrl: client.logoDataUrl || "",
    logoFileName: client.logoFileName || "",
    description: client.description || "",
    deviceTypes,
    platforms,
    scopes,
    tags: Array.isArray(client.tags) ? [...client.tags] : [],
    downloadUrl: client.download_url ?? client.downloadUrl ?? "",
    docsUrl: client.docs_url ?? client.docsUrl ?? "",
    quickImportEnabled: Boolean(client.quick_import_enabled ?? client.quickImportEnabled),
    quickImportUrl: client.quick_import_url ?? client.quickImportUrl ?? "",
    subscriptionTemplate: client.subscription_template ?? client.subscriptionTemplate ?? "clashmeta",
    hasUploadedLogo: Boolean(client.has_uploaded_logo ?? client.hasUploadedLogo),
    isBuiltin: Boolean(client.is_builtin ?? client.isBuiltin),
  };
}

const clientSettingsState = {
  clients: CLIENT_PREVIEW_MODE ? buildPreviewClients() : [],
  deviceType: "desktop",
  platform: "windows",
  loading: !CLIENT_PREVIEW_MODE,
  loaded: CLIENT_PREVIEW_MODE,
  error: "",
  lastAction: CLIENT_PREVIEW_MODE
    ? "默认客户端已载入。当前修改只保留在本地页面中。"
    : "正在读取客户端目录…",
};

let injectionPending = false;
let navigationPending = false;
let accessDenied = false;
let frontendConfigPromise = null;
let clientSettingsPending = false;

function isFrontendConfigRoute() {
  return `${window.location.pathname}${window.location.hash}`.includes(FRONTEND_CONFIG_ROUTE);
}

function isSystemConfigRoute() {
  return `${window.location.pathname}${window.location.hash}`.includes("/config/system");
}

function isClientSettingsRoute() {
  return CLIENT_PREVIEW_MODE || `${window.location.pathname}${window.location.hash}`.includes(CLIENT_SETTINGS_ROUTE);
}

function getAccessToken() {
  const rawValue = window.localStorage.getItem(TOKEN_STORAGE_KEY);
  if (!rawValue) return null;

  try {
    const stored = JSON.parse(rawValue);
    if (stored.expire !== null && stored.expire !== undefined && stored.expire <= Date.now()) {
      return null;
    }
    return stored.value || null;
  } catch {
    return null;
  }
}

function getApiUrl(path) {
  const baseUrl = window.settings?.base_url || "/";
  const normalizedBaseUrl = baseUrl.endsWith("/") ? baseUrl : `${baseUrl}/`;
  const securePath = encodeURIComponent(window.settings?.secure_path || "");
  return `${normalizedBaseUrl}api/v2/${securePath}/${path}`;
}

async function request(path, options = {}) {
  const accessToken = getAccessToken();
  if (!accessToken) {
    const error = new Error("登录状态已失效");
    error.status = 401;
    throw error;
  }

  const response = await window.fetch(getApiUrl(path), {
    ...options,
    headers: {
      Accept: "application/json",
      Authorization: accessToken,
      "Content-Language": window.localStorage.getItem("XBOARD_LOCALE") || "zh-CN",
      ...options.headers,
    },
  });
  const payload = await response.json().catch(() => ({}));

  if (!response.ok) {
    const validationMessage = Object.values(payload.errors || {}).flat().find(Boolean);
    const error = new Error(validationMessage || payload.message || "请求失败，请稍后重试");
    error.status = response.status;
    throw error;
  }

  return payload.data;
}

function getFrontendConfig() {
  if (!frontendConfigPromise) {
    frontendConfigPromise = request("config/fetch?key=frontend").catch((error) => {
      if (error.status === 401 || error.status === 403) {
        accessDenied = true;
      } else {
        frontendConfigPromise = null;
      }
      throw error;
    });
  }

  return frontendConfigPromise;
}

function getFrontendConfigHref(referenceLink) {
  const url = new URL(referenceLink.href, window.location.href);
  if (SYSTEM_CONFIG_ROUTE_PATTERN.test(url.hash)) {
    url.hash = url.hash.replace(SYSTEM_CONFIG_ROUTE_PATTERN, FRONTEND_CONFIG_ROUTE);
  } else {
    url.pathname = url.pathname.replace(SYSTEM_CONFIG_ROUTE_PATTERN, FRONTEND_CONFIG_ROUTE);
    url.search = "";
    url.hash = "";
  }
  return url.href;
}

function syncNavigationState(link) {
  const active = isFrontendConfigRoute();
  link.classList.toggle("bg-muted", active);
  link.classList.toggle("hover:bg-muted", active);
  link.classList.toggle("hover:bg-transparent", !active);
  link.classList.toggle("hover:underline", !active);

  if (active) {
    link.setAttribute("aria-current", "page");
  } else {
    link.removeAttribute("aria-current");
  }
}

function createDesktopNavigation(referenceLink, href) {
  const link = referenceLink.cloneNode(true);
  const icon = link.querySelector("span")?.cloneNode(true);

  link.id = NAVIGATION_ID;
  link.href = href;
  link.replaceChildren();
  if (icon) link.append(icon);
  link.append(document.createTextNode("前端配置"));
  syncNavigationState(link);

  return link;
}

function createMobileNavigation(href) {
  const link = document.createElement("a");
  link.id = MOBILE_NAVIGATION_ID;
  link.className = "ueg-frontend-config-mobile-link";
  link.href = href;
  link.textContent = "前端配置";
  if (isFrontendConfigRoute()) link.setAttribute("aria-current", "page");
  return link;
}

function findSystemSettingsNavigation() {
  return [...document.querySelectorAll("#root aside nav")].find((element) =>
    element.querySelector('a[href*="/config/system/server"]')
      && element.querySelector('a[href*="/config/system/app"]'),
  );
}

async function injectFrontendConfigNavigation() {
  if (accessDenied || navigationPending || !isSystemConfigRoute()) return;

  const navigation = findSystemSettingsNavigation();
  if (!navigation) return;

  const existingLink = document.getElementById(NAVIGATION_ID);
  if (existingLink) syncNavigationState(existingLink);

  if (existingLink && document.getElementById(MOBILE_NAVIGATION_ID)) return;

  navigationPending = true;
  try {
    await getFrontendConfig();
    if (accessDenied || !isSystemConfigRoute()) return;

    const currentNavigation = findSystemSettingsNavigation();
    if (!currentNavigation) return;

    const insertionAnchor = currentNavigation.querySelector('a[href*="/config/system/server"]');
    if (!insertionAnchor) return;

    const iconReference = currentNavigation.querySelector('a[href*="/config/system/app"]')
      || insertionAnchor;
    const href = getFrontendConfigHref(insertionAnchor);
    if (!document.getElementById(NAVIGATION_ID)) {
      const desktopLink = createDesktopNavigation(iconReference, href);
      insertionAnchor.before(desktopLink);
    }

    const aside = currentNavigation.closest("aside");
    if (aside && !document.getElementById(MOBILE_NAVIGATION_ID)) {
      aside.append(createMobileNavigation(href));
    }
  } catch (error) {
    if (error.status !== 401 && error.status !== 403) {
      console.error("Unable to add UEG frontend configuration navigation", error);
    }
  } finally {
    navigationPending = false;
  }
}

function createCard(enabled) {
  const card = document.createElement("section");
  card.id = CARD_ID;
  card.className = "ueg-self-use-card";
  card.setAttribute("aria-labelledby", `${CARD_ID}-title`);

  const copy = document.createElement("div");
  copy.className = "ueg-self-use-copy";

  const title = document.createElement("h4");
  title.id = `${CARD_ID}-title`;
  title.className = "ueg-self-use-title";
  title.textContent = "自用模式";

  const description = document.createElement("p");
  description.className = "ueg-self-use-description";
  description.textContent = "启用后，普通用户将隐藏订购套餐、订单中心和邀请返利，并显示配额信息。";

  const status = document.createElement("p");
  status.className = "ueg-self-use-status";
  status.setAttribute("aria-live", "polite");
  status.textContent = enabled ? "当前已启用" : "当前未启用";

  copy.append(title, description, status);

  const toggle = document.createElement("button");
  toggle.type = "button";
  toggle.className = "ueg-self-use-switch";
  toggle.setAttribute("role", "switch");
  toggle.setAttribute("aria-label", "自用模式");
  toggle.setAttribute("aria-checked", String(enabled));

  const thumb = document.createElement("span");
  thumb.className = "ueg-self-use-switch-thumb";
  thumb.setAttribute("aria-hidden", "true");
  toggle.append(thumb);

  toggle.addEventListener("click", async () => {
    const previousValue = toggle.getAttribute("aria-checked") === "true";
    const nextValue = !previousValue;

    toggle.disabled = true;
    toggle.setAttribute("aria-checked", String(nextValue));
    status.dataset.state = "pending";
    status.textContent = "正在保存…";

    try {
      await request("config/save", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ self_use_mode: nextValue }),
      });
      status.dataset.state = "success";
      status.textContent = nextValue ? "已启用并保存" : "已停用并保存";
    } catch (error) {
      toggle.setAttribute("aria-checked", String(previousValue));
      status.dataset.state = "error";
      status.textContent = error.message;
    } finally {
      toggle.disabled = false;
    }
  });

  card.append(copy, toggle);
  return card;
}

async function injectSelfUseMode() {
  if (accessDenied || injectionPending || !isFrontendConfigRoute() || document.getElementById(CARD_ID)) return;

  const form = document.querySelector("#root form.space-y-8");
  if (!form) return;

  injectionPending = true;
  try {
    const data = await getFrontendConfig();
    if (!isFrontendConfigRoute() || document.getElementById(CARD_ID)) return;

    const currentForm = document.querySelector("#root form.space-y-8");
    if (!currentForm) return;

    const card = createCard(Boolean(data?.frontend?.self_use_mode));
    const submitButton = currentForm.querySelector('button[type="submit"]');
    if (submitButton) {
      currentForm.insertBefore(card, submitButton);
    } else {
      currentForm.append(card);
    }
  } catch (error) {
    // A staff account receives 403 here; do not render an administrator-only control.
    if (error.status === 401 || error.status === 403) {
      accessDenied = true;
    } else {
      console.error("Unable to load UEG self-use mode setting", error);
    }
  } finally {
    injectionPending = false;
  }
}

function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function getClientTemplateLabel(value) {
  return CLIENT_TEMPLATE_OPTIONS.find((option) => option.value === value)?.label || value;
}

function getClientOptionLabels(values, options) {
  return values
    .map((value) => options.find((option) => option.value === value)?.label || value)
    .join(" · ");
}

function getClientLogoSource(client) {
  return client.logoMode === "upload" ? client.logoDataUrl || client.logoUrl : client.logoUrl;
}

function getClientInitials(name) {
  return String(name || "APP")
    .split(/\s+/)
    .map((part) => part.charAt(0))
    .join("")
    .slice(0, 2)
    .toUpperCase();
}

function renderClientLogo(client, className = "ueg-client-logo") {
  const source = getClientLogoSource(client);
  const initials = escapeHtml(getClientInitials(client.name));
  return `
    <span class="${className}" data-logo-fallback="${initials}">
      ${source ? `<img src="${escapeHtml(source)}" alt="${escapeHtml(client.name)} Logo" />` : `<span>${initials}</span>`}
    </span>
  `;
}

function bindLogoFallbacks(scope) {
  scope.querySelectorAll("[data-logo-fallback] img").forEach((image) => {
    image.addEventListener("error", () => {
      const container = image.closest("[data-logo-fallback]");
      if (!container) return;
      container.replaceChildren(document.createTextNode(container.dataset.logoFallback || "APP"));
    }, { once: true });
  });
}

function replaceLastTextNode(element, label) {
  const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
  const textNodes = [];
  let node = walker.nextNode();
  while (node) {
    if (node.nodeValue?.trim()) textNodes.push(node);
    node = walker.nextNode();
  }
  const target = textNodes.at(-1);
  if (target && target.nodeValue?.trim() !== label) target.nodeValue = label;
}

function renameClientSettingsNavigation() {
  document.querySelectorAll('a[href*="/config/system/app"]').forEach((link) => {
    replaceLastTextNode(link, "客户端设置");
  });

  if (!isClientSettingsRoute() || CLIENT_PREVIEW_MODE) return;
  document.querySelectorAll("#root h1, #root h2, #root h3").forEach((heading) => {
    if (/APP\s*设置|应用设置/i.test(heading.textContent || "")) {
      heading.textContent = "客户端设置";
    }
  });
}

function getCurrentPlatformOptions() {
  return CLIENT_PLATFORM_OPTIONS.filter((option) => option.device === clientSettingsState.deviceType);
}

function getVisibleClients() {
  return clientSettingsState.clients
    .filter((client) => client.scopes.some((scope) => (
      scope.deviceType === clientSettingsState.deviceType
      && scope.platform === clientSettingsState.platform
    )))
    .sort((a, b) => {
      const aOrder = a.scopes.find((scope) => scope.deviceType === clientSettingsState.deviceType && scope.platform === clientSettingsState.platform)?.sortOrder ?? 0;
      const bOrder = b.scopes.find((scope) => scope.deviceType === clientSettingsState.deviceType && scope.platform === clientSettingsState.platform)?.sortOrder ?? 0;
      return aOrder - bOrder || String(a.id).localeCompare(String(b.id));
    });
}

function setPreviewCategoryOrder(clients) {
  clients.forEach((client, index) => {
    const scope = client.scopes.find((item) => (
      item.deviceType === clientSettingsState.deviceType
      && item.platform === clientSettingsState.platform
    ));
    if (scope) scope.sortOrder = (index + 1) * 10;
  });
}

async function loadClientSettings(root, successMessage = "") {
  if (CLIENT_PREVIEW_MODE) {
    renderClientSettingsPage(root);
    return;
  }

  clientSettingsState.loading = true;
  clientSettingsState.error = "";
  renderClientSettingsPage(root);
  try {
    const data = await request("client/fetch");
    clientSettingsState.clients = (data?.clients || []).map(normalizeClientRecord);
    clientSettingsState.loaded = true;
    clientSettingsState.lastAction = successMessage || "客户端目录已从服务器载入。";
  } catch (error) {
    clientSettingsState.error = error.message;
    clientSettingsState.lastAction = "客户端目录读取失败。";
  } finally {
    clientSettingsState.loading = false;
    renderClientSettingsPage(root);
  }
}

function renderClientSettingsPage(root) {
  const quickImportCount = clientSettingsState.clients.filter((client) => client.quickImportEnabled).length;
  const platformCount = new Set(clientSettingsState.clients.flatMap((client) => client.platforms)).size;
  const visibleClients = getVisibleClients();
  const platformOptions = getCurrentPlatformOptions();

  root.innerHTML = `
    <section class="ueg-client-settings-page" aria-labelledby="ueg-client-settings-title">
      <header class="ueg-client-settings-header">
        <div class="ueg-client-settings-heading">
          <p class="ueg-client-settings-eyebrow">系统设置 / 客户端设置</p>
          <h1 id="ueg-client-settings-title">客户端设置</h1>
          <p>配置订阅中心展示的客户端、分类顺序、下载入口和订阅模板。默认客户端与后续新增项使用同一套设置。</p>
        </div>
        <button type="button" class="ueg-client-button ueg-client-button-primary" data-client-action="add" ${clientSettingsState.loading ? "disabled" : ""}>添加客户端</button>
      </header>

      <div class="ueg-client-preview-notice ${clientSettingsState.error ? "is-error" : ""}" role="status" aria-live="polite">
        <span class="ueg-client-preview-dot" aria-hidden="true"></span>
        <div>
          <strong>${CLIENT_PREVIEW_MODE ? "本地交互原型" : "持久化客户端目录"}</strong>
          <span>${escapeHtml(clientSettingsState.error || clientSettingsState.lastAction)}${CLIENT_PREVIEW_MODE ? " 尚未连接后端，不会保存到服务器。" : ""}</span>
        </div>
      </div>

      <div class="ueg-client-summary-grid" aria-label="客户端配置摘要">
        <article><span>客户端</span><strong>${clientSettingsState.clients.length}</strong><small>可编辑与分类排序</small></article>
        <article><span>快速导入</span><strong>${quickImportCount}</strong><small>已启用</small></article>
        <article><span>系统平台</span><strong>${platformCount}</strong><small>当前覆盖</small></article>
        <article><span>订阅模板</span><strong>${CLIENT_TEMPLATE_OPTIONS.length}</strong><small>可供选择</small></article>
      </div>

      <section class="ueg-client-catalog" aria-labelledby="ueg-client-catalog-title">
        <div class="ueg-client-catalog-header">
          <div>
            <h2 id="ueg-client-catalog-title">展示顺序</h2>
            <p>先选择设备类型与系统平台，再调整当前分类内的顺序。跨端客户端在每个分类中分别排序。</p>
          </div>
          <span class="ueg-client-catalog-count">${visibleClients.length} / ${clientSettingsState.clients.length} 个客户端</span>
        </div>
        <div class="ueg-client-order-filters" aria-label="展示顺序分类">
          <label>
            <span>设备类型</span>
            <select data-client-filter="device">
              ${CLIENT_DEVICE_OPTIONS.map((option) => `<option value="${option.value}" ${option.value === clientSettingsState.deviceType ? "selected" : ""}>${option.label}</option>`).join("")}
            </select>
          </label>
          <label>
            <span>系统平台</span>
            <select data-client-filter="platform">
              ${platformOptions.map((option) => `<option value="${option.value}" ${option.value === clientSettingsState.platform ? "selected" : ""}>${option.label}</option>`).join("")}
            </select>
          </label>
          <p>当前只调整「${escapeHtml(CLIENT_DEVICE_OPTIONS.find((option) => option.value === clientSettingsState.deviceType)?.label)} / ${escapeHtml(CLIENT_PLATFORM_OPTIONS.find((option) => option.value === clientSettingsState.platform)?.label)}」</p>
        </div>
        <div class="ueg-client-list">
          ${clientSettingsState.loading ? '<div class="ueg-client-list-state">正在读取客户端目录…</div>' : visibleClients.length ? visibleClients.map((client, index) => `
            <article class="ueg-client-row" data-client-id="${escapeHtml(client.id)}">
              <div class="ueg-client-order" aria-label="当前分类顺序 ${index + 1}">${String(index + 1).padStart(2, "0")}</div>
              ${renderClientLogo(client)}
              <div class="ueg-client-row-main">
                <div class="ueg-client-row-title">
                  <h3>${escapeHtml(client.name)}</h3>
                  <span>${escapeHtml(getClientTemplateLabel(client.subscriptionTemplate))}</span>
                  ${client.quickImportEnabled ? '<span class="is-import-enabled">快速导入</span>' : ""}
                  ${client.isBuiltin ? '<span>内置</span>' : ""}
                </div>
                <p>${escapeHtml(client.description)}</p>
                <div class="ueg-client-row-meta">
                  <span>${escapeHtml(getClientOptionLabels(client.deviceTypes, CLIENT_DEVICE_OPTIONS))}</span>
                  <span>${escapeHtml(getClientOptionLabels(client.platforms, CLIENT_PLATFORM_OPTIONS))}</span>
                  ${client.tags.map((tag) => `<span>${escapeHtml(tag)}</span>`).join("")}
                </div>
              </div>
              <div class="ueg-client-row-actions" aria-label="${escapeHtml(client.name)} 排序与编辑操作">
                <button type="button" data-client-action="move-up" data-client-id="${escapeHtml(client.id)}" ${index === 0 ? "disabled" : ""}>上移</button>
                <button type="button" data-client-action="move-down" data-client-id="${escapeHtml(client.id)}" ${index === visibleClients.length - 1 ? "disabled" : ""}>下移</button>
                <button type="button" class="ueg-client-edit-button" data-client-action="edit" data-client-id="${escapeHtml(client.id)}">编辑</button>
                <button type="button" class="ueg-client-delete-button" data-client-action="delete" data-client-id="${escapeHtml(client.id)}">删除</button>
              </div>
            </article>
          `).join("") : '<div class="ueg-client-list-state">当前分类还没有客户端，可通过“添加客户端”补充。</div>'}
        </div>
      </section>
    </section>
  `;

  bindLogoFallbacks(root);
  root.querySelector('[data-client-action="add"]')?.addEventListener("click", () => openClientEditor());
  root.querySelector('[data-client-filter="device"]')?.addEventListener("change", (event) => {
    clientSettingsState.deviceType = event.target.value;
    clientSettingsState.platform = CLIENT_PLATFORM_OPTIONS.find((option) => option.device === event.target.value)?.value || "windows";
    renderClientSettingsPage(root);
  });
  root.querySelector('[data-client-filter="platform"]')?.addEventListener("change", (event) => {
    clientSettingsState.platform = event.target.value;
    renderClientSettingsPage(root);
  });
  root.querySelectorAll("[data-client-action][data-client-id]").forEach((button) => {
    button.addEventListener("click", () => void handleClientAction(button.dataset.clientAction, button.dataset.clientId, root));
  });
}

async function handleClientAction(action, clientId, root) {
  const client = clientSettingsState.clients.find((item) => String(item.id) === String(clientId));
  if (!client) return;

  if (action === "edit") {
    openClientEditor(clientId);
    return;
  }

  if (action === "delete") {
    if (!window.confirm(`确定从用户端展示中删除 ${client.name} 吗？`)) return;
    if (CLIENT_PREVIEW_MODE) {
      clientSettingsState.clients = clientSettingsState.clients.filter((item) => String(item.id) !== String(client.id));
      clientSettingsState.lastAction = `${client.name} 已从本地目录删除。`;
      renderClientSettingsPage(root);
      return;
    }
    try {
      await request("client/drop", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: client.id }),
      });
      await loadClientSettings(root, `${client.name} 已删除。`);
    } catch (error) {
      clientSettingsState.error = error.message;
      renderClientSettingsPage(root);
    }
    return;
  }

  const visibleClients = getVisibleClients();
  const index = visibleClients.findIndex((item) => String(item.id) === String(client.id));
  const targetIndex = action === "move-up" ? index - 1 : index + 1;
  if (index < 0 || targetIndex < 0 || targetIndex >= visibleClients.length) return;
  visibleClients.splice(index, 1);
  visibleClients.splice(targetIndex, 0, client);
  setPreviewCategoryOrder(visibleClients);

  if (CLIENT_PREVIEW_MODE) {
    clientSettingsState.lastAction = `${client.name} 在当前分类中的展示顺序已调整。`;
    renderClientSettingsPage(root);
    return;
  }

  try {
    await request("client/sort", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        device_type: clientSettingsState.deviceType,
        platform: clientSettingsState.platform,
        ids: visibleClients.map((item) => item.id),
      }),
    });
    await loadClientSettings(root, `${client.name} 在当前分类中的展示顺序已保存。`);
  } catch (error) {
    clientSettingsState.error = error.message;
    await loadClientSettings(root);
  }
}

function renderCheckboxOptions(name, options, selectedValues) {
  return options.map((option) => `
    <label class="ueg-client-check-option">
      <input type="checkbox" name="${name}" value="${escapeHtml(option.value)}" ${selectedValues.includes(option.value) ? "checked" : ""} ${option.device ? `data-platform-device="${option.device}"` : ""} />
      <span>${escapeHtml(option.label)}</span>
    </label>
  `).join("");
}

function openClientEditor(clientId = null) {
  document.getElementById(CLIENT_EDITOR_ID)?.remove();
  const existingClient = clientSettingsState.clients.find((client) => String(client.id) === String(clientId));
  const client = existingClient || {
    id: "",
    name: "",
    logoMode: "upload",
    logoUrl: "",
    logoDataUrl: "",
    logoFileName: "",
    description: "",
    deviceTypes: ["desktop"],
    platforms: ["windows"],
    scopes: [{ deviceType: "desktop", platform: "windows", sortOrder: 10 }],
    tags: [],
    downloadUrl: "",
    docsUrl: "",
    quickImportEnabled: false,
    quickImportUrl: "",
    subscriptionTemplate: "clashmeta",
  };
  let pendingLogoDataUrl = client.logoDataUrl || "";
  let pendingLogoFileName = client.logoFileName || "";
  let pendingLogoFile = null;

  const dialog = document.createElement("dialog");
  dialog.id = CLIENT_EDITOR_ID;
  dialog.className = "ueg-client-editor";
  dialog.innerHTML = `
    <form class="ueg-client-editor-form" novalidate>
      <header>
        <div>
          <p>${existingClient ? (client.isBuiltin ? "编辑内置客户端" : "编辑客户端") : "添加客户端"}</p>
          <h2>${existingClient ? escapeHtml(client.name) : "新客户端"}</h2>
        </div>
        <button type="button" class="ueg-client-editor-close" aria-label="关闭客户端编辑器">关闭</button>
      </header>
      <div class="ueg-client-editor-scroll">
        <div class="ueg-client-form-error" role="alert" hidden></div>

        <section class="ueg-client-form-section">
          <div class="ueg-client-form-section-heading"><strong>基本信息</strong><span>用户在订阅中心首先看到的内容</span></div>
          <div class="ueg-client-form-grid">
            <label class="ueg-client-field">
              <span>客户端名称 <b>*</b></span>
              <input type="text" name="name" value="${escapeHtml(client.name)}" required maxlength="48" placeholder="例如 Clash Party" />
            </label>
            <label class="ueg-client-field ueg-client-field-wide">
              <span>描述 <b>*</b></span>
              <textarea name="description" required maxlength="180" rows="3" placeholder="说明适合哪些用户与平台">${escapeHtml(client.description)}</textarea>
            </label>
          </div>
        </section>

        <section class="ueg-client-form-section">
          <div class="ueg-client-form-section-heading"><strong>客户端 Logo</strong><span>支持上传图片或填写图片链接</span></div>
          <div class="ueg-client-logo-editor">
            <div class="ueg-client-logo-preview" data-client-logo-preview></div>
            <div class="ueg-client-logo-controls">
              <div class="ueg-client-logo-mode" role="radiogroup" aria-label="Logo 来源">
                <label><input type="radio" name="logoMode" value="upload" ${client.logoMode === "upload" ? "checked" : ""} /><span>上传图片</span></label>
                <label><input type="radio" name="logoMode" value="url" ${client.logoMode === "url" ? "checked" : ""} /><span>图片链接</span></label>
              </div>
              <label class="ueg-client-field" data-logo-mode-panel="upload">
                <span>选择图片</span>
                <input type="file" name="logoFile" accept="image/png,image/jpeg,image/webp" />
                <small>支持 PNG、JPG、WebP，最大 2 MB；SVG 不上传，避免可执行脚本进入公开目录。</small>
                <em data-logo-file-name>${escapeHtml(pendingLogoFileName || (client.hasUploadedLogo ? "已保存上传图片" : "尚未选择文件"))}</em>
              </label>
              <label class="ueg-client-field" data-logo-mode-panel="url">
                <span>图片链接</span>
                <input type="url" name="logoUrl" value="${escapeHtml(client.logoMode === "url" ? client.logoUrl : "")}" placeholder="https://example.com/client-logo.png" />
                <small>建议使用 HTTPS 的正方形图片，加载失败时用户端显示名称缩写。</small>
              </label>
            </div>
          </div>
        </section>

        <section class="ueg-client-form-section">
          <div class="ueg-client-form-section-heading"><strong>展示范围</strong><span>设备与系统平台会决定用户端筛选结果</span></div>
          <fieldset class="ueg-client-fieldset">
            <legend>设备类型 <b>*</b></legend>
            <div class="ueg-client-check-grid">${renderCheckboxOptions("deviceTypes", CLIENT_DEVICE_OPTIONS, client.deviceTypes)}</div>
          </fieldset>
          <fieldset class="ueg-client-fieldset">
            <legend>系统平台 <b>*</b></legend>
            <div class="ueg-client-check-grid">${renderCheckboxOptions("platforms", CLIENT_PLATFORM_OPTIONS, client.platforms)}</div>
          </fieldset>
          <label class="ueg-client-field">
            <span>Tag</span>
            <input type="text" name="tags" value="${escapeHtml(client.tags.join(", "))}" placeholder="推荐, Android, 跨平台" />
            <small>用英文或中文逗号分隔，用户端将显示为标签。</small>
          </label>
        </section>

        <section class="ueg-client-form-section">
          <div class="ueg-client-form-section-heading"><strong>下载与导入</strong><span>订阅模板决定为该客户端生成哪类配置</span></div>
          <div class="ueg-client-form-grid">
            <label class="ueg-client-field ueg-client-field-wide">
              <span>下载链接 <b>*</b></span>
              <input type="url" name="downloadUrl" value="${escapeHtml(client.downloadUrl)}" required placeholder="https://github.com/org/repo/releases" />
            </label>
            <label class="ueg-client-field ueg-client-field-wide">
              <span>教程链接</span>
              <input type="url" name="docsUrl" value="${escapeHtml(client.docsUrl || "")}" placeholder="https://example.com/client-guide" />
            </label>
            <label class="ueg-client-field">
              <span>订阅模板 <b>*</b></span>
              <select name="subscriptionTemplate" required>
                ${CLIENT_TEMPLATE_OPTIONS.map((option) => `<option value="${option.value}" ${client.subscriptionTemplate === option.value ? "selected" : ""}>${option.label}</option>`).join("")}
              </select>
            </label>
            <label class="ueg-client-toggle-field">
              <input type="checkbox" name="quickImportEnabled" ${client.quickImportEnabled ? "checked" : ""} />
              <span><strong>启用快速导入</strong><small>用户端显示“快速导入”按钮</small></span>
            </label>
            <label class="ueg-client-field ueg-client-field-wide" data-quick-import-panel>
              <span>快速导入链接 <b>*</b></span>
              <input type="text" name="quickImportUrl" value="${escapeHtml(client.quickImportUrl)}" placeholder="clash://install-config?url={url}&name={name}" />
              <small>可使用 <code>{url}</code>、<code>{base64url}</code> 作为订阅地址，<code>{name}</code> 作为订阅名称。</small>
            </label>
          </div>
        </section>
      </div>
      <footer>
        <p>${CLIENT_PREVIEW_MODE ? "本地预览只更新当前页面状态。" : "保存后会立即更新订阅中心；分类顺序在列表中单独调整。"}</p>
        <div>
          <button type="button" class="ueg-client-button ueg-client-button-secondary" data-editor-action="cancel">取消</button>
          <button type="submit" class="ueg-client-button ueg-client-button-primary">${CLIENT_PREVIEW_MODE ? "应用到本地预览" : "保存客户端"}</button>
        </div>
      </footer>
    </form>
  `;
  document.body.append(dialog);

  const form = dialog.querySelector("form");
  const errorBox = dialog.querySelector(".ueg-client-form-error");
  const logoPreview = dialog.querySelector("[data-client-logo-preview]");
  const logoUrlInput = form.elements.logoUrl;
  const logoFileInput = form.elements.logoFile;
  const quickImportToggle = form.elements.quickImportEnabled;
  const quickImportInput = form.elements.quickImportUrl;

  function showError(message) {
    errorBox.textContent = message;
    errorBox.hidden = !message;
    if (message) errorBox.scrollIntoView({ block: "nearest" });
  }

  function getLogoMode() {
    return form.querySelector('input[name="logoMode"]:checked')?.value || "url";
  }

  function updateLogoPreview() {
    const mode = getLogoMode();
    const source = mode === "upload"
      ? pendingLogoDataUrl || (client.logoMode === "upload" ? client.logoUrl : "")
      : logoUrlInput.value.trim();
    logoPreview.replaceChildren();
    if (!source) {
      logoPreview.textContent = getClientInitials(form.elements.name.value || "APP");
      return;
    }
    const image = document.createElement("img");
    image.src = source;
    image.alt = "Logo 预览";
    image.addEventListener("error", () => {
      logoPreview.textContent = getClientInitials(form.elements.name.value || "APP");
    }, { once: true });
    logoPreview.append(image);
  }

  function updateLogoMode() {
    const mode = getLogoMode();
    dialog.querySelectorAll("[data-logo-mode-panel]").forEach((panel) => {
      panel.hidden = panel.dataset.logoModePanel !== mode;
    });
    updateLogoPreview();
  }

  function updateQuickImportState() {
    const enabled = quickImportToggle.checked;
    dialog.querySelector("[data-quick-import-panel]").classList.toggle("is-disabled", !enabled);
    quickImportInput.disabled = !enabled;
    quickImportInput.required = enabled;
  }

  function updatePlatformAvailability() {
    const selectedDevices = [...form.querySelectorAll('input[name="deviceTypes"]:checked')].map((input) => input.value);
    form.querySelectorAll("[data-platform-device]").forEach((input) => {
      const available = selectedDevices.includes(input.dataset.platformDevice);
      input.disabled = !available;
      if (!available) input.checked = false;
    });
  }

  dialog.querySelectorAll('input[name="logoMode"]').forEach((input) => input.addEventListener("change", updateLogoMode));
  dialog.querySelectorAll('input[name="deviceTypes"]').forEach((input) => input.addEventListener("change", updatePlatformAvailability));
  form.elements.name.addEventListener("input", updateLogoPreview);
  logoUrlInput.addEventListener("input", updateLogoPreview);
  quickImportToggle.addEventListener("change", updateQuickImportState);
  logoFileInput.addEventListener("change", () => {
    const file = logoFileInput.files?.[0];
    if (!file) return;
    if (!["image/png", "image/jpeg", "image/webp"].includes(file.type)) {
      showError("请选择 PNG、JPG 或 WebP 图片文件。");
      logoFileInput.value = "";
      return;
    }
    if (file.size > 2 * 1024 * 1024) {
      showError("Logo 图片不能超过 2 MB。");
      logoFileInput.value = "";
      return;
    }
    const reader = new FileReader();
    reader.addEventListener("load", () => {
      pendingLogoDataUrl = typeof reader.result === "string" ? reader.result : "";
      pendingLogoFileName = file.name;
      pendingLogoFile = file;
      dialog.querySelector("[data-logo-file-name]").textContent = file.name;
      showError("");
      updateLogoPreview();
    });
    reader.addEventListener("error", () => showError("图片读取失败，请重新选择。"));
    reader.readAsDataURL(file);
  });

  function closeDialog() {
    dialog.close();
  }

  dialog.querySelector(".ueg-client-editor-close").addEventListener("click", closeDialog);
  dialog.querySelector('[data-editor-action="cancel"]').addEventListener("click", closeDialog);
  dialog.addEventListener("close", () => dialog.remove(), { once: true });
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    showError("");
    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }

    const deviceTypes = [...form.querySelectorAll('input[name="deviceTypes"]:checked')].map((input) => input.value);
    const platforms = [...form.querySelectorAll('input[name="platforms"]:checked')].map((input) => input.value);
    if (!deviceTypes.length) {
      showError("至少选择一个设备类型。");
      return;
    }
    if (!platforms.length) {
      showError("至少选择一个系统平台。");
      return;
    }

    const logoMode = getLogoMode();
    if (logoMode === "upload" && !pendingLogoDataUrl && !client.hasUploadedLogo) {
      showError("请上传一张 Logo 图片，或切换到图片链接。");
      return;
    }
    if (logoMode === "url" && !logoUrlInput.value.trim()) {
      showError("请填写 Logo 图片链接，或切换到上传图片。");
      return;
    }

    const tags = form.elements.tags.value.split(/[,，]/).map((tag) => tag.trim()).filter(Boolean);
    const scopes = platforms.map((platform) => {
      const deviceType = getPlatformDevice(platform);
      const existingScope = client.scopes?.find((scope) => scope.deviceType === deviceType && scope.platform === platform);
      const categoryMax = Math.max(0, ...clientSettingsState.clients.flatMap((item) => item.scopes || [])
        .filter((scope) => scope.deviceType === deviceType && scope.platform === platform)
        .map((scope) => scope.sortOrder));
      return { deviceType, platform, sortOrder: existingScope?.sortOrder || categoryMax + 10 };
    });
    const record = normalizeClientRecord({
      id: existingClient?.id || `client-${Date.now()}`,
      name: form.elements.name.value.trim(),
      logoMode,
      logoUrl: logoUrlInput.value.trim(),
      logoDataUrl: pendingLogoDataUrl,
      logoFileName: pendingLogoFileName,
      description: form.elements.description.value.trim(),
      scopes,
      tags,
      download_url: form.elements.downloadUrl.value.trim(),
      docs_url: form.elements.docsUrl.value.trim(),
      quick_import_enabled: quickImportToggle.checked,
      quick_import_url: quickImportToggle.checked ? quickImportInput.value.trim() : "",
      subscription_template: form.elements.subscriptionTemplate.value,
      has_uploaded_logo: client.hasUploadedLogo || Boolean(pendingLogoFile),
      is_builtin: client.isBuiltin || false,
    });

    if (CLIENT_PREVIEW_MODE) {
      if (existingClient) {
        const index = clientSettingsState.clients.findIndex((item) => String(item.id) === String(existingClient.id));
        clientSettingsState.clients[index] = record;
        clientSettingsState.lastAction = `${record.name} 的本地设置已更新。`;
      } else {
        clientSettingsState.clients.push(record);
        clientSettingsState.lastAction = `${record.name} 已添加到各所选分类末尾。`;
      }

      closeDialog();
      const root = document.getElementById(CLIENT_SETTINGS_ROOT_ID);
      if (root) renderClientSettingsPage(root);
      return;
    }

    const submitButton = form.querySelector('button[type="submit"]');
    submitButton.disabled = true;
    submitButton.textContent = "正在保存…";
    const payload = new FormData();
    if (existingClient) payload.append("id", String(existingClient.id));
    payload.append("name", record.name);
    payload.append("description", record.description);
    payload.append("logo_mode", record.logoMode);
    payload.append("logo_url", record.logoMode === "url" ? record.logoUrl : "");
    if (pendingLogoFile) payload.append("logo_file", pendingLogoFile, pendingLogoFile.name);
    payload.append("tags", JSON.stringify(tags));
    payload.append("download_url", record.downloadUrl);
    payload.append("docs_url", record.docsUrl || "");
    payload.append("quick_import_enabled", record.quickImportEnabled ? "1" : "0");
    payload.append("quick_import_url", record.quickImportEnabled ? record.quickImportUrl : "");
    payload.append("subscription_template", record.subscriptionTemplate);
    payload.append("scopes", JSON.stringify(scopes.map((scope) => ({
      device_type: scope.deviceType,
      platform: scope.platform,
    }))));

    try {
      await request("client/save", { method: "POST", body: payload });
      closeDialog();
      const root = document.getElementById(CLIENT_SETTINGS_ROOT_ID);
      if (root) await loadClientSettings(root, `${record.name} 已保存。`);
    } catch (error) {
      showError(error.message);
      submitButton.disabled = false;
      submitButton.textContent = "保存客户端";
    }
  });

  updateLogoMode();
  updatePlatformAvailability();
  updateQuickImportState();
  dialog.showModal();
  window.setTimeout(() => form.elements.name.focus(), 0);
}

function restoreClientSettingsSource() {
  document.querySelectorAll(`.${CLIENT_SOURCE_HIDDEN_CLASS}`).forEach((element) => {
    element.classList.remove(CLIENT_SOURCE_HIDDEN_CLASS);
  });
  if (!CLIENT_PREVIEW_MODE) document.getElementById(CLIENT_SETTINGS_ROOT_ID)?.remove();
}

function injectClientSettings() {
  renameClientSettingsNavigation();
  if (!isClientSettingsRoute()) {
    restoreClientSettingsSource();
    return;
  }
  if (clientSettingsPending || document.getElementById(CLIENT_SETTINGS_ROOT_ID)) return;
  clientSettingsPending = true;

  try {
    let root;
    if (CLIENT_PREVIEW_MODE) {
      root = document.getElementById("root");
      if (!root) return;
      root.id = CLIENT_SETTINGS_ROOT_ID;
    } else {
      const sourceForm = document.querySelector("#root form");
      if (!sourceForm?.parentElement) return;
      sourceForm.classList.add(CLIENT_SOURCE_HIDDEN_CLASS);
      root = document.createElement("div");
      root.id = CLIENT_SETTINGS_ROOT_ID;
      sourceForm.before(root);
    }
    renderClientSettingsPage(root);
    if (!CLIENT_PREVIEW_MODE) {
      void loadClientSettings(root);
    } else if (CLIENT_PREVIEW_EDITOR && !document.getElementById(CLIENT_EDITOR_ID)) {
      window.setTimeout(() => {
        openClientEditor(CLIENT_PREVIEW_EDITOR === "new" ? null : CLIENT_PREVIEW_EDITOR);
      }, 0);
    }
  } finally {
    clientSettingsPending = false;
  }
}

const observer = new MutationObserver(() => {
  void injectFrontendConfigNavigation();
  void injectSelfUseMode();
  injectClientSettings();
});

observer.observe(document.documentElement, { childList: true, subtree: true });
window.addEventListener("hashchange", () => {
  void injectFrontendConfigNavigation();
  void injectSelfUseMode();
  injectClientSettings();
});
window.addEventListener("popstate", () => {
  void injectFrontendConfigNavigation();
  void injectSelfUseMode();
  injectClientSettings();
});
void injectFrontendConfigNavigation();
void injectSelfUseMode();
injectClientSettings();
