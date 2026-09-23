import { test, expect, type Page } from '@playwright/test';
import { loginAs } from '../_fixtures/actor';
import { userId, wp, ensureUser, setUserMeta } from '../_fixtures/wp';
import { softSkip } from '../_fixtures/precondition';

/**
 * Wave-4 PROFILE bridge-tab degrade-to-absent — EFFECT-BASED (J-747..J-749),
 * plus the MEMBER-role legs (J-823, J-824) that view the same tabs as a plain
 * member rather than the admin owner.
 *
 * Mirrors the J-724 Media (WPMediaVerse) pattern for the OTHER integration
 * profile tabs the coverage re-scan named: Discussions (Jetonomy), Achievements
 * (wb-gamification), and Portfolio (Pro suite). Each degrade test asserts the
 * same real consequence: with the integration's nav toggle ON the tab is
 * PRESENT, and when the owner turns it OFF the tab RETIRES (present → absent)
 * while the hero and the rest of the tab bar keep rendering — a graceful
 * degrade, never a fatal. The member-leg tests (J-823 Discussions, J-824
 * Articles/WB Member Blog) instead prove the ON-state content itself renders
 * for a VIEWER who is not the profile owner and not an admin.
 *
 * Only tabs that are actually registrable on THIS harness run the full effect
 * assertion. The harness runs jetonomy + jetonomy-pro + learnomy(+pro) +
 * wpmediaverse + buddynext-pro active, but NOT wb-gamification or WB Member
 * Blog, and varundubey has no visible suite panels — so a bridge whose plugin
 * registers no profile tab here is softSkipped WITH a reason (per the repo's
 * honest-coverage rule) rather than asserted against markup that cannot exist.
 *
 * Toggle keys are the shared `buddynext_integration_{key}_nav` options read by
 * buddynext_integration_enabled() (buddynext.php). Each test restores the option
 * to its on ('1') default in a finally, so reruns are idempotent.
 *
 * Actors: A = varundubey (admin owner; profile OWNER for every tab here). B =
 * bn_e2e_target, a seeded subscriber used ONLY for the member-role legs, as
 * the VIEWER of A's tabs — proving a plain member (not the owner, not an
 * admin) can see the integration activity on someone else's profile.
 * Selectors are declared locally (repo rule: never edit the shared selectors.ts).
 *
 * Covers: cap-show-a-member-s-forum-job-listing-or-course-activity-on-thei, cap-list-published-articles-on-a-profile
 * Roles: admin, member
 * Note: the degrade tests (J-747/748/749) walk admin only - the owner toggling
 * their own tab off. J-823 adds the member leg for Discussions (a plain member
 * viewing A's Discussions tab and seeing the credential strip or an honest
 * empty state, never a fatal). J-824 adds the member leg for Articles (WB
 * Member Blog) the same way, softSkipped when the bridge is not resolvable on
 * this harness - the sibling bridge-tab soft-skip pattern. Achievements is
 * gamification (badges), not itself a forum/job/listing/course/article
 * promise; it rides along in this file because all three original tabs share
 * the same degrade pattern.
 */

const A_LOGIN = process.env.BN_TEST_USER ?? 'varundubey';
const B_LOGIN = process.env.BN_TEST_OTHER_USER ?? 'bn_e2e_target';
const B_EMAIL = 'bn_e2e_target@example.com';
const B_NAME = 'BN E2E Target';
const memberUrl = (login: string) => `/members/${login}/`;

const HERO = '.bn-pf-hero';
const ANY_TAB = 'a.bn-tab[role="tab"]';
const tabFor = (slug: string) => `a.bn-tab[href$="/${slug}/"]`;

// Discussions panel content contract (templates/parts/profile/discussions-panel.php):
// either the credential strip (has discussions/reputation) or the empty state.
const JT_CRED_STRIP = '.bn-jt-cred-strip';
// Articles panel content contract (templates/parts/profile/articles-panel.php):
// either the published-articles list or the empty state.
const ARTICLES_LIST = '.bn-articles';
const EMPTY_STATE = '.bn-empty-state';

let A_ID = 0;
let B_ID = 0;

test.beforeAll(async () => {
    A_ID = await userId(A_LOGIN);
    B_ID = await ensureUser(B_LOGIN, B_EMAIL, B_NAME);
    expect(A_ID, `actor "${A_LOGIN}" must exist`).toBeGreaterThan(0);
    expect(B_ID, `viewer "${B_LOGIN}" must exist`).toBeGreaterThan(0);
    // Fresh members are force-redirected to onboarding, which never reaches a
    // profile tab.
    await setUserMeta(B_ID, 'bn_onboarding_complete', '1');
});

/**
 * A plain member (B) views another member's (A's) bridge tab and gets real
 * rendered content — the credential strip / list when there is any, or the
 * template's own honest empty state, but never a blank panel or a fatal.
 * softSkips (with reason) when the tab itself is absent on this harness,
 * matching assertBridgeDegrade's precondition-missing pattern.
 */
async function assertMemberSeesTabContent(
    page: Page,
    testInfo: import('@playwright/test').TestInfo,
    optionKey: string,
    tabSlug: string,
    contentSelector: string,
    absentReason: string
): Promise<void> {
    await wp(['option', 'update', optionKey, '1']);
    try {
        await loginAs(page, B_LOGIN);
        await page.goto(memberUrl(A_LOGIN));
        await expect(page.locator(HERO).first()).toBeVisible();

        if ((await page.locator(tabFor(tabSlug)).count()) === 0) {
            softSkip(testInfo, absentReason);
            return;
        }

        // Navigate straight to the tab URL (same pattern as J-721's About-tab
        // check) rather than clicking - a full page load is the reliable way
        // to reach server-rendered tab content regardless of client routing.
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(`${memberUrl(A_LOGIN)}${tabSlug}/`);
        await expect(page.locator(HERO).first()).toBeVisible();

        // Effect: the tab body actually rendered the integration's content
        // contract for this non-owner, non-admin viewer - not a blank panel.
        const rendered = page.locator(`${contentSelector}, ${EMPTY_STATE}`).first();
        await expect(
            rendered,
            `a member viewing another member's ${tabSlug} tab must see either real content or the empty state, never nothing`
        ).toBeVisible();
    } finally {
        await wp(['option', 'update', optionKey, '1']);
    }
}

/**
 * Drive one bridge tab through the ON→OFF degrade. When the tab is absent even
 * with the integration ON, softSkip with `absentReason` — the plugin registers no
 * profile tab on this harness, which is a finding, not a failure.
 */
async function assertBridgeDegrade(
    page: Page,
    testInfo: import('@playwright/test').TestInfo,
    optionKey: string,
    tabSlug: string,
    absentReason: string
): Promise<void> {
    await wp(['option', 'update', optionKey, '1']);
    try {
        await loginAs(page, A_LOGIN);
        await page.goto(memberUrl(A_LOGIN));
        await expect(page.locator(HERO).first()).toBeVisible();

        const present = await page.locator(tabFor(tabSlug)).count();
        if (present === 0) {
            softSkip(testInfo, absentReason);
            return;
        }

        // ON: the tab is present.
        await expect(page.locator(tabFor(tabSlug)).first()).toBeVisible();

        // Owner turns the integration's nav off.
        await wp(['option', 'update', optionKey, '0']);
        await page.goto(memberUrl(A_LOGIN));

        // Degrade, not error: hero still renders, the tab is gone, the bar survives.
        await expect(page.locator(HERO).first(), 'the profile still renders after the toggle').toBeVisible();
        await expect(
            page.locator(tabFor(tabSlug)),
            `the ${tabSlug} tab must retire when its integration is off`
        ).toHaveCount(0);
        expect(
            await page.locator(ANY_TAB).count(),
            'the rest of the tab bar still renders'
        ).toBeGreaterThan(0);
    } finally {
        await wp(['option', 'update', optionKey, '1']);
    }
}

test.describe('profile / bridge-tab degrade (effect-based)', () => {
    test('J-747 Discussions (Jetonomy) tab degrades to absent when its nav toggle is off', async ({ page }, testInfo) => {
        await assertBridgeDegrade(
            page,
            testInfo,
            'buddynext_integration_jetonomy_nav',
            'discussions',
            'Jetonomy registers no Discussions profile tab on this harness (plugin inactive or nav off by default).'
        );
    });

    test('J-748 Achievements (gamification) tab degrades to absent when its nav toggle is off', async ({ page }, testInfo) => {
        await assertBridgeDegrade(
            page,
            testInfo,
            'buddynext_integration_gamification_nav',
            'achievements',
            'wb-gamification is not active on this harness, so no Achievements profile tab is registered — cannot assert its retire path here.'
        );
    });

    test('J-749 Portfolio (Pro suite) tab degrades to absent when its nav toggle is off', async ({ page }, testInfo) => {
        // The Portfolio tab shows only when a suite integration contributes a
        // VISIBLE panel for the member (SuiteProfile::visible_panels). On this
        // harness the sole visible panel for user 1 is Career Board's Jobs shelf
        // (Learnomy's are owner-only credential shelves that stay empty without
        // enrolments), so the toggle that actually retires the tab is the
        // careerboard nav integration — turning it off empties the panel set and
        // the aggregate Portfolio tab retires with it.
        await assertBridgeDegrade(
            page,
            testInfo,
            'buddynext_integration_careerboard_nav',
            'portfolio',
            'No suite integration contributes a visible Portfolio panel for this member on the harness (no Career-Board jobs/resume or Learnomy credentials), so the Portfolio tab is not registered — cannot assert its retire path here.'
        );
    });

    test('J-823 a member views another member\'s Discussions (Jetonomy) tab and sees the activity (mobile 390px)', async ({ page }, testInfo) => {
        await assertMemberSeesTabContent(
            page,
            testInfo,
            'buddynext_integration_jetonomy_nav',
            'discussions',
            JT_CRED_STRIP,
            'Jetonomy registers no Discussions profile tab on this harness (plugin inactive or nav off by default) — cannot assert the member-view content path here.'
        );
    });

    test('J-824 a member views another member\'s Articles (WB Member Blog) tab and sees the activity (mobile 390px)', async ({ page }, testInfo) => {
        await assertMemberSeesTabContent(
            page,
            testInfo,
            'buddynext_integration_blog_nav',
            'articles',
            ARTICLES_LIST,
            'WB Member Blog is not active on this harness, so no Articles profile tab is registered — cannot assert the member-view content path here.'
        );
    });
});
