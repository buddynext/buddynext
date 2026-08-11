import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from '../_fixtures/precondition';
import { sel, urls } from '../_fixtures/selectors';
import { readRestNonce, postIdOfCard, deletePostRest } from '../_fixtures/feed-wave1.helpers';

/**
 * J-503 media upload in the composer (B1).
 *
 * Written from the member's promise: "the photo I attach is on the post — it is
 * a photo card, not a line of text." The coverage matrix had media upload
 * MISSING. This attaches a real image through the composer's file picker, waits
 * for the WPMediaVerse upload to land (the tile gains a data-media-id), posts,
 * and after a full reload asserts the card carries a rendered media tile — the
 * server actually stored and re-served the attachment. A composer that flips a
 * preview but drops the media on submit fails here.
 *
 * Media routes through WPMediaVerse; the Image tool is server-gated on the
 * engine being active. If the button is absent the engine is genuinely off, so
 * we softSkip with a clear reason rather than a false pass.
 */
test.describe('feed / media upload', () => {
    const pickMedia = '.bn-composer__tools .bn-composer__tool[data-wp-on--click="actions.pickMedia"]';
    const mediaThumbReady = '.bn-composer__media-thumb[data-media-id]';
    const cardMedia = '.bn-post-card__media, .bn-media-tile__img';

    // A small but valid PNG (10x10, solid) — enough for the engine to store and thumbnail.
    const pngBuffer = Buffer.from(
        'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAHElEQVR4nGP8z8Dwn4EIwDiqkL4KRxWiKwQAtWMH8f0uKq4AAAAASUVORK5CYII=',
        'base64'
    );

    test('J-503 an attached image renders as media on the resulting post', async ({ authenticatedPage: page }, testInfo) => {
        let createdId = 0;
        let nonce = '';
        const stamp = Date.now().toString().slice(-6);
        const caption = `j503 photo post ${stamp}`;

        try {
            await page.goto(urls.feed);
            await expect(page.locator(sel.composer).first()).toBeVisible();
            nonce = await readRestNonce(page);

            const picker = page.locator(pickMedia).first();
            if (!(await picker.isVisible().catch(() => false))) {
                softSkip(testInfo, 'Image tool absent — WPMediaVerse media engine is not active on this site.');
                return;
            }

            await page.locator(sel.composerTextarea).first().fill(caption);

            // pickMedia wires the file-input change listener then opens the chooser.
            const [chooser] = await Promise.all([
                page.waitForEvent('filechooser'),
                picker.click(),
            ]);
            await chooser.setFiles({ name: `j503-${stamp}.png`, mimeType: 'image/png', buffer: pngBuffer });

            // Upload landed when the tile carries a real media id (is-uploading cleared).
            await expect(page.locator(mediaThumbReady).first()).toBeVisible({ timeout: 20_000 });

            await page.locator(sel.composerSubmit).first().click();
            await expect(page.locator(sel.postCard).filter({ hasText: caption }).first()).toBeVisible({ timeout: 10_000 });

            // Reload; the persisted card must present the media, not text alone.
            await page.goto(urls.feed);
            const card = page.locator(sel.postCard).filter({ hasText: caption }).first();
            await expect(card).toBeVisible({ timeout: 10_000 });
            createdId = await postIdOfCard(page, caption);
            await expect(card.locator(cardMedia).first()).toBeVisible({ timeout: 10_000 });
            // And a real <img> tile, not just an empty media wrapper.
            await expect(card.locator('.bn-media-tile__img, .bn-post-card__media img').first()).toBeVisible();
        } finally {
            await deletePostRest(page.request, nonce, createdId).catch(() => {});
        }
    });
});
