import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-65-mobile-bottom-nav.
 *
 * The 5-item bottom tab bar appears below 768px. Scoped to mobile + ipad
 * projects (we don't bother running it on desktop since the rail is the
 * primary nav there).
 */
test.describe('shell / mobile bottom-nav', () => {
    test('renders .bn-mobile-nav with 5 items on small viewports', async ({ authenticatedPage: page }, testInfo) => {
        test.skip(testInfo.project.name === 'desktop', 'Desktop uses .bn-app__rail, not the bottom-bar nav.');
        await page.goto(urls.feed);
        await expect(page.locator(sel.app)).toBeVisible();

        const viewport = page.viewportSize();
        if (viewport && viewport.width >= 768) {
            // iPad landscape is wider than the breakpoint  -  the spec
            // still passes because the rail takes over instead.
            const railVisible = await page.locator(sel.rail).isVisible().catch(() => false);
            expect(railVisible).toBeTruthy();
            return;
        }

        const mobileNav = page.locator(sel.mobileNav);
        await expect(mobileNav).toBeVisible();

        const items = await mobileNav.locator(sel.mobileNavItem).count();
        expect(items).toBe(5);
    });
});
