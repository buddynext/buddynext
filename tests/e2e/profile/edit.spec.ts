import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from "../_fixtures/precondition";
import { sel, urls } from '../_fixtures/selectors';
import { userId, getUserMeta, setUserMeta, deleteUserMeta } from '../_fixtures/wp';
import { openMemberSession } from '../_fixtures/feed-wave1.helpers';

// A valid 4x4 PNG (correct IDAT CRC — ImageMagick rejects a malformed one),
// small and well under the 4MB / 1024px avatar cap.
const PNG_IMG = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAIAAAAmkwkpAAAADklEQVR4nGNoQAIMxHEAcFIYAYPG8BkAAAAASUVORK5CYII=',
    'base64'
);

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

        // Wait for the SAVE, not for a page load.
        //
        // waitForLoadState() only ever worked here by accident: saving used to
        // redirect to the member profile, and this happened to sit in the gap.
        // The save no longer navigates (the member stays on the edit screen, by
        // design), so that call now resolves instantly and the goto below races
        // the in-flight PUT and aborts it — the value never persists and the
        // round-trip fails. Waiting on the request makes the sequencing explicit
        // and asserts the save actually succeeded.
        await Promise.all([
            page.waitForResponse(
                (r) => r.url().includes('/me/profile') && r.request().method() === 'PUT' && r.status() === 200,
                { timeout: 15000 }
            ),
            save.click(),
        ]);

        // Round-trip  -  reload edit page and confirm the value stuck.
        await page.goto(urls.memberEdit(user));
        const reloaded = page.locator('textarea[name="bio"], textarea[name="description"], [data-field="bio"] textarea').first();
        if (await reloaded.isVisible().catch(() => false)) {
            await expect(reloaded).toHaveValue(value);
        }
    });

    /**
     * J-33 avatar upload actually writes the avatar (effect).
     *
     * UPGRADED from WEAK → EFFECT (Wave-4). The old body only read the file
     * input's `accept` attribute — presence, not a write — and deliberately never
     * uploaded, so it passed even if the whole avatar path was broken. It now
     * uploads a real image through the canonical media write path (multipart POST
     * /me/cover's sibling, POST /me/avatar → ImageStorageService) and asserts the
     * canonical `bn_avatar` usermeta is written to a stored URL. The account is
     * left as found: the upload is removed (DELETE /me/avatar covers J-707's
     * remove path) and any prior avatar meta restored. J-707 owns the remove
     * journey; this owns the SET.
     */
    test('J-33 avatar upload writes the bn_avatar meta (effect)', async ({ authenticatedPage: page, browser }) => {
        const uid = await userId(user);
        expect(uid, 'actor must resolve to a user id').toBeGreaterThan(0);
        const original = await getUserMeta(uid, 'bn_avatar');

        const sess = await openMemberSession(browser, user);
        try {
            const res = await sess.request.post('/wp-json/buddynext/v1/me/avatar', {
                headers: { 'X-WP-Nonce': sess.nonce },
                multipart: {
                    avatar: { name: 'e2e-avatar.png', mimeType: 'image/png', buffer: PNG_IMG },
                },
            });
            expect(res.status(), 'avatar upload must succeed').toBe(200);
            const body = (await res.json().catch(() => ({}))) as { avatar_url?: string };
            expect(String(body.avatar_url ?? ''), 'the response carries the stored URL').toMatch(/^https?:\/\//);

            // Effect: the canonical bn_avatar usermeta is now a stored image URL.
            const stored = await getUserMeta(uid, 'bn_avatar');
            expect(stored, 'bn_avatar usermeta must be written by the upload').toMatch(/^https?:\/\//);
            expect(stored, 'the stored avatar must differ from the pre-upload value').not.toBe(original);
        } finally {
            await sess.request
                .delete('/wp-json/buddynext/v1/me/avatar', { headers: { 'X-WP-Nonce': sess.nonce } })
                .catch(() => undefined);
            if (original) {
                await setUserMeta(uid, 'bn_avatar', original);
            } else {
                await deleteUserMeta(uid, 'bn_avatar');
            }
            await sess.ctx.close();
        }
    });

    /**
     * J-35 custom profile field edit + save.
     *
     * UPGRADED from WEAK → EFFECT (Wave-3). The old body filled a never-matching
     * `input[name^="bn_profile_field"]` selector, clicked Save, then only
     * `waitForLoadState('domcontentloaded')` — which the matrix flagged as
     * presence-with-no-round-trip: it passed even if the PUT silently 500'd. It
     * now mirrors J-34 on a REAL admin-defined text field (`pronouns`): wait for
     * the PUT /me/profile 200, reload, and assert the typed value round-trips
     * from the server. The account is restored to the value it was found with.
     */
    test('J-35 custom profile field edits save', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.memberEdit(user));
        await expect(page.locator(sel.app)).toBeVisible();

        const field = page.locator('input[name="pronouns"]').first();
        if (!(await field.isVisible().catch(() => false))) {
            softSkip(testInfo, 'The `pronouns` custom field is not on the edit form.');
            return;
        }

        const original = await field.inputValue();
        const value = `e2e ${Date.now().toString().slice(-5)}`;
        const save = page.locator('button[type="submit"]').first();

        try {
            await field.fill(value);
            await Promise.all([
                page.waitForResponse(
                    (r) => r.url().includes('/me/profile') && r.request().method() === 'PUT' && r.status() === 200,
                    { timeout: 15000 }
                ),
                save.click(),
            ]);

            // Round-trip: reload and confirm the value stuck server-side.
            await page.goto(urls.memberEdit(user));
            await expect(page.locator('input[name="pronouns"]').first()).toHaveValue(value);
        } finally {
            // Leave the field exactly as found.
            await page.goto(urls.memberEdit(user));
            const restore = page.locator('input[name="pronouns"]').first();
            if (await restore.isVisible().catch(() => false)) {
                await restore.fill(original);
                await Promise.all([
                    page.waitForResponse(
                        (r) => r.url().includes('/me/profile') && r.request().method() === 'PUT' && r.status() === 200,
                        { timeout: 15000 }
                    ),
                    save.click(),
                ]).catch(() => undefined);
            }
        }
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

    /**
     * C2 — member-type SELF-SELECT actually assigns (effect).
     *
     * UPGRADED from WEAK → EFFECT (Wave-3). The dedup test above still guards
     * "renders <=1 time"; the matrix separately flagged C2 as WEAK because
     * nothing ever SELECTED a type or checked the assignment — it passed even if
     * the write silently failed. This drives the real self-select control: the
     * `<select>` auto-saves on change (data-wp-on--change="actions.setMemberType"
     * → PUT /users/{id}/member-type), so we change it, wait for that PUT to 200,
     * reload, and assert BOTH the rendered value AND the server truth
     * (usermeta bn_member_type). A round-trip to a second option and back proves
     * the assign path writes a concrete slug; the second hop restores the account.
     */
    test('C2 member-type self-select assigns and persists (DB confirm)', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.memberEdit(user));
        await expect(page.locator(sel.app)).toBeVisible();

        const select = page.locator('#bn-ep-member-type');
        if (!(await select.count())) {
            softSkip(testInfo, 'No self-select member types configured on this site.');
            return;
        }

        const uid = await userId(user);
        expect(uid, 'actor must resolve to a user id').toBeGreaterThan(0);

        const origSlug = await select.inputValue();
        const optionValues = await select.locator('option').evaluateAll(
            (opts) => opts.map((o) => (o as HTMLOptionElement).value)
        );
        const target = optionValues.find((v) => v !== origSlug);
        if (target === undefined) {
            softSkip(testInfo, 'Only one member-type option available — no change to assert.');
            return;
        }

        const waitAssign = () =>
            page.waitForResponse(
                (r) => r.url().includes('/member-type') && r.request().method() === 'PUT' && r.status() === 200,
                { timeout: 15000 }
            );

        try {
            // Hop 1 — change to a DIFFERENT option; the change auto-saves.
            await Promise.all([waitAssign(), select.selectOption(target)]);
            await page.goto(urls.memberEdit(user));
            await expect(page.locator('#bn-ep-member-type')).toHaveValue(target);
            expect(
                await getUserMeta(uid, 'bn_member_type'),
                'the chosen member type must be written to usermeta'
            ).toBe(target);

            // Hop 2 — change back to the original (a concrete non-empty slug when
            // the member started assigned); doubles as the restore.
            await Promise.all([waitAssign(), page.locator('#bn-ep-member-type').selectOption(origSlug)]);
            await page.goto(urls.memberEdit(user));
            expect(
                await getUserMeta(uid, 'bn_member_type'),
                'reverting must write the original slug back'
            ).toBe(origSlug);
        } finally {
            // Defensive restore if an assertion aborted mid-round-trip.
            if ((await getUserMeta(uid, 'bn_member_type')) !== origSlug) {
                await setUserMeta(uid, 'bn_member_type', origSlug);
            }
        }
    });
});
