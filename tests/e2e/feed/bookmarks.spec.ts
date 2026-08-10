import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip, resolveOtherMemberSlug } from '../_fixtures/precondition';
import { sel, urls } from '../_fixtures/selectors';
import {
    readRestNonce,
    postIdOfCard,
    deletePostRest,
    restGet,
    openMemberSession,
    type MemberSession,
} from '../_fixtures/feed-wave1.helpers';

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
 * J-19 WEAK ASSERTION REPLACED (Wave-4, 2026-08-10). The prior J-19 opened the
 * share modal and asserted only that the popover was visible — no repost ever
 * happened, so a Repost button wired to nothing would still pass. It is now
 * effect-based: it seeds a public post from a SECOND member, opens the real
 * share modal on that post, types a quote into the note field and clicks Repost
 * (the modal's `POST /posts/{id}/share`), then reloads the actor's own feed and
 * asserts the reshare card appears carrying the quote text AND the original
 * author's attribution (`.bn-post-card__shared-embed` → shared-name + a link to
 * the original post). A repost that 200s but never publishes a card, or one that
 * loses attribution, fails at the reload. Copy-link permalink stays covered by
 * `feed/share-permalink.spec.ts` (J-514). The reshare is unshared and the seeded
 * post deleted in `finally`.
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

    // Share-modal controls — scoped per-spec (shared selectors.ts is off-limits).
    const shareBtn = '[data-wp-on--click="actions.openShare"]';
    const shareModal = '.bn-share-modal';
    const shareNote = '.bn-share-modal .bn-share-modal__note';
    const shareRepost = '.bn-share-modal .bn-share-modal__repost';
    const sharedEmbed = '.bn-post-card__shared-embed';
    const sharedName = '.bn-post-card__shared-name';
    const sharedContentLink = '.bn-post-card__shared-content-link';

    test('J-19 quoting + reposting another member\'s post publishes an attributed reshare', async ({ authenticatedPage: page, browser }, testInfo) => {
        const authorSlug = await resolveOtherMemberSlug(page, 'varundubey');
        if (!authorSlug) {
            softSkip(testInfo, 'No second member to author the post being reshared.');
            return;
        }

        let author: MemberSession | null = null;
        let origId = 0;
        let myNonce = '';
        const stamp = Date.now().toString().slice(-6);
        const origBody = `j19 original by ${authorSlug} ${stamp}`;
        const quote = `j19 my take ${stamp}`;

        try {
            // Seed a public post owned by the OTHER member.
            author = await openMemberSession(browser, authorSlug);
            const created = await restPostRaw(author.request, author.nonce, origBody);
            expect(created.status, `seed post -> ${created.status}`).toBeLessThan(300);
            origId = created.id;
            expect(origId).toBeGreaterThan(0);

            // View the original on its permalink (renders regardless of feed
            // inclusion) and open the share modal from its Share control.
            await page.goto(urls.feed);
            myNonce = await readRestNonce(page);
            await page.goto(`/p/${origId}/`);
            const origCard = page.locator(sel.postCard).filter({ hasText: origBody }).first();
            await expect(origCard).toBeVisible({ timeout: 10_000 });

            const share = origCard.locator(shareBtn).first();
            if (!(await share.isVisible().catch(() => false))) {
                softSkip(testInfo, 'Share control absent (buddynext_allow_shares off).');
                return;
            }
            await share.click();
            const modal = page.locator(shareModal).first();
            await expect(modal).toBeVisible({ timeout: 5_000 });

            // Quote + repost (the modal's POST /posts/{id}/share with the note).
            await page.locator(shareNote).first().fill(quote);
            await page.locator(shareRepost).first().click();

            // The reshare is my own public post — it surfaces at the top of my feed.
            await page.goto(urls.feed);
            const reshare = page.locator(sel.postCard).filter({ hasText: quote }).first();
            await expect(reshare, 'my reshare card with the quote must appear in my feed').toBeVisible({ timeout: 10_000 });

            // Attribution: the embedded original names its author and links back to
            // the original post — proving this is a real reshare, not a bare copy.
            const embed = reshare.locator(sharedEmbed).first();
            await expect(embed).toBeVisible();
            await expect(reshare.locator(sharedName).first()).not.toBeEmpty();
            await expect(reshare.locator(sharedContentLink).first()).toHaveAttribute('href', new RegExp(`/p/${origId}\\b`));
        } finally {
            // Remove my reshare of the original (share/unshare keys on the original id).
            if (myNonce && origId) {
                await page.request
                    .delete(`/wp-json/buddynext/v1/posts/${origId}/share`, { headers: { 'X-WP-Nonce': myNonce } })
                    .catch(() => undefined);
            }
            if (author && origId) {
                await author.request
                    .delete(`/wp-json/buddynext/v1/posts/${origId}`, { headers: { 'X-WP-Nonce': author.nonce } })
                    .catch(() => undefined);
            }
            if (author) {
                await author.ctx.close().catch(() => {});
            }
        }
    });
});

/** Minimal REST helper: create a public post, returning {status,id}. */
async function restPostRaw(
    request: import('@playwright/test').APIRequestContext,
    nonce: string,
    content: string
): Promise<{ status: number; id: number }> {
    const res = await request.post('/wp-json/buddynext/v1/posts', {
        headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
        data: { content, privacy: 'public' },
    });
    let id = 0;
    try {
        const body = (await res.json()) as { id?: number };
        id = body.id ?? 0;
    } catch {
        /* non-JSON */
    }
    return { status: res.status(), id };
}
