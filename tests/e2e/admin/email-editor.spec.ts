import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from "../_fixtures/precondition";
import { wp } from '../_fixtures/wp';
import { dbSeedingAvailable } from '../_fixtures/db.fixture';

/**
 * J-60 email editor.
 *
 * Covers: cap-edit-the-emails-it-sends
 * Roles: admin
 *
 * The editor is the Notifications hub "Email Templates" tab
 * (EmailEditor::hub_url(), includes/Admin/EmailEditor.php ~74). The old
 * standalone page=buddynext-emails slug is not registered and answers 403, so a
 * spec pointed at it soft-skipped forever. The URL is declared here rather than
 * in the shared selectors fixture, which other suites own.
 */
const TEMPLATES_URL = '/wp-admin/admin.php?page=buddynext-notifications&tab=templates';
const SLUG = 'bn.new_follower';

/** Count of templates the editor's catalogue declares (the rail renders one link each). */
async function catalogueCount(): Promise<number> {
    return Number(
        await wp([
            'eval',
            `$n = 0; foreach ( ( new \\BuddyNext\\Admin\\EmailEditor() )->get_catalogue() as $t ) { $n += count( $t ); } echo $n;`,
        ])
    );
}

/** The stored row for a template type, base64 JSON so it round-trips through the shell intact ('' when absent). */
async function rowSnapshot(slug: string): Promise<string> {
    return (
        await wp([
            'eval',
            `global $wpdb; $r = $wpdb->get_row( $wpdb->prepare( "SELECT subject, preview_text, body_html, enabled FROM {$wpdb->prefix}bn_email_templates WHERE type = %s", '${slug}' ), ARRAY_A );` +
                ` echo $r ? base64_encode( wp_json_encode( $r ) ) : '';`,
        ])
    ).trim();
}

async function storedSubject(slug: string): Promise<string> {
    return (
        await wp([
            'eval',
            `global $wpdb; echo (string) $wpdb->get_var( $wpdb->prepare( "SELECT subject FROM {$wpdb->prefix}bn_email_templates WHERE type = %s", '${slug}' ) );`,
        ])
    ).trim();
}

test.describe('admin / email editor', () => {
    test('email editor page renders template list and preview', async ({ authenticatedPage: page }, testInfo) => {
        const resp = await page.goto(TEMPLATES_URL, { waitUntil: 'domcontentloaded' });
        if ((resp?.status() ?? 200) >= 400) {
            softSkip(testInfo, 'Email editor not registered.');
            return;
        }
        // Rail + editor pane, per EmailEditor.php ~955-1020.
        const rail = page.locator('#bn-email-rail-list');
        await expect(rail, 'the template rail renders').toBeVisible();
        await expect(page.locator('#bn-email-panel-preview'), 'the editor carries a preview panel').toHaveCount(1);
        // EFFECT: the rail lists every template the catalogue declares, no more, no less.
        if (dbSeedingAvailable()) {
            const declared = await catalogueCount();
            expect(declared, 'the catalogue declares templates').toBeGreaterThan(0);
            await expect(rail.locator('a.bn-split__rail-item')).toHaveCount(declared);
        }
    });

    test('saving a subject writes the template row the sender reads', async ({ authenticatedPage: page }, testInfo) => {
        test.skip(!dbSeedingAvailable(), 'Needs WP-CLI (BN_WP_PATH) to read the template row.');
        test.slow();
        const resp = await page.goto(`${TEMPLATES_URL}&template=${encodeURIComponent(SLUG)}`, {
            waitUntil: 'domcontentloaded',
        });
        if ((resp?.status() ?? 200) >= 400) {
            softSkip(testInfo, 'Email editor not registered.');
            return;
        }
        const before = await rowSnapshot(SLUG);
        const subject = `E2E subject ${Date.now().toString().slice(-6)} on {{site_name}}`;
        try {
            await page.locator('#bn-subject').fill(subject);
            await page.locator('button[type="submit"][form="bn-email-save-form"]').click();
            await page.waitForURL(/[?&]updated=1/, { timeout: 15_000 });
            // EFFECT: bn_email_templates (what EmailSender sends from) holds the new subject.
            expect(await storedSubject(SLUG), 'the saved subject is the stored row').toBe(subject);
            // And the editor re-reads it from the row on a fresh load.
            await page.goto(`${TEMPLATES_URL}&template=${encodeURIComponent(SLUG)}`, { waitUntil: 'domcontentloaded' });
            await expect(page.locator('#bn-subject')).toHaveValue(subject);
        } finally {
            // Put the row back exactly as found (or remove it if there was none).
            await wp([
                'eval',
                `global $wpdb; $t = $wpdb->prefix . 'bn_email_templates'; $snap = '${before}';` +
                    ` if ( '' === $snap ) { $wpdb->delete( $t, array( 'type' => '${SLUG}' ) ); }` +
                    ` else { $wpdb->update( $t, (array) json_decode( base64_decode( $snap ), true ), array( 'type' => '${SLUG}' ) ); }`,
            ]).catch(() => undefined);
        }
    });
});
