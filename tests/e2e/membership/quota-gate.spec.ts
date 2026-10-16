import { test, expect } from '@playwright/test';
import { settleOverlays, satisfyRequiredFields, userId, tablePrefix, dbCount } from '../_fixtures/wp';
import { bnApi } from '../_fixtures/spaces-rest';
import type { Page } from '@playwright/test';

/**
 * J-74 profile-field quota gate — the actor matrix a green admin run hides.
 *
 * Covers: none - no CAPABILITIES.md row names a plan-based ceiling on the
 * number of optional profile fields a member may fill. Distinct from "Gate
 * profile field groups behind a plan/tier?" (that is all-or-nothing group
 * locking; this is a numeric quota across fields that are not themselves
 * locked). Flagging as friction rather than overclaiming that row.
 * Roles: member, admin
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
    await settleOverlays(page);
    await satisfyRequiredFields(login);
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

/** The viewer's own stored profile values by field key, per GET /me/profile (the route the app reads). */
async function storedProfile(page: Page): Promise<Record<string, unknown>> {
    const r = await bnApi(page, 'GET', '/me/profile');
    expect(r.status, 'GET /me/profile').toBe(200);
    const fields = ((r.data as { fields?: Array<{ field_key: string; value_raw?: unknown }> }).fields ?? []);
    return Object.fromEntries(fields.map((f) => [f.field_key, f.value_raw]));
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
        // EFFECT: the wall is enforced in the data, not only announced: the member holds no more
        // non-empty optional values than the plan message says it allows.
        const allowed = Number(((await planErrors.first().innerText()).match(/fill in (\d+)/i) ?? [])[1] ?? NaN);
        expect(allowed, 'the plan message names the allowed count').toBeGreaterThan(0);
        const uid = await userId(limited);
        const p = await tablePrefix();
        const stored = await dbCount(
            `SELECT COUNT(*) FROM ${p}bn_profile_values v INNER JOIN ${p}bn_profile_fields f ON f.id = v.field_id ` +
                `WHERE v.user_id = ${uid} AND f.is_required = 0 AND v.value <> '' AND v.value <> '[]'`
        );
        expect(stored, `plan allows ${allowed} optional fields, the member has ${stored} stored`).toBeLessThanOrEqual(allowed);

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

        // Snapshot what the admin has now, so the fill below can be undone exactly.
        const original = await storedProfile(page);
        const names: string[] = [];
        for (const [name] of FIELDS) {
            const el = page.locator(`[name="${name}"]`).first();
            if (await el.count() && await el.isVisible().catch(() => false)) {
                names.push(name);
            }
        }

        try {
            await fillManyOptionalFields(page);
            await page.getByRole('button', { name: /save changes/i }).first().click();

            // Admin is exempt: the plan-limit message must NOT appear on any control.
            const planErrors = page.locator('.bn-ep-field-error, .bn-ep-injected-error').filter({ hasText: PLAN_MSG });
            await page.waitForTimeout(2000);
            expect(await planErrors.count()).toBe(0);

            // EFFECT: "no message" is only exemption if the values were actually kept. Every
            // field the form exposed is stored non-empty, and the plain text ones hold exactly
            // what was typed (a silent drop with no message would pass the check above).
            const typed = Object.fromEntries(FIELDS);
            await expect
                .poll(async () => {
                    const now = await storedProfile(page);
                    return names.filter((n) => n in now && (now[n] === '' || now[n] === null || now[n] === undefined));
                }, { timeout: 10_000, message: 'fields the admin filled were not stored' })
                .toEqual([]);
            const now = await storedProfile(page);
            for (const key of ['bio', 'headline']) {
                if (names.includes(key) && key in now) {
                    expect(String(now[key]), `stored ${key} for the exempt admin`).toBe(typed[key]);
                }
            }
        } finally {
            // Leave the admin's profile as found.
            const restore: Record<string, string> = {};
            for (const n of names) {
                const v = original[n];
                if (n in original && (typeof v === 'string' || v === null)) {
                    restore[n] = v ?? '';
                }
            }
            if (Object.keys(restore).length > 0) {
                await bnApi(page, 'PUT', '/me/profile', restore).catch(() => undefined);
            }
        }
    });
});
