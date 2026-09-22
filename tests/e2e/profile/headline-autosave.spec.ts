import { test, expect } from '@playwright/test';
import { loginAs } from '../_fixtures/actor';
import { userId, ensureUser, setUserMeta } from '../_fixtures/wp';

/**
 * Wave-3 PROFILE A1 hero — headline blur-autosave — EFFECT-BASED (J-735).
 *
 * The MEMBER-ACTION-COVERAGE-MATRIX marks "Edit headline (blur autosave) →
 * PUT /me/profile" MISSING: only bio (J-34) had a text-field round-trip. The
 * headline sits in the edit hero and carries `data-wp-on--blur="actions.autosave"`
 * — it saves the moment focus leaves the field, with no manual Save click. This
 * asserts the real consequence: blurring fires a PUT /me/profile that returns
 * 200, and the typed value survives a full reload (server truth, not an
 * optimistic in-DOM value).
 *
 * Actor: A = varundubey (owner, admin). Self-cleaning: the original headline is
 * restored through the same autosave path so reruns start from the value they
 * found. A second actor, B = bn_e2e_target (a plain subscriber), repeats the
 * same autosave-and-persist walk on their OWN headline at phone width, closing
 * the (capability, member) cell — nothing in CAPABILITIES.md distinguishes an
 * admin editing their own headline from a member editing theirs.
 *
 * Selectors are declared locally (repo rule) from templates/parts/profile-edit-hero.php.
 *
 * Covers: cap-give-members-a-profile-with-custom-fields, cap-set-your-own-display-name-avatar-cover-photo-and-headline
 * Roles: admin, member
 * Note: loose fit - the headline is a core hero field, not a custom profile
 * field, but the edit-and-persist promise is the same PUT /me/profile path;
 * no closer CAPABILITIES.md row exists for the hero headline specifically.
 */

const A_LOGIN = process.env.BN_TEST_USER ?? 'varundubey';
const B_LOGIN = process.env.BN_TEST_OTHER_USER ?? 'bn_e2e_target';
const B_EMAIL = 'bn_e2e_target@example.com';
const B_NAME = 'BN E2E Target';
const editUrl = (login: string) => `/members/${login}/edit/`;

const HEADLINE = '#bn-ep-headline';
const NAME = '#bn-ep-name';

let A_ID = 0;
let B_ID = 0;

test.beforeAll(async () => {
    A_ID = await userId(A_LOGIN);
    B_ID = await ensureUser(B_LOGIN, B_EMAIL, B_NAME);
    expect(A_ID, `actor "${A_LOGIN}" must exist`).toBeGreaterThan(0);
    expect(B_ID, `member "${B_LOGIN}" must exist`).toBeGreaterThan(0);
    await setUserMeta(B_ID, 'bn_onboarding_complete', '1');
});

test.describe('profile / headline blur-autosave (effect-based)', () => {
    test('J-735 blurring the headline autosaves it and it persists on reload', async ({ page }) => {
        await loginAs(page, A_LOGIN);
        await page.goto(editUrl(A_LOGIN));
        const headline = page.locator(HEADLINE);
        await expect(headline).toBeVisible();

        const original = await headline.inputValue();
        const value = `e2e headline ${Date.now().toString().slice(-6)}`;

        const waitAutosave = () =>
            page.waitForResponse(
                (r) =>
                    r.url().includes('/me/profile') &&
                    r.request().method() === 'PUT' &&
                    r.status() === 200,
                { timeout: 15_000 }
            );

        try {
            // Type, then move focus to another field — the blur is what fires the
            // autosave. Assert the PUT itself completed 200 (not just a DOM flip).
            await headline.fill(value);
            await Promise.all([waitAutosave(), page.locator(NAME).first().click()]);

            // Round-trip: reload the edit form; the field shows the saved value.
            await page.goto(editUrl(A_LOGIN));
            await expect(
                page.locator(HEADLINE),
                'the autosaved headline must survive a reload'
            ).toHaveValue(value);
        } finally {
            // Restore the original headline through the same blur-autosave path.
            await page.goto(editUrl(A_LOGIN));
            const restore = page.locator(HEADLINE);
            if (await restore.isVisible().catch(() => false)) {
                await restore.fill(original);
                await Promise.all([waitAutosave(), page.locator(NAME).first().click()]).catch(
                    () => undefined
                );
            }
        }
    });

    /**
     * J-735-member — a plain MEMBER (not the admin owner) blur-autosaves their
     * own headline and it persists, at phone width. Same PUT /me/profile path
     * as J-735, walked as B (subscriber) on B's own profile.
     */
    test('J-735-member a plain member autosaves their own headline on blur (mobile 390px)', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await loginAs(page, B_LOGIN);
        await page.goto(editUrl(B_LOGIN));
        const headline = page.locator(HEADLINE);
        await expect(headline).toBeVisible();

        const original = await headline.inputValue();
        const value = `e2e member headline ${Date.now().toString().slice(-6)}`;

        const waitAutosave = () =>
            page.waitForResponse(
                (r) =>
                    r.url().includes('/me/profile') &&
                    r.request().method() === 'PUT' &&
                    r.status() === 200,
                { timeout: 15_000 }
            );

        try {
            await headline.fill(value);
            await Promise.all([waitAutosave(), page.locator(NAME).first().click()]);

            await page.goto(editUrl(B_LOGIN));
            await expect(
                page.locator(HEADLINE),
                'a member\'s own autosaved headline must survive a reload'
            ).toHaveValue(value);
        } finally {
            await page.goto(editUrl(B_LOGIN));
            const restore = page.locator(HEADLINE);
            if (await restore.isVisible().catch(() => false)) {
                await restore.fill(original);
                await Promise.all([waitAutosave(), page.locator(NAME).first().click()]).catch(
                    () => undefined
                );
            }
        }
    });
});
