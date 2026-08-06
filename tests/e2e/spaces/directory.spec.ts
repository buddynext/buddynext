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
        // Pick a chip that is NOT already selected, so "it became selected" is a
        // real state change rather than something that was true on arrival.
        //
        // Then pin it by category slug. A locator built from [aria-selected="false"]
        // is re-resolved on every assertion, so the moment the click succeeds it
        // stops matching the chip we clicked and silently re-targets the next
        // unselected one — the assertion then fails against correct behaviour.
        const unselected = page.locator(`${sel.spaceFilter}[aria-selected="false"]`).first();
        if (!(await unselected.isVisible().catch(() => false))) {
            softSkip(testInfo, 'Only one category chip; nothing to switch to.');
            return;
        }
        const slug = await unselected.getAttribute('data-bn-cat-slug');
        const target = slug
            ? page.locator(`${sel.spaceFilter}[data-bn-cat-slug="${slug}"]`)
            : unselected;
        await target.click();

        // The chips are role="tab" in a role="tablist", so the state lives on
        // aria-selected — not aria-pressed, and not a class. Asserting a class
        // failed against markup that was working correctly.
        await expect(target).toHaveAttribute('aria-selected', 'true', { timeout: 3_000 });

        // Exactly one chip lit at a time is the stated contract in the template.
        await expect
            .poll(async () => page.locator(`${sel.spaceFilter}[aria-selected="true"]`).count(), { timeout: 3_000 })
            .toBe(1);
    });

    test('J-39 search updates space list', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.spaces);
        // Was an inline '.bn-spaces__search input' copy that matched nothing.
        // Spaces and members share templates/parts/filter-strip.php.
        const search = page.locator(sel.spaceSearch).first();
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
