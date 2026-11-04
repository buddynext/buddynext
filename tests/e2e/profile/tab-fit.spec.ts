import { test, expect, type Page } from '@playwright/test';
import { loginAs } from '../_fixtures/actor';
import { userId } from '../_fixtures/wp';
import { bnApi } from '../_fixtures/spaces-rest';

/**
 * Fit or fold (shell/extras.js) — show what fits, put only the rest under "More".
 *
 *   J-996 profile tab bar — every tab shows while it fits (no More on a wide
 *         screen); on a phone the tabs that do not fit fold into More, the
 *         current tab never folds, and a folded tab opens from the menu.
 *   J-997 own-profile actions — Share profile / Share to feed sit in the row
 *         while they fit; More appears only when they do not.
 *
 * Owner rule (2026-11-04): fold by space, never by a count. GitHub #221.
 */

const A_LOGIN = process.env.BN_TEST_USER ?? 'varundubey';
const memberUrl = (login: string, tab = '') => `/members/${login}/${tab ? `${tab}/` : ''}`;
const BAR = '.bn-navgroup__bar';
const TAB = `${BAR} .bn-tabs > a.bn-tab`;
const MORE = `${BAR} .bn-fit-more`;
const MENU_ITEM = `${BAR} .bn-fit-menu a`;

/** Ids of the profile tabs the route resolves for this viewer. */
async function navTabIds(page: Page, uid: number): Promise<string[]> {
    const r = await bnApi(page, 'GET', `/users/${uid}/profile`);
    expect(r.status, `GET /users/${uid}/profile`).toBe(200);
    const nav = ((r.data ?? {}) as { nav?: { tabs?: Array<{ id?: string }> } }).nav ?? {};
    return (nav.tabs ?? []).map((t) => String(t.id ?? ''));
}

/** Last path segment of each tab link (its slug), split by visible vs folded. */
async function barState(page: Page): Promise<{ shown: string[]; folded: string[]; more: boolean; current: string }> {
    return page.evaluate(([tabSel, moreSel, itemSel]) => {
        const slug = (href: string) => new URL(href, location.href).pathname.replace(/\/+$/, '').split('/').pop() || '';
        const tabs = [...document.querySelectorAll<HTMLAnchorElement>(tabSel)];
        const cur = tabs.find((t) => t.getAttribute('aria-current') === 'page' || t.getAttribute('aria-selected') === 'true');
        return {
            shown: tabs.filter((t) => !t.hidden).map((t) => slug(t.href)),
            folded: [...document.querySelectorAll<HTMLAnchorElement>(itemSel)].map((a) => slug(a.href)),
            more: !(document.querySelector(moreSel) as HTMLElement | null)?.hidden,
            current: cur ? slug(cur.href) : '',
        };
    }, [TAB, MORE, MENU_ITEM]);
}

test.describe('Fit or fold: tab bar and profile actions', () => {
    test('J-996 the profile tab bar folds only what does not fit, and never the current tab', async ({ page }) => {
        await loginAs(page, A_LOGIN);
        const uid = await userId(A_LOGIN);

        // Wide: everything fits, so there is no More at all.
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto(memberUrl(A_LOGIN));
        await expect(page.locator(BAR)).toBeVisible();
        const wide = await barState(page);
        expect(wide.more, 'no More on a screen wide enough for every tab').toBe(false);
        expect(wide.folded, 'nothing folded on a wide screen').toEqual([]);

        // EFFECT: the bar shows exactly the top-level tabs the route resolves.
        const routeTabs = await navTabIds(page, uid);
        expect(routeTabs.length, 'the route resolves several tabs').toBeGreaterThan(3);

        // Phone: what does not fit folds; shown + folded is still every tab, in order.
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(memberUrl(A_LOGIN));
        const narrow = await barState(page);
        expect(narrow.more, 'More appears once the tabs no longer fit').toBe(true);
        expect(narrow.folded.length, 'some tabs fold on a phone').toBeGreaterThan(0);
        expect(narrow.shown.length, 'the tabs that fit stay in the bar').toBeGreaterThan(1);
        expect(
            await page.evaluate(() => document.documentElement.scrollWidth > innerWidth),
            'the page does not scroll sideways'
        ).toBe(false);

        // The current tab never folds: open the last folded tab directly.
        const target = narrow.folded[narrow.folded.length - 1];
        await page.goto(memberUrl(A_LOGIN, target));
        const onTarget = await barState(page);
        expect(onTarget.current, 'the current tab is marked').toBe(target);
        expect(onTarget.shown, 'the current tab stays in the bar').toContain(target);
        expect(onTarget.folded, 'the current tab is not in the menu').not.toContain(target);

        // A folded tab opens from the menu; Escape closes it and returns focus.
        await page.goto(memberUrl(A_LOGIN));
        const btn = page.locator(`${MORE} button`);
        await btn.click();
        await expect(btn).toHaveAttribute('aria-expanded', 'true');
        await page.keyboard.press('Escape');
        await expect(btn).toHaveAttribute('aria-expanded', 'false');
        await expect(btn).toBeFocused();
        await btn.click();
        const first = page.locator(MENU_ITEM).first();
        const firstSlug = (await barState(page)).folded[0];
        await Promise.all([page.waitForURL(new RegExp(`/${firstSlug}/?$`)), first.click()]);
        expect((await barState(page)).current, 'the menu link opened its tab').toBe(firstSlug);
    });

    test('J-997 own profile: Share sits in the row while it fits, More only when it does not', async ({ page }) => {
        await loginAs(page, A_LOGIN);
        const uid = await userId(A_LOGIN);
        const row = '.bn-pf-actions[data-bn-fit]';

        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto(memberUrl(A_LOGIN));
        await expect(page.locator(`${row} [data-bn-fit-item="share"]`)).toBeVisible();
        await expect(page.locator(`${row} [data-bn-fit-item="mention"]`)).toBeVisible();
        await expect(page.locator(`${row} [data-bn-fit-more]`), 'no More holding only the share actions').toBeHidden();

        // EFFECT: Share to feed composes a post mentioning this member.
        const r = await bnApi(page, 'GET', `/users/${uid}/profile`);
        expect(r.status).toBe(200);
        const handle = String(((r.data ?? {}) as { username?: string; user_login?: string; slug?: string }).username ?? '');
        const href = (await page.locator(`${row} [data-bn-fit-item="mention"]`).getAttribute('href')) ?? '';
        expect(href, 'Share to feed opens the composer with a mention').toContain('mention=');
        if (handle) expect(href).toContain(encodeURIComponent(handle));

        // Very narrow: the row folds from the end, the copies show in More.
        await page.setViewportSize({ width: 320, height: 800 });
        await page.goto(memberUrl(A_LOGIN));
        await expect(page.locator(`${row} [data-bn-fit-more]`), 'More appears once Share no longer fits').toBeVisible();
        await expect(page.locator(`${row} [data-bn-fit-copy="mention"]`)).toBeAttached();
        expect(
            await page.locator(`${row} [data-bn-fit-copy="mention"]`).evaluate((el) => (el as HTMLElement).hidden),
            'the folded Share to feed is offered in More'
        ).toBe(false);
        expect(
            await page.evaluate(() => document.documentElement.scrollWidth > innerWidth),
            'the page does not scroll sideways'
        ).toBe(false);
    });
});
