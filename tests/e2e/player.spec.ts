import { expect, test, type Page } from '@playwright/test';
import { execSync } from 'node:child_process';

// Serial: both tests reset and reuse today's session for the same user.
test.describe.configure({ mode: 'serial' });

function resetTodaySessions(): void {
  execSync(
    `php artisan tinker --execute='App\\Models\\WorkoutSession::whereIn("user_id", App\\Models\\User::where("email", "patient@example.com")->pluck("id"))->delete();'`,
    { cwd: process.cwd() },
  );
}

async function startSession(page: Page): Promise<void> {
  await page.goto('/login');
  await page.fill('#email', 'patient@example.com');
  await page.fill('#password', 'password');
  await page.click('button:has-text("Log in")');
  await page.waitForURL('/dashboard', { timeout: 10000 });
  await page.goto('/today');
  await page.click('button:has-text("Start session")');
  await page.waitForURL(/\/sessions\/\d+/);
  await expect(page.getByTestId('player-card')).toBeVisible();
}

test.describe('Session player', () => {
  test.beforeEach(() => {
    resetTodaySessions();
  });

  test('full happy path: log sets, finish every exercise, complete session', async ({ page }) => {
    await startSession(page);

    const progress = page.getByText(/of \d+ exercises done/);
    const total = Number((await progress.textContent())?.match(/of (\d+)/)?.[1]);
    expect(total).toBeGreaterThan(0);

    // log every set of the first exercise
    const setButtons = page.locator('[data-testid="player-card"] button:has-text("Set")');
    const sets = await setButtons.count();
    for (let i = 0; i < sets; i++) {
      await setButtons.nth(i).click();
      await expect(setButtons.nth(i)).toBeDisabled();
    }
    await expect(page.locator('[data-testid="player-card"] button:has-text("✓")')).toHaveCount(sets);

    // mark every exercise done; player auto-advances
    for (let done = 1; done <= total; done++) {
      await page.getByTestId('item-done').click();
      await expect(page.getByText(`${done} of ${total} exercises done`)).toBeVisible();
    }

    // pain / RPE / note captured at completion
    await page.selectOption('[data-testid="pain-select"]', '3');
    await page.selectOption('[data-testid="rpe-select"]', '7');
    await page.fill('[data-testid="session-note"]', 'felt strong');
    await page.getByTestId('complete-session').click();
    await expect(page.getByText('Session done.')).toBeVisible();
    await expect(page.getByText('pain 3/10')).toBeVisible();
    await expect(page.getByTestId('sync-badge')).toBeHidden({ timeout: 10000 });

    // survives reload: server owns the state now
    await page.reload();
    await expect(page.getByText('Session done.')).toBeVisible();
    await expect(page.getByText(`${total} of ${total} exercises done`)).toBeVisible();
    await expect(page.getByText('pain 3/10')).toBeVisible();
    await expect(page.getByText('RPE 7/10')).toBeVisible();

    // history shows the completed session with its observations
    await page.goto('/history');
    await expect(page.getByText('pain 3/10')).toBeVisible();
    await expect(page.getByText('RPE 7/10')).toBeVisible();
    await expect(page.getByText('felt strong')).toBeVisible();
  });

  test('offline: sets queue locally, replay to server when back online', async ({ page, context }) => {
    await startSession(page);

    await context.setOffline(true);

    const setButtons = page.locator('[data-testid="player-card"] button:has-text("Set")');
    await setButtons.nth(0).click();
    await expect(setButtons.nth(0)).toBeDisabled();
    await setButtons.nth(1).click();
    await expect(setButtons.nth(1)).toBeDisabled();

    // optimistic UI + queue badge while offline
    await expect(page.getByTestId('sync-badge')).toContainText('offline — 2 saved');
    await expect(page.locator('[data-testid="player-card"] button:has-text("✓")')).toHaveCount(2);

    await context.setOffline(false);

    // online event drains the queue
    await expect(page.getByTestId('sync-badge')).toBeHidden({ timeout: 10000 });

    // hard reload: the two sets must now come from the server
    await page.reload();
    await expect(page.getByTestId('player-card')).toBeVisible();
    await expect(page.locator('[data-testid="player-card"] button:has-text("✓")')).toHaveCount(2);
  });
});
