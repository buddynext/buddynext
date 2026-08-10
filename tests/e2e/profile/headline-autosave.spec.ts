import { test, expect } from '@playwright/test';
import { loginAs } from '../_fixtures/actor';
import { userId } from '../_fixtures/wp';

/**
 * Wave-3 PROFILE A1 hero — headline blur-autosave — EFFECT-BASED (J-735).
 *
 * The MEMBER-ACTION-COVERAGE-MATRIX marks "Edit headline (blur autosave) →
 * PUT /me/profile" MISSING: only bio (J-34) had a text-field round-trip. The
 * headline sits in the edit hero and carries `data-wp-on--blur="actions.autosave"`
 * — it saves the moment focus leaves the field, with no manual Save click. This
 * asserts the real consequence: blurring fires a PUT /me/profile that returns
 * 200, and the typed value survives a full reload (server truth, not an
 * optimistic in-DOM value).
 *
 * Actor: A = varundubey (owner). Self-cleaning: the original headline is restored
 * through the same autosave path so reruns start from the value they found.
 *
 * Selectors are declared locally (repo rule) from templates/parts/profile-edit-hero.php.
 */

const A_LOGIN = process.env.BN_TEST_USER ?? 'varundubey';
const editUrl = (login: string) => `/members/${login}/edit/`;

const HEADLINE = '#bn-ep-headline';
const NAME = '#bn-ep-name';

let A_ID = 0;

test.beforeAll(async () => {
    A_ID = await userId(A_LOGIN);
    expect(A_ID, `actor "${A_LOGIN}" must exist`).toBeGreaterThan(0);
});

test.describe('profile / headline blur-autosave (effect-based)', () => {
    test('J-735 blurring the headline autosaves it and it persists on reload', async ({ page }) => {
        await loginAs(page, A_LOGIN);
        await page.goto(editUrl(A_LOGIN));
        const headline = page.locator(HEADLINE);
        await expect(headline).toBeVisible();

        const original = await headline.inputValue();
        const value = `e2e headline ${Date.now().toString().slice(-6)}`;

        const waitAutosave = () =>
            page.waitForResponse(
                (r) =>
                    r.url().includes('/me/profile') &&
                    r.request().method() === 'PUT' &&
                    r.status() === 200,
                { timeout: 15_000 }
            );

        try {
            // Type, then move focus to another field — the blur is what fires the
            // autosave. Assert the PUT itself completed 200 (not just a DOM flip).
            await headline.fill(value);
            await Promise.all([waitAutosave(), page.locator(NAME).first().click()]);

            // Round-trip: reload the edit form; the field shows the saved value.
            await page.goto(editUrl(A_LOGIN));
            await expect(
                page.locator(HEADLINE),
                'the autosaved headline must survive a reload'
            ).toHaveValue(value);
        } finally {
            // Restore the original headline through the same blur-autosave path.
            await page.goto(editUrl(A_LOGIN));
            const restore = page.locator(HEADLINE);
            if (await restore.isVisible().catch(() => false)) {
                await restore.fill(original);
                await Promise.all([waitAutosave(), page.locator(NAME).first().click()]).catch(
                    () => undefined
                );
            }
        }
    });
});
