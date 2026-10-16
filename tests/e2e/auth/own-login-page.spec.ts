import { test, expect } from '@playwright/test';
import { writeFileSync, unlinkSync } from 'node:fs';
import { join } from 'node:path';
import { createPage, deletePage, wp } from '../_fixtures/wp';
import { urls } from '../_fixtures/selectors';
import { sessionStatus } from '../_fixtures/effects';

/**
 * J-991 a site with its own login page: BuddyNext sends guests there (card 10401156664).
 *
 * Written from the developer's promise: "I pointed buddynext_login_url at my own
 * page, so a guest who clicks Log in, or hits a members-only page, ends up on MY
 * login page and comes back where they were." Before 1.2.6 the guest gates and
 * every Log in link went to the BuddyNext screen whatever the site wanted.
 *
 * The filter lives in a throwaway mu-plugin that only acts for a browser carrying
 * the bn_qa_own_login cookie, so the rest of the site (and other journeys running
 * against it) never see it. The same mu-plugin makes Explore members-only for that
 * browser, to exercise a guest gate without changing a site setting.
 *
 * Effect read-back: the guest lands on the custom page with redirect_to naming the
 * page they came from, and the REST layer still reports them logged out.
 *
 * Covers: cap-register-and-log-in-without-wp-login
 * Roles: anon
 */
test.describe('auth / own login page', () => {
    const WP_PATH = process.env.BN_WP_PATH ?? '';
    test.skip(!WP_PATH, 'BN_WP_PATH is needed to install the request-scoped filter.');

    const slug = 'qa-own-login';
    const mu = join(WP_PATH, 'wp-content', 'mu-plugins', 'qa-own-login-url.php');
    let pageId = 0;

    test.beforeAll(async () => {
        pageId = (await createPage('<p>QA own login page</p>', { title: 'QA own login', slug })).id;
        writeFileSync(
            mu,
            [
                '<?php',
                "if ( isset( $_COOKIE['bn_qa_own_login'] ) ) {",
                "\tadd_filter( 'pre_option_buddynext_public_explore', static fn() => '0' );",
                `\tadd_filter( 'buddynext_login_url', static fn() => home_url( '/${slug}/' ) );`,
                '}',
                '',
            ].join('\n')
        );
    });

    test.afterAll(async () => {
        try {
            unlinkSync(mu);
        } catch {
            /* already gone */
        }
        await deletePage(pageId);
    });

    test('J-991 Log in links and guest gates go to the site\'s own login page and carry the return page', async ({ browser, baseURL }) => {
        const ctx = await browser.newContext();
        const host = new URL(baseURL as string).hostname;
        await ctx.addCookies([{ name: 'bn_qa_own_login', value: '1', domain: host, path: '/' }]);
        const page = await ctx.newPage();
        try {
            const own = new RegExp(`/${slug}/\\?redirect_to=`);

            // 1. A guest clicks "Log in to join" on an open space.
            const list = await page.request.get('/wp-json/buddynext/v1/spaces?per_page=20');
            expect(list.status(), 'guest can list spaces').toBe(200);
            const body = (await list.json()) as { items?: Array<{ slug?: string; type?: string }> } | Array<{ slug?: string; type?: string }>;
            const items = Array.isArray(body) ? body : body.items ?? [];
            const open = items.find((s) => s.type === 'open' && s.slug);
            test.skip(!open, 'no open space to visit as a guest');
            // Resolve the spaces base from the page mapping; slugs are site-configurable.
            const spacesBase = await wp(['eval', 'echo BuddyNext\\Core\\PageRouter::spaces_url();']);
            const spaceUrl = `${spacesBase.replace(/\/$/, '')}/${open!.slug}/`;
            await page.goto(spaceUrl);
            const join = page.getByRole('link', { name: 'Log in to join' }).first();
            await expect(join).toBeVisible();
            await join.click();
            await expect(page).toHaveURL(own);
            expect(decodeURIComponent(new URL(page.url()).searchParams.get('redirect_to') ?? ''), 'returns to the space').toBe(spaceUrl);
            await expect(page.getByText('QA own login page')).toBeVisible();

            // 2. A guest opens a members-only page: the gate sends them to the same place.
            await page.goto(urls.explore);
            await expect(page).toHaveURL(own);
            expect(new URL(page.url()).searchParams.get('redirect_to') ?? '', 'returns to Explore').toContain(urls.explore);

            // EFFECT: nothing logged the guest in on the way.
            expect(await sessionStatus(page), 'still a guest').toBeGreaterThanOrEqual(401);

            // 3. Without the filter the BuddyNext login screen is untouched.
            await ctx.clearCookies();
            await page.goto(spaceUrl);
            const plain = await page.getByRole('link', { name: 'Log in to join' }).first().getAttribute('href');
            expect(plain ?? '', 'no filter: the BuddyNext login page').not.toContain(`/${slug}/`);
            expect(plain ?? '').toContain('redirect_to=');
        } finally {
            await ctx.close();
        }
    });
});
