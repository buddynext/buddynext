import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from '../_fixtures/precondition';
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-11-feed-home-loads, plus the pagination assertions that were missing.
 */
test.describe('feed / home', () => {
    test('home feed renders shell + composer', async ({ authenticatedPage: page }) => {
        await page.goto(urls.feed);
        await expect(page.locator(sel.app)).toBeVisible();
        await expect(page.locator(sel.appMain)).toBeVisible();

        // Composer is present (logged-in only).
        const composerCount = await page.locator(sel.composer).count();
        expect(composerCount).toBeGreaterThan(0);
    });

    test('home feed renders posts OR empty state', async ({ authenticatedPage: page }) => {
        await page.goto(urls.feed);
        await expect(page.locator(sel.app)).toBeVisible();

        const postCount = await page.locator(sel.postCard).count();
        const empty = await page.locator(sel.feedEmpty).count();
        expect(postCount > 0 || empty > 0).toBeTruthy();
    });

    /**
     * Load more appends the next page and advances the cursor.
     *
     * Uncovered until now, which is how the cost in GitHub #141 went unmeasured:
     * the control worked, so nothing failed, and nobody looked at what it cost
     * or whether it kept its promise past the first click.
     *
     * Asserts the member-visible contract only — more cards afterwards, none of
     * them duplicated, and the control either advances or retires. Deliberately
     * says nothing about HOW the page is fetched, so the pending move off the
     * whole-document region swap does not have to rewrite this test.
     */
    test('Load more appends a page without duplicating what is already shown', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.feed);
        await expect(page.locator(sel.app)).toBeVisible();

        const control = page.locator(sel.feedLoadMore).first();
        if (!(await control.isVisible().catch(() => false))) {
            softSkip(testInfo, 'Feed has one page or fewer — no Load more control to exercise.');
            return;
        }

        const idsOf = async () =>
            (await page.locator(`${sel.postCard}[data-post-id]`).evaluateAll(
                (els) => els.map((e) => (e as HTMLElement).dataset.postId || '')
            )).filter(Boolean);

        const before = await idsOf();
        expect(before.length, 'feed rendered no identifiable post cards').toBeGreaterThan(0);

        await control.click();

        // The control is replaced by the swap, so poll on the card count rather
        // than waiting on an element that is about to be recreated.
        await expect
            .poll(async () => (await idsOf()).length, { timeout: 15_000 })
            .toBeGreaterThan(before.length);

        const after = await idsOf();

        // No duplicates. Cumulative ?shown=N pagination re-renders everything
        // already on screen, so an off-by-one in the window shows up here first.
        expect(after.length - new Set(after).size, `duplicate post ids after Load more: ${after.length - new Set(after).size}`).toBe(0);

        // Everything that was on screen is still on screen: appending must not
        // drop what the member was reading.
        const missing = before.filter((id) => !after.includes(id));
        expect(missing, `posts disappeared after Load more: ${missing.join(', ')}`).toHaveLength(0);

        // The control either offers another page or retires. Still showing a
        // control that yields nothing is the failure this guards.
        const stillThere = await page.locator(sel.feedLoadMore).first().isVisible().catch(() => false);
        if (stillThere) {
            const href = await page.locator(sel.feedLoadMore).first().getAttribute('href');
            expect(href, 'Load more is still visible but has no href to advance to').toBeTruthy();
        }
    });
});
