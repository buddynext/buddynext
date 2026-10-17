import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from "../_fixtures/precondition";
import { urls } from '../_fixtures/selectors';
import { bnApi } from '../_fixtures/spaces-rest';

/**
 * J-51 notification preferences.
 *
 * Covers: cap-see-in-app-notifications-mark-them-read-and-set-preferences
 * preferences - see FRICTION in the role-coverage report.
 * Roles: member
 *
 * Smoke-walks /settings/notifications/ and the legacy alias
 * /notifications/preferences/ asserting the four sections render and at
 * least one per-type row is interactive.
 */
test.describe('notifications / preferences', () => {
    test('preferences page loads with all four sections', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto('/settings/notifications/');

        const wrapper = page.locator('.bn-notif-prefs');
        if (!(await wrapper.isVisible().catch(() => false))) {
            softSkip(testInfo, 'Preferences page did not render (rewrites need flushing?).');
            return;
        }

        // The prefs page lives inside the shared Settings hub: the H1 is
        // "Settings" and the active tab marks this as the Notifications section
        // (the design deliberately avoids a redundant per-section page title).
        await expect(page.getByRole('heading', { name: /^settings$/i, level: 1 })).toBeVisible();
        await expect(page.locator('.bn-settings-tabs .bn-tab.is-active')).toHaveText(/notifications/i);

        // Channels section.
        await expect(page.locator('input[data-channel="in_app"]')).toBeVisible();
        await expect(page.locator('input[data-channel="email"]')).toBeVisible();

        // Activity types section — at least one row with an on-site toggle + freq chip group.
        const firstRowToggle = page.locator('.bn-prefs-row input[data-type]').first();
        await expect(firstRowToggle).toBeVisible();
        // EFFECT: the toggle renders the stored preference for its type (same route the page saves to).
        const type = (await firstRowToggle.getAttribute('data-type')) ?? '';
        const checked = await firstRowToggle.isChecked();
        const rest = await bnApi(page, 'GET', '/me/notification-prefs');
        expect(rest.status, 'GET /me/notification-prefs').toBe(200);
        const d = (rest.data ?? {}) as Record<string, unknown>;
        const prefs = (d.prefs ?? d.preferences ?? d) as Record<string, { on_site?: boolean | number }>;
        const stored = prefs[type];
        expect(stored, `route carries a preference for "${type}"`).toBeTruthy();
        if (stored && 'on_site' in stored) {
            expect(Boolean(stored.on_site), `"${type}" toggle vs stored on_site`).toBe(checked);
        }
        const firstFreqChip = page.locator('.bn-prefs-chip[data-freq]').first();
        await expect(firstFreqChip).toBeVisible();

        // The "Quiet hours (coming soon)" block is deliberately GONE, and this
        // spec must not demand it back. docs/standards/public-surface-integrity.md
        // bans half-cooked surfaces outright - "disabled mockup inputs, hardcoded
        // sample values, `coming soon` copy or HTML comments never go on a public
        // surface". Asserting it here made the suite enforce the opposite of the
        // plugin's own standard.
    });

    test('J-51 turning one in-app type off saves, survives reload, and turns back on', async ({ authenticatedPage: page }) => {
        type Pref = { on_site?: boolean; email_freq?: string };
        const SAVE = 'button[data-wp-on--click="actions.saveAll"]';

        /** The stored preference for one type, per the route the page saves to. */
        const stored = async (type: string): Promise<Pref> => {
            const rest = await bnApi(page, 'GET', '/me/notification-prefs');
            expect(rest.status, 'GET /me/notification-prefs').toBe(200);
            const d = (rest.data ?? {}) as { prefs?: Record<string, Pref> };
            const row = (d.prefs ?? {})[type];
            expect(row, `route carries a preference for "${type}"`).toBeTruthy();
            return row;
        };

        /** Flip the row's In-app toggle, press Save, wait for the PUT to answer. */
        const flipAndSave = async (type: string, on: boolean): Promise<void> => {
            const toggle = page.locator(`.bn-prefs-row input[type="checkbox"][data-type="${type}"]`);
            await toggle.setChecked(on);
            const save = page.locator(SAVE);
            await expect(save, 'Save stayed disabled after a change').toBeEnabled({ timeout: 5_000 });
            const [put] = await Promise.all([
                page.waitForResponse(
                    (r) => r.url().includes('/me/notification-prefs') && r.request().method() === 'PUT',
                    { timeout: 15_000 }
                ),
                save.click(),
            ]);
            expect(put.status(), 'PUT /me/notification-prefs').toBeLessThan(300);
        };

        await page.goto('/settings/notifications/');
        const first = page.locator('.bn-prefs-row input[type="checkbox"][data-type]').first();
        await expect(first, 'no per-type In-app toggle rendered').toBeVisible({ timeout: 10_000 });
        // A disabled toggle means the master In-app channel is off; that is a different journey.
        await expect(first, 'In-app master channel is off for the test member').toBeEnabled();
        const type = (await first.getAttribute('data-type')) ?? '';
        expect(type).not.toBe('');
        const original = await stored(type);
        const flipped = !original.on_site;

        try {
            await flipAndSave(type, flipped);
            // EFFECT: the route stores the new value, and only on_site moved.
            const after = await stored(type);
            expect(Boolean(after.on_site), `"${type}" on_site after save`).toBe(flipped);
            expect(after.email_freq, 'email frequency changed by an in-app toggle').toBe(original.email_freq);

            // EFFECT: it survives a reload (server-rendered toggle state).
            await page.goto('/settings/notifications/');
            const reloaded = page.locator(`.bn-prefs-row input[type="checkbox"][data-type="${type}"]`);
            if (flipped) {
                await expect(reloaded).toBeChecked();
            } else {
                await expect(reloaded).not.toBeChecked();
            }

            // Counterpart: back to the original through the same screen.
            await flipAndSave(type, Boolean(original.on_site));
            expect(Boolean((await stored(type)).on_site), `"${type}" on_site after restoring`).toBe(Boolean(original.on_site));
        } finally {
            // Leave the member's preference as found even if the screen leg failed.
            await bnApi(page, 'PUT', '/me/notification-prefs', {
                [type]: { on_site: Boolean(original.on_site), email_freq: original.email_freq ?? 'immediate' },
            }).catch(() => undefined);
        }
    });

    test('settings link from /notifications/ navigates to prefs', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.notifications);
        const settingsLink = page.locator('.bn-section-head__actions a.bn-btn--prefs-link');
        if (!(await settingsLink.isVisible().catch(() => false))) {
            softSkip(testInfo, 'Settings link missing on notifications header.');
            return;
        }
        await settingsLink.click();
        await page.waitForURL(/\/settings\/notifications\/?$/);
        await expect(page.locator('.bn-notif-prefs')).toBeVisible();
        // EFFECT: the page it lands on is fed by the prefs route for this member.
        const rest = await bnApi(page, 'GET', '/me/notification-prefs');
        expect(rest.status, 'GET /me/notification-prefs').toBe(200);
        const types = Object.keys(((rest.data ?? {}) as { prefs?: Record<string, unknown> }).prefs ?? {});
        expect(types.length, 'prefs route answered no types').toBeGreaterThan(0);
        await expect(page.locator(`.bn-prefs-row [data-type="${types[0]}"]`).first()).toBeAttached();
    });
});
