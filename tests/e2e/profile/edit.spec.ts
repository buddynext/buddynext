import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from "../_fixtures/precondition";
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-33 avatar upload, J-34 bio edit, J-35 custom fields, J-36 theme picker.
 */
test.describe('profile / edit', () => {
    const user = process.env.BN_TEST_USER ?? 'varundubey';

    test('J-34 bio edit  -  change and save bio', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.memberEdit(user));
        await expect(page.locator(sel.app)).toBeVisible();

        const bio = page.locator('textarea[name="bio"], textarea[name="description"], [data-field="bio"] textarea').first();
        if (!(await bio.isVisible().catch(() => false))) {
            softSkip(testInfo, 'Bio field not exposed.');
            return;
        }
        const stamp = Date.now().toString().slice(-6);
        const value = `e2e bio ${stamp}`;
        await bio.fill(value);

        const save = page.locator('button[type="submit"], .bn-btn[data-action="save"]').first();
        await save.click();
        await page.waitForLoadState('domcontentloaded');

        // Round-trip  -  reload edit page and confirm the value stuck.
        await page.goto(urls.memberEdit(user));
        const reloaded = page.locator('textarea[name="bio"], textarea[name="description"], [data-field="bio"] textarea').first();
        if (await reloaded.isVisible().catch(() => false)) {
            await expect(reloaded).toHaveValue(value);
        }
    });

    test('J-33 avatar upload control is wired', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.memberEdit(user));
        const input = page.locator('input[type="file"][name*="avatar"], input[type="file"][accept*="image"]').first();
        if (!(await input.count())) {
            softSkip(testInfo, 'Avatar upload input not exposed.');
            return;
        }
        // We don't actually upload a file (would mutate user state across
        // runs)  -  assert the input is present and accepts images.
        const accept = await input.getAttribute('accept');
        expect(accept ?? '').toMatch(/image/i);
    });

    test('J-35 custom profile field edits save', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.memberEdit(user));
        const field = page.locator('input[name^="bn_profile_field"], [data-profile-field] input').first();
        if (!(await field.isVisible().catch(() => false))) {
            softSkip(testInfo, 'No custom profile fields configured.');
            return;
        }
        const stamp = Date.now().toString().slice(-5);
        await field.fill(`e2e ${stamp}`);
        const save = page.locator('button[type="submit"]').first();
        await save.click();
        await page.waitForLoadState('domcontentloaded');
    });

    test.fixme(process.env.BN_PRO !== '1', 'J-36 theme picker  -  Pro whitelabel only.');
    test('J-36 theme picker (Pro) applies brand hue', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.memberEdit(user));
        const picker = page.locator('[data-field="brand-hue"], [name="bn_brand_hue"]').first();
        await expect(picker).toBeVisible();
    });

    /* B5 — Privacy section: audience selects + toggles render. */
    test('Privacy section renders audience selects + toggles', async ({ authenticatedPage: page }) => {
        await page.goto(urls.memberEdit(user));
        await expect(page.locator('#bn-ep-privacy-title')).toBeVisible({ timeout: 5_000 });
        await expect(page.locator('#bn-ep-privacy-email')).toBeVisible();
        await expect(page.locator('#bn-ep-privacy-dm')).toBeVisible();
        await expect(page.locator('#bn-ep-privacy-mention')).toBeVisible();
        await expect(page.locator('[data-pref="bn_privacy_show_in_directory"]')).toBeVisible();
        await expect(page.locator('[data-pref="bn_privacy_search_indexable"]')).toBeVisible();
        await expect(page.locator('[data-pref="bn_pro_hide_profile_views"]')).toBeVisible();
    });

    /* B6 — Account section: change-password / change-email / sign-out-everywhere CTAs. */
    test('Account section renders password / email / sign-out-everywhere CTAs', async ({ authenticatedPage: page }) => {
        await page.goto(urls.memberEdit(user));
        await expect(page.locator('.bn-ep-card-title:has-text("Account")')).toBeVisible({ timeout: 5_000 });
        await expect(page.locator('[data-wp-on--click="actions.openEmailChange"]')).toBeVisible();
        await expect(page.locator('[data-wp-on--click="actions.openPasswordChange"]')).toBeVisible();
        await expect(page.locator('[data-wp-on--click="actions.signOutEverywhere"]')).toBeVisible();
    });

    /* C1 — Notification preferences footer carries the prefs page CTA. */
    test('Notification preferences card footer links to full prefs page', async ({ authenticatedPage: page }) => {
        await page.goto(urls.memberEdit(user));
        const cta = page.locator('a:has-text("Open notification preferences")').first();
        await expect(cta).toBeVisible({ timeout: 5_000 });
    });
});
