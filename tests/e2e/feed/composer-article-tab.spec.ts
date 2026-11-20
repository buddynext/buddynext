import { test, expect } from '../_fixtures/auth.fixture';
import { urls } from '../_fixtures/selectors';
import { dbCount, tablePrefix, userId } from '../_fixtures/wp';

/**
 * J-909-composer-article-tab.
 *
 * The What's New composer's Post / Article tabs (partials/composer.php,
 * partials/composer-article.php, the buddynext/post-composer store): a real
 * ARIA tab widget driven by the keyboard, a Post box that survives a switch and
 * is never submitted by it, and counts that match the member's posts.
 *
 * Covers: cap-write-a-long-form-article-from-the-feed
 * Roles: admin
 *
 * The acting session is the admin, who can always write (Member Blog when it is
 * active, else the WordPress editor). Effects are read back from the database:
 * no feed post is created by switching tabs, and the My drafts / My articles
 * pills equal the author's draft and publish + private counts.
 */

const ADMIN_LOGIN = process.env.BN_TEST_USER ?? 'varundubey';

test.describe('feed / composer Post and Article tabs', () => {
    test('arrow keys, Home and End move between the tabs with a roving tabindex', async ({ authenticatedPage: page }) => {
        await page.goto(urls.feed);

        const tablist = page.getByRole('tablist', { name: 'What to share' });
        await expect(tablist).toBeVisible();
        const postTab = tablist.getByRole('tab', { name: 'Post' });
        const articleTab = tablist.getByRole('tab', { name: 'Article' });

        await expect(postTab).toHaveAttribute('aria-selected', 'true');
        await expect(postTab).toHaveAttribute('tabindex', '0');
        await expect(articleTab).toHaveAttribute('tabindex', '-1');

        await postTab.focus();
        await page.keyboard.press('ArrowRight');
        await expect(articleTab).toBeFocused();
        await expect(articleTab).toHaveAttribute('aria-selected', 'true');
        await expect(articleTab).toHaveAttribute('tabindex', '0');
        await expect(postTab).toHaveAttribute('tabindex', '-1');
        await expect(page.locator('#' + (await articleTab.getAttribute('aria-controls')))).toBeVisible();
        await expect(page.locator('#' + (await postTab.getAttribute('aria-controls')))).toBeHidden();

        await page.keyboard.press('Home');
        await expect(postTab).toBeFocused();
        await expect(postTab).toHaveAttribute('aria-selected', 'true');

        await page.keyboard.press('End');
        await expect(articleTab).toBeFocused();

        await page.keyboard.press('ArrowLeft');
        await expect(postTab).toBeFocused();
        await expect(postTab).toHaveAttribute('aria-selected', 'true');
    });

    test('switching tabs keeps the post text and never posts it', async ({ authenticatedPage: page }) => {
        await page.goto(urls.feed);
        const stamp = Date.now().toString().slice(-6);
        const content = `Half a thought before switching tabs ${stamp}`;

        const textarea = page.locator('.bn-composer__prompt').first();
        await textarea.fill(content);

        const tablist = page.getByRole('tablist', { name: 'What to share' });
        await tablist.getByRole('tab', { name: 'Article' }).click();
        await expect(textarea).toBeHidden();
        await tablist.getByRole('tab', { name: 'Post' }).click();
        await expect(textarea).toHaveValue(content);

        const p = await tablePrefix();
        expect(await dbCount(`SELECT COUNT(*) FROM ${p}bn_posts WHERE content LIKE '%${stamp}%'`)).toBe(0);
        await textarea.fill('');
    });

    test('the draft and article counts match the author\'s posts, and Start writing carries the title', async ({ authenticatedPage: page }) => {
        const uid = await userId(ADMIN_LOGIN);
        const p = await tablePrefix();
        const drafts = await dbCount(
            `SELECT COUNT(*) FROM ${p}posts WHERE post_author=${uid} AND post_type='post' AND post_status='draft'`
        );
        const articles = await dbCount(
            `SELECT COUNT(*) FROM ${p}posts WHERE post_author=${uid} AND post_type='post' AND post_status IN ('publish','private')`
        );

        await page.goto(urls.feed);
        await page.getByRole('tablist', { name: 'What to share' }).getByRole('tab', { name: 'Article' }).click();

        const panel = page.locator('.bn-composer-article');
        await expect(panel.locator('.bn-composer-article__link', { hasText: 'My drafts' }).locator('.bn-composer-article__count')).toHaveText(String(drafts));
        await expect(panel.locator('.bn-composer-article__link', { hasText: 'My articles' }).locator('.bn-composer-article__count')).toHaveText(String(articles));

        const title = `Weekly office hours ${Date.now().toString().slice(-6)}`;
        const field = panel.locator('.bn-composer-article__title');
        const name = (await field.getAttribute('name')) ?? 'bn_title';
        await field.fill(title);
        await Promise.all([page.waitForURL((url) => url.searchParams.get(name) === title), panel.getByRole('button', { name: 'Start writing' }).click()]);
    });
});
