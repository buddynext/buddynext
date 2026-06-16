import { test, expect } from '../_fixtures/auth.fixture';
import { urls } from '../_fixtures/selectors';

/**
 * Regression spec for A1 — the owner-only profile actions (Edit Profile link +
 * the cover-edit pencil) must NOT render on other members' profiles, even for
 * admin viewers, since the edit link unconditionally targets the viewer's own
 * edit URL via get_edit_profile_url() and would leak unrelated UI.
 *
 * v2: the action bar is `.bn-pf-actions` and renders on every profile, but with
 * different buttons — owner gets an "Edit profile" link (href -> /edit/), other
 * viewers get Follow / Connect / Message. The owner marker is therefore the
 * edit link inside the bar (`.bn-pf-actions a[href*="/edit/"]`) plus the cover
 * pencil (`.bn-pf-cover__edit`), not a dedicated `.bn-profile-actions-bar`.
 */
test.describe('profile / owner gate', () => {
    test('Edit Profile / cover pencil do NOT render on a non-owner profile', async ({ authenticatedPage: page }) => {
        const otherMember = process.env.BN_TEST_OTHER_USER ?? 'member1';
        await page.goto(urls.member(otherMember));
        await expect(page.locator('.bn-pf-hero').first()).toBeVisible({ timeout: 5_000 });

        // No owner Edit-profile link in the action bar on someone else's profile.
        await expect(page.locator('.bn-pf-actions a[href*="/edit/"]')).toHaveCount(0);

        // No cover-edit pencil (owner-only in templates/profile/view.php).
        await expect(page.locator('.bn-pf-cover__edit')).toHaveCount(0);
    });

    test('Edit Profile DOES render on own profile', async ({ authenticatedPage: page }) => {
        const user = process.env.BN_TEST_USER ?? 'varundubey';
        await page.goto(urls.member(user));
        await expect(page.locator('.bn-pf-hero').first()).toBeVisible({ timeout: 5_000 });
        await expect(page.locator('.bn-pf-actions').first()).toBeVisible();
        await expect(page.locator('.bn-pf-actions a[href*="/edit/"]').first()).toBeVisible();
    });
});
