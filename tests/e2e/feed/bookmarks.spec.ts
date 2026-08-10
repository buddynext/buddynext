import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from '../_fixtures/precondition';
import { sel, urls } from '../_fixtures/selectors';
import { readRestNonce, postIdOfCard, deletePostRest, restGet } from '../_fixtures/feed-wave1.helpers';

type BookmarkIds = { ids: number[] };

/**
 * J-18 bookmark a post (B2) + J-19 share (B2).
 *
 * J-18 WEAK ASSERTION REPLACED (Wave-3, 2026-08-10). The prior J-18 asserted
 * only that the bookmark button's own class changed / gained `is-bookmarked` /
 * flipped `aria-pressed` — a pure optimistic-UI signal that flips client-side
 * even when `POST /posts/{id}/bookmark` silently 500s. It never looked at the
 * bookmarks list. It is now effect-based: bookmark a FRESH OWN post, then assert
 * the post id appears in `GET /me/bookmarks` (server truth), and after
 * un-bookmarking assert it is gone. A dead write path leaves the list unchanged
 * and fails the poll. The post is deleted in finally.
 *
 * J-19 (share popover) is left as a presence check here — the effect-based Share
 * / Copy-link journey is covered by `feed/share-permalink.spec.ts` (J-514,
 * Wave 2), which navigates the real permalink the modal exposes.
 */
test.describe('feed / bookmarks + share', () => {
    const bookmarksOf = async (page: import('@playwright/test').Page, nonce: string): Promise<number[]> =>
        (await restGet<BookmarkIds>(page.request, nonce, '/me/bookmarks')).body.ids ?? [];

    test('J-18 bookmarking adds the post to my bookmarks; un-bookmark removes it (server truth)', async ({ authenticatedPage: page }, testInfo) => {
        let createdId = 0;
        let nonce = '';
        const stamp = Date.now().toString().slice(-6);
        const body = `j18 bookmark-target ${stamp}`;

        try {
            await page.goto(urls.feed);
            await expect(page.locator(sel.composer).first()).toBeVisible();
            nonce = await readRestNonce(page);
            await page.locator(sel.composerTextarea).first().fill(body);
            await page.locator(sel.composerSubmit).first().click();
            await expect(page.locator(sel.postCard).filter({ hasText: body }).first()).toBeVisible({ timeout: 10_000 });

            // Reload so the card's bookmark store is hydrated from the server.
            await page.goto(urls.feed);
            const card = page.locator(sel.postCard).filter({ hasText: body }).first();
            await expect(card).toBeVisible({ timeout: 10_000 });
            createdId = await postIdOfCard(page, body);
            expect(createdId).toBeGreaterThan(0);

            const btn = card.locator(sel.postBookmark).first();
            if (!(await btn.isVisible().catch(() => false))) {
                softSkip(testInfo, 'Bookmarks disabled (buddynext_allow_bookmarks off) — no control to test.');
                return;
            }

            // Precondition truth: a fresh post is not yet bookmarked.
            expect(await bookmarksOf(page, nonce)).not.toContain(createdId);

            // Bookmark.
            await btn.click();
            await expect
                .poll(async () => (await bookmarksOf(page, nonce)).includes(createdId), { timeout: 8_000 })
                .toBe(true);

            // Persistence across reload, then un-bookmark.
            await page.goto(urls.feed);
            const card2 = page.locator(sel.postCard).filter({ hasText: body }).first();
            await expect(card2).toBeVisible({ timeout: 10_000 });
            expect(await bookmarksOf(page, nonce)).toContain(createdId);

            await card2.locator(sel.postBookmark).first().click();
            await expect
                .poll(async () => (await bookmarksOf(page, nonce)).includes(createdId), { timeout: 8_000 })
                .toBe(false);
        } finally {
            await deletePostRest(page.request, nonce, createdId).catch(() => {});
        }
    });

    test('J-19 share opens a share popover', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.feed);
        if ((await page.locator(sel.postCard).count()) === 0) {
            softSkip(testInfo, 'Feed empty.');
            return;
        }
        const firstCard = page.locator(sel.postCard).first();
        const btn = firstCard.locator(sel.postShare).first();
        if (!(await btn.isVisible().catch(() => false))) {
            softSkip(testInfo, 'No share control in build.');
            return;
        }

        await btn.click();
        // Live build uses `.bn-share-modal` (modal backdrop) for the
        // share UI. Earlier role-based selectors matched the WP admin
        // bar menu (role="menu"), so keep the BN class as the primary
        // signal. Effect-based Share/Copy-link coverage is in share-permalink.spec.ts.
        const popover = page.locator('.bn-share-modal:not([hidden]), .bn-share-modal__panel, [data-share-popover]').first();
        await expect(popover).toBeVisible({ timeout: 5_000 });
    });
});
