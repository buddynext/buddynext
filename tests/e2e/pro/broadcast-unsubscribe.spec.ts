import { test, expect } from '../_fixtures/auth.fixture';
import { loginAs } from '../_fixtures/actor';
import { urls } from '../_fixtures/selectors';

const MEMBER_LOGIN = process.env.BN_TEST_OTHER_USER ?? 'alice';

/**
 * J-950 let a member unsubscribe from broadcasts (Pro).
 *
 * Written from the member's promise: "when I turn off newsletters and
 * announcements, the community stops emailing me every broadcast, not just the
 * one I clicked unsubscribe from last." The control lives in the Channels card
 * on Settings -> Notifications: `Email\BroadcastUnsubscribe::render_prefs_optout()`
 * hooks `buddynext_notification_prefs_channels_after` and renders
 * `input[data-bn-broadcast-optout]`, wired to Free's
 * `assets/js/notifications/prefs-store.js` `actions.setBroadcastOptOut`, which
 * POSTs `{ unsubscribed_all_broadcasts }` to `buddynext-pro/v1/me/email-preferences`
 * (`EmailUnsubscribeController`). That controller is also the single writer read
 * by `BroadcastService::send_pending()` before every send, so flipping this
 * checkbox is the same flag that actually stops the email — not a decorative
 * toggle. `BroadcastUnsubscribe::META_GLOBAL` usermeta is the effect verified
 * here via server truth (a fresh REST GET), not the checkbox's own `checked`
 * state, which can only prove the click landed in the DOM.
 *
 * Effect-based: toggle off, confirm `GET /me/email-preferences` reports
 * `unsubscribed_all_broadcasts: true` and the checkbox reads unchecked after a
 * full reload; toggle back on and confirm both revert. The pref is restored to
 * "subscribed" in `finally` so the member fixture is left as found for other
 * specs/runs.
 *
 * Covers: cap-let-members-unsubscribe-from-broadcasts
 * Roles: member
 */
test.describe('pro / broadcast unsubscribe', () => {
    test.fixme(process.env.BN_PRO !== '1', 'Broadcast unsubscribe is a Pro feature. Set BN_PRO=1 to run.');

    const optOutInput = 'input[data-bn-broadcast-optout]';
    const prefsUrl = '/wp-json/buddynext-pro/v1/me/email-preferences';

    test('J-950 turning off "Newsletters and announcements" opts the member out of every broadcast', async ({ page }) => {
        await loginAs(page, MEMBER_LOGIN);

        const readPref = async (): Promise<boolean> => {
            const nonce = await page.evaluate(async () => {
                const r = await fetch('/wp-json/buddynext/v1/auth/nonce', { credentials: 'same-origin' });
                const j = (await r.json().catch(() => ({}))) as { nonce?: string };
                return j.nonce ?? '';
            });
            const res = await page.request.get(prefsUrl, { headers: { 'X-WP-Nonce': nonce } });
            expect(res.status(), `GET email-preferences -> ${res.status()}`).toBe(200);
            const body = (await res.json()) as { unsubscribed_all_broadcasts?: boolean };
            return Boolean(body.unsubscribed_all_broadcasts);
        };

        try {
            await page.goto(urls.settingsNotifications);
            const checkbox = page.locator(optOutInput).first();
            await expect(checkbox).toBeVisible({ timeout: 10_000 });

            // Baseline: whatever state it starts in, drive it to a known state (subscribed).
            if (!(await checkbox.isChecked())) {
                await checkbox.check();
                await expect.poll(readPref, { timeout: 8_000 }).toBe(false);
            }
            expect(await readPref()).toBe(false);

            // Effect: unchecking it opts the member out of EVERY broadcast (server truth).
            await checkbox.uncheck();
            await expect.poll(readPref, { timeout: 8_000 }).toBe(true);

            // Persistence: the opted-out state survives a full reload — the checkbox
            // re-renders unchecked because render_prefs_optout() reads the same flag.
            await page.goto(urls.settingsNotifications);
            const checkboxAfter = page.locator(optOutInput).first();
            await expect(checkboxAfter).toBeVisible({ timeout: 10_000 });
            await expect(checkboxAfter).not.toBeChecked();

            // Reverse: opting back in reverts the server flag.
            await checkboxAfter.check();
            await expect.poll(readPref, { timeout: 8_000 }).toBe(false);
        } finally {
            // Leave the member subscribed for other specs/runs.
            await page.goto(urls.settingsNotifications).catch(() => {});
            const cleanup = page.locator(optOutInput).first();
            if (await cleanup.isVisible().catch(() => false) && !(await cleanup.isChecked().catch(() => true))) {
                await cleanup.check().catch(() => {});
            }
        }
    });
});
