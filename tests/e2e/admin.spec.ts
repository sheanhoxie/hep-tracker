import { expect, test, type Page } from '@playwright/test';
import { execSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dirname = path.dirname(fileURLToPath(import.meta.url));

// Serial: the flow builds state (exercise → media → revision) across tests.
test.describe.configure({ mode: 'serial' });

const EXERCISE_NAME = 'E2E Test Exercise';
const EXERCISE_SLUG = 'e2e-test-exercise';

function tinker(code: string): void {
  execSync(`php artisan tinker --execute='${code}'`, { cwd: process.cwd() });
}

function ensureAdmin(): void {
  tinker(
    `App\\Models\\User::updateOrCreate(["email" => "admin@example.com"], ["name" => "Admin", "password" => "password", "role" => "admin", "email_verified_at" => now()]);`,
  );
}

// Idempotent: safe to run before AND after — a crashed previous run leaves no debris.
// reorder() is load-bearing: versions() has a built-in orderBy(version_no) that
// would otherwise win over orderByDesc.
function cleanup(): void {
  tinker(
    `$p = App\\Models\\Program::first();` +
      `$p->versions()->where("source_note", "e2e-admin")->get()->each->delete();` +
      `$p->versions()->reorder()->orderByDesc("version_no")->first()->update(["effective_to" => null]);` +
      `App\\Models\\Exercise::where("slug", "${EXERCISE_SLUG}")->delete();` +
      `Illuminate\\Support\\Facades\\Storage::disk("public")->deleteDirectory("exercise-media/${EXERCISE_SLUG}");`,
  );
}

async function login(page: Page, email: string): Promise<void> {
  await page.goto('/login');
  await page.fill('#email', email);
  await page.fill('#password', 'password');
  await page.click('button:has-text("Log in")');
  await page.waitForURL('/dashboard', { timeout: 10000 });
}

test.describe('Admin content management', () => {
  test.beforeAll(() => {
    ensureAdmin();
    cleanup();
  });

  test.afterAll(() => {
    cleanup();
  });

  test('admin creates an exercise and uploads a demo video', async ({ page }) => {
    await login(page, 'admin@example.com');

    await page.goto('/admin/exercises');
    await expect(page.getByRole('heading', { name: 'Exercises' })).toBeVisible();

    await page.click('a:has-text("New exercise")');
    await page.waitForURL('/admin/exercises/create');
    await page.fill('#canonical_name', EXERCISE_NAME);
    await page.click('button:has-text("Create")');
    await page.waitForURL(`/admin/exercises/${EXERCISE_SLUG}/edit`);

    await page.setInputFiles('input[type="file"][accept*="video"]', path.join(dirname, 'fixtures', 'demo.mp4'));
    await page.click('button:has-text("Add video")');
    await expect(page.locator(`video[src^="/storage/exercise-media/${EXERCISE_SLUG}/"]`)).toBeVisible({ timeout: 10000 });
  });

  test('admin prescribes it via a program revision', async ({ page }) => {
    await login(page, 'admin@example.com');

    await page.goto('/admin/program');
    const baseVersion = Number((await page.getByText(/v\d+ · effective/).textContent())?.match(/v(\d+) · effective/)?.[1]);

    await page.selectOption('#add-exercise', { label: EXERCISE_NAME });
    await page.click('button:has-text("Add")');
    const row = page.locator('tr', { hasText: EXERCISE_NAME });
    await row.locator('td:nth-child(4) input').fill('2'); // sets
    await row.locator('td:nth-child(5) input').fill('10'); // reps

    await page.fill('#source_note', 'e2e-admin');
    await page.click(`button:has-text("Save as v${baseVersion + 1}")`);
    await expect(page.getByText(`Revision v${baseVersion + 1} is live`)).toBeVisible({ timeout: 10000 });
  });

  test('the patient sees the new exercise and no admin surface', async ({ page }) => {
    await login(page, 'patient@example.com');

    await page.goto('/today');
    await expect(page.getByText(EXERCISE_NAME)).toBeVisible();

    await page.goto('/library');
    await expect(page.getByText(EXERCISE_NAME)).toBeVisible();

    await expect(page.locator('a[href="/admin/exercises"]')).toHaveCount(0);
    const response = await page.goto('/admin/exercises');
    expect(response?.status()).toBe(403);
  });
});
