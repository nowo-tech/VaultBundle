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
//#region extension/src/options.ts
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
	await ext.storage.local.set({ [STORAGE_KEYS.baseUrl]: baseUrl });
	if (statusEl) statusEl.textContent = "Saved.";
});
load();
//#endregion
