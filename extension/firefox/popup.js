//#region extension/src/api-client.ts
async function requestJson(baseUrl, path, options = {}) {
	if (!baseUrl) throw new Error("Configure the vault URL in extension options.");
	const headers = {
		Accept: "application/json",
		...options.headers ?? {}
	};
	if (options.token) headers.Authorization = `Bearer ${options.token}`;
	const response = await fetch(`${baseUrl}${path}`, {
		method: options.method,
		headers,
		body: options.body
	});
	let payload = null;
	const text = await response.text();
	if (text) try {
		payload = JSON.parse(text);
	} catch {
		payload = { raw: text };
	}
	if (!response.ok) {
		const message = payload?.error || `Request failed (${response.status}).`;
		throw new Error(message);
	}
	return payload;
}
//#endregion
//#region extension/src/runtime.ts
var ext = typeof globalThis.browser !== "undefined" ? globalThis.browser : globalThis.chrome;
//#endregion
//#region extension/src/storage-keys.ts
var STORAGE_KEYS = {
	baseUrl: "nowoVaultBaseUrl",
	token: "nowoVaultToken",
	expiresAt: "nowoVaultExpiresAt"
};
//#endregion
//#region extension/src/url.ts
function normalizeBaseUrl(url) {
	return String(url || "").trim().replace(/\/+$/, "");
}
//#endregion
//#region extension/src/session.ts
function isSessionValid(config) {
	if (!config.token) return false;
	if (!config.expiresAt) return true;
	return new Date(config.expiresAt).getTime() > Date.now();
}
//#endregion
//#region extension/src/api.ts
async function getConfig() {
	const data = await ext.storage.local.get(Object.values(STORAGE_KEYS));
	return {
		baseUrl: normalizeBaseUrl(String(data[STORAGE_KEYS.baseUrl] ?? "")),
		token: String(data[STORAGE_KEYS.token] ?? ""),
		expiresAt: String(data[STORAGE_KEYS.expiresAt] ?? "")
	};
}
async function setSession(token, expiresAt) {
	await ext.storage.local.set({
		[STORAGE_KEYS.token]: token,
		[STORAGE_KEYS.expiresAt]: expiresAt
	});
}
async function clearSession() {
	await ext.storage.local.remove([STORAGE_KEYS.token, STORAGE_KEYS.expiresAt]);
}
async function apiRequest(path, options = {}) {
	const config = await getConfig();
	return requestJson(config.baseUrl, path, {
		method: options.method,
		body: typeof options.body === "string" ? options.body : void 0,
		headers: options.headers,
		token: config.token || void 0
	});
}
async function login(username, password) {
	const payload = await apiRequest("/api/vault/extension/login", {
		method: "POST",
		headers: { "Content-Type": "application/json" },
		body: JSON.stringify({
			username,
			password
		})
	});
	await setSession(payload.token, payload.expiresAt);
	return payload;
}
async function logout() {
	if ((await getConfig()).token) try {
		await apiRequest("/api/vault/extension/logout", { method: "POST" });
	} catch {}
	await clearSession();
}
async function me() {
	return apiRequest("/api/vault/extension/me");
}
//#endregion
//#region extension/src/popup.ts
var statusEl = document.getElementById("status");
var loginForm = document.getElementById("login-form");
var sessionView = document.getElementById("session-view");
var usernameInput = document.getElementById("username");
var passwordInput = document.getElementById("password");
var identifierEl = document.getElementById("identifier");
var logoutBtn = document.getElementById("logout");
var optionsLink = document.getElementById("options-link");
function setStatus(message, isError = false) {
	if (!statusEl) return;
	statusEl.textContent = message;
	statusEl.className = isError ? "status error" : "status";
}
function showLogin() {
	if (loginForm) loginForm.hidden = false;
	if (sessionView) sessionView.hidden = true;
}
function showSession(identifier) {
	if (loginForm) loginForm.hidden = true;
	if (sessionView) sessionView.hidden = false;
	if (identifierEl) identifierEl.textContent = identifier;
}
async function refresh() {
	const config = await getConfig();
	if (!config.baseUrl) {
		setStatus("Set the vault URL in Options first.", true);
		showLogin();
		return;
	}
	if (!isSessionValid(config)) {
		showLogin();
		setStatus("Sign in to autofill logins on this browser.");
		return;
	}
	try {
		const profile = await me();
		showSession(profile.identifier || profile.userId || "Signed in");
		setStatus("Ready to autofill on matching sites.");
	} catch {
		showLogin();
		setStatus("Session expired. Sign in again.", true);
	}
}
loginForm?.addEventListener("submit", async (event) => {
	event.preventDefault();
	setStatus("Signing in…");
	try {
		await login(usernameInput?.value.trim() ?? "", passwordInput?.value ?? "");
		if (passwordInput) passwordInput.value = "";
		await refresh();
	} catch (error) {
		setStatus(error instanceof Error ? error.message : "Login failed.", true);
	}
});
logoutBtn?.addEventListener("click", async () => {
	await logout();
	showLogin();
	setStatus("Signed out.");
});
optionsLink?.addEventListener("click", (event) => {
	event.preventDefault();
	ext.runtime.openOptionsPage();
});
refresh();
//#endregion
