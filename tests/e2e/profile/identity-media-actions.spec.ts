import { test, expect } from '@playwright/test';
import { loginAs } from '../_fixtures/actor';
import {
    userId,
    getUserMeta,
    setUserMeta,
    deleteUserMeta,
    displayName,
    wp,
} from '../_fixtures/wp';

/**
 * Wave-1 PROFILE identity/media actions — EFFECT-BASED (J-707..J-709).
 *
 * These cover the A1 hero counterparts the MEMBER-ACTION-COVERAGE-MATRIX marks
 * MISSING — Remove avatar (DELETE /me/avatar), Remove cover (DELETE /me/cover,
 * the untested counterpart of a shipped 1.0.7 fix), and the display-name empty
 * validation path — asserting the real server effect (the meta key is cleared /
 * the empty name never persists), not just a control flip.
 *
 * The avatar/cover UPLOAD legs run through a canvas crop/reposition modal that is
 * not deterministically drivable from Playwright, so each precondition is
 * server-seeded (a custom avatar / cover meta) — which is exactly the state an
 * upload leaves — and the REMOVE counterpart is then driven end-to-end through
 * the UI and verified in the DB. Remove is the leg the matrix flags as MISSING.
 *
 * Actor: A = varundubey (owner). Self-cleaning: every test restores A's avatar/
 * cover/name meta to empty (or original) so reruns are idempotent.
 *
 * Selectors are declared locally (repo rule) from templates/parts/profile-edit-hero.php.
 */

const A_LOGIN = process.env.BN_TEST_USER ?? 'varundubey';
const SEED_AVATAR = 'http://buddynext-dev.local/wp-content/uploads/bn-e2e-avatar.png';
const SEED_COVER = 'http://buddynext-dev.local/wp-content/uploads/bn-e2e-cover.jpg';

const editUrl = (login: string) => `/members/${login}/edit/`;
const memberUrl = (login: string) => `/members/${login}/`;

const AVATAR_REMOVE = '.bn-ep-avatar-remove';
const COVER_REMOVE = '.bn-ep-cover-remove';
const NAME_INPUT = '#bn-ep-name';
const NAME_ERROR = '#bn-ep-error-display_name';
const SAVE_BTN = '.bn-ep-save-actions button[type="submit"]';
// bnConfirm() builds a dynamic modal; its confirm button carries the "Remove"
// label from removeAvatar()/removeCover().
const CONFIRM_REMOVE = '.bn-modal-backdrop [role="dialog"] button:has-text("Remove")';

let A_ID = 0;

test.beforeAll(async () => {
    A_ID = await userId(A_LOGIN);
    expect(A_ID, `actor "${A_LOGIN}" must exist`).toBeGreaterThan(0);
});

test.describe('profile / identity + media actions (effect-based)', () => {
    test('J-707 remove avatar reverts to initials (DELETE /me/avatar + meta cleared)', async ({
        page,
    }) => {
        // Seed a custom avatar so the Remove control is exposed (it is hidden
        // unless bn_avatar is set — the same gate the UI uses).
        await setUserMeta(A_ID, 'bn_avatar', SEED_AVATAR);
        try {
            await loginAs(page, A_LOGIN);
            await page.goto(editUrl(A_LOGIN));

            const remove = page.locator(AVATAR_REMOVE).first();
            await expect(remove, 'custom avatar must expose the Remove control').toBeVisible();

            await remove.click();
            await Promise.all([
                page.waitForResponse(
                    (r) => /\/avatar\b/.test(r.url()) && r.request().method() === 'DELETE',
                    { timeout: 10_000 }
                ),
                page.locator(CONFIRM_REMOVE).first().click(),
            ]);

            // Effect: the custom-avatar meta is cleared server-side.
            expect(
                await getUserMeta(A_ID, 'bn_avatar'),
                'remove avatar must clear the bn_avatar meta'
            ).toBe('');

            // UI: the Remove control retires (no custom avatar left).
            await expect(page.locator(AVATAR_REMOVE).first()).toBeHidden();

            // Reload — the control stays retired (persisted, not optimistic).
            await page.reload();
            await expect(page.locator(AVATAR_REMOVE).first()).toBeHidden();
        } finally {
            await deleteUserMeta(A_ID, 'bn_avatar');
        }
    });

    test('J-708 cover shows then remove reverts it (DELETE /me/cover + meta cleared)', async ({
        page,
    }) => {
        // Seed a cover (the state an upload leaves).
        await setUserMeta(A_ID, 'buddynext_cover_url', SEED_COVER);
        try {
            await loginAs(page, A_LOGIN);

            // Cover shows on the public hero.
            await page.goto(memberUrl(A_LOGIN));
            await expect(
                page.locator('.bn-pf-cover--has-image').first(),
                'seeded cover must render on the public hero'
            ).toBeVisible();

            // Remove via the edit hero.
            await page.goto(editUrl(A_LOGIN));
            const remove = page.locator(COVER_REMOVE).first();
            await expect(remove, 'a cover must expose the Remove control').toBeVisible();

            await remove.click();
            await Promise.all([
                page.waitForResponse(
                    (r) => /\/cover\b/.test(r.url()) && r.request().method() === 'DELETE',
                    { timeout: 10_000 }
                ),
                page.locator(CONFIRM_REMOVE).first().click(),
            ]);

            // Effect: the cover meta is cleared server-side.
            expect(
                await getUserMeta(A_ID, 'buddynext_cover_url'),
                'remove cover must clear the buddynext_cover_url meta'
            ).toBe('');

            // UI: the Remove control retires.
            await expect(page.locator(COVER_REMOVE).first()).toBeHidden();

            // Reload the public hero — the cover image is gone (reverted).
            await page.goto(memberUrl(A_LOGIN));
            await expect(page.locator('.bn-pf-cover--has-image')).toHaveCount(0);
        } finally {
            await deleteUserMeta(A_ID, 'buddynext_cover_url');
        }
    });

    test('J-709 empty display name is rejected and never persists', async ({ page }) => {
        const original = await displayName(A_ID);
        expect(original.length, 'actor must start with a display name').toBeGreaterThan(0);

        try {
            await loginAs(page, A_LOGIN);
            await page.goto(editUrl(A_LOGIN));

            const name = page.locator(NAME_INPUT);
            await expect(name).toBeVisible();
            await name.fill('');

            await page.locator(SAVE_BTN).first().click();

            // The required-field guard surfaces an inline validation error...
            const error = page.locator(NAME_ERROR);
            await expect(error).toBeVisible();
            await expect(error).toHaveText(/required/i);

            // ...and the empty value is NOT written: the DB name is unchanged.
            expect(
                await displayName(A_ID),
                'empty name must not overwrite the stored display name'
            ).toBe(original);

            // Reload — the field still shows the original name (nothing persisted).
            await page.goto(editUrl(A_LOGIN));
            await expect(page.locator(NAME_INPUT)).toHaveValue(original);
        } finally {
            // Defensive restore in case a regression let the empty value through.
            const now = await displayName(A_ID);
            if (now !== original) {
                await wp(['user', 'update', String(A_ID), `--display_name=${original}`]);
            }
        }
    });
});
