import { test, expect } from '@playwright/test';

import { loginAs } from '../_fixtures/actor';

/**
 * J-816 setup wizard step rail stays on one row.
 *
 * Covers: cap-onboard-a-new-member-with-a-wizard (admin side only - the site
 * setup wizard at admin page buddynext-setup named in that row's How; the
 * member-facing /me/onboarding flow itself is not walked here)
 * Roles: admin
 *
 * Seven labelled steps did not fit the 720px wizard card, so "Done" wrapped onto
 * a second line by itself at every desktop width, and the rail took 3-4 rows on a
 * phone. On every step, at every project viewport, the rail is a single row and
 * names the current step; the other steps keep their names for screen readers.
 */

const ADMIN = process.env.BN_TEST_USER ?? 'varundubey';
const STEPS = ['branding', 'registration', 'profile_fields', 'spaces', 'pages', 'addons'];

test('J-816 the wizard step rail is one row and names the current step', async ({ page }) => {
    await loginAs(page, ADMIN);

    for (const step of STEPS) {
        await page.goto(`/wp-admin/admin.php?page=buddynext-setup&step=${step}`);
        const rail = page.locator('.bn-wizard__steps');
        await expect(rail, `rail on ${step}`).toBeVisible();

        const rows = await rail.locator('.bn-wizard__step').evaluateAll((els) => new Set(els.map((e) => Math.round(e.getBoundingClientRect().top))).size);
        expect(rows, `rail on ${step} is one row`).toBe(1);

        const current = rail.locator('[aria-current="step"] .bn-wizard__step-name');
        await expect(current, `current step named on ${step}`).toBeVisible();

        const hiddenNames = await rail.locator('.bn-wizard__step:not([aria-current]) .bn-wizard__step-name').evaluateAll((els) => els.map((e) => (e.textContent ?? '').trim()).filter(Boolean).length);
        expect(hiddenNames, 'other steps keep their names for screen readers').toBeGreaterThan(0);
    }
});
