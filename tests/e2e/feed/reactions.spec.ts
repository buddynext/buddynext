import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';
import { readRestNonce, postIdOfCard, deletePostRest, restGet } from '../_fixtures/feed-wave1.helpers';

type ReactionCount = { count: number; has_reacted?: boolean; emoji?: string };

/**
 * J-16 react to a post (B2).
 *
 * WEAK ASSERTION REPLACED (Wave-3, 2026-08-10). The prior version asserted only
 * that the React button gained an `is-reacted`/`is-active` class, flipped
 * `aria-pressed`, or that the reaction-summary strip became visible — a pure
 * optimistic-UI signal. Every one of those flips client-side the instant the
 * emoji is clicked, so the test stayed GREEN even if `POST /reactions/toggle`
 * silently 500'd and the reaction row was never written. It was the single
 * largest false-confidence row in the matrix's WEAK cluster.
 *
 * Now effect-based: the reaction is made on a FRESH OWN post (count fully
 * controlled, deleted in finally) and every assertion reads server truth from
 * `GET /reactions?object_type=post&object_id={id}` — the same endpoint that
 * hydrates the summary. After reacting we assert `has_reacted=true` +
 * `count>=1`; after toggling the SAME emoji off we assert the server reverts to
 * `has_reacted=false` + `count=0`. A dead write path (optimistic flip over a
 * 500) leaves the row absent and fails the poll. The truth read survives a
 * reload, proving persistence rather than a class swap.
 */
test.describe('feed / reactions', () => {
    const reactionPath = (id: number) => `/reactions?object_type=post&object_id=${id}`;

    const readReaction = async (page: import('@playwright/test').Page, nonce: string, id: number): Promise<ReactionCount> =>
        (await restGet<ReactionCount>(page.request, nonce, reactionPath(id))).body;

    test('J-16 reacting writes a reaction row; removing it reverts (server truth)', async ({ authenticatedPage: page }) => {
        let createdId = 0;
        let nonce = '';
        const stamp = Date.now().toString().slice(-6);
        const body = `j16 react-target ${stamp}`;

        try {
            // Fresh own post starts at zero reactions, so 0->1->0 is exercised end-to-end.
            await page.goto(urls.feed);
            await expect(page.locator(sel.composer).first()).toBeVisible();
            nonce = await readRestNonce(page);
            await page.locator(sel.composerTextarea).first().fill(body);
            await page.locator(sel.composerSubmit).first().click();
            await expect(page.locator(sel.postCard).filter({ hasText: body }).first()).toBeVisible({ timeout: 10_000 });

            // Reload so the card's reaction store is hydrated from the server.
            await page.goto(urls.feed);
            const card = page.locator(sel.postCard).filter({ hasText: body }).first();
            await expect(card).toBeVisible({ timeout: 10_000 });
            createdId = await postIdOfCard(page, body);
            expect(createdId).toBeGreaterThan(0);

            // Precondition truth: no reaction on a brand-new post.
            expect((await readReaction(page, nonce, createdId)).count).toBe(0);

            // React: open the picker, pick the first emoji.
            const reactBtn = card.locator(sel.postReact).first();
            await reactBtn.click();
            const emoji = card.locator(sel.postReactEmoji).first();
            await expect(emoji).toBeVisible({ timeout: 5_000 });
            await emoji.click();

            // Effect: the reaction row is written (server truth, not the button class).
            await expect
                .poll(async () => (await readReaction(page, nonce, createdId)).has_reacted, { timeout: 8_000 })
                .toBe(true);
            expect((await readReaction(page, nonce, createdId)).count).toBeGreaterThanOrEqual(1);

            // Persistence: the row survives a full reload (proves it is not an
            // optimistic in-memory flip that a reload would erase).
            await page.goto(urls.feed);
            await expect(page.locator(sel.postCard).filter({ hasText: body }).first()).toBeVisible({ timeout: 10_000 });
            expect((await readReaction(page, nonce, createdId)).has_reacted).toBe(true);

            // Remove: reopen the picker, pick the SAME emoji -> toggles the reaction off.
            const card2 = page.locator(sel.postCard).filter({ hasText: body }).first();
            await card2.locator(sel.postReact).first().click();
            const emoji2 = card2.locator(sel.postReactEmoji).first();
            await expect(emoji2).toBeVisible({ timeout: 5_000 });
            await emoji2.click();

            // Effect: the server reverts to no reaction.
            await expect
                .poll(async () => (await readReaction(page, nonce, createdId)).has_reacted, { timeout: 8_000 })
                .toBe(false);
            expect((await readReaction(page, nonce, createdId)).count).toBe(0);
        } finally {
            await deletePostRest(page.request, nonce, createdId).catch(() => {});
        }
    });
});
