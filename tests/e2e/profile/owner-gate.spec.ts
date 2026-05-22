import { test, expect } from '../_fixtures/auth.fixture';
import { urls } from '../_fixtures/selectors';

/**
 * Regression spec for A1 — the owner action bar (Edit Profile / Edit Avatar
 * / Edit Cover) must NOT render on other members' profiles, even for admin
 * viewers. The links unconditionally point at the viewer's own edit URL via
 * get_edit_profile_url(), so rendering them on someone else's profile leaks
 * unrelated UI.
 */
test.describe('profile / owner gate', () => {
    test('Edit Profile / Avatar / Cover do NOT render on a non-owner profile', async ({ authenticatedPage: page }) => {
        const otherMember = process.env.BN_TEST_OTHER_USER ?? 'member1';
        await page.goto(urls.member(otherMember));
        await expect(page.locator('.bn-pf-hero').first()).toBeVisible({ timeout: 5_000 });

        // The owner action bar partial.
        await expect(page.locator('.bn-profile-actions-bar')).toHaveCount(0);

        // The individual action buttons.
        await expect(page.locator('.bn-owner-action--edit_profile')).toHaveCount(0);
        await expect(page.locator('.bn-owner-action--edit_avatar')).toHaveCount(0);
        await expect(page.locator('.bn-owner-action--edit_cover')).toHaveCount(0);

        // Cover edit pencil (only owner gets this in templates/profile/view.php).
        await expect(page.locator('.bn-pf-cover__edit')).toHaveCount(0);
    });

    test('Edit Profile DOES render on own profile', async ({ authenticatedPage: page }) => {
        const user = process.env.BN_TEST_USER ?? 'varundubey';
        await page.goto(urls.member(user));
        await expect(page.locator('.bn-pf-hero').first()).toBeVisible({ timeout: 5_000 });
        await expect(page.locator('.bn-profile-actions-bar')).toBeVisible();
        await expect(page.locator('.bn-owner-action--edit_profile')).toBeVisible();
    });
});
