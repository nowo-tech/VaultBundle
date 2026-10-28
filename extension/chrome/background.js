// extension/src/api-client.ts
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
// extension/src/runtime.ts
var ext = typeof globalThis.browser !== "undefined" ? globalThis.browser : globalThis.chrome;
// extension/src/storage-keys.ts
var STORAGE_KEYS = {
	baseUrl: "nowoVaultBaseUrl",
	token: "nowoVaultToken",
	expiresAt: "nowoVaultExpiresAt"
};
// extension/src/url.ts
function normalizeBaseUrl(url) {
	return String(url || "").trim().replace(/\/+$/, "");
}
// extension/src/session.ts
function isSessionValid(config) {
	if (!config.token) return false;
	if (!config.expiresAt) return true;
	return new Date(config.expiresAt).getTime() > Date.now();
}
// extension/src/api.ts
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
/**
* @param scheme scheme of the page: on `http` the server only returns logins whose website is explicitly `http://`
*               (a stored https site is never offered on its plain-HTTP twin, SEC-004 H1)
*/
async function loginsForDomain(domain, scheme = "https") {
	return apiRequest(`/api/vault/extension/logins?${new URLSearchParams({
		domain,
		scheme
	}).toString()}`);
}
// extension/src/page-origin.ts
/** Scheme + host of an http(s) page URL (from the sender tab), or null for any other page. */
function pageOrigin(url) {
	if (!url) return null;
	let parsed;
	try {
		parsed = new URL(url);
	} catch {
		return null;
	}
	if (parsed.protocol !== "https:" && parsed.protocol !== "http:" || parsed.hostname === "") return null;
	return {
		scheme: parsed.protocol === "https:" ? "https" : "http",
		host: parsed.hostname
	};
}
/** Logins without their password, for the content script. */
function summarizeLogins(logins) {
	return logins.map(({ id, title, username }) => ({
		id,
		title,
		username
	}));
}
// extension/src/background.ts
/** Last logins fetched per tab (with passwords), released to the content script one at a time after a trusted click. */
var tabLogins = /* @__PURE__ */ new Map();
ext.runtime.onMessage.addListener((message, sender, sendResponse) => {
	if (sender.id !== ext.runtime.id) return false;
	if (message?.type === "vault:getLogins") {
		handleGetLogins(sender).then((logins) => sendResponse({
			ok: true,
			logins
		})).catch((error) => sendResponse({
			ok: false,
			error: error.message
		}));
		return true;
	}
	if (message?.type === "vault:fill") {
		const page = pageOrigin(sender.tab?.url ?? sender.url);
		const entry = sender.tab?.id !== void 0 ? tabLogins.get(sender.tab.id) : void 0;
		const login = page && entry && entry.host === page.host ? entry.logins.find((candidate) => candidate.id === String(message.id ?? "")) : void 0;
		sendResponse(login ? {
			ok: true,
			username: login.username,
			password: login.password
		} : { ok: false });
		return false;
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
ext.tabs?.onRemoved?.addListener((tabId) => {
	tabLogins.delete(tabId);
});
async function handleGetLogins(sender) {
	const page = pageOrigin(sender.tab?.url ?? sender.url);
	if (!page || sender.tab?.id === void 0) throw new Error("Unsupported page.");
	if (!isSessionValid(await getConfig())) throw new Error("Not signed in.");
	const logins = (await loginsForDomain(page.host, page.scheme)).logins ?? [];
	tabLogins.set(sender.tab.id, {
		host: page.host,
		logins
	});
	return summarizeLogins(logins);
}
