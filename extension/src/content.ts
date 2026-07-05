import {
    fillCredentials,
    findLoginFields,
    removeAutofillPanel,
    renderAutofillPanel,
} from './login-fields.js';
import { ext } from './runtime.js';
import type { VaultLoginMatch } from './types.js';

function scanPage(): void {
    const fields = findLoginFields(document);
    if (!fields) {
        removeAutofillPanel();
        return;
    }

    ext.runtime.sendMessage(
        { type: 'vault:getLogins', domain: window.location.hostname },
        (response: { ok?: boolean; logins?: VaultLoginMatch[] }) => {
            if (ext.runtime.lastError || !response?.ok) {
                removeAutofillPanel();
                return;
            }

            renderAutofillPanel(document, response.logins ?? [], fields, (login) => {
                fillCredentials(login, fields);
            });
        },
    );
}

const observer = new MutationObserver(() => scanPage());
observer.observe(document.documentElement, { childList: true, subtree: true });
scanPage();
