import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from '../_fixtures/precondition';
import { sel, urls } from '../_fixtures/selectors';
import { readRestNonce, postIdOfCard, deletePostRest } from '../_fixtures/feed-wave1.helpers';
import type { Page } from '@playwright/test';

/**
 * J-560..J-563 media lightbox (B5).
 *
 * Written from the member's promise: "I tap a photo in the feed and it opens
 * full-size; I can page between the photos on a post; and my reaction / favorite
 * / comment on it actually stick. A logged-out visitor can look and download but
 * cannot touch any of that." The coverage matrix had every B5 lightbox row
 * MISSING. The lightbox is BN-owned UX (assets/js/media/lightbox.js) driving the
 * WPMediaVerse engine routes (mvs/v1/media/{id}/...); it opens from feed media
 * tiles (`.bn-media-tile[data-bn-media-id]`) into the footer shell
 * (`.bn-lightbox`, templates/partials/media-lightbox.php).
 *
 * Effect discipline: open is proven by the media element actually mounting on the
 * stage; prev/next by the stage media CHANGING; the write actions by CLOSING and
 * RE-OPENING the lightbox — which re-fetches state from the engine (loadPanel) —
 * so a react/favorite/comment is asserted as SERVER truth, not an optimistic
 * flip; and the guest gate by the write controls being ABSENT from the DOM for a
 * logged-out visitor while view + download remain. Every seeded post is deleted
 * in `finally`. Media routes through WPMediaVerse; when the Image tool is absent
 * the engine is off and the spec soft-skips on that real precondition.
 */
test.describe('feed / media lightbox', () => {
    // Per-spec selectors (shared selectors.ts is off-limits for parallel agents).
    const pickMedia = '.bn-composer__tools .bn-composer__tool[data-wp-on--click="actions.pickMedia"]';
    const mediaThumbReady = '.bn-composer__media-thumb[data-media-id]';
    // Relative tile selector — composed against a post card via card.locator(),
    // and against the page for the guest permalink. Do NOT prefix with
    // .bn-post-card or card.locator() would look for a nested post card.
    const mediaTile = '.bn-media-tile[data-bn-media-id][data-media-type="image"]';
    const lightbox = '.bn-lightbox';
    const lbMedia = '.bn-lightbox .bn-lightbox__media';
    const lbNext = '.bn-lightbox [data-bn-lb-next]';
    const lbPrev = '.bn-lightbox [data-bn-lb-prev]';
    const lbClose = '.bn-lightbox .bn-lightbox__close[data-bn-lb-close]';
    const lbReaction = '.bn-lightbox .bn-lightbox__reaction';
    const lbFavorite = '.bn-lightbox [data-bn-lb-favorite]';
    const lbCommentInput = '.bn-lightbox [data-bn-lb-comment-input]';
    const lbCommentForm = '.bn-lightbox [data-bn-lb-comment-form]';
    const lbComments = '.bn-lightbox [data-bn-lb-comments]';
    const lbDownload = '.bn-lightbox [data-bn-lb-download]';
    // Relative — composed under lbComments via .locator(); no .bn-lightbox prefix.
    const lbComment = '.bn-lightbox__comment';

    // Two distinct 10x10 PNGs (red / blue) so a 2-item post has visibly different
    // stage sources — prev/next must change the media, not just the index.
    const redPng = Buffer.from(
        'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAIAAAACUFjqAAAAEklEQVR4nGO4IyeHBzGMSmNDAIhsbWE7QP0RAAAAAElFTkSuQmCC',
        'base64'
    );
    const bluePng = Buffer.from(
        'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAIAAAACUFjqAAAAEklEQVR4nGOQk7uDBzGMSmNDAPPtbWGFsfM9AAAAAElFTkSuQmCC',
        'base64'
    );

    /**
     * Compose a public post with `files.length` images through the real composer
     * upload flow, publish it, and return its server post id. Returns 0 with a
     * soft-skip signalled by the caller when the media engine is off.
     */
    async function createMediaPost(
        page: Page,
        caption: string,
        files: Array<{ name: string; buffer: Buffer }>
    ): Promise<number> {
        await page.goto(urls.feed);
        await expect(page.locator(sel.composer).first()).toBeVisible();

        const picker = page.locator(pickMedia).first();
        if (!(await picker.isVisible().catch(() => false))) {
            return -1; // engine off — caller soft-skips.
        }

        await page.locator(sel.composerTextarea).first().fill(caption);
        const [chooser] = await Promise.all([
            page.waitForEvent('filechooser'),
            picker.click(),
        ]);
        await chooser.setFiles(files.map((f) => ({ name: f.name, mimeType: 'image/png', buffer: f.buffer })));

        // Each upload lands when its tile carries a real media id.
        await expect(page.locator(mediaThumbReady)).toHaveCount(files.length, { timeout: 30_000 });

        await page.locator(sel.composerSubmit).first().click();
        await expect(page.locator(sel.postCard).filter({ hasText: caption }).first()).toBeVisible({ timeout: 10_000 });

        await page.goto(urls.feed);
        const card = page.locator(sel.postCard).filter({ hasText: caption }).first();
        await expect(card).toBeVisible({ timeout: 10_000 });
        return postIdOfCard(page, caption);
    }

    const openFirstTile = async (page: Page, caption: string): Promise<void> => {
        const card = page.locator(sel.postCard).filter({ hasText: caption }).first();
        await card.locator(mediaTile).first().scrollIntoViewIfNeeded();
        await card.locator(mediaTile).first().click();
        await expect(page.locator(lightbox)).toBeVisible({ timeout: 8_000 });
        await expect(page.locator(lbMedia).first()).toBeVisible({ timeout: 8_000 });
    };

    test('J-560 tapping a feed photo opens the lightbox with that media', async ({ authenticatedPage: page }, testInfo) => {
        let postId = 0;
        let nonce = '';
        const stamp = Date.now().toString().slice(-6);
        const caption = `j560 lightbox open ${stamp}`;

        try {
            await page.goto(urls.feed);
            nonce = await readRestNonce(page);
            postId = await createMediaPost(page, caption, [{ name: `j560-${stamp}.png`, buffer: redPng }]);
            if (postId === -1) {
                softSkip(testInfo, 'Image tool absent — WPMediaVerse media engine is not active.');
                return;
            }
            expect(postId).toBeGreaterThan(0);

            await openFirstTile(page, caption);

            // The stage mounted a real <img> with a src — the media is showing.
            const media = page.locator(lbMedia).first();
            await expect(media).toBeVisible();
            const src = await media.getAttribute('src');
            expect(src, 'lightbox media must have a source').toBeTruthy();
        } finally {
            await deletePostRest(page.request, nonce, postId).catch(() => {});
        }
    });

    test('J-561 prev / next pages between the photos on a post', async ({ authenticatedPage: page }, testInfo) => {
        let postId = 0;
        let nonce = '';
        const stamp = Date.now().toString().slice(-6);
        const caption = `j561 lightbox nav ${stamp}`;

        try {
            await page.goto(urls.feed);
            nonce = await readRestNonce(page);
            postId = await createMediaPost(page, caption, [
                { name: `j561a-${stamp}.png`, buffer: redPng },
                { name: `j561b-${stamp}.png`, buffer: bluePng },
            ]);
            if (postId === -1) {
                softSkip(testInfo, 'Image tool absent — WPMediaVerse media engine is not active.');
                return;
            }
            expect(postId).toBeGreaterThan(0);

            // Two tiles must have rendered for navigation to be meaningful.
            const card = page.locator(sel.postCard).filter({ hasText: caption }).first();
            const tileCount = await card.locator(mediaTile).count();
            expect(tileCount, 'need 2 image tiles to test prev/next').toBeGreaterThanOrEqual(2);

            await openFirstTile(page, caption);
            const firstSrc = await page.locator(lbMedia).first().getAttribute('src');

            // Next → the stage media changes.
            await page.locator(lbNext).first().click();
            await expect
                .poll(async () => page.locator(lbMedia).first().getAttribute('src'), { timeout: 8_000 })
                .not.toBe(firstSrc);
            const secondSrc = await page.locator(lbMedia).first().getAttribute('src');
            expect(secondSrc).toBeTruthy();

            // Prev → back to the first media.
            await page.locator(lbPrev).first().click();
            await expect
                .poll(async () => page.locator(lbMedia).first().getAttribute('src'), { timeout: 8_000 })
                .toBe(firstSrc);
        } finally {
            await deletePostRest(page.request, nonce, postId).catch(() => {});
        }
    });

    test('J-562 react / favorite / comment in the lightbox persist across reopen', async ({ authenticatedPage: page }, testInfo) => {
        let postId = 0;
        let nonce = '';
        const stamp = Date.now().toString().slice(-6);
        const caption = `j562 lightbox actions ${stamp}`;
        const commentText = `j562 nice shot ${stamp}`;

        try {
            await page.goto(urls.feed);
            nonce = await readRestNonce(page);
            postId = await createMediaPost(page, caption, [{ name: `j562-${stamp}.png`, buffer: redPng }]);
            if (postId === -1) {
                softSkip(testInfo, 'Image tool absent — WPMediaVerse media engine is not active.');
                return;
            }
            expect(postId).toBeGreaterThan(0);

            await openFirstTile(page, caption);

            // The interaction controls exist for a logged-in member.
            const reaction = page.locator(lbReaction).first();
            const favorite = page.locator(lbFavorite).first();
            if (!(await reaction.isVisible().catch(() => false)) || !(await favorite.isVisible().catch(() => false))) {
                softSkip(testInfo, 'Lightbox interaction controls absent — reactions/favorite may be disabled.');
                return;
            }

            // React (first reaction), favorite, and comment.
            await reaction.click();
            await expect(reaction).toHaveClass(/is-active/, { timeout: 8_000 });

            await favorite.click();
            await expect(favorite).toHaveAttribute('aria-pressed', 'true', { timeout: 8_000 });

            await page.locator(lbCommentInput).first().fill(commentText);
            await page.locator(lbCommentForm).first().evaluate((f) => (f as HTMLFormElement).requestSubmit());
            await expect(page.locator(lbComments).locator(lbComment, { hasText: commentText }).first())
                .toBeVisible({ timeout: 8_000 });

            // Server truth: close and RE-OPEN — loadPanel re-fetches from the engine.
            await page.locator(lbClose).first().click();
            await expect(page.locator(lightbox)).toBeHidden({ timeout: 5_000 });
            await openFirstTile(page, caption);

            await expect(page.locator(lbReaction).first()).toHaveClass(/is-active/, { timeout: 8_000 });
            await expect(page.locator(lbFavorite).first()).toHaveAttribute('aria-pressed', 'true', { timeout: 8_000 });
            await expect(page.locator(lbComments).locator(lbComment, { hasText: commentText }).first())
                .toBeVisible({ timeout: 8_000 });
        } finally {
            await deletePostRest(page.request, nonce, postId).catch(() => {});
        }
    });

    test('J-563 a logged-out visitor gets view + download only (no write controls)', async ({ authenticatedPage: page, browser }, testInfo) => {
        let postId = 0;
        let nonce = '';
        const stamp = Date.now().toString().slice(-6);
        const caption = `j563 guest lightbox ${stamp}`;

        try {
            await page.goto(urls.feed);
            nonce = await readRestNonce(page);
            postId = await createMediaPost(page, caption, [{ name: `j563-${stamp}.png`, buffer: redPng }]);
            if (postId === -1) {
                softSkip(testInfo, 'Image tool absent — WPMediaVerse media engine is not active.');
                return;
            }
            expect(postId).toBeGreaterThan(0);

            // Fresh logged-OUT context on the public permalink.
            const guestCtx = await browser.newContext();
            try {
                const guest = await guestCtx.newPage();
                await guest.goto(`/p/${postId}/`, { waitUntil: 'domcontentloaded' });

                const tile = guest.locator(mediaTile).first();
                if (!(await tile.isVisible().catch(() => false))) {
                    softSkip(testInfo, 'Public permalink does not expose the media tile to guests on this site.');
                    return;
                }
                await tile.click();
                await expect(guest.locator(lightbox)).toBeVisible({ timeout: 8_000 });
                await expect(guest.locator(lbMedia).first()).toBeVisible({ timeout: 8_000 });

                // View + download remain.
                await expect(guest.locator(lbDownload).first()).toBeVisible();

                // Write controls are ABSENT from the DOM (not merely hidden) for a guest.
                await expect(guest.locator(lbReaction)).toHaveCount(0);
                await expect(guest.locator(lbFavorite)).toHaveCount(0);
                await expect(guest.locator(lbCommentForm)).toHaveCount(0);
            } finally {
                await guestCtx.close().catch(() => {});
            }
        } finally {
            await deletePostRest(page.request, nonce, postId).catch(() => {});
        }
    });
});
