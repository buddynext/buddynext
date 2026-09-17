import { test, expect } from '@playwright/test';

import { loginAs } from '../_fixtures/actor';

/**
 * J-817 pages fit a phone screen.
 *
 * At phone width no page scrolls sideways: the document is no wider than the
 * viewport. Covers the main BuddyNext hubs and a plain WordPress page, signed in,
 * so a header or shell element that overflows is caught whichever side (host
 * theme or BuddyNext) renders it. The failure message names the widest elements.
 *
 * Runs on the mobile project only (iPhone 14, 390px).
 */

const MEMBER = process.env.BN_TEST_USER ?? 'varundubey';
const PAGES = ['/activity/', '/activity/explore/', '/members/', '/spaces/', `/members/${MEMBER}/`, `/members/${MEMBER}/edit/`, '/'];

test('J-817 no page scrolls sideways at phone width', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile', 'Phone-width journey.');
    await loginAs(page, MEMBER);

    const failures: string[] = [];
    for (const path of PAGES) {
        await page.goto(path);
        await page.waitForLoadState('load');
        const result = await page.evaluate(() => {
            const vw = window.innerWidth;
            const wide: string[] = [];
            document.querySelectorAll('body *').forEach((el) => {
                const style = getComputedStyle(el);
                if (style.position === 'fixed' || style.visibility === 'hidden' || style.display === 'none') {
                    return;
                }
                const r = el.getBoundingClientRect();
                const parent = el.parentElement?.getBoundingClientRect();
                if (r.width > 0 && r.right > vw + 1 && (!parent || parent.right <= vw + 1)) {
                    wide.push(`${el.tagName.toLowerCase()}.${String((el as HTMLElement).className).trim().split(/\s+/).slice(0, 2).join('.')} right=${Math.round(r.right)}`);
                }
            });
            return { overflow: document.documentElement.scrollWidth - vw, wide: wide.slice(0, 4) };
        });
        if (result.overflow > 1) {
            failures.push(`${path}: ${result.overflow}px wider than the screen (${result.wide.join(' | ')})`);
        }
    }

    expect(failures, 'pages that scroll sideways').toEqual([]);

    // The iOS anti-zoom floor still holds on BuddyNext's own fields, and stays off
    // the host theme's controls (its header search keeps the theme's size).
    for (const path of ['/activity/', `/members/${MEMBER}/edit/`]) {
        await page.goto(path);
        const sizes = await page.evaluate(() => {
            const px = (el: Element) => parseFloat(getComputedStyle(el).fontSize);
            const ours = Array.from(document.querySelectorAll('.bn-app input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button]), .bn-app textarea, .bn-app select')).map(px);
            const theme = document.querySelector('.search-form input[name="s"]');
            return { smallestOurs: ours.length ? Math.min(...ours) : 16, count: ours.length, theme: theme ? px(theme) : null };
        });
        expect(sizes.count, `${path} has BuddyNext fields to check`).toBeGreaterThan(0);
        expect(sizes.smallestOurs, `${path}: BuddyNext fields at least 16px (no iOS zoom)`).toBeGreaterThanOrEqual(16);
        if (sizes.theme !== null) {
            expect(sizes.theme, `${path}: theme header search keeps the theme's size`).toBeLessThan(16);
        }
    }
});
