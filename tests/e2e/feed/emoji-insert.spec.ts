import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from '../_fixtures/precondition';
import { sel, urls } from '../_fixtures/selectors';
import { readRestNonce, postIdOfCard, deletePostRest, restGet } from '../_fixtures/feed-wave1.helpers';

type FeedHome = { items?: Array<{ id: number; content?: string }> };

/**
 * J-540 emoji insert in the composer (B1).
 *
 * Written from the member's promise: "the emoji I pick from the composer picker
 * ends up in the post I publish." The coverage matrix had "Emoji insert" MISSING.
 *
 * Effect-based: the picked emoji character must survive the whole round-trip —
 * inserted into the textarea, submitted, and present in the SERVER-STORED body
 * after a full reload AND in the REST feed response. A picker that opens but
 * never writes to the field (or a composer that strips the glyph) fails at the
 * reload/REST assertions, not just at an optimistic DOM check. The trigger is
 * gated by `buddynext_enable_emoji_picker` (default on); when the option is off
 * the button is not rendered and the spec soft-skips on that real precondition.
 * The post is deleted in finally.
 */
test.describe('feed / emoji insert', () => {
    const emojiTrigger = '.bn-composer .bn-emoji-trigger';
    const emojiPopover = '.bn-emoji-popover:not([hidden])';
    const emojiOption = '.bn-emoji-popover:not([hidden]) .bn-emoji-popover__option';

    test('J-540 an emoji picked in the composer lands in the published post body', async ({ authenticatedPage: page }, testInfo) => {
        let createdId = 0;
        let nonce = '';
        const stamp = Date.now().toString().slice(-6);
        const text = `j540 emoji ${stamp} `;

        try {
            await page.goto(urls.feed);
            const composer = page.locator(sel.composer).first();
            await expect(composer).toBeVisible();
            nonce = await readRestNonce(page);

            const trigger = page.locator(emojiTrigger).first();
            if (!(await trigger.isVisible().catch(() => false))) {
                softSkip(testInfo, 'Emoji picker disabled (buddynext_enable_emoji_picker off) — no trigger to test.');
                return;
            }

            const ta = page.locator(sel.composerTextarea).first();
            await ta.fill(text);

            // Open the picker (panel is appended to <body>, not inside the composer).
            await trigger.click();
            await expect(page.locator(emojiPopover).first()).toBeVisible({ timeout: 5_000 });

            // Pick the first emoji; capture the exact glyph it will insert.
            const option = page.locator(emojiOption).first();
            await expect(option).toBeVisible({ timeout: 5_000 });
            const glyph = await option.getAttribute('data-emoji-char');
            expect(glyph, 'emoji option must carry its unicode glyph').toBeTruthy();
            const emojiChar = glyph as string;
            await option.click();

            // The glyph is now in the composer field (client-side effect).
            await expect(ta).toHaveValue(new RegExp(`${stamp}.*${escapeRe(emojiChar)}`), { timeout: 5_000 });

            // Publish.
            await page.locator(sel.composerSubmit).first().click();
            await expect(page.locator(sel.postCard).filter({ hasText: `j540 emoji ${stamp}` }).first())
                .toBeVisible({ timeout: 10_000 });

            // Reload: the post is really published (server-rendered card present).
            await page.goto(urls.feed);
            const card = page.locator(sel.postCard).filter({ hasText: `j540 emoji ${stamp}` }).first();
            await expect(card).toBeVisible({ timeout: 10_000 });
            createdId = await postIdOfCard(page, `j540 emoji ${stamp}`);
            expect(createdId).toBeGreaterThan(0);

            // REST truth (the load-bearing effect): the SERVER-STORED body carries
            // the exact glyph. The rendered card is deliberately NOT asserted for the
            // glyph — BuddyNext staticizes emoji to an <img> on display, so the raw
            // character is absent from the card's text node even though it persisted.
            // The raw `content` field is the stored source of truth.
            const feed = await restGet<FeedHome>(page.request, nonce, '/feed/home?per_page=20');
            const stored = (feed.body.items ?? []).find((it) => it.id === createdId);
            expect(stored, 'created post must be in the REST feed').toBeTruthy();
            expect(String(stored?.content ?? '')).toContain(emojiChar);
        } finally {
            await deletePostRest(page.request, nonce, createdId).catch(() => {});
        }
    });
});

/** Escape a string for safe embedding in a RegExp (the emoji glyph is literal). */
function escapeRe(s: string): string {
    return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}
