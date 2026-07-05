(function() {
	//#region extension/src/login-fields.ts
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
	function fillCredentials(login, fields) {
		if (fields.usernameField) setFieldValue(fields.usernameField, login.username);
		setFieldValue(fields.passwordField, login.password);
	}
	var AUTOFILL_PANEL_ID = "nowo-vault-autofill-panel";
	function removeAutofillPanel(doc = document) {
		doc.getElementById(AUTOFILL_PANEL_ID)?.remove();
	}
	function renderAutofillPanel(doc, logins, fields, onSelect) {
		removeAutofillPanel(doc);
		if (logins.length === 0) return;
		const panel = doc.createElement("div");
		panel.id = AUTOFILL_PANEL_ID;
		panel.className = "nowo-vault-panel";
		const title = doc.createElement("strong");
		title.textContent = "Nowo Vault";
		panel.appendChild(title);
		logins.slice(0, 5).forEach((login) => {
			const button = doc.createElement("button");
			button.type = "button";
			button.textContent = `${login.title} (${login.username})`;
			button.addEventListener("click", () => {
				onSelect(login);
				removeAutofillPanel(doc);
			});
			panel.appendChild(button);
		});
		const rect = fields.passwordField.getBoundingClientRect();
		panel.style.top = `${window.scrollY + rect.bottom + 6}px`;
		panel.style.left = `${window.scrollX + rect.left}px`;
		doc.body.appendChild(panel);
	}
	//#endregion
	//#region extension/src/runtime.ts
	var ext = typeof globalThis.browser !== "undefined" ? globalThis.browser : globalThis.chrome;
	//#endregion
	//#region extension/src/content.ts
	function scanPage() {
		const fields = findLoginFields(document);
		if (!fields) {
			removeAutofillPanel();
			return;
		}
		ext.runtime.sendMessage({
			type: "vault:getLogins",
			domain: window.location.hostname
		}, (response) => {
			if (ext.runtime.lastError || !response?.ok) {
				removeAutofillPanel();
				return;
			}
			renderAutofillPanel(document, response.logins ?? [], fields, (login) => {
				fillCredentials(login, fields);
			});
		});
	}
	new MutationObserver(() => scanPage()).observe(document.documentElement, {
		childList: true,
		subtree: true
	});
	scanPage();
	//#endregion
})();
