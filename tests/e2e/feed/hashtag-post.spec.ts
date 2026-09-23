import { test, expect } from '../_fixtures/auth.fixture';
import { loginAs } from '../_fixtures/actor';
import { sel, urls } from '../_fixtures/selectors';
import { readRestNonce, postIdOfCard, deletePostRest } from '../_fixtures/feed-wave1.helpers';
import { wp } from '../_fixtures/wp';

const MEMBER_LOGIN = process.env.BN_TEST_OTHER_USER ?? 'alice';

/**
 * J-511 composer #hashtag → the tag's feed lists the post (B1).
 *
 * Written from the member's promise: "a post I write with #tag shows up on that
 * tag's page." The coverage matrix had "#hashtag typeahead" MISSING. This does
 * not test the typeahead popover — it tests the CONSEQUENCE that matters: the
 * hashtag is extracted, indexed, and the post surfaces on /activity/hashtag/{tag}/.
 *
 * Effect-based: the post is composed through the real composer, the hashtag
 * indexer (Action Scheduler async worker `buddynext_async_index_hashtags`) is
 * drained via wp-cli so the assertion is deterministic rather than racing the
 * queue, and then the tag page is loaded fresh and asserted to contain the
 * post card. A composer that stores the '#tag' as plain text but never indexes
 * it (so the tag page stays empty) fails here. Post deleted in `finally`.
 *
 * Covers: cap-hashtag-and-follow-topics
 * Roles: admin, member
 */
test.describe('feed / composer hashtag', () => {
    test('J-511 a post with #hashtag is listed on that hashtag feed', async ({ authenticatedPage: page }) => {
        const stamp = Date.now().toString().slice(-6);
        const tag = `e2etag${stamp}`;
        const content = `j511 tagging #${tag} here`;
        let postId = 0;
        let nonce = '';

        try {
            await page.goto(urls.feed);
            await expect(page.locator(sel.composer).first()).toBeVisible();
            nonce = await readRestNonce(page);

            await page.locator(sel.composerTextarea).first().fill(content);
            await page.locator(sel.composerSubmit).first().click();
            await expect(page.locator(sel.postCard).filter({ hasText: content }).first()).toBeVisible({ timeout: 10_000 });

            await page.goto(urls.feed);
            postId = await postIdOfCard(page, content);
            expect(postId, 'resolved server post id').toBeGreaterThan(0);

            // Hashtag extraction runs on the `buddynext_async_index_hashtags` hook,
            // queued through Action Scheduler on create. Drain that group so the tag
            // is linked before we look — otherwise the assertion races the queue.
            await wp(['action-scheduler', 'run', '--group=buddynext']).catch(() => '');

            // The tag page is a fresh server render of every public post carrying
            // the tag. The post I just wrote must be one of them.
            await page.goto(urls.hashtag(tag), { waitUntil: 'domcontentloaded' });
            await expect(
                page.locator(sel.postCard).filter({ hasText: content }).first()
            ).toBeVisible({ timeout: 10_000 });
        } finally {
            await deletePostRest(page.request, nonce, postId).catch(() => {});
        }
    });

    /**
     * J-511 member leg. A member is who actually browses hashtags day to day —
     * the promise is "and follow topics", which starts with a member finding
     * their own tagged post there. Same indexer drain, same effect check.
     */
    test('J-511 member  -  a member post with #hashtag is listed on that hashtag feed', async ({ page }) => {
        await loginAs(page, MEMBER_LOGIN);
        const stamp = Date.now().toString().slice(-6);
        const tag = `e2etagm${stamp}`;
        const content = `j511m member tagging #${tag} here`;
        let postId = 0;
        let nonce = '';

        try {
            await page.goto(urls.feed);
            await expect(page.locator(sel.composer).first()).toBeVisible();
            nonce = await readRestNonce(page);

            await page.locator(sel.composerTextarea).first().fill(content);
            await page.locator(sel.composerSubmit).first().click();
            await expect(page.locator(sel.postCard).filter({ hasText: content }).first()).toBeVisible({ timeout: 10_000 });

            await page.goto(urls.feed);
            postId = await postIdOfCard(page, content);
            expect(postId, 'resolved server post id').toBeGreaterThan(0);

            await wp(['action-scheduler', 'run', '--group=buddynext']).catch(() => '');

            await page.goto(urls.hashtag(tag), { waitUntil: 'domcontentloaded' });
            await expect(
                page.locator(sel.postCard).filter({ hasText: content }).first()
            ).toBeVisible({ timeout: 10_000 });
        } finally {
            await deletePostRest(page.request, nonce, postId).catch(() => {});
        }
    });
});
