import { test, expect, type Page } from '@playwright/test';
import { loginAs } from '../_fixtures/actor';
import { userId, wp } from '../_fixtures/wp';
import { softSkip } from '../_fixtures/precondition';

/**
 * Wave-4 PROFILE bridge-tab degrade-to-absent — EFFECT-BASED (J-747..J-749).
 *
 * Mirrors the J-724 Media (WPMediaVerse) pattern for the OTHER integration
 * profile tabs the coverage re-scan named: Discussions (Jetonomy), Achievements
 * (wb-gamification), and Portfolio (Pro suite). Each test asserts the same real
 * consequence: with the integration's nav toggle ON the tab is PRESENT, and when
 * the owner turns it OFF the tab RETIRES (present → absent) while the hero and the
 * rest of the tab bar keep rendering — a graceful degrade, never a fatal.
 *
 * Only tabs that are actually registrable on THIS harness run the full effect
 * assertion. The harness runs jetonomy + jetonomy-pro + learnomy(+pro) +
 * wpmediaverse + buddynext-pro active, but NOT wb-gamification, and varundubey
 * has no visible suite panels — so a bridge whose plugin registers no profile tab
 * here is softSkipped WITH a reason (per the repo's honest-coverage rule) rather
 * than asserted against markup that cannot exist.
 *
 * Toggle keys are the shared `buddynext_integration_{key}_nav` options read by
 * buddynext_integration_enabled() (buddynext.php). Each test restores the option
 * to its on ('1') default in a finally, so reruns are idempotent.
 *
 * Actor: A = varundubey (admin owner; the tabs render on their own profile).
 * Selectors are declared locally (repo rule: never edit the shared selectors.ts).
 */

const A_LOGIN = process.env.BN_TEST_USER ?? 'varundubey';
const memberUrl = (login: string) => `/members/${login}/`;

const HERO = '.bn-pf-hero';
const ANY_TAB = 'a.bn-tab[role="tab"]';
const tabFor = (slug: string) => `a.bn-tab[href$="/${slug}/"]`;

let A_ID = 0;

test.beforeAll(async () => {
    A_ID = await userId(A_LOGIN);
    expect(A_ID, `actor "${A_LOGIN}" must exist`).toBeGreaterThan(0);
});

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
        // VISIBLE panel for the member (SuiteProfile::visible_panels). Learnomy's
        // panels are owner-only credential shelves, so the toggle that retires the
        // tab is the learnomy nav integration.
        await assertBridgeDegrade(
            page,
            testInfo,
            'buddynext_integration_learnomy_nav',
            'portfolio',
            'No suite integration contributes a visible Portfolio panel for this member on the harness (varundubey has no Learnomy/Career-Board credentials), so the Portfolio tab is not registered — cannot assert its retire path here.'
        );
    });
});
