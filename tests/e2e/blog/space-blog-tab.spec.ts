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
    test('Articles in Spaces off: no tab, no write link, the old address opens the Space, and turning it on restores', async ({ authenticatedPage: page }) => {
        test.skip(!(await memberBlogActive()), 'Member Blog is not active on this site');
        const space = await createSpaceApi(page, { name: `Switch ${Date.now().toString().slice(-6)}` });
        const uid = await userId(ADMIN_LOGIN);
        const title = `Switch article ${Date.now().toString().slice(-6)}`;
        const OPTION = 'buddynext_integration_blog_subtab_spaces';
        // The settings screen queues this batch when the switch is saved; run it directly here.
        const settle = () => wp(['eval', '(new \\BuddyNext\\Feed\\BlogPostListener())->resync_space_cards( 0 );']);
        const postId = Number(
            await wp(['post', 'create', `--post_title=${title}`, '--post_content=Body.', '--post_status=publish', `--post_author=${uid}`, `--meta_input={"_bn_space_id":${space.id}}`, '--porcelain'])
        );
        const tab = page.locator(`a[href$="/spaces/${space.slug}/blog/"]`);

        try {
            await page.goto(`/spaces/${space.slug}/`);
            await expect(tab.first()).toBeAttached(); // present; it may sit in the More menu at some widths

            await wp(['option', 'update', OPTION, '0']);
            await settle();
            await page.goto(`/spaces/${space.slug}/`);
            await expect(tab).toHaveCount(0);
            await expect(page.getByRole('link', { name: 'Write for this Space' })).toHaveCount(0);
            await page.goto(`/add-new-post/?bn_space=${space.id}`);
            await expect(page.getByText('Writing for')).toHaveCount(0);
            await page.goto(`/spaces/${space.slug}/blog/`);
            await expect(page.locator('.bn-article-card', { hasText: title })).toHaveCount(0);
            await expect(page.locator('h1').first()).toContainText('Switch');

            await wp(['option', 'update', OPTION, '1']);
            await settle();
            await page.goto(`/spaces/${space.slug}/`);
            await expect(tab.first()).toBeAttached();
        } finally {
            await wp(['option', 'update', OPTION, '1']).catch(() => undefined);
            await wp(['post', 'delete', String(postId), '--force']).catch(() => undefined);
            await deleteSpaceApi(page, space.id);
        }
    });
    test('the Space owner can switch the Articles tab off for one Space only', async ({ authenticatedPage: page }) => {
        test.skip(!(await memberBlogActive()), 'Member Blog is not active on this site');
        const off = await createSpaceApi(page, { name: `Owner off ${Date.now().toString().slice(-6)}` });
        const on = await createSpaceApi(page, { name: `Owner on ${Date.now().toString().slice(-6)}` });
        const uid = await userId(ADMIN_LOGIN);
        const title = `Per Space ${Date.now().toString().slice(-6)}`;
        const post = (spaceId: number) => wp(['post', 'create', `--post_title=${title} ${spaceId}`, '--post_content=Body.', '--post_status=publish', `--post_author=${uid}`, `--meta_input={"_bn_space_id":${spaceId}}`, '--porcelain']);
        const ids = [Number(await post(off.id)), Number(await post(on.id))];
        const tab = (slug: string) => page.locator(`a[href$="/spaces/${slug}/blog/"]`);
        // The switch is a Space field; its save queues this batch, run here directly.
        const settle = (spaceId: number) => wp(['eval', `(new \\BuddyNext\\Feed\\BlogPostListener())->resync_space_cards( 0, ${spaceId} );`]);

        try {
            await wp(['eval', `update_space_meta( ${off.id}, 'articles_tab', '0' );`]);
            await settle(off.id);
            await page.goto(`/spaces/${off.slug}/`);
            await expect(tab(off.slug)).toHaveCount(0);
            await expect(page.getByRole('link', { name: 'Write for this Space' })).toHaveCount(0);
            await page.goto(`/spaces/${on.slug}/`);
            await expect(tab(on.slug).first()).toBeAttached();
            await page.goto(`/spaces/${off.slug}/blog/`);
            await expect(page.locator('.bn-article-card', { hasText: `${title} ${off.id}` })).toHaveCount(0);

            await wp(['eval', `update_space_meta( ${off.id}, 'articles_tab', '1' );`]);
            await settle(off.id);
            await page.goto(`/spaces/${off.slug}/`);
            await expect(tab(off.slug).first()).toBeAttached();
        } finally {
            for (const id of ids) {
                await wp(['post', 'delete', String(id), '--force']).catch(() => undefined);
            }
            await deleteSpaceApi(page, off.id);
            await deleteSpaceApi(page, on.id);
        }
    });
});
