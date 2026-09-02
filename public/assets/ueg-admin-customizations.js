const CARD_ID = "ueg-self-use-mode";
const FRONTEND_CONFIG_ROUTE = "/config/system/frontend";
const TOKEN_STORAGE_KEY = "XBOARD_ACCESS_TOKEN";
const NAVIGATION_ID = "ueg-frontend-config-navigation";
const MOBILE_NAVIGATION_ID = "ueg-frontend-config-mobile-navigation";
const SYSTEM_CONFIG_ROUTE_PATTERN = /\/config\/system(?:\/[^/?#]*)?\/?$/;

let injectionPending = false;
let navigationPending = false;
let accessDenied = false;
let frontendConfigPromise = null;

function isFrontendConfigRoute() {
  return `${window.location.pathname}${window.location.hash}`.includes(FRONTEND_CONFIG_ROUTE);
}

function isSystemConfigRoute() {
  return `${window.location.pathname}${window.location.hash}`.includes("/config/system");
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
    const error = new Error(payload.message || "请求失败，请稍后重试");
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

const observer = new MutationObserver(() => {
  void injectFrontendConfigNavigation();
  void injectSelfUseMode();
});

observer.observe(document.documentElement, { childList: true, subtree: true });
window.addEventListener("hashchange", () => {
  void injectFrontendConfigNavigation();
  void injectSelfUseMode();
});
window.addEventListener("popstate", () => {
  void injectFrontendConfigNavigation();
  void injectSelfUseMode();
});
void injectFrontendConfigNavigation();
void injectSelfUseMode();
