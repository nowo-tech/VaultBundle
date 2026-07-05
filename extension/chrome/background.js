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
async function apiRequest(path, options = {}) {
	const config = await getConfig();
	return requestJson(config.baseUrl, path, {
		method: options.method,
		body: typeof options.body === "string" ? options.body : void 0,
		headers: options.headers,
		token: config.token || void 0
	});
}
async function loginsForDomain(domain) {
	return apiRequest(`/api/vault/extension/logins?${new URLSearchParams({ domain }).toString()}`);
}
//#endregion
//#region extension/src/background.ts
ext.runtime.onMessage.addListener((message, _sender, sendResponse) => {
	if (message?.type === "vault:getLogins") {
		handleGetLogins(String(message.domain ?? "")).then((logins) => sendResponse({
			ok: true,
			logins
		})).catch((error) => sendResponse({
			ok: false,
			error: error.message
		}));
		return true;
	}
	if (message?.type === "vault:sessionStatus") {
		getConfig().then((config) => sendResponse({
			ok: true,
			signedIn: isSessionValid(config),
			baseUrl: config.baseUrl
		})).catch((error) => sendResponse({
			ok: false,
			error: error.message
		}));
		return true;
	}
	return false;
});
async function handleGetLogins(domain) {
	if (!isSessionValid(await getConfig())) throw new Error("Not signed in.");
	return (await loginsForDomain(domain)).logins ?? [];
}
//#endregion
