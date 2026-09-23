import { test, expect } from '../_fixtures/auth.fixture';
import { wp } from '../_fixtures/wp';

/**
 * J-63 AI moderation toggle (Pro).
 *
 * Covers: cap-moderate-with-ai
 * Roles: admin
 *
 * The toggle is not on the generic Settings screen (`bn_ai_moderation` /
 * `[data-toggle="ai-moderation"]` never existed there) - AIModAdmin registers
 * its own tab under the Moderation hub (`AdminHub::register_tab('moderation',
 * 'ai', ...)`, legacy slug `buddynextpro-ai-moderation`), landing at
 * admin.php?page=buddynext-moderation&tab=ai. The real, always-rendered
 * control is `buddynextpro_ai_mod_scan_posts_enabled` ("Also monitor all new
 * posts"), built by `AdminPageBase::render_toggle_row()` as
 * `#bn-toggle-buddynextpro_ai_mod_scan_posts_enabled`.
 *
 * `AIModAdmin::register()` (the call that wires the tab into AdminHub at all)
 * only runs when the `ai-moderation` FEATURE TOGGLE is on
 * (Plugin.php's $bn_feature_of map + buddynext_feature_enabled() gate) - it is
 * a default-off feature, so on a fresh site `?tab=ai` silently falls back to
 * whatever OTHER Moderation tab is registered (observed: "Controls"). That is
 * a site-config precondition the harness sets up itself, same as every other
 * Pro spec seeding a tier/plan/default - NOT external AI infra: the toggle
 * renders regardless of whether an AI provider is connected (the connector
 * only gates whether the automation actually RUNS - AIModAdmin's own copy:
 * "until then it stays off"). So this is a spec-setup fix, not a soft-skip.
 */
test.describe('pro / ai moderation', () => {
    test.fixme(process.env.BN_PRO !== '1', 'AI moderation lands in Pro only.');

    let previouslyEnabled: boolean | null = null;

    test.beforeAll(async () => {
        if (process.env.BN_PRO !== '1') {
            return;
        }
        const out = (
            await wp([
                'eval',
                `$opt = get_option( 'buddynext_features', array() );` +
                    ` echo wp_json_encode( array_key_exists( 'ai-moderation', $opt ) ? (bool) $opt['ai-moderation'] : null );` +
                    ` $opt['ai-moderation'] = true;` +
                    ` update_option( 'buddynext_features', $opt );`,
            ])
        ).trim();
        previouslyEnabled = JSON.parse(out) as boolean | null;
    });

    test.afterAll(async () => {
        if (process.env.BN_PRO !== '1') {
            return;
        }
        await wp([
            'eval',
            `$opt = get_option( 'buddynext_features', array() );` +
                (null === previouslyEnabled
                    ? ` unset( $opt['ai-moderation'] );`
                    : ` $opt['ai-moderation'] = ${previouslyEnabled ? 'true' : 'false'};`) +
                ` update_option( 'buddynext_features', $opt );`,
        ]).catch(() => undefined);
    });

    test('AI moderation toggle visible in settings', async ({ authenticatedPage: page }) => {
        await page.goto('/wp-admin/admin.php?page=buddynext-moderation&tab=ai');
        const toggle = page.locator('#bn-toggle-buddynextpro_ai_mod_scan_posts_enabled');
        await expect(toggle).toBeVisible();
    });
});
