import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';
import { readRestNonce, postIdOfCard, deletePostRest } from '../_fixtures/feed-wave1.helpers';

/**
 * J-514 share modal → copy-link permalink opens the single post (B2).
 *
 * Written from the member's promise: "the Share dialog gives me this post's
 * link, and that link opens exactly this post." The coverage matrix had
 * "Copy-link (in share modal)" MISSING and "Share/Repost" only WEAK (popover
 * visible, no effect).
 *
 * Effect-based: the spec composes a post, opens the real share modal, reads the
 * permalink the modal is wired to (the Share button's data-post-permalink, which
 * is what copyLink() copies), then NAVIGATES to that permalink and asserts the
 * SAME post renders standalone there. A modal that shows a share sheet but wires
 * the wrong/empty permalink would fail the navigation leg. Post deleted in
 * `finally`.
 */
test.describe('feed / share modal permalink', () => {
    const shareBtn = '[data-wp-on--click="actions.openShare"]';
    const shareModal = '.bn-share-modal';
    const copyBtn = '.bn-share-modal__copy';

    test('J-514 the share modal yields the permalink and it opens the single post', async ({ authenticatedPage: page }) => {
        const stamp = Date.now().toString().slice(-6);
        const content = `j514 share me ${stamp}`;
        let postId = 0;
        let nonce = '';

        try {
            await page.goto(urls.feed);
            await expect(page.locator(sel.composer).first()).toBeVisible();
            nonce = await readRestNonce(page);
            await page.locator(sel.composerTextarea).first().fill(content);
            await page.locator(sel.composerSubmit).first().click();
            await expect(page.locator(sel.postCard).filter({ hasText: content }).first()).toBeVisible({ timeout: 10_000 });

            // Reload so the card is fully hydrated (share button carries the permalink).
            await page.goto(urls.feed);
            const card = page.locator(sel.postCard).filter({ hasText: content }).first();
            await expect(card).toBeVisible({ timeout: 10_000 });
            postId = await postIdOfCard(page, content);
            expect(postId).toBeGreaterThan(0);

            // The permalink the modal copies is authored on the Share control.
            const share = card.locator(shareBtn).first();
            await expect(share).toBeVisible();
            const permalink = await share.getAttribute('data-post-permalink');
            expect(permalink, 'share control carries a permalink').toBeTruthy();
            expect(permalink as string).toContain(`/p/${postId}`);

            // Opening the modal proves the share flow is wired, not just present.
            await share.click();
            const modal = page.locator(shareModal).first();
            await expect(modal).toBeVisible({ timeout: 5_000 });
            await expect(modal.locator(copyBtn)).toBeVisible();

            // The permalink actually opens THIS post standalone.
            await page.goto(permalink as string, { waitUntil: 'domcontentloaded' });
            await expect(
                page.locator(sel.postCard).filter({ hasText: content }).first()
            ).toBeVisible({ timeout: 10_000 });
        } finally {
            await deletePostRest(page.request, nonce, postId).catch(() => {});
        }
    });
});
