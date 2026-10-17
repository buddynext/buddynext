import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from "../_fixtures/precondition";
import { sel } from '../_fixtures/selectors';
import { wp } from '../_fixtures/wp';
import { dbSeedingAvailable } from '../_fixtures/db.fixture';

/**
 * J-58 admin features toggle.
 *
 * Covers: cap-administer-everything-from-one-menu
 * Roles: admin
 *
 * The feature toggles live on the Features tab (Settings::render_tab_features,
 * includes/Admin/Settings.php ~2578), relocated by AdminHub::TAB_PLACEMENT; the
 * bare settings URL opens the General tab, where no toggle exists, so the old
 * parity branch never ran. tab=features redirects to wherever the tab lives now.
 */
const FEATURES_URL = '/wp-admin/admin.php?page=buddynext-settings&tab=features';
// An off-by-default integration nobody else's journey depends on, so flipping it
// for a moment changes nothing a parallel reader sees.
const FLIP_SLUG = 'webhooks';

async function registryOn(slug: string): Promise<boolean> {
    return (await wp(['eval', `echo buddynext_service('features')->is_enabled('${slug}') ? '1' : '0';`])).trim() === '1';
}

/** The stored owner choice in buddynext_features[slug]: '1', '0', or '' when unset. */
async function storedChoice(slug: string): Promise<string> {
    return (
        await wp([
            'eval',
            `$o = (array) get_option( 'buddynext_features', array() ); echo array_key_exists( '${slug}', $o ) ? ( $o['${slug}'] ? '1' : '0' ) : '';`,
        ])
    ).trim();
}

test.describe('admin / settings  -  features', () => {
    test('features panel renders toggles', async ({ authenticatedPage: page }, testInfo) => {
        const resp = await page.goto(FEATURES_URL, { waitUntil: 'domcontentloaded' });
        if ((resp?.status() ?? 200) >= 400) {
            softSkip(testInfo, 'Settings page unavailable to this user.');
            return;
        }
        const panel = page.locator(sel.adminFeatures).first();
        const fallbackForm = page.locator('form#buddynext-settings, form:has(input[name*="bn_"])').first();
        const anyVisible = (await panel.isVisible().catch(() => false)) || (await fallbackForm.isVisible().catch(() => false));
        expect(anyVisible).toBeTruthy();
        const toggles = page.locator('input[type="checkbox"][name^="buddynext_features["]');
        await expect(toggles.first(), 'the Features tab renders feature toggles').toBeAttached();
        // EFFECT: every toggle renders the state the feature registry actually runs with.
        if (dbSeedingAvailable()) {
            const live = JSON.parse(
                await wp([
                    'eval',
                    `$r = buddynext_service('features'); $o = array(); foreach ( array_keys( $r->catalog() ) as $s ) { $o[ $s ] = $r->is_enabled( (string) $s ); } echo wp_json_encode( $o );`,
                ])
            ) as Record<string, boolean>;
            for (const toggle of await toggles.all()) {
                const slug = ((await toggle.getAttribute('name')) ?? '').replace(/^buddynext_features\[|\]$/g, '');
                const checked = await toggle.isChecked();
                expect(live[slug] === true, `feature "${slug}": toggle ${checked ? 'on' : 'off'}, registry ${live[slug]}`).toBe(checked);
            }
        }
    });

    test('flipping a feature and saving changes what the registry runs with', async ({ authenticatedPage: page }, testInfo) => {
        test.skip(!dbSeedingAvailable(), 'Needs WP-CLI (BN_WP_PATH) to read the option back.');
        test.slow();
        const resp = await page.goto(FEATURES_URL, { waitUntil: 'domcontentloaded' });
        if ((resp?.status() ?? 200) >= 400) {
            softSkip(testInfo, 'Settings page unavailable to this user.');
            return;
        }
        const originalStored = await storedChoice(FLIP_SLUG);
        const originalOn = await registryOn(FLIP_SLUG);
        const toggle = page.locator(`input[type="checkbox"][name="buddynext_features[${FLIP_SLUG}]"]`);
        await expect(toggle, `the ${FLIP_SLUG} toggle is offered`).toBeAttached();
        expect(await toggle.isChecked(), 'toggle renders the live state').toBe(originalOn);

        const save = async (on: boolean) => {
            await toggle.setChecked(on, { force: true });
            await page.locator('form.bn-settings-form button[type="submit"][data-variant="primary"]').first().click();
            await page.waitForURL(/settings-updated=true/, { timeout: 15_000 });
        };
        try {
            await save(!originalOn);
            // EFFECT: the stored owner choice and the registry both moved, not just the switch.
            expect(await storedChoice(FLIP_SLUG), 'buddynext_features stores the new choice').toBe(originalOn ? '0' : '1');
            expect(await registryOn(FLIP_SLUG), 'the registry runs with the new choice').toBe(!originalOn);

            // Flip back through the same screen and prove it stuck the other way too.
            await page.goto(FEATURES_URL, { waitUntil: 'domcontentloaded' });
            await save(originalOn);
            expect(await registryOn(FLIP_SLUG), 'flipping back restores the registry state').toBe(originalOn);
        } finally {
            // Leave the option exactly as found, including "never set".
            await wp([
                'eval',
                `$o = (array) get_option( 'buddynext_features', array() ); $v = '${originalStored}';` +
                    ` if ( '' === $v ) { unset( $o['${FLIP_SLUG}'] ); } else { $o['${FLIP_SLUG}'] = '1' === $v; }` +
                    ` update_option( 'buddynext_features', $o );`,
            ]).catch(() => undefined);
        }
    });
});
