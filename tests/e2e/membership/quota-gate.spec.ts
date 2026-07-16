import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * J-74 profile-field quota gate — the actor matrix a green admin run hides.
 *
 * Written from two promises: a PLAN-LIMITED member "can fill exactly what the
 * pricing page says, and when I hit the wall I'm told which field and why —
 * not silently dropped"; the OWNER/admin "is never gated by a plan I set for
 * my members." Admins are exempt from every entitlement gate, so a journey run
 * only as admin proves nothing about quotas — this runs BOTH actors.
 *
 * Exists because the quota gate shipped broken behind admin-only checks: silent
 * 422s, a double-grant that halved the plan, a checkbox that ate a slot. This
 * asserts the user-visible EFFECT: the plan-limit message appears on real
 * controls for the limited member and NEVER for the admin.
 *
 * Actor control is direct (?autologin=<login> mu-plugin), not the shared
 * authenticatedPage fixture, because the whole point is who is logged in.
 */
const EDIT = (login: string) => `/members/${login}/edit/`;
const PLAN_MSG = /plan lets you fill in \d+ optional profile field/i;

// Flat optional text fields on the edit form. 8 > any sane plan limit, so a
// limited member is guaranteed to hit the wall regardless of what they had
// filled before — the assertion never depends on a clean baseline.
const FIELDS: Array<[string, string]> = [
    ['bio', 'quota bio'],
    ['location', 'quota loc'],
    ['website', 'https://quota.example.com'],
    ['pronouns', 'they/them'],
    ['headline', 'quota headline'],
    ['birth_date', '1990-01-01'],
    ['musical_journey', 'quota journey'],
    ['probe_birthday', '1991-02-02'],
];

async function login(page: Page, login: string): Promise<void> {
    await page.goto(`/?autologin=${login}`, { waitUntil: 'domcontentloaded' });
}

async function setOptionalFields(page: Page, value: string): Promise<number> {
    let touched = 0;
    for (const [name] of FIELDS) {
        const el = page.locator(`[name="${name}"]`).first();
        if (await el.count() && await el.isVisible().catch(() => false)) {
            await el.fill(value);
            touched++;
        }
    }
    return touched;
}

async function fillManyOptionalFields(page: Page): Promise<number> {
    let filled = 0;
    for (const [name, value] of FIELDS) {
        const el = page.locator(`[name="${name}"]`).first();
        if (await el.count() && await el.isVisible().catch(() => false)) {
            await el.fill(value);
            filled++;
        }
    }
    return filled;
}

const saveProfile = (page: Page): Promise<void> =>
    page.getByRole('button', { name: /save changes/i }).first().click();

test.describe('membership / profile-field quota gate', () => {
    test('J-74a plan-limited member hits the wall with a named, on-screen message', async ({ page }, testInfo) => {
        const limited = process.env.BN_QUOTA_USER ?? 'bugfix_quota';
        await login(page, limited);
        await page.goto(EDIT(limited));

        if (!(await page.locator('[name="bio"], [name="location"]').first().count())) {
            testInfo.skip(true, 'Profile edit form / optional fields not exposed for this user.');
            return;
        }

        // Reset the optional fields to empty first, so the fill below genuinely
        // GROWS the filled set — clearing is always allowed, and it is the only
        // way to make the quota wall reproducible regardless of prior state.
        await setOptionalFields(page, '');
        await saveProfile(page);
        await page.waitForTimeout(1500);
        await page.goto(EDIT(limited));

        const filled = await fillManyOptionalFields(page);
        expect(filled, 'need several optional fields to exceed any plan limit').toBeGreaterThanOrEqual(6);
        await saveProfile(page);

        // The gate must SHOW itself: at least one control carries the plan-limit
        // message on screen (the honest-feedback the silent-422 bug violated).
        const planErrors = page.locator('.bn-ep-field-error, .bn-ep-injected-error').filter({ hasText: PLAN_MSG });
        await expect(planErrors.first()).toBeVisible({ timeout: 10_000 });

        // Idempotent: re-saving without adding a new field must NOT raise a fresh
        // wall for fields already filled (editing a filled unit is free — the
        // re-consume bug). Save again; the error count must not grow.
        const before = await planErrors.count();
        await saveProfile(page);
        await page.waitForTimeout(1500);
        expect(await planErrors.count()).toBeLessThanOrEqual(before);

        // Leave the member as found — clear the fields this test filled.
        await page.goto(EDIT(limited));
        await setOptionalFields(page, '');
        await saveProfile(page);
    });

    test('J-74b admin is exempt — the same fill saves with no quota message', async ({ page }, testInfo) => {
        // Admin edits their OWN profile via autologin=1 (user ID 1 = admin).
        await login(page, '1');
        const meEdit = await page.evaluate(() => {
            const link = document.querySelector('a[href*="/members/"][href$="/edit/"]') as HTMLAnchorElement | null;
            if (link) return new URL(link.href).pathname;
            const prof = document.querySelector('a[href*="/members/"]') as HTMLAnchorElement | null;
            return prof ? new URL(prof.href).pathname.replace(/\/$/, '') + '/edit/' : null;
        });
        if (!meEdit) {
            testInfo.skip(true, 'Could not resolve admin profile edit URL.');
            return;
        }
        await page.goto(meEdit);
        if (!(await page.locator('[name="bio"], [name="location"]').first().count())) {
            testInfo.skip(true, 'Admin profile edit form not exposed.');
            return;
        }

        await fillManyOptionalFields(page);
        await page.getByRole('button', { name: /save changes/i }).first().click();

        // Admin is exempt: the plan-limit message must NOT appear on any control.
        const planErrors = page.locator('.bn-ep-field-error, .bn-ep-injected-error').filter({ hasText: PLAN_MSG });
        await page.waitForTimeout(2000);
        expect(await planErrors.count()).toBe(0);
    });
});
