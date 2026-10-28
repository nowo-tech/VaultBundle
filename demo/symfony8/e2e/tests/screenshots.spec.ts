import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { waitForToolbar } from './helpers';

/** REQ-DEMO-013 — README screenshot (captured as PNG, converted to WebP by scripts/to-webp.mjs). Demo fixture data only. */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('Vault screenshots', () => {
  test.use({ viewport: { width: 1280, height: 900 } });

  test('vault-items — items list with folders, tags and actions', async ({ page }) => {
    await page.goto('/tools/vault/items');
    await expect(page.locator('table a', { hasText: 'Demo broad login' }).first()).toBeVisible();
    // Add a folder so the list shows the folder navigation in use.
    if (!(await page.getByRole('link', { name: 'Work', exact: true }).count())) {
      await page.locator('[data-vault-folder-toggle]').click();
      const form = page.locator('form[data-vault-folder-form]');
      await form.locator('input[name="name"]').fill('Work');
      await form.locator('button[type="submit"]').click();
      await expect(page.getByRole('link', { name: 'Work', exact: true }).first()).toBeVisible();
      await page.goto('/tools/vault/items');
    }
    await waitForToolbar(page);
    await page.screenshot({ path: resolve(outDir, 'vault-items.png') });
  });
});
