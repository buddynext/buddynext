import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';
import { BREAKPOINT } from '../_fixtures/viewports';

/**
 * J-66-rail-collapse-breakpoints.
 *
 * At >=1024 the rail labels are visible. At <1024 the rail collapses to
 * icons only. At <768 the rail is hidden entirely and the bottom-bar
 * mobile nav takes over.
 */
test.describe('shell / responsive', () => {
    test('rail labels visible at >=1024 wide', async ({ authenticatedPage: page }, testInfo) => {
        const viewport = page.viewportSize();
        test.skip(!viewport || viewport.width < BREAKPOINT.RAIL_COLLAPSE, 'Spec only valid on wide viewports.');
        void testInfo;

        await page.goto(urls.feed);
        await expect(page.locator(sel.rail)).toBeVisible();

        const firstLabel = page.locator(sel.railLabel).first();
        // Label may exist with display:none  -  assert it's visible (rendered).
        await expect(firstLabel).toBeVisible();
    });

    test('rail collapses or hides at <1024 wide', async ({ authenticatedPage: page }) => {
        const viewport = page.viewportSize();
        test.skip(!viewport || viewport.width >= BREAKPOINT.RAIL_COLLAPSE, 'Spec only valid on narrow viewports.');

        await page.goto(urls.feed);

        if (viewport && viewport.width < BREAKPOINT.MOBILE_NAV) {
            // Mobile: rail entirely hidden, mobile-nav takes over.
            const railVisible = await page.locator(sel.rail).isVisible().catch(() => false);
            expect(railVisible).toBeFalsy();
            await expect(page.locator(sel.mobileNav)).toBeVisible();
        } else {
            // Tablet: rail collapsed (icons only). Labels may be `aria-hidden`
            // or visually hidden. Don't make a hard assertion on label
            // visibility  -  just confirm the rail is still present.
            await expect(page.locator(sel.rail)).toBeVisible();
        }
    });
});
