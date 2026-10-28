(function() {
	// extension/src/login-fields.ts
	function isLikelyUsernameField(input) {
		const hint = `${input.name} ${input.id} ${input.autocomplete} ${input.placeholder}`.toLowerCase();
		return /user|email|login|account|identifier/.test(hint);
	}
	function findLoginFields(doc) {
		const passwordFields = Array.from(doc.querySelectorAll("input[type=\"password\"]"));
		if (passwordFields.length === 0) return null;
		const passwordField = passwordFields[0];
		const form = passwordField.closest("form") ?? doc;
		const textFields = Array.from(form.querySelectorAll("input[type=\"text\"], input[type=\"email\"], input:not([type])")).filter((input) => input !== passwordField && !input.hidden && input.offsetParent !== null);
		return {
			usernameField: textFields.find(isLikelyUsernameField) ?? textFields[0] ?? null,
			passwordField
		};
	}
	function setFieldValue(input, value) {
		input.focus();
		input.value = value;
		input.dispatchEvent(new Event("input", { bubbles: true }));
		input.dispatchEvent(new Event("change", { bubbles: true }));
	}
	function fillCredentials(credentials, fields) {
		if (fields.usernameField) setFieldValue(fields.usernameField, credentials.username);
		setFieldValue(fields.passwordField, credentials.password);
	}
	/** Panel styles, inside the shadow root (page CSS cannot restyle or hide-and-overlay it). */
	var PANEL_CSS = `.nowo-vault-panel {
  z-index: 2147483646;
  min-width: 220px;
  max-width: 320px;
  padding: 10px;
  border-radius: 8px;
  background: #111827;
  color: #f9fafb;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
  font: 13px/1.4 system-ui, sans-serif;
}

.nowo-vault-panel strong {
  display: block;
  margin-bottom: 8px;
}

.nowo-vault-panel button {
  display: block;
  width: 100%;
  margin-top: 6px;
  padding: 6px 8px;
  border: 0;
  border-radius: 6px;
  background: #2563eb;
  color: #fff;
  cursor: pointer;
  text-align: left;
}`;
	/** Host element of the panel; its content lives in a CLOSED shadow root the page cannot query or click into. */
	var panels = /* @__PURE__ */ new WeakMap();
	function removeAutofillPanel(doc = document) {
		panels.get(doc)?.remove();
		panels.delete(doc);
	}
	/**
	* Renders the login choices next to the password field.
	*
	* Security (SEC-004 H1): the page must not be able to trigger a fill. The panel is rendered in a closed shadow root
	* (no fixed id, not reachable through `document`), clicks are only honoured when `isTrusted` (a real user gesture,
	* not `element.click()` / `dispatchEvent` from page scripts), and the summaries carry no password — the content
	* script asks the background for it only after such a click.
	*
	* @returns the shadow root (tests only), or null when nothing is rendered
	*/
	function renderAutofillPanel(doc, logins, fields, onSelect, isTrustedClick = (event) => event.isTrusted) {
		removeAutofillPanel(doc);
		if (logins.length === 0) return null;
		const host = doc.createElement("div");
		const root = host.attachShadow({ mode: "closed" });
		const panel = doc.createElement("div");
		panel.className = "nowo-vault-panel";
		const title = doc.createElement("strong");
		title.textContent = "Nowo Vault";
		panel.appendChild(title);
		logins.slice(0, 5).forEach((login) => {
			const button = doc.createElement("button");
			button.type = "button";
			button.textContent = `${login.title} (${login.username})`;
			button.addEventListener("click", (event) => {
				if (!isTrustedClick(event)) return;
				onSelect(login);
				removeAutofillPanel(doc);
			});
			panel.appendChild(button);
		});
		const style = doc.createElement("style");
		style.textContent = PANEL_CSS;
		root.append(style, panel);
		const rect = fields.passwordField.getBoundingClientRect();
		host.style.position = "absolute";
		host.style.zIndex = "2147483647";
		host.style.top = `${window.scrollY + rect.bottom + 6}px`;
		host.style.left = `${window.scrollX + rect.left}px`;
		doc.body.appendChild(host);
		panels.set(doc, host);
		return root;
	}
	/** Runs `callback` once per animation-free burst of calls (page DOM mutations would otherwise hit the API each time). */
	function debounce(callback, delayMs) {
		let timer = null;
		return () => {
			if (timer !== null) clearTimeout(timer);
			timer = setTimeout(() => {
				timer = null;
				callback();
			}, delayMs);
		};
	}
	// extension/src/runtime.ts
	var ext = typeof globalThis.browser !== "undefined" ? globalThis.browser : globalThis.chrome;
	// extension/src/content.ts
	function scanPage() {
		const fields = findLoginFields(document);
		if (!fields) {
			removeAutofillPanel();
			return;
		}
		ext.runtime.sendMessage({ type: "vault:getLogins" }, (response) => {
			if (ext.runtime.lastError || !response?.ok) {
				removeAutofillPanel();
				return;
			}
			renderAutofillPanel(document, response.logins ?? [], fields, (login) => {
				ext.runtime.sendMessage({
					type: "vault:fill",
					id: login.id
				}, (fill) => {
					if (ext.runtime.lastError || !fill?.ok) return;
					fillCredentials({
						username: fill.username ?? "",
						password: fill.password ?? ""
					}, fields);
				});
			});
		});
	}
	var scanSoon = debounce(scanPage, 300);
	new MutationObserver(() => scanSoon()).observe(document.documentElement, {
		childList: true,
		subtree: true
	});
	scanPage();
})();
