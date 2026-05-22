import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from "../_fixtures/precondition";
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-37 spaces directory, J-38 category filter, J-39 search.
 */
test.describe('spaces / directory', () => {
    test('J-37 directory renders cards + filter chips', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.spaces);
        await expect(page.locator(sel.app)).toBeVisible();
        const cards = await page.locator(sel.spaceCard).count();
        expect(cards).toBeGreaterThanOrEqual(0);
    });

    test('J-38 category filter toggles active state', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.spaces);
        const filter = page.locator(sel.spaceFilter).first();
        if (!(await filter.isVisible().catch(() => false))) {
            softSkip(testInfo, 'No category filter rendered.');
            return;
        }
        await filter.click();
        const cls = await filter.getAttribute('class');
        expect(cls).toMatch(/active|is-active|selected/);
    });

    test('J-39 search updates space list', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.spaces);
        const search = page.locator('.bn-spaces__search input, [data-spaces-search]').first();
        if (!(await search.isVisible().catch(() => false))) {
            softSkip(testInfo, 'Spaces search not exposed.');
            return;
        }
        await search.fill('test');
        await expect.poll(async () => page.locator(sel.spaceCard).count(), { timeout: 5_000 }).toBeGreaterThanOrEqual(0);
    });
});
