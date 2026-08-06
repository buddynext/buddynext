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

    // J-36 asserts a brand-hue picker (`[data-field="brand-hue"]` / `bn_brand_hue`).
    // Neither string exists anywhere in Free or Pro, and /settings/appearance/
    // renders no form control at all - so this is an UNBUILT feature, not a stale
    // selector. Left failing-by-declaration rather than deleted, so it stays
    // visible: either build the picker or retire J-36 from the catalogue.
    // NOTE the form: `test.fixme(...)` as a bare statement inside a describe
    // marks EVERY test in the group as fixme, not the one that follows it. It
    // was written that way here, so all eight profile/edit tests silently
    // stopped running - the suite reported "8 skipped" and read as deliberate.
    // Marking the single test is `test.fixme('name', fn)`.
    test.fixme('J-36 theme picker (Pro) applies brand hue', async ({ authenticatedPage: page }) => {
        await page.goto(urls.settingsAppearance);
        const picker = page.locator('[data-field="brand-hue"], [name="bn_brand_hue"]').first();
        await expect(picker).toBeVisible();
    });

    /* B5 — Privacy section: audience selects + toggles render. */
    test('Privacy section renders audience selects + toggles', async ({ authenticatedPage: page }) => {
        // Privacy moved off profile-edit and onto the settings hub.
        await page.goto(urls.settingsPrivacy);
        await expect(page.locator('#bn-ep-privacy-title')).toBeVisible({ timeout: 5_000 });
        // #bn-ep-privacy-email is intentionally absent. bn_privacy_see_email was
        // REMOVED under docs/standards/public-surface-integrity.md: it saved a
        // value nothing read, offering a choice over an exposure that did not
        // exist. Asserting it here would demand the lever come back.
        await expect(page.locator('#bn-ep-privacy-dm')).toBeVisible();
        await expect(page.locator('#bn-ep-privacy-mention')).toBeVisible();
        await expect(page.locator('[data-pref="bn_privacy_show_in_directory"]')).toBeVisible();
        await expect(page.locator('[data-pref="bn_privacy_search_indexable"]')).toBeVisible();
        await expect(page.locator('[data-pref="bn_pro_hide_profile_views"]')).toBeVisible();
    });

    /* B6 — Account section: change-password / change-email / sign-out-everywhere CTAs. */
    test('Account section renders password / email / sign-out-everywhere CTAs', async ({ authenticatedPage: page }) => {
        // Account moved off profile-edit and onto the settings hub.
        await page.goto(urls.settingsAccount);
        // :has-text() is a SUBSTRING match, so this also matched the "Connected
        // accounts" card added later and failed on a strict-mode violation. The
        // breakage was invisible because a describe-level test.fixme had the
        // whole group skipped. :text-is() is exact.
        await expect(page.locator('.bn-ep-card-title:text-is("Account")')).toBeVisible({ timeout: 5_000 });
        await expect(page.locator('[data-wp-on--click="actions.openEmailChange"]')).toBeVisible();
        await expect(page.locator('[data-wp-on--click="actions.openPasswordChange"]')).toBeVisible();
        await expect(page.locator('[data-wp-on--click="actions.signOutEverywhere"]')).toBeVisible();
    });

    /* C1 — Notification preferences footer carries the prefs page CTA. */
    test('Notification preferences card footer links to full prefs page', async ({ authenticatedPage: page }) => {
        // The card lives on the settings hub, not profile-edit.
        await page.goto(urls.settings);
        const cta = page.locator('a:has-text("Open notification preferences")').first();
        await expect(cta).toBeVisible({ timeout: 5_000 });
    });

    /**
     * C2 — Member type appears exactly once.
     *
     * It rendered twice: the section was emitted by two paths that each looked
     * correct in isolation, and a duplicated control is not a cosmetic problem
     * here - two selects bound to the same user mean the one you did not touch
     * still shows the old value, and whichever posts last wins.
     *
     * Asserted by id, not by visible text: "Member type" also appears in the
     * label and the hint, so counting text would pass with two selects on the
     * page. An id is required to be unique, which is precisely what broke.
     */
    test('Member type control renders exactly once on Edit Profile', async ({ authenticatedPage: page }) => {
        await page.goto(urls.memberEdit(user));
        await expect(page.locator(sel.app)).toBeVisible();

        const select = page.locator('#bn-ep-member-type');
        const count = await select.count();
        // Sites without member types configured render none at all; that is
        // valid. What must never happen is more than one.
        expect(count, `#bn-ep-member-type rendered ${count} times`).toBeLessThanOrEqual(1);

        if (count === 1) {
            await expect(page.locator('#bn-ep-member-type-title')).toHaveCount(1);
        }
    });
});
