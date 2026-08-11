import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';
import { readRestNonce, restPost, deletePostRest } from '../_fixtures/feed-wave1.helpers';

/**
 * J-520 single-post permalink (B4).
 *
 * Written from the member's promise: "a post's permalink opens exactly that
 * post on its own page." The coverage matrix had "Single-post permalink"
 * MISSING.
 *
 * Effect-based, discriminating: two distinct posts A and B are seeded, then each
 * permalink is opened and asserted to render ITS post and NOT the other. A
 * permalink route that simply re-renders the whole feed (both posts present)
 * would pass a naive "post visible" check but fails this cross-check. Both posts
 * deleted in `finally`.
 */
test.describe('feed / single-post permalink', () => {
    test('J-520 each permalink renders its own post standalone', async ({ authenticatedPage: page }) => {
        const stamp = Date.now().toString().slice(-6);
        const textA = `j520 alpha ${stamp}`;
        const textB = `j520 bravo ${stamp}`;
        let idA = 0;
        let idB = 0;
        let nonce = '';

        try {
            await page.goto(urls.feed);
            await expect(page.locator(sel.composer).first()).toBeVisible();
            nonce = await readRestNonce(page);

            const a = await restPost<{ id?: number }>(page.request, nonce, '/posts', { content: textA, privacy: 'public' });
            const b = await restPost<{ id?: number }>(page.request, nonce, '/posts', { content: textB, privacy: 'public' });
            idA = a.body.id ?? 0;
            idB = b.body.id ?? 0;
            expect(idA).toBeGreaterThan(0);
            expect(idB).toBeGreaterThan(0);

            // Permalink A shows A, not B.
            await page.goto(`/p/${idA}/`, { waitUntil: 'domcontentloaded' });
            await expect(page.locator(sel.postCard).filter({ hasText: textA }).first()).toBeVisible({ timeout: 10_000 });
            await expect(page.locator(sel.postCard).filter({ hasText: textB })).toHaveCount(0);

            // Permalink B shows B, not A.
            await page.goto(`/p/${idB}/`, { waitUntil: 'domcontentloaded' });
            await expect(page.locator(sel.postCard).filter({ hasText: textB }).first()).toBeVisible({ timeout: 10_000 });
            await expect(page.locator(sel.postCard).filter({ hasText: textA })).toHaveCount(0);
        } finally {
            await deletePostRest(page.request, nonce, idA).catch(() => {});
            await deletePostRest(page.request, nonce, idB).catch(() => {});
        }
    });
});
