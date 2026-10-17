import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';
import { bnApi } from '../_fixtures/spaces-rest';

/**
 * J-29-profile-view-own.
 *
 * Covers: cap-give-members-a-profile-with-custom-fields, cap-set-your-own-display-name-avatar-cover-photo-and-headline
 * Roles: admin
 * Note: loose fit - a smoke check that the profile hero/stats/tabs render at
 * all, not specifically the custom-fields promise; no closer row exists. Only
 * the admin owner's own profile is walked (authenticatedPage fixture).
 */
test.describe('profile / view', () => {
    test('own profile renders hero + stats + tabs', async ({ authenticatedPage: page }) => {
        const user = process.env.BN_TEST_USER ?? 'varundubey';
        await page.goto(urls.member(user));
        await expect(page.locator(sel.app)).toBeVisible();

        await expect(page.locator(sel.profileHero).first()).toBeVisible({ timeout: 5_000 });
        // Stats and tabs are best-effort  -  accept missing tabs (some
        // builds use a single column on mobile).
        const statsVisible = await page.locator(sel.profileStats).first().isVisible().catch(() => false);
        const tabsVisible = await page.locator(sel.profileTab).first().isVisible().catch(() => false);
        expect(statsVisible || tabsVisible).toBeTruthy();
        // EFFECT: the hero shows the member the profile route describes.
        const rest = await bnApi(page, 'GET', '/me/profile');
        expect(rest.status, 'GET /me/profile').toBe(200);
        const d = (rest.data ?? {}) as { user_id?: number; display_name?: string; user?: { display_name?: string } };
        const name = d.display_name ?? d.user?.display_name ?? '';
        expect(name, 'profile route names the member').not.toBe('');
        await expect(page.locator(sel.profileHero).first()).toContainText(name);

        // EFFECT: the hero's follower / following pills equal the totals of the list
        // routes that feed the Network tab (a hidden pill only when the total is 0).
        const uid = Number(d.user_id ?? 0);
        expect(uid, 'profile route carries the member id').toBeGreaterThan(0);
        for (const metric of ['followers', 'following']) {
            const list = await bnApi(page, 'GET', `/users/${uid}/${metric}?per_page=1`);
            expect(list.status, `GET /users/{id}/${metric}`).toBe(200);
            const total = Number(((list.data ?? {}) as { total?: number }).total ?? -1);
            const pill = page.locator(`.bn-nav-metric[href*="/${metric}/"] .bn-nav-metric__value`).first();
            if (await pill.count()) {
                const shown = parseInt((await pill.innerText()).replace(/[^\d-]/g, ''), 10) || 0;
                expect(shown, `the ${metric} pill shows the route total`).toBe(total);
            } else {
                expect(total, `the ${metric} pill is hidden only when the route total is 0`).toBe(0);
            }
        }
    });
});
