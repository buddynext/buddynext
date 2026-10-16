import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';
import { idsOf, restList } from '../_fixtures/effects';
import { bnApi } from '../_fixtures/spaces-rest';
import { drainQueue } from '../_fixtures/wp';

/**
 * J-23-hashtag-feed.
 *
 * Covers: cap-hashtag-and-follow-topics
 * Roles: admin
 * Note: uses the authenticatedPage fixture (admin owner only) - no member or
 * anon viewer of the hashtag feed is walked.
 */
test.describe('explore / hashtag feed', () => {
    test('hashtag feed page loads and scopes posts to the tag', async ({ authenticatedPage: page }) => {
        const slug = 'playwright';
        await page.goto(urls.hashtag(slug));
        await expect(page.locator(sel.app)).toBeVisible();

        // Header includes the slug.
        const heading = page.locator('h1, h2').filter({ hasText: new RegExp(slug, 'i') }).first();
        await expect(heading).toBeVisible({ timeout: 5_000 }).catch(async () => {
            // Some builds put the tag in a chip rather than a heading.
            const fallback = page.locator(`text=/#?${slug}/i`).first();
            await expect(fallback).toBeVisible();
        });
        // EFFECT: the page lists what the tag feed route answers for this tag: both empty or both populated.
        const rest = await restList(page, `/hashtags/${slug}/feed`);
        expect([200, 404], `GET /hashtags/${slug}/feed`).toContain(rest.status);
        const rendered = await page.locator(sel.postCard).count();
        expect(rendered > 0, `screen shows ${rendered} posts, REST answers ${rest.items.length}`).toBe(rest.items.length > 0);
    });

    test('hashtag feed lists a seeded tagged post and only posts of that tag', async ({ authenticatedPage: page }) => {
        // The fixed slug above may not exist on a site (both sides empty, which proves little).
        // Seed a post under a tag only this run knows, plus an untagged control.
        const stamp = Date.now().toString().slice(-6);
        const tag = `e2ehf${stamp}`;
        const tagged = `hashtag feed marker ${stamp} #${tag}`;
        const control = `hashtag feed control ${stamp} no tag`;
        let taggedId = 0;
        let controlId = 0;

        try {
            await page.goto(urls.feed, { waitUntil: 'domcontentloaded' });
            const a = await bnApi(page, 'POST', '/posts', { type: 'text', content: tagged });
            expect(a.status, `seed tagged post failed: ${JSON.stringify(a.data)}`).toBe(201);
            taggedId = Number((a.data as { id?: number }).id);
            const b = await bnApi(page, 'POST', '/posts', { type: 'text', content: control });
            expect(b.status, `seed control post failed: ${JSON.stringify(b.data)}`).toBe(201);
            controlId = Number((b.data as { id?: number }).id);
            // Hashtag extraction is deferred to Action Scheduler; run it instead of sleeping.
            await drainQueue(['buddynext_async_index_hashtags', 'buddynext_async_index_post']);

            // EFFECT: the tag route answers exactly the tagged post.
            const rest = await restList(page, `/hashtags/${tag}/feed`);
            expect(rest.status, `GET /hashtags/${tag}/feed`).toBe(200);
            const ids = idsOf(rest.data);
            expect(ids, 'tagged post missing from the tag feed route').toContain(taggedId);
            expect(ids, 'untagged post leaked into the tag feed route').not.toContain(controlId);
            expect(ids.length, 'a tag only this run used should hold one post').toBe(1);

            // The screen shows that same answer: the tagged card, not the control.
            await page.goto(urls.hashtag(tag), { waitUntil: 'domcontentloaded' });
            await expect(page.locator(sel.postCard).filter({ hasText: tagged }).first()).toBeVisible({ timeout: 10_000 });
            await expect(page.locator(sel.postCard).filter({ hasText: control })).toHaveCount(0);

            // EFFECT: deleting the post empties the tag feed again (the counterpart).
            const del = await bnApi(page, 'DELETE', `/posts/${taggedId}`);
            expect(del.status, `delete failed: ${JSON.stringify(del.data)}`).toBeLessThan(300);
            taggedId = 0;
            await drainQueue(['buddynext_async_index_hashtags', 'buddynext_async_index_post']);
            const after = await restList(page, `/hashtags/${tag}/feed`);
            expect(after.items.length, 'deleted post still listed on its tag feed').toBe(0);
        } finally {
            if (taggedId > 0) {
                await bnApi(page, 'DELETE', `/posts/${taggedId}`).catch(() => undefined);
            }
            if (controlId > 0) {
                await bnApi(page, 'DELETE', `/posts/${controlId}`).catch(() => undefined);
            }
        }
    });
});
