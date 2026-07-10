import { test, expect } from '@playwright/test';

const navSelector = (name: string) => `[data-sidebar="menu-button"]:has-text("${name}")`;

test.describe('Sidenav Navigation', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/login');
    await page.fill('#email', 'patient@example.com');
    await page.fill('#password', 'password');
    await page.click('button:has-text("Log in")');
    await page.waitForURL('/dashboard', { timeout: 10000 });
  });

  test('sidenav buttons navigate to their pages', async ({ page }) => {
    await page.click(navSelector('Today'));
    await expect(page).toHaveURL('/today');
    await expect(page.locator('h1')).toContainText('Due today');

    await page.click(navSelector('Library'));
    await expect(page).toHaveURL('/library');
    await expect(page.locator('h1')).toContainText('Exercise library');

    await page.click(navSelector('History'));
    await expect(page).toHaveURL('/history');
    await expect(page.locator('h1')).toContainText('Session history');

    await page.click(navSelector('Dashboard'));
    await expect(page).toHaveURL('/dashboard');
  });

  test('sidenav marks the current page active', async ({ page }) => {
    await page.click(navSelector('Library'));
    await expect(page).toHaveURL('/library');
    await expect(page.locator(navSelector('Library'))).toHaveAttribute('data-active', 'true');
    await expect(page.locator(navSelector('Today'))).toHaveAttribute('data-active', 'false');
  });
});
