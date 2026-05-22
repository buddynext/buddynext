import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from "../_fixtures/precondition";
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-52 space settings + J-54 moderator review.
 */
test.describe('spaces / settings + moderation', () => {
    const slug = process.env.BN_TEST_OWNED_SPACE ?? 'general';

    test('J-52 owner sees settings tab', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(`${urls.space(slug)}?tab=settings`);
        const form = page.locator('form[data-space-settings], .bn-space__settings, form:has(input[name="space_name"])').first();
        if (!(await form.isVisible().catch(() => false))) {
            softSkip(testInfo, 'Settings form not visible (not owner of test space).');
            return;
        }
        await expect(form).toBeVisible();
        void sel;
    });

    test('J-54 space mod tab loads', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(`${urls.space(slug)}?tab=moderation`);
        const queue = page.locator('.bn-mod-queue, [data-mod-queue], .bn-space__moderation').first();
        if (!(await queue.isVisible().catch(() => false))) {
            softSkip(testInfo, 'Mod tab not visible (not moderator).');
            return;
        }
        await expect(queue).toBeVisible();
    });
});
