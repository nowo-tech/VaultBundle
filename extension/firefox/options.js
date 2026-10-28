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
/** The vault URL must be https (plain http only for localhost development): the API carries the token and passwords. */
function isSecureBaseUrl(url) {
	try {
		const parsed = new URL(url);
		return parsed.protocol === "https:" || parsed.protocol === "http:" && [
			"localhost",
			"127.0.0.1",
			"[::1]"
		].includes(parsed.hostname);
	} catch {
		return false;
	}
}
// extension/src/options.ts
var form = document.getElementById("options-form");
var baseUrlInput = document.getElementById("base-url");
var statusEl = document.getElementById("status");
async function load() {
	const data = await ext.storage.local.get(STORAGE_KEYS.baseUrl);
	if (baseUrlInput) baseUrlInput.value = String(data[STORAGE_KEYS.baseUrl] ?? "");
}
form?.addEventListener("submit", async (event) => {
	event.preventDefault();
	const baseUrl = normalizeBaseUrl(baseUrlInput?.value ?? "");
	if (!isSecureBaseUrl(baseUrl)) {
		if (statusEl) statusEl.textContent = "Use an https:// vault URL (http:// only for localhost).";
		return;
	}
	await ext.storage.local.set({ [STORAGE_KEYS.baseUrl]: baseUrl });
	if (statusEl) statusEl.textContent = "Saved.";
});
load();
