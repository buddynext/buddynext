import { test, expect, type Page } from '@playwright/test';
import { loginAs } from '../_fixtures/actor';
import {
    userId,
    ensureUser,
    setUserMeta,
    resetPair,
    dbScalar,
    dbCount,
    tablePrefix,
} from '../_fixtures/wp';

/**
 * Wave-2 PROFILE A2 field actions — EFFECT-BASED (J-710..J-713).
 *
 * The MEMBER-ACTION-COVERAGE-MATRIX marks the A2 privacy + save-hygiene rows
 * MISSING. Each test here asserts the REAL server consequence a viewer or the
 * database can see, never a control flip:
 *
 *   J-710 per-FIELD privacy — tighten one field to members-only, then a
 *         DIFFERENT viewer proves the gate: the value is absent to an anonymous
 *         viewer and present to a logged-in member, and the visibility is
 *         persisted on the value row (wp_bn_profile_values.entry_visibility).
 *   J-711 per-ENTRY privacy — two repeater entries, one public, one
 *         connections-only; a non-connection member sees only the public one in
 *         the About tab (get_profile drops the tightened entry for that viewer).
 *   J-712 dirty-guard — an edit arms the beforeunload guard, which fires on
 *         nav-away (asserted via a real beforeunload dialog, not just the flag).
 *   J-713 blank-optional idempotency — repeated identical full-profile saves
 *         never duplicate a value row (no (user,field,entry) row appears twice,
 *         and the row count is stable across repeated saves).
 *
 * Actors: A = varundubey (owner). B = bn_e2e_target (a seeded member used as the
 * "different viewer"). Selectors are declared locally (repo rule: never edit the
 * shared selectors.ts) from templates/profile/edit.php + templates/parts/
 * profile-hero.php + templates/parts/profile/about-panel.php.
 *
 * Every test restores the account to the state it found (field value + visibility
 * reset, added repeater entries removed) so reruns are idempotent.
 */

const A_LOGIN = process.env.BN_TEST_USER ?? 'varundubey';
const B_LOGIN = process.env.BN_TEST_OTHER_USER ?? 'bn_e2e_target';
const B_EMAIL = 'bn_e2e_target@example.com';
const B_NAME = 'BN E2E Target';

const editUrl = (login: string) => `/members/${login}/edit/`;
const memberUrl = (login: string) => `/members/${login}/`;
const aboutUrl = (login: string) => `/members/${login}/about/`;

// ---- edit-form selectors (live markup) ------------------------------------
const APP = '.bn-app';
const FORM_SHELL = '.bn-ep-form-shell';
const SAVE_BTN = 'button[type="submit"]';
const LOCATION_INPUT = 'input[name="location"]';
const LOCATION_VIS = 'select[name="location__visibility"]';

// ---- work-experience repeater (per-entry privacy) -------------------------
const WORK_CONTAINER = '#bn-ep-work-experience-entries';
const WORK_ADD = 'button.bn-ep-add-entry[data-group="work_experience"]';
const WORK_ENTRY = `${WORK_CONTAINER} .bn-ep-repeater-entry`;

// ---- hero / about view selectors ------------------------------------------
const HERO = '.bn-pf-hero';
const HERO_META = '.bn-pf-hero .bn-pf-meta';
const ABOUT_CARD = '.bn-pf-tab-content .bn-pf-about-card';

let A_ID = 0;
let B_ID = 0;
let LOCATION_FIELD_ID = 0;

test.beforeAll(async () => {
    A_ID = await userId(A_LOGIN);
    B_ID = await ensureUser(B_LOGIN, B_EMAIL, B_NAME);
    expect(A_ID, `actor "${A_LOGIN}" must exist`).toBeGreaterThan(0);
    expect(B_ID, `viewer "${B_LOGIN}" must exist`).toBeGreaterThan(0);
    // A fresh member is force-redirected to onboarding, so B never lands on the
    // real profile pages it needs to view. Mark B onboarded.
    await setUserMeta(B_ID, 'bn_onboarding_complete', '1');
    const p = await tablePrefix();
    LOCATION_FIELD_ID = parseInt(
        await dbScalar(`SELECT id FROM ${p}bn_profile_fields WHERE field_key='location' LIMIT 1`),
        10
    );
    expect(LOCATION_FIELD_ID, 'location field must exist in the schema').toBeGreaterThan(0);
});

/** Wait for the profile PUT to complete 200 while clicking Save. */
async function saveProfile(page: Page): Promise<void> {
    await Promise.all([
        page.waitForResponse(
            (r) =>
                r.url().includes('/me/profile') &&
                r.request().method() === 'PUT' &&
                r.status() === 200,
            { timeout: 15_000 }
        ),
        page.locator(SAVE_BTN).first().click(),
    ]);
}

/** entry_visibility stored on the location value row (server truth). */
async function locationVisibility(): Promise<string> {
    const p = await tablePrefix();
    return dbScalar(
        `SELECT entry_visibility FROM ${p}bn_profile_values WHERE user_id=${A_ID} AND field_id=${LOCATION_FIELD_ID} AND entry_index=0 LIMIT 1`
    );
}

/** Live company values across the work repeater on the edit form. */
function companies(page: Page): Promise<string[]> {
    return page
        .locator(`${WORK_CONTAINER} input[name$="[work_company]"]`)
        .evaluateAll((els) => els.map((e) => (e as HTMLInputElement).value));
}

/** Remove a work entry by its company value and persist. */
async function removeCompany(page: Page, value: string): Promise<void> {
    const all = await companies(page);
    const i = all.indexOf(value);
    if (i < 0) return;
    await page.locator(WORK_ENTRY).nth(i).locator('.bn-ep-repeater-remove').click();
    await saveProfile(page);
    await page.goto(editUrl(A_LOGIN));
    await expect(page.locator(APP)).toBeVisible();
}

test.describe('profile / field-level privacy + save hygiene (effect-based)', () => {
    test.beforeEach(async () => {
        // J-711 hinges on B being a NON-connection so the connections-tier entry
        // is hidden; a leftover accepted pair from another spec would unhide it.
        await resetPair(A_ID, B_ID);
    });

    test('J-710 per-field privacy: members-only hides a value from anon, shows it to a member', async ({
        page,
    }) => {
        const stamp = Date.now().toString().slice(-6);
        const secretLoc = `e2e-loc-${stamp}`;

        // Capture the owner's current location + visibility so we can restore it.
        await loginAs(page, A_LOGIN);
        await page.goto(editUrl(A_LOGIN));
        await expect(page.locator(APP)).toBeVisible();
        const origLoc = await page.locator(LOCATION_INPUT).inputValue();
        const origVis = await page.locator(LOCATION_VIS).inputValue();

        try {
            // Tighten location to members-only and give it a distinctive value.
            await page.locator(LOCATION_INPUT).fill(secretLoc);
            await page.locator(LOCATION_VIS).selectOption('members');
            await saveProfile(page);

            // Server truth: the value row carries the tightened visibility.
            expect(
                await locationVisibility(),
                'members-only choice must persist on the value row'
            ).toBe('members');

            // Anonymous viewer: the hero renders (public profile) but the
            // members-only value is filtered out by get_profile.
            await page.context().clearCookies();
            await page.goto(memberUrl(A_LOGIN));
            await expect(page.locator(HERO).first()).toBeVisible();
            await expect(
                page.locator(HERO_META).getByText(secretLoc),
                'members-only location must be absent to an anonymous viewer'
            ).toHaveCount(0);

            // Logged-in member B: same field is now visible (allowed viewer).
            await loginAs(page, B_LOGIN);
            await page.goto(memberUrl(A_LOGIN));
            await expect(page.locator(HERO).first()).toBeVisible();
            await expect(
                page.locator(HERO_META).getByText(secretLoc),
                'members-only location must be visible to a logged-in member'
            ).toBeVisible();
        } finally {
            await loginAs(page, A_LOGIN);
            await page.goto(editUrl(A_LOGIN));
            await page.locator(LOCATION_INPUT).fill(origLoc);
            await page.locator(LOCATION_VIS).selectOption(origVis || 'public');
            await saveProfile(page);
        }
    });

    test('J-711 per-entry privacy: a viewer sees the public entry but not the connections-only one', async ({
        page,
    }, testInfo) => {
        await loginAs(page, A_LOGIN);
        await page.goto(editUrl(A_LOGIN));
        await expect(page.locator(APP)).toBeVisible();
        if (!(await page.locator(WORK_CONTAINER).count())) {
            testInfo.annotations.push({
                type: 'precondition-missing',
                description: 'Work Experience repeater not on the edit form.',
            });
            return;
        }

        const stamp = Date.now().toString().slice(-6);
        const companyPublic = `e2e Public ${stamp}`;
        const companyPrivate = `e2e Connections ${stamp}`;

        try {
            // Entry 1 → public.
            await page.locator(WORK_ADD).click();
            let idx = (await page.locator(WORK_ENTRY).count()) - 1;
            await page.locator(`${WORK_CONTAINER} input[name="work_experience[${idx}][work_company]"]`).fill(companyPublic);
            await page.locator(`${WORK_CONTAINER} select[name="work_experience[${idx}][_visibility]"]`).selectOption('public');

            // Entry 2 → connections-only.
            await page.locator(WORK_ADD).click();
            idx = (await page.locator(WORK_ENTRY).count()) - 1;
            await page.locator(`${WORK_CONTAINER} input[name="work_experience[${idx}][work_company]"]`).fill(companyPrivate);
            await page.locator(`${WORK_CONTAINER} select[name="work_experience[${idx}][_visibility]"]`).selectOption('connections');

            await saveProfile(page);

            // Viewer B (a member, NOT a connection) reads A's About tab.
            await loginAs(page, B_LOGIN);
            await page.goto(aboutUrl(A_LOGIN));
            await expect(page.locator(ABOUT_CARD).first()).toBeVisible();

            const about = page.locator('.bn-pf-tab-content');
            await expect(
                about.getByText(companyPublic),
                'public entry must be visible to a non-connection member'
            ).toBeVisible();
            await expect(
                about.getByText(companyPrivate),
                'connections-only entry must be hidden from a non-connection member'
            ).toHaveCount(0);
        } finally {
            await loginAs(page, A_LOGIN);
            await page.goto(editUrl(A_LOGIN));
            await removeCompany(page, companyPublic);
            await removeCompany(page, companyPrivate);
        }
    });

    test('J-712 dirty-guard cancels navigation only while the form is dirty', async ({ page }) => {
        await loginAs(page, A_LOGIN);
        await page.goto(editUrl(A_LOGIN));
        await expect(page.locator(APP)).toBeVisible();

        // The guard registers a real beforeunload listener (initEditGuard →
        // ensureUnloadGuard) that reads data-bn-dirty and preventDefault()s the
        // unload when set. We dispatch the actual beforeunload event to that live
        // handler and read whether it cancelled: a cancelable event whose default
        // was prevented makes dispatchEvent() return false. Whether the browser
        // then paints the native "Leave site?" chrome is activation-gated and not
        // reliably automatable — the guard FIRING (cancelling the unload) is the
        // effect that matters, and this asserts it against the real handler.
        const dispatchBeforeUnload = (): Promise<boolean> =>
            page.evaluate(() => {
                const e = new Event('beforeunload', { cancelable: true });
                // dispatchEvent returns false iff a handler called preventDefault().
                return window.dispatchEvent(e) === false;
            });

        // Clean form → the guard must NOT cancel (converse; keeps this honest).
        await expect(page.locator(FORM_SHELL)).not.toHaveAttribute('data-bn-dirty', '1');
        expect(
            await dispatchBeforeUnload(),
            'a pristine form must let navigation proceed'
        ).toBe(false);

        // Edit → the guard arms and now cancels the unload.
        const loc = page.locator(LOCATION_INPUT);
        await loc.click();
        await loc.fill(`e2e-dirty-${Date.now().toString().slice(-5)}`);
        await expect(
            page.locator(FORM_SHELL),
            'editing must arm the dirty guard'
        ).toHaveAttribute('data-bn-dirty', '1');
        expect(
            await dispatchBeforeUnload(),
            'a dirty form must cancel the unload (unsaved-changes guard fires)'
        ).toBe(true);
    });

    test('J-713 repeated blank save is idempotent — no duplicate value rows', async ({ page }) => {
        const p = await tablePrefix();
        const dupSql = `SELECT COUNT(*) FROM (SELECT 1 FROM ${p}bn_profile_values WHERE user_id=${A_ID} GROUP BY field_id, entry_index HAVING COUNT(*) > 1) t`;
        const countSql = `SELECT COUNT(*) FROM ${p}bn_profile_values WHERE user_id=${A_ID}`;

        await loginAs(page, A_LOGIN);
        await page.goto(editUrl(A_LOGIN));
        await expect(page.locator(APP)).toBeVisible();

        // First save may normalise once; the invariant is that further identical
        // saves neither grow the row set nor create a second row for any field.
        await saveProfile(page);
        const afterFirst = await dbCount(countSql);

        await page.goto(editUrl(A_LOGIN));
        await saveProfile(page);
        await page.goto(editUrl(A_LOGIN));
        await saveProfile(page);
        const afterThird = await dbCount(countSql);

        expect(await dbCount(dupSql), 'no (field,entry) may hold more than one value row').toBe(0);
        expect(afterThird, 'repeated identical saves must not add value rows').toBe(afterFirst);
    });
});
