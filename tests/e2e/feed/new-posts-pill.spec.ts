import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip, resolveOtherMemberSlug } from '../_fixtures/precondition';
import { sel, urls } from '../_fixtures/selectors';
import { openMemberSession, restPost, restGet, readRestNonce, type MemberSession } from '../_fixtures/feed-wave1.helpers';

type NewCount = { count: number; newest_id: number };

/**
 * J-541 "N new posts" pill (B4).
 *
 * Written from the member's promise: "when someone else posts while I'm reading
 * the feed, I'm told, and one click brings the newer posts in." The coverage
 * matrix had this MISSING.
 *
 * Effect-based, no realtime/Pro dependency required — the pill's Free path polls
 * `GET /feed/new-count`. The spec:
 *   1. loads the feed as the actor and seeds a public post from a SECOND member,
 *   2. asserts the SERVER endpoint the pill polls (`/feed/new-count?after_id=…`)
 *      returns count>=1 with newest_id above the watermark — real server truth,
 *      not a synthetic DOM event,
 *   3. drives the pill's genuine poll path by dispatching `visibilitychange`
 *      (the store re-polls new-count on tab-focus), then asserts the
 *      `.bn-feed-new-pill` appears, and
 *   4. clicks the pill and asserts the reload actually surfaces the newer post.
 * A dead new-count endpoint fails at step 2; a pill wired to nothing fails at
 * step 3; a click that doesn't reload/refresh fails at step 4. The seeded post
 * and the second session are torn down in finally.
 */
test.describe('feed / new-posts pill', () => {
    const pill = '.bn-feed-new-pill';

    test('J-541 a new post by another member raises the pill; clicking loads it', async ({ authenticatedPage: page, browser }, testInfo) => {
        const authorSlug = await resolveOtherMemberSlug(page, 'varundubey');
        if (!authorSlug) {
            softSkip(testInfo, 'No second member to author the newer post.');
            return;
        }

        let author: MemberSession | null = null;
        let postId = 0;
        const stamp = Date.now().toString().slice(-6);
        const body = `j541 newer post ${stamp}`;

        try {
            // Actor is reading the feed. Capture the watermark exactly as the pill
            // store does: the highest post id already rendered.
            await page.goto(urls.feed);
            await expect(page.locator(sel.composer).first()).toBeVisible();
            const meNonce = await readRestNonce(page);
            const watermark = await page.evaluate(() => {
                let max = 0;
                document.querySelectorAll('[data-post-id]').forEach((el) => {
                    const id = parseInt((el as HTMLElement).dataset.postId ?? '0', 10);
                    if (id > max) { max = id; }
                });
                return max;
            });

            // A second member posts a public update while the actor sits on the feed.
            author = await openMemberSession(browser, authorSlug);
            const created = await restPost<{ id?: number }>(author.request, author.nonce, '/posts', {
                content: body,
                privacy: 'public',
            });
            expect(created.status, `seed post -> ${created.status}`).toBeLessThan(300);
            postId = created.body.id ?? 0;
            expect(postId).toBeGreaterThan(0);

            // Server truth: the endpoint the pill polls sees the new post above the
            // watermark. This is the non-fakeable core of the journey.
            await expect
                .poll(async () => {
                    const res = await restGet<NewCount>(page.request, meNonce, `/feed/new-count?after_id=${watermark}&filter=for-you`);
                    return res.body.count;
                }, { timeout: 10_000 })
                .toBeGreaterThanOrEqual(1);
            const nc = await restGet<NewCount>(page.request, meNonce, `/feed/new-count?after_id=${watermark}&filter=for-you`);
            expect(nc.body.newest_id).toBeGreaterThanOrEqual(postId);

            // UI effect: drive the store's real poll path (it re-polls new-count on
            // tab focus) and assert the pill renders. Re-dispatch each attempt to
            // absorb the async fetch.
            await expect(async () => {
                await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
                const shown = await page.locator(pill).isVisible().catch(() => false);
                expect(shown).toBe(true);
            }).toPass({ timeout: 20_000 });

            // Clicking the pill reloads; the newer post is now in the feed.
            await page.locator(pill).click();
            await expect(page.locator(sel.postCard).filter({ hasText: body }).first())
                .toBeVisible({ timeout: 10_000 });
        } finally {
            if (author && postId) {
                await author.request
                    .delete(`/wp-json/buddynext/v1/posts/${postId}`, { headers: { 'X-WP-Nonce': author.nonce } })
                    .catch(() => undefined);
            }
            if (author) {
                await author.ctx.close().catch(() => {});
            }
        }
    });
});
