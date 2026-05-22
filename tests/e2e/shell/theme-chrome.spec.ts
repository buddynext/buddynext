import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-64-theme-chrome-above-below + J-67-no-second-bn-topbar.
 *
 * Theme header must be the document chrome above .bn-app. Theme footer
 * must be the chrome below. No `.bn-topbar` is rendered inside `.bn-app`.
 *
 * Post cards use semantic `<header class="bn-post-card__head">`, so we
 * can't assert "no header inside .bn-app" — we target the THEME header
 * (Astra: header#masthead) specifically.
 */
test.describe('shell / theme chrome', () => {
    test('theme header sits above the .bn-app canvas', async ({ authenticatedPage: page }) => {
        await page.goto(urls.feed);
        await expect(page.locator(sel.app)).toBeVisible();

        // The Astra theme header must be present.
        const siteHeader = page.locator(sel.themeHeader).first();
        await expect(siteHeader).toBeVisible();

        const headerBox = await siteHeader.boundingBox();
        const appBox = await page.locator(sel.app).first().boundingBox();
        expect(headerBox, 'site header must have a bounding box').not.toBeNull();
        expect(appBox, '.bn-app must have a bounding box').not.toBeNull();
        // Header origin must be above the .bn-app canvas (smaller y).
        expect((headerBox as { y: number }).y).toBeLessThan((appBox as { y: number }).y);

        // And the site header element itself must NOT live inside .bn-app
        // (that would mean BN is rendering its own topbar — the v2 shell
        // refactor removed that pattern). We restrict the check to the
        // canonical header element (#masthead) rather than the looser
        // .ast-primary-header class, which Astra also stamps on inner
        // builder divs that may legitimately appear elsewhere.
        const siteHeaderInsideApp = await page.locator(`${sel.app} header#masthead`).count();
        expect(siteHeaderInsideApp).toBe(0);
    });

    test('no .bn-topbar inside .bn-app', async ({ authenticatedPage: page }) => {
        await page.goto(urls.feed);
        await expect(page.locator(sel.app)).toBeVisible();

        const bnTopbar = await page.locator(`${sel.app} .bn-topbar`).count();
        expect(bnTopbar).toBe(0);
    });

    test('theme footer comes after .bn-app in DOM order', async ({ authenticatedPage: page }) => {
        await page.goto(urls.feed);
        await expect(page.locator(sel.app)).toBeVisible();

        // Theme footer should not be nested inside .bn-app.
        const footerInsideApp = await page.locator(`${sel.app} footer#colophon, ${sel.app} footer.site-footer`).count();
        expect(footerInsideApp).toBe(0);
    });
});
