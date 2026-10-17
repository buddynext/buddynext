import { test, expect, type Page } from '@playwright/test';
import { loginAs } from '../_fixtures/actor';
import {
    userId,
    ensureUser,
    setUserMeta,
    resetPair,
    tablePrefix,
    dbCount,
    displayName,
    wp,
    satisfyRequiredFields,
} from '../_fixtures/wp';
import { bnApi } from '../_fixtures/spaces-rest';

/** A member's profile as the page's viewer reads it from REST (raw body + serialised). */
async function profileOf(page: Page, uid: number): Promise<{ status: number; body: Record<string, unknown>; json: string }> {
    const r = await bnApi(page, 'GET', `/users/${uid}/profile`);
    return { status: r.status, body: (r.data ?? {}) as Record<string, unknown>, json: JSON.stringify(r.data ?? null) };
}

/** Ids of the profile tabs the route resolves for this viewer (what an app would draw). */
async function navTabIds(page: Page, uid: number): Promise<string[]> {
    const prof = await profileOf(page, uid);
    expect(prof.status, `GET /users/${uid}/profile`).toBe(200);
    const nav = (prof.body.nav ?? {}) as { tabs?: Array<{ id?: string }> };
    return (nav.tabs ?? []).map((t) => String(t.id ?? ''));
}

/** The `ids` list of a social-graph route ({ ids: [...] }). */
async function graphIds(page: Page, path: string): Promise<number[]> {
    const r = await bnApi(page, 'GET', path);
    expect(r.status, `GET ${path}`).toBe(200);
    return (((r.data ?? {}) as { ids?: unknown[] }).ids ?? []).map(Number);
}

/**
 * Wave-2 PROFILE A3 tab + completion actions — EFFECT-BASED (J-720..J-725).
 *
 * The MEMBER-ACTION-COVERAGE-MATRIX marks the A3 rows MISSING. Each test asserts
 * a real consequence a viewer or the DB can see, never presence-only:
 *
 *   J-720 profile-strength — filling a field lifts the strength ring on reload
 *         (a fresh member goes from 0% to a higher %).
 *   J-721 About tab content — a filled repeater entry is rendered in the About
 *         tab for a DIFFERENT viewer (not just "a tab exists").
 *   J-722 Connections tab — after A↔B connect+accept (driven through the UI),
 *         the accepted peer appears in the owner's Connections grid.
 *   J-723 pending inbox — an incoming connection request shows in the owner-only
 *         pending-requests inbox on the Connections tab.
 *   J-724 bridge degrade-to-absent — the Media tab (WPMediaVerse) is present
 *         while its nav integration is on, and RETIRES (not errors) when the
 *         owner toggles it off; the profile still renders.
 *   J-725 About tab is content-gated — a member with about content has the tab,
 *         a member with none does not (absent, not empty-broken).
 *
 * Actors: A = varundubey (owner, has profile content). B = bn_e2e_target (a
 * seeded member; used both as a viewer and as a fresh 0%-strength subject).
 * Selectors declared locally (repo rule) from templates/parts/profile/*.php,
 * templates/parts/nav-bar.php and templates/profile/edit.php. Every mutation is
 * reverted so reruns are idempotent.
 *
 * Covers: cap-give-members-a-profile-with-custom-fields, cap-follow-people-and-connect-mutually
 * Roles: admin, member
 * Note: J-720/J-721/J-725 cover the profile-fields/About-tab promise; J-722/
 * J-723 cover follow-connect (connections tab + pending inbox); J-724 (Media
 * bridge tab retiring) does not map to any current CAPABILITIES.md row (the
 * WPMediaVerse Media tab is not the same promise as the forum/job/listing/
 * course bridge-panels row) and is left unpinned. Both A and B log in via
 * loginAs() throughout.
 */

const A_LOGIN = process.env.BN_TEST_USER ?? 'varundubey';
const B_LOGIN = process.env.BN_TEST_OTHER_USER ?? 'bn_e2e_target';
const B_EMAIL = 'bn_e2e_target@example.com';
const B_NAME = 'Jordan Ellis';

const editUrl = (login: string) => `/members/${login}/edit/`;
const memberUrl = (login: string) => `/members/${login}/`;
const aboutUrl = (login: string) => `/members/${login}/about/`;
const connectionsUrl = (login: string) => `/members/${login}/connections/`;

// ---- edit / hero / tab selectors (live markup) ----------------------------
const APP = '.bn-app';
const HERO = '.bn-pf-hero';
const SAVE_BTN = 'button[type="submit"]';
// Location became a picker (hidden input + widget) with the Pro location field type; the
// plain text field with the same per-field privacy lock is Website.
const LOCATION_INPUT = 'input[name="pronouns"]';
const RING_PCT = '.bn-pf-ring__pct';
const ABOUT_CARD = '.bn-pf-tab-content .bn-pf-about-card';
const TAB = 'a.bn-tab[role="tab"]';
const ABOUT_TAB = 'a.bn-tab[href$="/about/"]';
const MEDIA_TAB = 'a.bn-tab[href$="/media/"]';

// ---- work repeater --------------------------------------------------------
const WORK_CONTAINER = '#bn-ep-work-experience-entries';
const WORK_ADD = 'button.bn-ep-add-entry[data-group="work_experience"]';
const WORK_ENTRY = `${WORK_CONTAINER} .bn-ep-repeater-entry`;

// ---- profile-hero connect/accept actions ----------------------------------
const CONNECT_BTN = 'button[data-wp-on--click="actions.connect"]';
const ACCEPT_BTN = 'button[data-wp-on--click="actions.acceptRequest"]';

// ---- connections panel (people-panel.php) ---------------------------------
const PENDING_SECTION = '.bn-follow-requests';
const PENDING_NAME = '.bn-follow-requests__name';

const MEDIA_NAV_OPTION = 'buddynext_integration_media_nav';

let A_ID = 0;
let B_ID = 0;

test.beforeAll(async () => {
    A_ID = await userId(A_LOGIN);
    B_ID = await ensureUser(B_LOGIN, B_EMAIL, B_NAME);
    expect(A_ID, `actor "${A_LOGIN}" must exist`).toBeGreaterThan(0);
    expect(B_ID, `viewer "${B_LOGIN}" must exist`).toBeGreaterThan(0);
    await setUserMeta(B_ID, 'bn_onboarding_complete', '1');
});

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

/** Wipe every profile field value for a user (fresh 0%-strength baseline). */
async function clearProfileValues(uid: number): Promise<void> {
    const p = await tablePrefix();
    await wp(['db', 'query', `DELETE FROM ${p}bn_profile_values WHERE user_id=${uid}`]);
    // A member with empty required fields cannot complete a full save (1.2.5 rule), so an
    // "empty" baseline still carries the required answers.
    await satisfyRequiredFields(uid);
}

/** Truly empty profile (no required answers either): only for a member who VIEWS, never saves. */
async function clearProfileValuesHard(uid: number): Promise<void> {
    const p = await tablePrefix();
    await wp(['db', 'query', `DELETE FROM ${p}bn_profile_values WHERE user_id=${uid}`]);
    // Raw SQL skips the profile cache; on a site with an object cache the old values would still render.
    await wp(['cache', 'flush']);
}

/**
 * Take B's member type off for one test and return how to put it back.
 *
 * A member_type profile field shows the member's type as profile content, so a
 * member with a type is not an "empty" profile: it fills its group (strength) and
 * gives the About tab something to show. The tests that need a blank member clear
 * the type with the values; the type is restored so the shared member is unchanged.
 */
async function clearMemberType(uid: number): Promise<() => Promise<void>> {
    const typeId = parseInt(
        await wp(['eval', `$t = buddynext_service( 'member_types' )->get_user_type( ${uid} ); echo (int) ( $t['id'] ?? 0 );`]),
        10
    ) || 0;
    if (typeId > 0) {
        await wp(['eval', `buddynext_service( 'member_types' )->remove_user_type( ${uid} );`]);
    }
    return async () => {
        if (typeId > 0) {
            await wp(['eval', `buddynext_service( 'member_types' )->assign_type( ${uid}, ${typeId} );`]);
        }
    };
}

async function connectionCount(status: string): Promise<number> {
    const p = await tablePrefix();
    return dbCount(
        `SELECT COUNT(*) FROM ${p}bn_connections WHERE ((requester_id=${A_ID} AND recipient_id=${B_ID}) OR (requester_id=${B_ID} AND recipient_id=${A_ID})) AND status='${status}'`
    );
}

function companies(page: Page): Promise<string[]> {
    return page
        .locator(`${WORK_CONTAINER} input[name$="[work_company]"]`)
        .evaluateAll((els) => els.map((e) => (e as HTMLInputElement).value));
}

async function removeCompany(page: Page, value: string): Promise<void> {
    const all = await companies(page);
    const i = all.indexOf(value);
    if (i < 0) return;
    await page.locator(WORK_ENTRY).nth(i).locator('.bn-ep-repeater-remove').click();
    await saveProfile(page);
    await page.goto(editUrl(A_LOGIN));
    await expect(page.locator(APP)).toBeVisible();
}

// The Profile Strength ring lives in the right sidebar, which is display:none
// below 1025px (bn-shell.css .bn-app__right). Below that the hero completeness
// chip (.bn-pf-completeness, rendered while under 100%) is the canonical
// percentage. Read whichever the current viewport actually paints, so this
// journey holds at every shipping width instead of only desktop — on iPad the
// sidebar ring is present in the DOM but hidden, which is by design, not a bug.
const COMPLETENESS_PCT = '.bn-pf-completeness__label';
const strengthLocator = (page: Page) =>
    page.locator((page.viewportSize()?.width ?? 1280) >= 1025 ? RING_PCT : COMPLETENESS_PCT).first();

const ringPct = (page: Page): Promise<number> =>
    strengthLocator(page)
        .innerText()
        .then((s) => parseInt(s.replace(/[^\d-]/g, ''), 10) || 0);

test.describe('profile / A3 tabs + completion (effect-based)', () => {
    test('J-720 profile strength rises when a field is filled', async ({ page }) => {
        // Start B from a clean 0% baseline (no field values, no member type).
        await clearProfileValues(B_ID);
        const restoreType = await clearMemberType(B_ID);
        try {
            await loginAs(page, B_LOGIN);
            await page.goto(memberUrl(B_LOGIN));
            await expect(strengthLocator(page)).toBeVisible();
            const before = await ringPct(page);
            // The required answers alone give a small baseline; the promise is that filling a
            // field moves the ring, so the assertion below is relative.
            expect(before, 'baseline is below full').toBeLessThan(100);

            // Fill one field through the real edit UI.
            await page.goto(editUrl(B_LOGIN));
            await expect(page.locator(APP)).toBeVisible();
            const filled = `Portland ${Date.now().toString().slice(-5)}`;
            await page.locator(LOCATION_INPUT).fill(filled);
            await saveProfile(page);
            // EFFECT: the value the ring is computed from is on the profile route.
            const own = await bnApi(page, 'GET', '/me/profile');
            expect(own.status, 'GET /me/profile').toBe(200);
            expect(JSON.stringify(own.data ?? null), 'the filled field is stored').toContain(filled);

            // Reload the profile — the strength ring reflects the new value.
            await page.goto(memberUrl(B_LOGIN));
            await expect(strengthLocator(page)).toBeVisible();
            const after = await ringPct(page);
            expect(after, 'filling a field must raise the strength ring').toBeGreaterThan(before);
            // EFFECT: the sidebar ring is the route's strength percent, not its own maths
            // (the phone chip shows completion instead, so parity is asserted on the ring).
            if ((page.viewportSize()?.width ?? 1280) >= 1025) {
                const me = await bnApi(page, 'GET', '/me/profile');
                const strength = ((me.data ?? {}) as { strength?: { percent?: number } }).strength;
                expect(Number(strength?.percent), 'GET /me/profile strength.percent equals the ring').toBe(after);
            }
        } finally {
            await clearProfileValues(B_ID);
            await restoreType();
        }
    });

    test('J-721 About tab renders a filled field to a viewer', async ({ page }, testInfo) => {
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

        const company = `Harbor Bakery ${Date.now().toString().slice(-6)}`;
        try {
            await page.locator(WORK_ADD).click();
            const idx = (await page.locator(WORK_ENTRY).count()) - 1;
            await page.locator(`${WORK_CONTAINER} input[name="work_experience[${idx}][work_company]"]`).fill(company);
            await saveProfile(page);

            // A different viewer opens the About tab and sees the value.
            await loginAs(page, B_LOGIN);
            await page.goto(aboutUrl(A_LOGIN));
            await expect(page.locator(ABOUT_CARD).first()).toBeVisible();
            await expect(
                page.locator('.bn-pf-tab-content').getByText(company),
                'a filled entry must render in the About tab for a viewer'
            ).toBeVisible();
            // EFFECT: the viewer's REST read of A's profile carries the entry.
            const asViewer = await profileOf(page, A_ID);
            expect(asViewer.status, 'viewer GET /users/{A}/profile').toBe(200);
            expect(asViewer.json, 'the saved entry is in the viewer\'s REST payload').toContain(company);
        } finally {
            await loginAs(page, A_LOGIN);
            await page.goto(editUrl(A_LOGIN));
            await removeCompany(page, company);
        }
    });

    test('J-722 Connections tab lists an accepted peer', async ({ page }) => {
        await resetPair(A_ID, B_ID);
        try {
            // A requests B (through the hero UI).
            await loginAs(page, A_LOGIN);
            await page.goto(memberUrl(B_LOGIN));
            await expect(page.locator(HERO).first()).toBeVisible();
            await Promise.all([
                page.waitForResponse(
                    (r) => r.url().includes(`/users/${B_ID}/connect`) && r.request().method() === 'POST',
                    { timeout: 10_000 }
                ),
                page.locator(HERO).first().locator(CONNECT_BTN).first().click(),
            ]);

            // B accepts.
            await loginAs(page, B_LOGIN);
            await page.goto(memberUrl(A_LOGIN));
            await expect(page.locator(HERO).first()).toBeVisible();
            await Promise.all([
                page.waitForResponse(
                    (r) => r.url().includes(`/users/${A_ID}/connect/accept`) && r.request().method() === 'POST',
                    { timeout: 10_000 }
                ),
                page.locator(HERO).first().locator(ACCEPT_BTN).first().click(),
            ]);
            expect(await connectionCount('accepted'), 'precondition: the pair is connected').toBe(1);

            // A's own Connections tab lists B in the accepted grid.
            await loginAs(page, A_LOGIN);
            await page.goto(connectionsUrl(A_LOGIN));
            await expect(
                page.locator(`.bn-pf-tab-content a[href*="/members/${B_LOGIN}"]`).first(),
                'the accepted peer must appear in the connections grid'
            ).toBeVisible();
            // EFFECT: the connections route the grid is built from lists B, and the
            // status route reads the pair as accepted.
            expect(await graphIds(page, `/users/${A_ID}/connections?per_page=50`), 'B is in A\'s connections per the route').toContain(B_ID);
            const st = await bnApi(page, 'GET', `/users/${B_ID}/connection/status`);
            expect(
                ((st.data ?? {}) as { connection?: { state?: string } }).connection?.state,
                'GET /users/{B}/connection/status'
            ).toBe('accepted');
        } finally {
            await resetPair(A_ID, B_ID);
        }
    });

    test('J-723 pending-connections inbox shows an incoming request', async ({ page }) => {
        await resetPair(A_ID, B_ID);
        try {
            // B sends A a connection request.
            await loginAs(page, B_LOGIN);
            await page.goto(memberUrl(A_LOGIN));
            await expect(page.locator(HERO).first()).toBeVisible();
            await Promise.all([
                page.waitForResponse(
                    (r) => r.url().includes(`/users/${A_ID}/connect`) && r.request().method() === 'POST',
                    { timeout: 10_000 }
                ),
                page.locator(HERO).first().locator(CONNECT_BTN).first().click(),
            ]);
            expect(await connectionCount('pending'), 'precondition: a pending request exists').toBe(1);

            // A opens the Connections tab: the owner-only inbox shows B's request.
            await loginAs(page, A_LOGIN);
            await page.goto(connectionsUrl(A_LOGIN));
            await expect(
                page.locator(PENDING_SECTION),
                'the pending-requests inbox must render for the owner'
            ).toBeVisible();
            await expect(
                page.locator(PENDING_NAME).filter({ hasText: await displayName(B_ID) }).first(),
                'the incoming request must name the requester'
            ).toBeVisible();
            // EFFECT: the inbox route lists B as a requester for A.
            expect(await graphIds(page, '/me/connection-requests'), 'B is in A\'s incoming requests per the route').toContain(B_ID);
        } finally {
            await resetPair(A_ID, B_ID);
        }
    });

    test('J-724 Media bridge tab degrades to absent when its nav toggle is off', async ({ page }) => {
        // Baseline: WPMediaVerse is active and the media nav integration defaults on.
        await wp(['option', 'update', MEDIA_NAV_OPTION, '1']);
        try {
            await loginAs(page, A_LOGIN);
            await page.goto(memberUrl(A_LOGIN));
            await expect(page.locator(HERO).first()).toBeVisible();
            await expect(
                page.locator(MEDIA_TAB).first(),
                'the Media tab is present while the integration is on'
            ).toBeVisible();
            // EFFECT: the nav the profile route resolves carries the Media tab too.
            expect(await navTabIds(page, A_ID), 'route nav while the integration is on').toContain('media');

            // Owner turns the media nav integration off.
            await wp(['option', 'update', MEDIA_NAV_OPTION, '0']);
            await page.goto(memberUrl(A_LOGIN));
            // The profile still renders (degrade, not error) and the tab is gone.
            await expect(page.locator(HERO).first()).toBeVisible();
            await expect(
                page.locator(MEDIA_TAB),
                'the Media tab must retire when the integration is off'
            ).toHaveCount(0);
            // EFFECT: the saved option is what retired it, and the route nav agrees.
            expect((await wp(['option', 'get', MEDIA_NAV_OPTION])).trim(), 'the stored integration toggle').toBe('0');
            expect(await navTabIds(page, A_ID), 'route nav while the integration is off').not.toContain('media');
            // Other tabs still render — proving the bar itself did not break.
            expect(await page.locator(TAB).count(), 'the tab bar still renders').toBeGreaterThan(0);
        } finally {
            await wp(['option', 'update', MEDIA_NAV_OPTION, '1']);
        }
    });

    test('J-725 About tab is content-gated (present with content, absent without)', async ({ page }) => {
        // B has no about content at all (the required answers count as content) → no About tab.
        // Log in first: loginAs() refills the required answers, the hard clear must come after.
        await loginAs(page, B_LOGIN);
        await clearProfileValuesHard(B_ID);
        const restoreType = await clearMemberType(B_ID);
        try {

            // Give A about content (skills / work) so the card has something to show; the
            // configured test member starts empty.
            await wp([
                'eval',
                `buddynext_service( 'profiles' )->save_profile( ${A_ID}, array( 'skills' => 'Trail photography and bread baking', 'about_me' => 'I run a small weekend bakery', 'work_company' => 'Harbor Bakery Co', 'work_title' => 'Head Baker' ) ); echo 'ok';`,
            ]);
            await page.goto(memberUrl(A_LOGIN));
            await expect(page.locator(HERO).first()).toBeVisible();
            await expect(
                page.locator(ABOUT_TAB).first(),
                'a profile with about content shows the About tab'
            ).toBeVisible();
            // EFFECT: the route nav and payload agree: A has about content and the tab.
            expect(await navTabIds(page, A_ID), 'route nav for a profile with about content').toContain('about');
            expect((await profileOf(page, A_ID)).json, 'the about content is in the REST payload').toContain('Trail photography and bread baking');

            // B (emptied) has none → the About tab is absent, profile still renders.
            await page.goto(memberUrl(B_LOGIN));
            await expect(page.locator(HERO).first()).toBeVisible();
            await expect(
                page.locator(ABOUT_TAB),
                'a profile without about content must not show the About tab'
            ).toHaveCount(0);
            // EFFECT: the route nav drops the tab for the emptied member, and no value row is left.
            expect(await navTabIds(page, B_ID), 'route nav for a profile without about content').not.toContain('about');
            const p = await tablePrefix();
            expect(await dbCount(`SELECT COUNT(*) FROM ${p}bn_profile_values WHERE user_id=${B_ID}`), 'B holds no profile values').toBe(0);
        } finally {
            await satisfyRequiredFields(B_ID);
            await restoreType();
        }
    });
});
