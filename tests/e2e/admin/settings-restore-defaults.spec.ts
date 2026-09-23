import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from '../_fixtures/precondition';
import {
    dbSeedingAvailable,
    setOption,
    getOption,
    deleteOption,
} from '../_fixtures/db.fixture';

/**
 * J-818-settings-restore-defaults (card 9995933507).
 *
 * Covers: cap-warn-suspend-or-shadow-ban, cap-let-members-report-content
 * Roles: admin
 *
 * The owner changes three config settings AND the banned words on the Moderation
 * tab, then clicks "Restore defaults". The confirm dialog must list exactly the
 * three config settings (never the banned words) and, after confirming, those
 * three return to their defaults while the banned words are left untouched.
 *
 * Effect-first: the assertions read the option rows through WP-CLI (the reset
 * DELETES a resettable option so its declared default applies again), not a
 * screen string. Runs on every configured project - desktop, iPad and 390px
 * mobile - so the "desktop and 390px" requirement is covered by the matrix.
 *
 * Three resettable config settings on the Moderation tab and their declared
 * defaults (see includes/Admin/Settings.php::fields_moderation):
 *   buddynext_auto_hide_threshold      -> 5   "Auto-hide after N reports"
 *   buddynext_strike_warn_threshold    -> 2   "Strikes before warning"
 *   buddynext_strike_suspend_threshold -> 5   "Strikes before suspension"
 * Owner data on the same tab, never resettable:
 *   buddynext_banned_words (resettable => false) "Banned words"
 */

const TAB_URL = '/wp-admin/admin.php?page=buddynext-settings&tab=moderation';

const CONFIG = [
    { key: 'buddynext_auto_hide_threshold', label: 'Auto-hide after N reports', off: '1', def: '5' },
    { key: 'buddynext_strike_warn_threshold', label: 'Strikes before warning', off: '4', def: '2' },
    { key: 'buddynext_strike_suspend_threshold', label: 'Strikes before suspension', off: '9', def: '5' },
];
// Every resettable config option on the Moderation tab, so the test can wipe the
// tab to a clean default baseline before seeding exactly the three above. Without
// this, any option a real site left off-default would also (correctly) appear in
// the dialog, and the "exactly three" assertion would flap.
const ALL_RESETTABLE = [
    'buddynext_auto_hide_threshold',
    'buddynext_mod_queue_alert_threshold',
    'buddynext_strike_warn_threshold',
    'buddynext_strike_suspend_threshold',
    'buddynext_strike_perma_ban_threshold',
    'buddynext_post_rate_limit',
    'buddynext_comment_rate_limit',
    'buddynext_duplicate_post_window',
    'buddynext_new_member_post_threshold',
];
const BANNED_WORDS = 'buddynext_banned_words';
const BANNED_SEED = 'spamword';

test.describe('admin / settings  -  restore defaults', () => {
    test.skip(!dbSeedingAvailable(), 'Needs WP-CLI seeding (BN_WP_PATH).');

    test.beforeEach(async () => {
        // Clean default baseline: delete every resettable option so nothing else
        // differs, then seed exactly the three config settings + owner data.
        for (const k of ALL_RESETTABLE) {
            await deleteOption(k);
        }
        for (const c of CONFIG) {
            await setOption(c.key, c.off);
        }
        await setOption(BANNED_WORDS, BANNED_SEED);
    });

    test.afterAll(async () => {
        // Leave the tab at defaults and clear the seeded owner data.
        for (const k of ALL_RESETTABLE) {
            await deleteOption(k);
        }
        await deleteOption(BANNED_WORDS);
    });

    test('dialog lists only the changed config settings, and the reset spares owner data', async ({
        authenticatedPage: page,
    }, testInfo) => {
        // Effect-first, so the body makes several WP-CLI reads (~2s each on Local
        // with PHP startup overhead); triple the timeout rather than flap on mobile.
        test.slow();
        const resp = await page.goto(TAB_URL, { waitUntil: 'domcontentloaded' });
        if ((resp?.status() ?? 200) >= 400) {
            softSkip(testInfo, 'Moderation settings tab unavailable to this user.');
            return;
        }

        const restoreBtn = page.locator('[data-bn-restore-defaults]').first();
        await expect(restoreBtn, 'Restore defaults button is present').toBeVisible();

        // Open the confirm dialog.
        await restoreBtn.click();
        const dialog = page.locator('.bn-dialog-backdrop');
        await expect(dialog).toBeVisible();

        // The dialog lists exactly the three changed config settings...
        const rows = dialog.locator('.bn-restore-list li');
        await expect(rows).toHaveCount(CONFIG.length);
        for (const c of CONFIG) {
            await expect(dialog.locator('.bn-restore-list li strong', { hasText: c.label })).toHaveCount(1);
        }
        // ...and never the owner-data "Banned words" among the reset list rows.
        // (The owner note DOES mention banned words - "your data ... is never
        // reset" - so scope this to the list, not the whole dialog.)
        await expect(dialog.locator('.bn-restore-list li strong', { hasText: 'Banned words' })).toHaveCount(0);
        await expect(dialog.locator('.bn-restore-owner-note')).toBeVisible();

        // Confirm -> the form posts and WP redirects back with the count notice.
        await dialog.locator('.bn-dialog__ok').click();
        await page.waitForURL(/bn_reset=\d+/, { timeout: 15000 });
        await expect(page.locator('#setting-error-buddynext_settings_reset')).toContainText(/restored/i);

        // Effect: the three config options are back to default (deleted -> declared
        // default applies), the banned words are untouched.
        for (const c of CONFIG) {
            const val = await getOption(c.key);
            // A reset DELETES the option, so WP-CLI reports it absent ('').
            expect(val, `${c.key} was reset (option row removed)`).toBe('');
        }
        expect(await getOption(BANNED_WORDS), 'banned words are owner data, never reset').toBe(BANNED_SEED);
    });
});
