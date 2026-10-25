import { test, expect } from '../_fixtures/auth.fixture';

/**
 * J-819-admin-menu-ia-order.
 *
 * Covers: cap-administer-everything-from-one-menu
 * Roles: admin
 *
 * The owner reads the BuddyNext admin top to bottom, so the sections follow the
 * owner journey, Moderation holds the daily tabs in the order a moderator meets
 * them, and a bookmark to the retired "Moderation Tools" page still lands on the
 * tab's new home.
 *
 * Effect-first: the order is read from the hub nav the owner sees, and the
 * redirect is asserted as a 302 with its Location, not as a screen string. A
 * redirect sent after the admin header prints silently fails on a host without
 * output buffering, which only the raw response shows.
 */

// Owner-journey order. Sections that need Pro or a feature may be absent; those
// that are present must keep this relative order.
const JOURNEY = [
    'buddynext',
    'buddynext-members',
    'buddynext-spaces',
    'buddynext-engagement',
    'buddynext-notifications',
    'buddynext-moderation',
    'buddynext-automation',
    'buddynext-monetization',
    'buddynext-campaigns',
    'buddynext-realtime',
    'buddynext-platform',
    'buddynext-integration-settings',
    'buddynext-settings',
    'buddynext-upgrade',
];
const MODERATION_TABS = ['pending', 'reports', 'suspensions', 'appeals', 'log'];

test.describe('admin / menu order and retired page', () => {
    test('J-819 sections follow the owner journey and Moderation keeps its tab order', async ({ authenticatedPage: page }) => {
        await page.goto('/wp-admin/admin.php?page=buddynext');
        const hrefs = await page.$$eval('.bn-hub-nav-link', (as) => as.map((a) => (a as HTMLAnchorElement).getAttribute('href') ?? ''));

        const pages: string[] = [];
        const tabs: Record<string, string[]> = {};
        for (const href of hrefs) {
            const q = new URL(href, 'http://x/wp-admin/').searchParams;
            const p = q.get('page') ?? '';
            if (!pages.includes(p)) pages.push(p);
            (tabs[p] ??= []).push(q.get('tab') ?? '');
        }

        expect(pages, 'no retired Moderation Tools section').not.toContain('buddynext-automod');
        const known = pages.filter((p) => JOURNEY.includes(p));
        expect(known, 'sections in owner-journey order').toEqual(JOURNEY.filter((p) => known.includes(p)));
        expect(known.length, 'the core sections are present').toBeGreaterThanOrEqual(8);
        expect(tabs['buddynext-moderation'], 'Moderation tabs in moderator order').toEqual(MODERATION_TABS);
    });

    test('J-819 a bookmark to the retired Moderation Tools page redirects to the tab new home', async ({ authenticatedPage: page }) => {
        const bulk = await page.request.get('/wp-admin/admin.php?page=buddynext-automod&tab=bulk', { maxRedirects: 0 });
        expect(bulk.status(), 'redirect, not an empty admin page').toBe(302);
        expect(bulk.headers()['location']).toContain('page=buddynext-automation');
        expect(bulk.headers()['location']).toContain('tab=bulk');

        const bare = await page.request.get('/wp-admin/admin.php?page=buddynext-automod', { maxRedirects: 0 });
        expect(bare.status()).toBe(302);
        expect(bare.headers()['location']).toContain('page=buddynext-automation');

        // The destination renders a real tab body.
        await page.goto(bulk.headers()['location']);
        await expect(page.locator('.bn-hub-nav-link[href*="buddynext-automation"]').first()).toBeVisible();
    });
});
