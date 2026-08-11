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
} from '../_fixtures/wp';

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
 */

const A_LOGIN = process.env.BN_TEST_USER ?? 'varundubey';
const B_LOGIN = process.env.BN_TEST_OTHER_USER ?? 'bn_e2e_target';
const B_EMAIL = 'bn_e2e_target@example.com';
const B_NAME = 'BN E2E Target';

const editUrl = (login: string) => `/members/${login}/edit/`;
const memberUrl = (login: string) => `/members/${login}/`;
const aboutUrl = (login: string) => `/members/${login}/about/`;
const connectionsUrl = (login: string) => `/members/${login}/connections/`;

// ---- edit / hero / tab selectors (live markup) ----------------------------
const APP = '.bn-app';
const HERO = '.bn-pf-hero';
const SAVE_BTN = 'button[type="submit"]';
const LOCATION_INPUT = 'input[name="location"]';
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

const ringPct = (page: Page): Promise<number> =>
    page
        .locator(RING_PCT)
        .first()
        .innerText()
        .then((s) => parseInt(s.replace(/[^\d-]/g, ''), 10) || 0);

test.describe('profile / A3 tabs + completion (effect-based)', () => {
    test('J-720 profile strength rises when a field is filled', async ({ page }) => {
        // Start B from a clean 0% baseline (no field values).
        await clearProfileValues(B_ID);
        try {
            await loginAs(page, B_LOGIN);
            await page.goto(memberUrl(B_LOGIN));
            await expect(page.locator(RING_PCT).first()).toBeVisible();
            const before = await ringPct(page);
            expect(before, 'a member with no fields starts at 0%').toBe(0);

            // Fill one field through the real edit UI.
            await page.goto(editUrl(B_LOGIN));
            await expect(page.locator(APP)).toBeVisible();
            await page.locator(LOCATION_INPUT).fill(`e2e City ${Date.now().toString().slice(-5)}`);
            await saveProfile(page);

            // Reload the profile — the strength ring reflects the new value.
            await page.goto(memberUrl(B_LOGIN));
            await expect(page.locator(RING_PCT).first()).toBeVisible();
            const after = await ringPct(page);
            expect(after, 'filling a field must raise the strength ring').toBeGreaterThan(before);
        } finally {
            await clearProfileValues(B_ID);
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

        const company = `e2e About ${Date.now().toString().slice(-6)}`;
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

            // Owner turns the media nav integration off.
            await wp(['option', 'update', MEDIA_NAV_OPTION, '0']);
            await page.goto(memberUrl(A_LOGIN));
            // The profile still renders (degrade, not error) and the tab is gone.
            await expect(page.locator(HERO).first()).toBeVisible();
            await expect(
                page.locator(MEDIA_TAB),
                'the Media tab must retire when the integration is off'
            ).toHaveCount(0);
            // Other tabs still render — proving the bar itself did not break.
            expect(await page.locator(TAB).count(), 'the tab bar still renders').toBeGreaterThan(0);
        } finally {
            await wp(['option', 'update', MEDIA_NAV_OPTION, '1']);
        }
    });

    test('J-725 About tab is content-gated (present with content, absent without)', async ({ page }) => {
        // B has no about content → no About tab.
        await clearProfileValues(B_ID);
        try {
            await loginAs(page, B_LOGIN);

            // A has profile content (skills / work / education) → About tab present.
            await page.goto(memberUrl(A_LOGIN));
            await expect(page.locator(HERO).first()).toBeVisible();
            await expect(
                page.locator(ABOUT_TAB).first(),
                'a profile with about content shows the About tab'
            ).toBeVisible();

            // B (emptied) has none → the About tab is absent, profile still renders.
            await page.goto(memberUrl(B_LOGIN));
            await expect(page.locator(HERO).first()).toBeVisible();
            await expect(
                page.locator(ABOUT_TAB),
                'a profile without about content must not show the About tab'
            ).toHaveCount(0);
        } finally {
            await clearProfileValues(B_ID);
        }
    });
});
