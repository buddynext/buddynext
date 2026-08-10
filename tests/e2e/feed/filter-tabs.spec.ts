import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip, resolveOtherMemberSlug } from '../_fixtures/precondition';
import { sel, urls } from '../_fixtures/selectors';
import { openMemberSession, restPost, type MemberSession } from '../_fixtures/feed-wave1.helpers';
import { userId, resetPair } from '../_fixtures/wp';

/**
 * J-521 feed filter tabs change scope (B4).
 *
 * Written from the member's promise: "the For-you and Following tabs show
 * different feeds — Following only shows people I follow." The coverage matrix
 * had "Filter tabs" MISSING.
 *
 * Effect-based, deterministic scope proof (not a class flip): a public post is
 * seeded from an author the viewer does NOT follow. On ?filter=for-you the
 * discovery catch-all surfaces it; on ?filter=following (authors I follow only)
 * it MUST be absent. Both server-rendered pages are asserted, plus aria-current
 * tracking the active tab. A filter that renders the same set regardless of tab
 * fails the Following leg. Seeded post + follow state torn down in `finally`.
 */
test.describe('feed / filter tabs', () => {
    const tab = (slug: string) => `.bn-feed-filter-tab[data-filter="${slug}"]`;

    test('J-521 Following excludes a non-followed author that For-you shows', async ({ authenticatedPage: page, browser }, testInfo) => {
        const authorSlug = await resolveOtherMemberSlug(page, 'varundubey');
        if (!authorSlug) {
            softSkip(testInfo, 'No other member to author the discovery post.');
            return;
        }
        const meId = await userId('varundubey');
        const authorId = await userId(authorSlug);
        expect(meId).toBeGreaterThan(0);
        expect(authorId).toBeGreaterThan(0);

        const stamp = Date.now().toString().slice(-6);
        const content = `j521 discovery ${stamp}`;
        let author: MemberSession | null = null;
        let postId = 0;

        try {
            // The viewer must NOT follow the author for the scope difference to hold.
            await resetPair(meId, authorId);

            author = await openMemberSession(browser, authorSlug);
            const created = await restPost<{ id?: number }>(author.request, author.nonce, '/posts', {
                content,
                privacy: 'public',
            });
            expect(created.status, `seed post -> ${created.status}`).toBeLessThan(300);
            postId = created.body.id ?? 0;
            expect(postId).toBeGreaterThan(0);

            // For-you (discovery) — the public post from a non-followed author shows.
            await page.goto(`${urls.feed}?filter=for-you`, { waitUntil: 'domcontentloaded' });
            await expect(page.locator(`${tab('for-you')}[aria-current="true"]`)).toHaveCount(1);
            await expect(page.locator(sel.postCard).filter({ hasText: content }).first()).toBeVisible({ timeout: 10_000 });

            // Following — scoped to authors I follow; the same post is absent.
            await page.goto(`${urls.feed}?filter=following`, { waitUntil: 'domcontentloaded' });
            await expect(page.locator(`${tab('following')}[aria-current="true"]`)).toHaveCount(1);
            await expect(page.locator(sel.postCard).filter({ hasText: content })).toHaveCount(0);
        } finally {
            if (author && postId) {
                await author.request
                    .delete(`/wp-json/buddynext/v1/posts/${postId}`, { headers: { 'X-WP-Nonce': author.nonce } })
                    .catch(() => undefined);
            }
            await resetPair(meId, authorId).catch(() => {});
            if (author) {
                await author.ctx.close().catch(() => {});
            }
        }
    });
});
