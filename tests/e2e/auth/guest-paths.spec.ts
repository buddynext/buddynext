import { test, expect } from '@playwright/test';
import { wp } from '../_fixtures/wp';
import { urls } from '../_fixtures/selectors';

/**
 * J-01 / J-02 / J-03 what a logged-out visitor meets first.
 *
 * Written from the owner's promise: "A visitor can find the way in, sees the
 * public community the settings allow, and is asked to log in only where the
 * settings say so." Each test reads the site's own settings first and asserts
 * the ONE branch they demand, never either/or.
 *
 * Effect read-back: the guest REST answer agrees with what the page shows
 * (explore items, the space list), and personal pages bounce to login with a
 * redirect_to naming them.
 *
 * Covers: cap-register-and-log-in-without-wp-login, cap-run-an-activity-feed-members-post-to, cap-group-content-into-spaces
 * Roles: anon
 */

const option = async (name: string, fallback: string): Promise<string> => {
    const v = (await wp(['option', 'get', name]).catch(() => '')).trim();
    return '' === v ? fallback : v;
};

const loginRequired = async (hub: string): Promise<boolean> => {
    const raw = await wp(['option', 'get', 'buddynext_nav_overrides', '--format=json']).catch(() => '');
    try {
        return Boolean((JSON.parse(raw || '{}')[hub] ?? {}).login_required);
    } catch {
        return false;
    }
};

test.describe('auth / guest paths', () => {
    test('J-01 a guest on the home page finds the log-in link and reaches the login form', async ({ page }) => {
        await page.goto('/');
        const links = page.locator('a[href*="/login/"]:not([href*="/signup"]):not([href*="/reset"])');
        expect(await links.count(), 'the home page offers a log-in link').toBeGreaterThan(0);

        // Phones often tuck the header link behind a menu: click a visible one,
        // otherwise follow the link the page offers.
        const visible = links.filter({ visible: true }).first();
        if (await visible.count()) {
            await visible.click();
        } else {
            await page.goto((await links.first().getAttribute('href')) ?? urls.auth);
        }

        await expect(page.locator('#bn-login-user')).toBeVisible();
        // EFFECT: the REST layer agrees the visitor is still a guest.
        expect((await page.request.get('/wp-json/buddynext/v1/me/profile')).status()).toBe(401);
    });

    test('J-02 a guest opening the feed lands where the public-explore setting says', async ({ page, baseURL }) => {
        const privateSite = '1' === (await option('buddynext_private_community', '0'));
        const publicExplore = '0' !== (await option('buddynext_public_explore', '1'));
        const feedGated = await loginRequired('feed');

        await page.goto(urls.feed);

        if (privateSite || !publicExplore || feedGated) {
            await expect(page).toHaveURL(/\/login\/|wp-login\.php/);
            await expect(page.locator('#bn-login-user')).toBeVisible();
        } else {
            await expect(page).toHaveURL(/\/activity\/explore\/?$/);
            // EFFECT: the guest REST feed has content and the page renders it.
            const api = await page.request.get('/wp-json/buddynext/v1/feed/explore?per_page=5');
            expect(api.status()).toBe(200);
            const items = ((await api.json()) as { items?: unknown[] }).items ?? [];
            expect(items.length, 'the public explore feed has something to show').toBeGreaterThan(0);
            await expect(page.locator('.ec-card, .bn-post-card, article[data-kind]').first()).toBeVisible();
        }

        // Personal pages always ask for login, and come back afterwards.
        await page.goto('/me/bookmarks/');
        await expect(page).toHaveURL(/redirect_to=[^&]*me%2Fbookmarks/);
        expect(new URL(page.url()).origin).toBe(new URL(baseURL ?? page.url()).origin);
    });

    test('J-03 a guest on the spaces directory sees what the visibility settings allow', async ({ page }) => {
        const privateSite = '1' === (await option('buddynext_private_community', '0'));
        const spacesGated = await loginRequired('spaces');

        const res = await page.goto(urls.spaces);

        if (privateSite || spacesGated) {
            await expect(page).toHaveURL(/\/login\/|wp-login\.php/);
            expect(await page.locator('.bn-sd-card').count(), 'no space cards leak to a guest').toBe(0);
            expect((await res?.text()) ?? '').not.toContain('bn-sd-card');
            return;
        }

        // EFFECT: the guest REST list and the page agree on at least one space.
        const api = await page.request.get('/wp-json/buddynext/v1/spaces?per_page=5');
        expect(api.status()).toBe(200);
        const spaces = (await api.json()) as Array<{ name: string; type: string }>;
        const listed = spaces.filter((s) => 'secret' !== s.type);
        expect(listed.length, 'seeded spaces are listed to guests').toBeGreaterThan(0);
        expect(spaces.some((s) => 'secret' === s.type), 'secret spaces never reach a guest').toBe(false);

        const card = page.locator('.bn-sd-card').first();
        await expect(card).toBeVisible();
        await expect(page.locator('.bn-sd-card', { hasText: listed[0].name }).first()).toBeAttached();
    });
});
