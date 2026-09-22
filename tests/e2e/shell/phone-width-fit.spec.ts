import { test, expect } from '@playwright/test';

import { loginAs } from '../_fixtures/actor';

/**
 * J-817 pages fit a phone screen.
 *
 * Covers: none - same front-end-shell gap as shell/mobile-nav.spec.ts (no
 * CAPABILITIES.md row states the app shell itself works on a phone). Also
 * covers J-819 later in this file (same gap).
 * Roles: member
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

/**
 * J-819 the composer submit row fits a phone screen with a restored draft.
 *
 * The submit row holds the character count, privacy chip and Post, and fits on one
 * line — until a restored draft adds the "Draft restored" notice + discard button,
 * which pushed Post off the right edge and gave the page sideways scroll
 * (card 10320487920). At phone width the draft cluster must drop to its own line so
 * Post stays fully on-screen and the page does not scroll sideways.
 *
 * Runs on the mobile project only (390px).
 */
test('J-819 composer submit row fits phone width with a restored draft', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile', 'Phone-width journey.');
    await loginAs(page, MEMBER);
    await page.goto('/activity/');
    await page.waitForLoadState('load');

    const result = await page.evaluate(() => {
        const draft = document.querySelector('.bn-composer__draft') as HTMLElement | null;
        const status = document.querySelector('.bn-composer__draft-status');
        const discard = document.querySelector('.bn-composer__draft-discard') as HTMLElement | null;
        const post = document.querySelector('.bn-composer__submit') as HTMLElement | null;
        if (!draft || !post) {
            return { ok: false as const };
        }
        // Force the "Draft restored" state the composer shows after leaving and
        // returning to a started post (Interactivity normally toggles `hidden`).
        draft.hidden = false;
        draft.removeAttribute('hidden');
        if (status) {
            status.textContent = 'Draft restored';
        }
        if (discard) {
            discard.hidden = false;
            discard.removeAttribute('hidden');
        }
        void draft.offsetWidth;
        const vw = window.innerWidth;
        const postRect = post.getBoundingClientRect();
        const draftRect = draft.getBoundingClientRect();
        return {
            ok: true as const,
            overflow: document.documentElement.scrollWidth - vw,
            postRight: Math.round(postRect.right),
            vw,
            postFullyVisible: postRect.right <= vw + 1 && postRect.left >= -1,
            draftReadable: (status?.textContent ?? '').trim().length > 0,
            // The fix drops the draft cluster to its OWN line above the controls at
            // phone width; measuring this (rather than a theme-specific overflow) makes
            // the guard hold on any theme, including ones whose composer would not have
            // overflowed. Without the fix the draft sits inline with Post (same line).
            draftOnItsOwnLine: Math.round(draftRect.bottom) <= Math.round(postRect.top) + 1,
        };
    });

    expect(result.ok, 'the activity composer is present for a signed-in member').toBeTruthy();
    if (!result.ok) {
        return;
    }
    // Outcome: with the draft showing, the page still does not scroll sideways and
    // Post is fully on-screen.
    expect(
        result.overflow,
        `page must not scroll sideways with a restored draft (Post right=${result.postRight}, viewport=${result.vw})`
    ).toBeLessThanOrEqual(1);
    expect(result.postFullyVisible, 'the Post button is fully inside the viewport').toBeTruthy();
    expect(result.draftReadable, 'the Draft restored notice is still readable').toBeTruthy();
    // Mechanism (theme-independent): the draft cluster is on its own line above the
    // controls, which is what keeps Post on-screen however wide the theme's composer.
    expect(result.draftOnItsOwnLine, 'the draft cluster drops to its own line at phone width').toBeTruthy();
});
