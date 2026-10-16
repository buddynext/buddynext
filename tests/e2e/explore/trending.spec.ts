import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';
import { restList } from '../_fixtures/effects';
import { bnApi } from '../_fixtures/spaces-rest';
import { drainQueue } from '../_fixtures/wp';

/**
 * J-21-explore-trending + J-22-hashtag-follow.
 *
 * Covers: cap-hashtag-and-follow-topics
 * Roles: admin
 * Note: uses the authenticatedPage fixture (admin owner only) - no member or
 * anon viewer of the explore deck is walked.
 */
test.describe('explore / trending', () => {
    // The explore page is a discovery DECK, not a post feed, and its trending tags
    // render as `.bn-ex-trend__tag` (sidebar-explore-trending-tags.php) /
    // `.bn-hashtag-list` (the trending-hashtags block) - NOT the shared
    // `sel.hashtagChip` (.bn-hashtag-chip / [data-hashtag]). Assert against the real
    // markup with a local selector (repo rule: never edit the shared selectors.ts).
    const trendingTags = '.bn-ex-trend__tag, .bn-hashtag-list a';

    test('trending page renders posts + hashtag chips', async ({ authenticatedPage: page }) => {
        // Trending counts the last 24 h, so a quiet site rightly shows no chips. Post
        // something tagged now, the way a member would, so the walk has a tag to rank.
        const tag = `sunsetwalk${Date.now().toString().slice(-6)}`;
        let postId = 0;
        try {
            await page.goto(urls.explore, { waitUntil: 'domcontentloaded' });
            const seed = await bnApi(page, 'POST', '/posts', {
                type: 'text',
                content: `Golden hour at Ocean Beach tonight, who is coming? #${tag}`,
            });
            expect(seed.status, `seed tagged post failed: ${JSON.stringify(seed.data)}`).toBe(201);
            postId = Number((seed.data as { id?: number }).id);
            await drainQueue(['buddynext_async_index_hashtags', 'buddynext_async_index_post']);

            await page.goto(urls.explore);
            await expect(page.locator(sel.app)).toBeVisible();
            // The deck (templates/partials/explore-card.php) renders `.ec-card`, not post cards.
            await expect(page.locator('.ec-card').first(), 'the Explore deck shows cards').toBeVisible({ timeout: 10_000 });

            // EFFECT: the new tag is ranked by the trending route and shown as a chip,
            // and every chip on screen is one the route ranks.
            const rest = await restList(page, '/hashtags/trending');
            expect(rest.status, 'GET /hashtags/trending').toBe(200);
            const ranked = rest.items.map((t) => String(t.slug ?? t.tag ?? t.name ?? '').toLowerCase());
            expect(ranked, 'the freshly used tag is trending').toContain(tag);
            await expect(page.locator(trendingTags).filter({ hasText: `#${tag}` }).first()).toBeVisible();
            const shown = (await page.locator('.bn-ex-trend__tag').allInnerTexts()).map((t) => t.replace(/^#/, '').trim().toLowerCase()).filter(Boolean);
            for (const shownTag of shown) {
                expect(ranked, `chip #${shownTag} is not in the trending route`).toContain(shownTag);
            }
        } finally {
            if (postId) {
                await bnApi(page, 'DELETE', `/posts/${postId}`).catch(() => undefined);
            }
        }
    });

    test('clicking a hashtag follow chip toggles its state', async ({ authenticatedPage: page }) => {
        // The follow control is the hashtag page's hero button `.bn-htf[data-hashtag]`
        // (parts/hashtag-hero.php:101). The shared `sel.hashtagFollow`
        // (`.bn-hashtag-related__follow`) is the RELATED-tags sidebar of a hashtag page and
        // never renders on /activity/explore/, so this journey soft-skipped on every run.
        // Seed a tag only this run knows so the walk starts unfollowed.
        const stamp = Date.now().toString().slice(-6);
        const tag = `e2efollow${stamp}`;
        let postId = 0;
        const followed = async () =>
            (await restList(page, '/me/hashtags')).items.some((t) => String(t.slug ?? t.tag ?? t.name ?? '') === tag);

        try {
            await page.goto(urls.explore, { waitUntil: 'domcontentloaded' });
            const seed = await bnApi(page, 'POST', '/posts', { type: 'text', content: `Early run along the waterfront this morning ${stamp} #${tag}` });
            expect(seed.status, `seed tagged post failed: ${JSON.stringify(seed.data)}`).toBe(201);
            postId = Number((seed.data as { id?: number }).id);
            await drainQueue(['buddynext_async_index_hashtags', 'buddynext_async_index_post']);
            expect(await followed(), 'a brand-new tag starts unfollowed').toBe(false);

            await page.goto(urls.hashtag(tag), { waitUntil: 'domcontentloaded' });
            const followBtn = page.locator(`.bn-htf[data-hashtag="${tag}"]`).first();
            await expect(followBtn, 'hashtag hero has no follow button').toBeVisible({ timeout: 10_000 });
            await expect(followBtn).toHaveAttribute('data-current-state', 'follow');

            // Follow.
            const [on] = await Promise.all([
                page.waitForResponse((r) => r.url().includes(`/hashtags/${tag}/follow`) && r.request().method() === 'POST', { timeout: 10_000 }),
                followBtn.click(),
            ]);
            expect(on.status(), 'POST follow').toBeLessThan(300);
            await expect(followBtn).toHaveAttribute('aria-pressed', 'true');
            // EFFECT: my followed-hashtags list holds the tag, and the tag counts me.
            await expect.poll(followed, { timeout: 5_000 }).toBe(true);
            const tagRow = await bnApi(page, 'GET', `/hashtags/${tag}`);
            expect(tagRow.status, `GET /hashtags/${tag}`).toBe(200);
            expect(Number((tagRow.data as { follower_count?: number }).follower_count ?? 0), 'follower_count after follow').toBe(1);
            // EFFECT: it survives a reload (server-rendered state).
            await page.goto(urls.hashtag(tag), { waitUntil: 'domcontentloaded' });
            await expect(followBtn).toHaveAttribute('data-current-state', 'following');

            // Unfollow (counterpart).
            const [off] = await Promise.all([
                page.waitForResponse((r) => r.url().includes(`/hashtags/${tag}/follow`) && r.request().method() === 'DELETE', { timeout: 10_000 }),
                followBtn.click(),
            ]);
            expect(off.status(), 'DELETE follow').toBeLessThan(300);
            await expect.poll(followed, { timeout: 5_000 }).toBe(false);
        } finally {
            await bnApi(page, 'DELETE', `/hashtags/${tag}/follow`).catch(() => undefined);
            if (postId > 0) {
                await bnApi(page, 'DELETE', `/posts/${postId}`).catch(() => undefined);
            }
        }
    });
});
