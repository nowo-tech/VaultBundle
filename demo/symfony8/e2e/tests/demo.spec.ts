import { test, expect } from '@playwright/test';

/**
 * REQ-DEMO-013 — Vault demo happy path (Playwright, separate from the PHPUnit HTTP tests in tests/E2E).
 * The demo auto-logs in demo@example.com; items come from the demo fixtures (fake credentials).
 * Pages follow the UiKit page contract (REQ-UI-003): scaffolds, view-model test ids, UiKit dialogs.
 */
test.describe('Vault demo', () => {
  test('vault home shows items, shared, trash and the password generator', async ({ page }) => {
    const response = await page.goto('/tools/vault');
    expect(response?.ok()).toBeTruthy();
    await expect(page.locator('[data-testid="vault-home"]')).toBeVisible();
    await expect(page.getByRole('heading', { level: 1, name: 'Vault' })).toBeVisible();
    for (const card of ['items', 'shared', 'trash', 'create', 'generator']) {
      await expect(page.locator(`[data-testid="vault-home-${card}"]`)).toBeVisible();
    }
    await expect(page.locator('button[data-vault-password-generator]')).toBeVisible();
  });

  test('items list opens a login item with the password masked', async ({ page }) => {
    await page.goto('/tools/vault/items');
    await expect(page.locator('[data-testid="vault-items"]')).toBeVisible();
    const link = page.locator('table a', { hasText: 'Demo broad login' }).first();
    await expect(link).toBeVisible();
    await link.click();
    await expect(page.locator('[data-testid="vault-item-form"]')).toBeVisible();
    await expect(page.locator('form[data-vault-item-form]')).toBeVisible();
    const password = page.locator('form[data-vault-item-form] input[type="password"]').first();
    await expect(password).toBeVisible();
    await expect(page.locator('form[data-vault-item-form] input[value="broad-user"]').first()).toBeVisible();
  });

  test('moving an item to the trash asks for confirmation in a dialog', async ({ page }) => {
    await page.goto('/tools/vault/items');
    const row = page.locator('tr', { hasText: 'Demo broad login' }).first();
    await row.getByRole('button', { name: 'Move to trash' }).click();
    const dialog = page.locator('dialog[open]');
    await expect(dialog).toBeVisible();
    await expect(dialog).toContainText('Demo broad login');
    // Cancel: nothing is trashed.
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(page.locator('dialog[open]')).toHaveCount(0);
    await expect(page.locator('table a', { hasText: 'Demo broad login' }).first()).toBeVisible();
  });

  test('password generator returns a generated password with its strength', async ({ page }) => {
    await page.goto('/tools/vault');
    await page.locator('button[data-vault-password-generator]').first().click();
    const dialog = page.locator('dialog[data-vault-password-modal]');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('[data-vault-password-output]')).not.toHaveValue('');
    await expect(dialog.locator('[data-vault-password-strength]')).not.toBeEmpty();
    await dialog.locator('[data-nowo-ui-dialog-close]').first().click();
    await expect(dialog).toBeHidden();
  });
});
