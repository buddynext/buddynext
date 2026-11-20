import { test, expect } from '../_fixtures/auth.fixture';
import { createSpaceApi, deleteSpaceApi } from '../_fixtures/spaces-rest';
import { userId, wp } from '../_fixtures/wp';

/**
 * J-910-space-blog-tab.
 *
 * A public Space's Blog tab (templates/parts/space-blog-panel.php, MemberBlogBridge):
 * its empty state and Write for this Space link for a writer, the article card for
 * everyone once one is published for the Space, the guest never seeing the tab while
 * it is empty, a private Space never having it, and the card leaving with the article.
 *
 * Covers: cap-group-content-into-spaces
 * Roles: admin, guest
 *
 * Needs Member Blog active (the article provider); skipped without it. The article is
 * created with the Space in meta_input so the feed card is built on insert, the same
 * way the write form stores it.
 */

const ADMIN_LOGIN = process.env.BN_TEST_USER ?? 'varundubey';

async function memberBlogActive(): Promise<boolean> {
    try {
        await wp(['plugin', 'is-active', 'buddypress-member-blog']);
        return true;
    } catch {
        return false;
    }
}

test.describe('spaces / Blog tab (J-910)', () => {
    test('the tab, its empty state, a published article and the counterpart', async ({ authenticatedPage: page, browser, baseURL }) => {
        test.skip(!(await memberBlogActive()), 'Member Blog is not active on this site');
        const space = await createSpaceApi(page, { name: `Blog tab ${Date.now().toString().slice(-6)}` });
        const uid = await userId(ADMIN_LOGIN);
        const title = `Space blog article ${Date.now().toString().slice(-6)}`;
        let postId = 0;
        const guest = await browser.newContext({ baseURL });
        const guestPage = await guest.newPage();

        try {
            // Writer, empty Space: the tab, the empty state and the write link.
            await page.goto(`/spaces/${space.slug}/blog/`);
            await expect(page.getByText('No articles in this space yet.')).toBeVisible();
            await expect(page.getByRole('link', { name: 'Write for this Space' }).first()).toHaveAttribute('href', new RegExp(`bn_space=${space.id}`));

            // Guest, empty Space: no Blog tab at all.
            await guestPage.goto(`/spaces/${space.slug}/`);
            await expect(guestPage.locator(`a[href$="/spaces/${space.slug}/blog/"]`)).toHaveCount(0);

            // Publish an article for the Space: the card shows for the guest too.
            postId = Number(
                await wp(['post', 'create', `--post_title=${title}`, '--post_content=Body for the space blog journey.', '--post_status=publish', `--post_author=${uid}`, `--meta_input={"_bn_space_id":${space.id}}`, '--porcelain'])
            );
            await guestPage.goto(`/spaces/${space.slug}/blog/`);
            await expect(guestPage.locator('.bn-article-card', { hasText: title })).toBeVisible();
            await expect(guestPage.getByRole('link', { name: 'Write for this Space' })).toHaveCount(0);
            await expect(guestPage.locator(`a[href$="/spaces/${space.slug}/blog/"]`).first()).toBeVisible();

            // Counterpart: deleting the article removes the card.
            await wp(['post', 'delete', String(postId), '--force']);
            postId = 0;
            await guestPage.goto(`/spaces/${space.slug}/blog/`);
            await expect(guestPage.locator('.bn-article-card', { hasText: title })).toHaveCount(0);
        } finally {
            if (postId) {
                await wp(['post', 'delete', String(postId), '--force']).catch(() => undefined);
            }
            await guest.close();
            await deleteSpaceApi(page, space.id);
        }
    });

    test('a private Space has no Blog tab, even for its owner', async ({ authenticatedPage: page }) => {
        test.skip(!(await memberBlogActive()), 'Member Blog is not active on this site');
        const space = await createSpaceApi(page, { name: `Private blog ${Date.now().toString().slice(-6)}`, type: 'private' });
        try {
            await page.goto(`/spaces/${space.slug}/`);
            await expect(page.locator(`a[href$="/spaces/${space.slug}/blog/"]`)).toHaveCount(0);
        } finally {
            await deleteSpaceApi(page, space.id);
        }
    });
});
