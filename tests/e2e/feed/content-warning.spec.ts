import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';
import { readRestNonce, restPost, deletePostRest } from '../_fixtures/feed-wave1.helpers';

/**
 * J-516 reveal content-warning (B2).
 *
 * Written from the member's promise: "a content-warning post is covered until I
 * choose to reveal it, and clicking reveal shows the content." The coverage
 * matrix had "Reveal content-warning" MISSING.
 *
 * Effect-based (client reveal is the real consequence here): the spec seeds a
 * post with content_warning=1 through REST (the only way to force the CW state
 * deterministically), reloads the feed so the card is server-rendered with the
 * overlay, asserts the overlay is SHOWN (content hidden behind it), clicks
 * "Show anyway", and asserts the overlay is then HIDDEN (content revealed). A
 * reveal button wired to nothing would leave the overlay visible and fail. Post
 * deleted in `finally`.
 */
test.describe('feed / content warning reveal', () => {
    const cwOverlay = '.bn-post-card__cw-overlay';
    const cwReveal = '.bn-post-card__cw-reveal';

    test('J-516 a content-warning post reveals its content on click', async ({ authenticatedPage: page }) => {
        const stamp = Date.now().toString().slice(-6);
        const content = `j516 sensitive ${stamp}`;
        let postId = 0;
        let nonce = '';

        try {
            await page.goto(urls.feed);
            await expect(page.locator(sel.composer).first()).toBeVisible();
            nonce = await readRestNonce(page);

            const created = await restPost<{ id?: number }>(page.request, nonce, '/posts', {
                content,
                privacy: 'public',
                content_warning: true,
                content_warning_type: 'nsfw',
            });
            expect(created.status, `seed CW post -> ${created.status}`).toBeLessThan(300);
            postId = created.body.id ?? 0;
            expect(postId).toBeGreaterThan(0);

            await page.goto(urls.feed);
            const card = page.locator(sel.postCard).filter({ hasText: content }).first();
            await expect(card).toBeVisible({ timeout: 10_000 });

            // Covered: the overlay is shown before reveal.
            const overlay = card.locator(cwOverlay).first();
            await expect(overlay).toBeVisible();

            const reveal = card.locator(cwReveal).first();
            await expect(reveal).toBeVisible();
            await reveal.click();

            // Revealed: the overlay is now hidden (state.showContent flipped true).
            await expect(overlay).toBeHidden({ timeout: 5_000 });
        } finally {
            await deletePostRest(page.request, nonce, postId).catch(() => {});
        }
    });
});
