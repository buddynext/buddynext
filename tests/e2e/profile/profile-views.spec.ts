import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-30 profile-views widget (Pro P5.3).
 */
test.describe('profile / who-viewed widget (Pro P5.3)', () => {
    test.fixme(process.env.BN_PRO !== '1', 'Who-viewed widget is a Pro feature. Set BN_PRO=1.');

    // Two preconditions this spec never establishes, both correct behaviour:
    //   1. the `analytics` feature is OFF by default on a fresh site, so
    //      AnalyticsCollector never records buddynext_profile_viewed;
    //   2. the widget returns early at zero views (ProfileViewsWidget.php:116).
    // Verified on a clean install: another member viewing the profile still left
    // view_count() at 0 with analytics off. Skipped rather than deleted so the
    // missing fixture stays visible instead of reading as a product failure.
    test.fixme(true, 'Needs the analytics feature enabled AND a seeded profile view.');

    test('widget renders on own profile', async ({ authenticatedPage: page }) => {
        const user = process.env.BN_TEST_USER ?? 'varundubey';
        await page.goto(urls.member(user));
        await expect(page.locator(sel.profileViewsWidget)).toBeVisible();
    });
});
