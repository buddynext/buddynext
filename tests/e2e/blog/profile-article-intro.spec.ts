import { test, expect } from '../_fixtures/auth.fixture';
import { urls } from '../_fixtures/selectors';
import { userId, wp } from '../_fixtures/wp';

/**
 * J-912-profile-article-intro.
 *
 * A row on the profile Articles tab (parts/article-card.php, row variant) ends a long
 * intro with a plain ellipsis, the same as the feed card, never WordPress's bracketed
 * "[...]" marker (BlogPostListener::excerpt_for()).
 *
 * Covers: cap-write-a-long-form-article-from-the-feed
 * Roles: admin
 *
 * Needs Member Blog active (it supplies the Articles tab); skipped without it.
 */
const ADMIN_LOGIN = process.env.BN_TEST_USER ?? 'varundubey';

test.describe('profile / Articles tab intro (J-912)', () => {
    test('a long intro ends with an ellipsis and no bracketed marker', async ({ authenticatedPage: page }) => {
        try {
            await wp(['plugin', 'is-active', 'buddypress-member-blog']);
        } catch {
            test.skip(true, 'Member Blog is not active on this site');
        }
        const uid = await userId(ADMIN_LOGIN);
        const title = `Intro ellipsis ${Date.now().toString().slice(-6)}`;
        const body = 'Plenty of words fill this article so the intro has to be cut short. '.repeat(10);
        const id = Number(await wp(['post', 'create', `--post_title=${title}`, `--post_content=${body}`, '--post_excerpt=', '--post_status=publish', `--post_author=${uid}`, '--porcelain']));
        try {
            await page.goto(`${urls.member(ADMIN_LOGIN)}articles/`);
            const excerpt = page.locator('.bn-article-card', { hasText: title }).locator('.bn-article-card__excerpt');
            await expect(excerpt).toBeVisible();
            const text = ((await excerpt.textContent()) ?? '').trim();
            expect(text.endsWith('…'), `intro was: ${text}`).toBe(true);
            expect(text).not.toContain('[');
        } finally {
            await wp(['post', 'delete', String(id), '--force']).catch(() => undefined);
        }
    });
});
