import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from '../_fixtures/precondition';
import { sel, urls } from '../_fixtures/selectors';
import { postIdOfCard } from '../_fixtures/feed-wave1.helpers';

/**
 * J-11-feed-home-loads, plus the pagination assertions that were missing.
 */
test.describe('feed / home', () => {
    /**
     * WEAK ASSERTION REPLACED (Wave-4, 2026-08-10). The prior "renders shell +
     * composer" asserted only presence (`composer count > 0`) — a composer that
     * renders but cannot be typed into, and a feed with no real content, both
     * passed. It is now effect-based: the composer textarea actually accepts and
     * echoes input (proving it is a live, editable field, not an inert shell),
     * and the feed carries at least one real post card whose server post id
     * resolves (proving the shell rendered real feed content, not an empty frame).
     */
    test('J-11 home shell renders a usable composer over real feed content', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.feed);
        await expect(page.locator(sel.app)).toBeVisible();
        await expect(page.locator(sel.appMain)).toBeVisible();

        // The composer is not just present — it is editable.
        const ta = page.locator(sel.composerTextarea).first();
        await expect(ta).toBeVisible();
        const probe = `shell-probe ${Date.now().toString().slice(-6)}`;
        await ta.fill(probe);
        await expect(ta).toHaveValue(probe);
        await ta.fill(''); // leave the composer as found (do not publish).

        // The shell rendered real feed content: a card whose server id resolves.
        const firstCard = page.locator(sel.postCard).first();
        if (!(await firstCard.isVisible().catch(() => false))) {
            softSkip(testInfo, 'Home feed genuinely empty for this actor — no post card to resolve.');
            return;
        }
        const text = (await firstCard.innerText().catch(() => '')).trim();
        const id = await postIdOfCard(page, text.split('\n')[0] ?? '');
        expect(id, 'a rendered feed card must carry a resolvable server post id').toBeGreaterThan(0);
    });

    /**
     * WEAK ASSERTION REPLACED (Wave-4, 2026-08-10). The prior test asserted
     * `postCount > 0 OR empty > 0` — it could never fail as long as the page
     * rendered anything, and never forced or identified a specific empty state.
     * It now forces a genuinely empty surface — the Network filter for an actor
     * with no accepted connections — and asserts the SPECIFIC empty-state card
     * for that filter: `.bn-feed-empty[data-filter="network"]` with its exact
     * "Build your network" heading and a real CTA. If the actor unexpectedly has
     * network posts (the empty precondition is genuinely absent) the spec
     * soft-skips with a reason rather than pass on a non-empty page.
     */
    test('J-11 an empty feed filter renders its specific empty-state card', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(`${urls.feed}?filter=network`, { waitUntil: 'domcontentloaded' });
        await expect(page.locator(sel.app)).toBeVisible();

        if ((await page.locator(sel.postCard).count()) > 0) {
            softSkip(testInfo, 'Actor has network posts — the empty precondition for the Network filter is not met.');
            return;
        }

        const emptyCard = page.locator('.bn-feed-empty[data-filter="network"]').first();
        await expect(emptyCard, 'the Network filter must render its own empty-state card').toBeVisible();
        await expect(emptyCard.locator('.bn-feed-empty__title')).toHaveText(/Build your network/i);
        await expect(emptyCard.locator('.bn-feed-empty__cta')).toBeVisible();
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
