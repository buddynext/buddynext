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
        // See directory/members.spec.ts: `count() >= 0` is true of every page,
        // including one this selector never matched. Assert the real contract.
        const cards = await page.locator(sel.spaceCard).count();
        const empty = await page.locator(sel.spaceDirectoryEmpty).isVisible().catch(() => false);
        expect(
            cards > 0 || empty,
            'spaces directory rendered neither space cards nor an empty state'
        ).toBeTruthy();
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
        const before = await page.locator(sel.spaceCard).count();
        await search.fill('test');
        // A search that changes nothing is not evidence the search works. Assert
        // the list actually settles into a filtered state — either the count
        // changed, or the directory says it found nothing. Polling for ">= 0"
        // asserted only that the page still existed.
        await expect.poll(async () => {
            const now = await page.locator(sel.spaceCard).count();
            const empty = await page.locator(sel.spaceDirectoryEmpty).isVisible().catch(() => false);
            return now !== before || empty;
        }, { timeout: 5_000 }).toBeTruthy();
    });
});
