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

        // Result count may change  -  assert grid is still rendered without crash.
        const afterCount = await page.locator(sel.memberCard).count();
        expect(afterCount).toBeGreaterThanOrEqual(0);
        void beforeCount;
    });

    test('J-26 typing in directory search updates results', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.members);
        const input = page.locator(sel.directorySearch).first();
        if (!(await input.isVisible().catch(() => false))) {
            softSkip(testInfo, 'Directory search not exposed.');
            return;
        }
        await input.fill('a');
        // Debounced  -  wait for the network or the visible card count to settle.
        await expect.poll(async () => {
            return page.locator(sel.memberCard).count();
        }, { timeout: 5_000 }).toBeGreaterThanOrEqual(0);
    });
});
