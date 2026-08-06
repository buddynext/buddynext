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

    /**
     * The create "+" sits in the centre slot. It once did not: the middle index
     * was computed over ALL configured slots rather than the VISIBLE ones, so
     * any hidden slot (capability, toggle, logged-out) shifted "+" off-centre
     * while the bar still rendered an odd number of items. Nothing noticed,
     * because item COUNT was the only thing asserted and the count was right.
     *
     * Both halves are checked, because either alone can pass while broken:
     * position in the rendered list catches the off-by-one, and geometry
     * catches a CSS order/flex change that leaves the DOM order intact.
     */
    test('the create "+" occupies the centre slot of the rendered bar', async ({ authenticatedPage: page }, testInfo) => {
        test.skip(testInfo.project.name === 'desktop', 'Desktop uses .bn-app__rail, not the bottom-bar nav.');
        await page.goto(urls.feed);
        await expect(page.locator(sel.app)).toBeVisible();

        const viewport = page.viewportSize();
        test.skip(!!viewport && viewport.width >= 768, 'Above the breakpoint the rail replaces the bottom bar.');

        const mobileNav = page.locator(sel.mobileNav);
        await expect(mobileNav).toBeVisible();

        const create = mobileNav.locator('.bn-mobile-nav__item--create');
        await expect(create, 'no create item rendered in the bottom bar').toHaveCount(1);

        // 1. Middle of the items actually rendered.
        const rendered = mobileNav.locator(sel.mobileNavItem);
        const total = await rendered.count();
        expect(total % 2, 'centre slot is only meaningful with an odd number of items').toBe(1);

        const classLists = await rendered.evaluateAll((els) =>
            els.map((el) => el.className)
        );
        const createIndex = classLists.findIndex((c) => c.includes('bn-mobile-nav__item--create'));
        expect(createIndex, `"+" is at slot ${createIndex} of ${total}, not the middle`).toBe(Math.floor(total / 2));

        // 2. And actually painted in the centre, not merely ordered there.
        const navBox = await mobileNav.boundingBox();
        const createBox = await create.boundingBox();
        expect(navBox && createBox).toBeTruthy();
        const navCentre = navBox!.x + navBox!.width / 2;
        const createCentre = createBox!.x + createBox!.width / 2;
        expect(
            Math.abs(navCentre - createCentre),
            `"+" centre is ${Math.round(Math.abs(navCentre - createCentre))}px from the bar centre`
        ).toBeLessThanOrEqual(8);
    });
});
