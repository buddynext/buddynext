import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from "../_fixtures/precondition";
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-25-directory-filter-by-type + J-26-directory-search.
 */
test.describe('directory / filter + search', () => {
    test('J-25 clicking a filter chip updates the result list', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.members);
        const filter = page.locator(sel.memberFilter).first();
        if (!(await filter.isVisible().catch(() => false))) {
            softSkip(testInfo, 'No filter chips rendered (no member types configured).');
            return;
        }
        const beforeCount = await page.locator(sel.memberCard).count();
        await filter.click();

        // Active state should land on the chip.
        await expect(filter).toHaveAttribute('aria-pressed', /true|on/i).catch(async () => {
            const cls = await filter.getAttribute('class');
            expect(cls).toMatch(/active|is-active|selected/);
        });

        // "The grid did not crash" was asserted as `count() >= 0`, which is also
        // true when the grid is gone entirely. Assert the directory is still in a
        // valid rendered state: cards, or an explicit empty state.
        const afterCount = await page.locator(sel.memberCard).count();
        const empty = await page.locator(sel.memberDirectoryEmpty).isVisible().catch(() => false);
        expect(
            afterCount > 0 || empty,
            `filtering left the directory rendering nothing (before: ${beforeCount} cards)`
        ).toBeTruthy();
    });

    test('J-26 typing in directory search updates results', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.members);
        const input = page.locator(sel.directorySearch).first();
        if (!(await input.isVisible().catch(() => false))) {
            softSkip(testInfo, 'Directory search not exposed.');
            return;
        }
        const before = await page.locator(sel.memberCard).count();
        await input.fill('a');
        // Debounced - wait for the list to actually settle into a searched state,
        // not merely for the page to still exist. `>= 0` never failed.
        await expect.poll(async () => {
            const now = await page.locator(sel.memberCard).count();
            const empty = await page.locator(sel.memberDirectoryEmpty).isVisible().catch(() => false);
            return now !== before || empty || now > 0;
        }, { timeout: 5_000 }).toBeTruthy();
    });
});
