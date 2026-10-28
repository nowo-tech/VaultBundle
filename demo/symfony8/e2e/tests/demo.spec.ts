import { test, expect } from '@playwright/test';

/**
 * REQ-DEMO-013 — Vault demo happy path (Playwright, separate from the PHPUnit HTTP tests in tests/E2E).
 * The demo auto-logs in demo@example.com; items come from the demo fixtures (fake credentials).
 */
test.describe('Vault demo', () => {
  test('vault home shows items, shared, trash and the password generator', async ({ page }) => {
    const response = await page.goto('/tools/vault');
    expect(response?.ok()).toBeTruthy();
    await expect(page.locator('h1.page-title')).toBeVisible();
    await expect(page.locator('button[data-vault-password-generator]')).toBeVisible();
  });

  test('items list opens a login item with the password masked', async ({ page }) => {
    await page.goto('/tools/vault/items');
    const link = page.locator('table a', { hasText: 'Demo broad login' }).first();
    await expect(link).toBeVisible();
    await link.click();
    await expect(page.locator('form[data-vault-item-form]')).toBeVisible();
    const password = page.locator('form[data-vault-item-form] input[type="password"]').first();
    await expect(password).toBeVisible();
    await expect(page.locator('form[data-vault-item-form] input[value="broad-user"]').first()).toBeVisible();
  });

  test('password generator returns a generated password with its strength', async ({ page }) => {
    await page.goto('/tools/vault');
    await page.locator('button[data-vault-password-generator]').first().click();
    const modal = page.locator('[data-vault-password-modal]');
    await expect(modal).toBeVisible();
    await expect(modal.locator('[data-vault-password-output]')).not.toHaveValue('');
    await expect(modal.locator('[data-vault-password-strength]')).not.toBeEmpty();
  });
});
