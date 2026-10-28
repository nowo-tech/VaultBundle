import { normalizeBaseUrl, STORAGE_KEYS } from './api.js';
import { isSecureBaseUrl } from './url.js';
import { ext } from './runtime.js';

const form = document.getElementById('options-form') as HTMLFormElement | null;
const baseUrlInput = document.getElementById('base-url') as HTMLInputElement | null;
const statusEl = document.getElementById('status');

async function load(): Promise<void> {
    const data = await ext.storage.local.get(STORAGE_KEYS.baseUrl);
    if (baseUrlInput) {
        baseUrlInput.value = String(data[STORAGE_KEYS.baseUrl] ?? '');
    }
}

form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const baseUrl = normalizeBaseUrl(baseUrlInput?.value ?? '');
    if (!isSecureBaseUrl(baseUrl)) {
        if (statusEl) {
            statusEl.textContent = 'Use an https:// vault URL (http:// only for localhost).';
        }
        return;
    }
    await ext.storage.local.set({ [STORAGE_KEYS.baseUrl]: baseUrl });
    if (statusEl) {
        statusEl.textContent = 'Saved.';
    }
});

void load();
