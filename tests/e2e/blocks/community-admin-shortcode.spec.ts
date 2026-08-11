import { test, expect } from '../_fixtures/auth.fixture';
import { createPage, deletePage } from '../_fixtures/wp';

/**
 * J-805 — [buddynext_community_admin] sidebar tabs stay on the page they are placed
 * on, instead of 404-ing to a hardcoded hub slug.
 *
 * The bug: the tabs were add_query_arg( 'bn_admin', ..., buddynext_community_admin_url() )
 * links — the hardcoded bn-community-admin slug — so on any other page every tab but
 * Overview navigated to /bn-community-admin/?bn_admin=... and hit a real 404.
 *
 * Proven by EFFECT: placed on an ordinary slug, every sidebar-tab link resolves to
 * THIS page's path (not /bn-community-admin/), and following one renders the panel
 * (not a 404). Requires the test user to be a community admin; skips cleanly if not.
 */
test.describe('blocks / community-admin shortcode tabs (J-805)', () => {
    test('J-805 sidebar tabs target the current page and do not 404', async ({
        authenticatedPage: page,
    }) => {
        const slug = 'e2e-community-admin';
        const rec = await createPage('[buddynext_community_admin]', {
            title: 'E2E Community Admin',
            slug,
        });
        try {
            await page.goto(rec.url, { waitUntil: 'domcontentloaded' });

            const tabs = page.locator(`a[href*="bn_admin="]`);
            const count = await tabs.count();
            // No tabs → the current user is not a community admin (panel shows the
            // access notice). That is a valid state, not a failure of this fix.
            test.skip(count === 0, 'test user lacks community-admin access');

            const thisPath = new URL(rec.url).pathname;
            const paths = new Set<string>();
            for (let i = 0; i < count; i++) {
                const href = await tabs.nth(i).getAttribute('href');
                if (href) {
                    paths.add(new URL(href, rec.url).pathname);
                }
            }

            // EFFECT 1: every tab stays on THIS page; none point at the hardcoded hub.
            expect([...paths]).toEqual([thisPath]);

            // EFFECT 2: following the Members tab renders the panel, not a WP 404.
            await page.goto(`${rec.url}?bn_admin=members`, { waitUntil: 'domcontentloaded' });
            await expect(page.locator('body.error404')).toHaveCount(0);
            await expect(page.locator('[class*="community-admin"], .bn-ca, .bn-ca-card').first()).toBeVisible({
                timeout: 10_000,
            });
        } finally {
            await deletePage(rec.id);
        }
    });
});
